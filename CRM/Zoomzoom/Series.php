<?php

use CRM_Zoomzoom_ExtensionUtil as E;

/**
 * Persistence and scheduled reconciliation for recurring Zoom meetings.
 *
 * Scheduling is deliberately owned by one system.  Civi-owned series use
 * native CiviCRM recurring entities; Zoom-owned series use this extension's
 * occurrence table and ordinary, independent CiviCRM Events.
 */
class CRM_Zoomzoom_Series {

  const ORIGIN_CIVI = 'civi';
  const ORIGIN_ZOOM = 'zoom';
  const STATUS_DIRTY = 'dirty';
  const STATUS_ACTIVE = 'active';
  const STATUS_ERROR = 'error';
  const STATUS_DETACHED = 'detached';
  const STATUS_DELETE_PENDING = 'delete_pending';

  private static $participantPropagation = FALSE;
  private static $schemaEnsured = FALSE;

  /**
   * Create extension-owned storage. Safe on install, enable, and upgrade.
   */
  public static function ensureSchema() {
    if (self::$schemaEnsured) {
      return;
    }
    CRM_Core_DAO::executeQuery(<<<SQL
CREATE TABLE IF NOT EXISTS civicrm_zoomzoom_series (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_event_id INT UNSIGNED NULL,
  civicrm_zoom_id VARCHAR(64) NULL,
  origin VARCHAR(8) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'dirty',
  sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
  registration_type TINYINT UNSIGNED NOT NULL DEFAULT 1,
  recurrence_hash CHAR(64) NULL,
  last_error TEXT NULL,
  last_synced_at DATETIME NULL,
  locked_until DATETIME NULL,
  created_date DATETIME NOT NULL,
  modified_date DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY UI_zoomzoom_series_parent (parent_event_id),
  UNIQUE KEY UI_zoomzoom_series_zoom (civicrm_zoom_id),
  KEY IX_zoomzoom_series_status (status, sync_enabled),
  CONSTRAINT FK_zoomzoom_series_event FOREIGN KEY (parent_event_id)
    REFERENCES civicrm_event(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
    );

    CRM_Core_DAO::executeQuery(<<<SQL
CREATE TABLE IF NOT EXISTS civicrm_zoomzoom_occurrence (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  series_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NULL,
  occurrence_id VARCHAR(128) NOT NULL,
  scheduled_start DATETIME NOT NULL,
  duration INT UNSIGNED NOT NULL DEFAULT 0,
  availability_status VARCHAR(24) NOT NULL DEFAULT 'available',
  meeting_uuid VARCHAR(255) NULL,
  last_synced_at DATETIME NULL,
  created_date DATETIME NOT NULL,
  modified_date DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY UI_zoomzoom_occurrence_event (event_id),
  UNIQUE KEY UI_zoomzoom_occurrence_remote (series_id, occurrence_id),
  KEY IX_zoomzoom_occurrence_start (series_id, scheduled_start),
  CONSTRAINT FK_zoomzoom_occurrence_series FOREIGN KEY (series_id)
    REFERENCES civicrm_zoomzoom_series(id) ON DELETE CASCADE,
  CONSTRAINT FK_zoomzoom_occurrence_event FOREIGN KEY (event_id)
    REFERENCES civicrm_event(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
    );
    self::$schemaEnsured = TRUE;
  }

  /**
   * Enable or detach a Civi-owned series from the Repeat form.
   */
  public static function setEnabled($parentEventId, $enabled) {
    self::ensureSchema();
    $parentEventId = (int) $parentEventId;
    if (!$parentEventId) {
      return;
    }
    $existing = self::getByParentEventId($parentEventId);
    $now = date('Y-m-d H:i:s');
    if ($enabled) {
      if ($existing) {
        CRM_Core_DAO::executeQuery(
          "UPDATE civicrm_zoomzoom_series SET sync_enabled = 1, status = %1, last_error = NULL, modified_date = %2 WHERE id = %3",
          [1 => [self::STATUS_DIRTY, 'String'], 2 => [$now, 'String'], 3 => [$existing['id'], 'Integer']]
        );
      }
      else {
        CRM_Core_DAO::executeQuery(
          "INSERT INTO civicrm_zoomzoom_series (parent_event_id, origin, status, sync_enabled, registration_type, created_date, modified_date) VALUES (%1, %2, %3, 1, 1, %4, %4)",
          [1 => [$parentEventId, 'Integer'], 2 => [self::ORIGIN_CIVI, 'String'], 3 => [self::STATUS_DIRTY, 'String'], 4 => [$now, 'String']]
        );
      }
      return;
    }

    if ($existing) {
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_zoomzoom_series SET sync_enabled = 0, status = %1, locked_until = NULL, modified_date = %2 WHERE id = %3",
        [1 => [self::STATUS_DETACHED, 'String'], 2 => [$now, 'String'], 3 => [$existing['id'], 'Integer']]
      );
      self::clearOperationalEventFields((int) $existing['id']);
    }
  }

  public static function markDirtyForEvent($eventId) {
    $series = self::getByEventId($eventId);
    if (!$series || $series['origin'] !== self::ORIGIN_CIVI || !$series['sync_enabled']) {
      return;
    }
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_zoomzoom_series SET status = %1, modified_date = NOW() WHERE id = %2",
      [1 => [self::STATUS_DIRTY, 'String'], 2 => [$series['id'], 'Integer']]
    );
  }

  public static function queueSeriesDeletion($eventId) {
    $series = self::getByEventId($eventId);
    if (!$series || empty($series['civicrm_zoom_id'])) {
      throw new CRM_Core_Exception(E::ts('No managed Zoom series is associated with this Event.'));
    }
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_zoomzoom_series SET status = %1, sync_enabled = 1, modified_date = NOW() WHERE id = %2",
      [1 => [self::STATUS_DELETE_PENDING, 'String'], 2 => [$series['id'], 'Integer']]
    );
  }

  public static function getByParentEventId($eventId) {
    self::ensureSchema();
    return self::fetchOne(
      "SELECT * FROM civicrm_zoomzoom_series WHERE parent_event_id = %1",
      [1 => [(int) $eventId, 'Integer']]
    );
  }

  public static function getByZoomId($civicrmZoomId) {
    self::ensureSchema();
    return self::fetchOne(
      "SELECT * FROM civicrm_zoomzoom_series WHERE civicrm_zoom_id = %1",
      [1 => [$civicrmZoomId, 'String']]
    );
  }

  public static function getByEventId($eventId) {
    self::ensureSchema();
    return self::fetchOne(
      "SELECT s.* FROM civicrm_zoomzoom_series s LEFT JOIN civicrm_zoomzoom_occurrence o ON o.series_id = s.id WHERE s.parent_event_id = %1 OR o.event_id = %1 LIMIT 1",
      [1 => [(int) $eventId, 'Integer']]
    );
  }

  public static function getOccurrenceByEventId($eventId) {
    self::ensureSchema();
    return self::fetchOne(
      "SELECT o.*, s.civicrm_zoom_id, s.origin, s.status AS series_status FROM civicrm_zoomzoom_occurrence o INNER JOIN civicrm_zoomzoom_series s ON s.id = o.series_id WHERE o.event_id = %1",
      [1 => [(int) $eventId, 'Integer']]
    );
  }

  public static function isManagedEvent($eventId) {
    $series = self::getByEventId($eventId);
    return $series && $series['status'] !== self::STATUS_DETACHED;
  }

  public static function isSeriesParent($eventId) {
    $series = self::getByParentEventId($eventId);
    return $series && $series['status'] !== self::STATUS_DETACHED;
  }

  public static function getStatusForEvent($eventId) {
    $series = self::getByEventId($eventId);
    if (!$series) {
      return NULL;
    }
    return [
      'id' => (int) $series['id'],
      'origin' => $series['origin'],
      'status' => $series['status'],
      'enabled' => (bool) $series['sync_enabled'],
      'zoom_id' => $series['civicrm_zoom_id'],
      'error' => $series['last_error'],
      'last_synced_at' => $series['last_synced_at'],
    ];
  }

  /**
   * Describe which Repeat-form controls are safe for an Event.
   *
   * Zoom-owned imports are independent CiviCRM Events, not native CiviCRM
   * repeat children. Their extension anchor is only a grouping convenience.
   */
  public static function getRepeatFormContext($eventId) {
    $eventId = (int) $eventId;
    $series = self::getByEventId($eventId);
    if (!$series || $series['status'] === self::STATUS_DETACHED) {
      return ['role' => 'unmanaged', 'is_locked' => FALSE, 'series' => NULL, 'occurrence' => NULL];
    }

    $occurrence = self::getOccurrenceByEventId($eventId);
    if ($series['origin'] === self::ORIGIN_ZOOM) {
      return ['role' => 'zoom_occurrence', 'is_locked' => TRUE, 'series' => $series, 'occurrence' => $occurrence];
    }
    if ((int) $series['parent_event_id'] === $eventId) {
      return ['role' => 'civi_parent', 'is_locked' => FALSE, 'series' => $series, 'occurrence' => $occurrence];
    }
    return ['role' => 'civi_occurrence', 'is_locked' => TRUE, 'series' => $series, 'occurrence' => $occurrence];
  }

  /**
   * Restore the display anchor for existing Zoom-owned imports without
   * assigning schedule ownership to CiviCRM.
   */
  public static function repairZoomOwnedAnchors() {
    self::ensureSchema();
    $series = CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_zoomzoom_series WHERE origin = 'zoom'");
    while ($series->fetch()) {
      self::normalizeZoomOwnedAnchor((int) $series->id);
    }
  }

  /**
   * Scheduled-job entry point.
   */
  public static function syncAll() {
    self::ensureSchema();
    $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'locked' => 0];
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT id FROM civicrm_zoomzoom_series WHERE sync_enabled = 1 AND status IN ('dirty','active','error','delete_pending') ORDER BY modified_date ASC"
    );
    while ($dao->fetch()) {
      $id = (int) $dao->id;
      $stats['processed']++;
      if (!self::acquireLock($id)) {
        $stats['locked']++;
        continue;
      }
      try {
        $series = self::fetchOne('SELECT * FROM civicrm_zoomzoom_series WHERE id = %1', [1 => [$id, 'Integer']]);
        if ($series['status'] === self::STATUS_DELETE_PENDING) {
          self::deleteRemoteSeries($series);
        }
        elseif ($series['origin'] === self::ORIGIN_CIVI) {
          self::syncCiviOwned($series);
        }
        else {
          self::syncZoomOwned($series);
        }
        $stats['succeeded']++;
      }
      catch (Throwable $e) {
        self::recordError($id, $e);
        $stats['failed']++;
      }
      finally {
        self::releaseLock($id);
      }
    }
    return $stats;
  }

  /**
   * Import a newly discovered Zoom type-8 meeting.
   *
   * Existing master-only Events are intentionally not migrated.
   */
  public static function importZoomSeries(array $meeting) {
    self::ensureSchema();
    if ((int) ($meeting['type'] ?? 0) !== 8 || empty($meeting['id'])) {
      return FALSE;
    }
    $civicrmZoomId = 'm' . $meeting['id'];
    $series = self::getByZoomId($civicrmZoomId);
    if (!$series) {
      $legacy = \Civi\Api4\Event::get(FALSE)
        ->addSelect('id')
        ->addWhere('zoom.zoom_id', '=', $civicrmZoomId)
        ->setLimit(1)
        ->execute();
      if ($legacy->count()) {
        CRM_Core_Error::debug_log_message('ZoomZoom recurring import skipped legacy master-only Event ' . $legacy[0]['id'] . ' for ' . $civicrmZoomId . '.');
        return FALSE;
      }
      $now = date('Y-m-d H:i:s');
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_zoomzoom_series (civicrm_zoom_id, origin, status, sync_enabled, registration_type, created_date, modified_date) VALUES (%1, %2, %3, 1, 1, %4, %4)",
        [1 => [$civicrmZoomId, 'String'], 2 => [self::ORIGIN_ZOOM, 'String'], 3 => [self::STATUS_DIRTY, 'String'], 4 => [$now, 'String']]
      );
      $series = self::getByZoomId($civicrmZoomId);
    }
    self::syncZoomOwned($series);
    return TRUE;
  }

  private static function syncCiviOwned(array $series) {
    if (empty($series['parent_event_id'])) {
      throw new CRM_Core_Exception(E::ts('The CiviCRM series parent no longer exists.'));
    }
    $event = self::getEvent((int) $series['parent_event_id']);
    $translated = self::translateCiviRecurrence($event);
    $hash = hash('sha256', json_encode([$translated, $event['title'], $event['start_date'], $event['end_date'], $event['event_tz']]));
    $payload = self::buildMeetingPayload($event, $translated);
    $createdMaster = FALSE;

    if (empty($series['civicrm_zoom_id'])) {
      if (!empty($event['zoom.zoom_id'])) {
        throw new CRM_Core_Exception(E::ts('This Event already has a legacy Zoom link. Detach or delete that one-time meeting before enabling recurring-series synchronization.'));
      }
      $remote = CRM_Zoomzoom_Zoom::createRecurringMeeting($payload);
      if (empty($remote['id'])) {
        throw new CRM_Core_Exception(E::ts('Zoom did not create the recurring meeting.'));
      }
      $series['civicrm_zoom_id'] = 'm' . $remote['id'];
      $createdMaster = TRUE;
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_zoomzoom_series SET civicrm_zoom_id = %1, recurrence_hash = %2, modified_date = NOW() WHERE id = %3",
        [1 => [$series['civicrm_zoom_id'], 'String'], 2 => [$hash, 'String'], 3 => [$series['id'], 'Integer']]
      );
    }
    else {
      // CiviCRM owns this schedule. Reassert the master on every poll so an
      // out-of-band Zoom edit cannot silently become authoritative.
      if (!CRM_Zoomzoom_Zoom::updateRecurringMeeting(substr($series['civicrm_zoom_id'], 1), $payload)) {
        if (CRM_Zoomzoom_Zoom::getLastResponseCode() === 404) {
          $remote = CRM_Zoomzoom_Zoom::createRecurringMeeting($payload);
          if (empty($remote['id'])) {
            throw new CRM_Core_Exception(E::ts('The Civi-owned Zoom series was deleted remotely and could not be recreated.'));
          }
          $series['civicrm_zoom_id'] = 'm' . $remote['id'];
          $createdMaster = TRUE;
          CRM_Core_DAO::executeQuery('UPDATE civicrm_zoomzoom_series SET civicrm_zoom_id = %1, modified_date = NOW() WHERE id = %2', [1 => [$series['civicrm_zoom_id'], 'String'], 2 => [$series['id'], 'Integer']]);
        }
        else {
          throw new CRM_Core_Exception(E::ts('Zoom rejected the recurring meeting update.'));
        }
      }
    }

    if (!$createdMaster) {
      self::applyCiviOccurrenceChanges($series);
    }
    $details = CRM_Zoomzoom_Zoom::getMeetingOccurrences(substr($series['civicrm_zoom_id'], 1), TRUE);
    if (empty($details['occurrences'])) {
      throw new CRM_Core_Exception(E::ts('Zoom returned no occurrences for the recurring meeting.'));
    }
    self::mapCiviOccurrencesAtomically($series, $event, $details['occurrences'], $details);
    self::syncCiviOwnedRegistrations($series);
    self::propagateParentParticipants((int) $series['id']);
    self::finishSync((int) $series['id'], $hash);
  }

  /**
   * Push mapped child edits using occurrence IDs before remapping the series.
   */
  private static function applyCiviOccurrenceChanges(array $series) {
    self::cancelPendingOccurrences($series);
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL AND availability_status = 'available' AND scheduled_start >= UTC_TIMESTAMP()",
      [1 => [$series['id'], 'Integer']]
    );
    while ($dao->fetch()) {
      $event = self::getEvent((int) $dao->event_id);
      $currentUtc = self::utcMinute($event['start_date'], $event['event_tz'] ?? NULL);
      $mappedUtc = self::utcMinute($dao->scheduled_start, 'UTC');
      $duration = !empty($event['end_date'])
        ? max(1, (int) ceil((strtotime($event['end_date']) - strtotime($event['start_date'])) / 60))
        : (int) $dao->duration;
      if ($currentUtc === $mappedUtc && $duration === (int) $dao->duration) {
        continue;
      }
      $tz = self::supportedTimezone($event['event_tz'] ?? NULL) ?: CRM_Core_Config::singleton()->userSystem->getTimeZoneString();
      $start = new DateTimeImmutable($event['start_date'], new DateTimeZone($tz));
      if (!CRM_Zoomzoom_Zoom::updateMeetingOccurrence(substr($series['civicrm_zoom_id'], 1), $dao->occurrence_id, [
        'start_time' => $start->format('Y-m-d\TH:i:s'),
        'timezone' => $tz,
        'duration' => $duration,
      ])) {
        throw new CRM_Core_Exception(E::ts('Zoom rejected a change to one recurring meeting occurrence.'));
      }
    }
  }

  private static function syncZoomOwned(array $series) {
    if (empty($series['civicrm_zoom_id'])) {
      throw new CRM_Core_Exception(E::ts('The Zoom-owned series has no Zoom meeting ID.'));
    }
    $details = CRM_Zoomzoom_Zoom::getMeetingOccurrences(substr($series['civicrm_zoom_id'], 1), TRUE);
    if (empty($details) || (isset($details['type']) && (int) $details['type'] !== 8)) {
      if (CRM_Zoomzoom_Zoom::getLastResponseCode() === 404) {
        self::detachDeletedZoomOwnedSeries($series);
        return;
      }
      throw new CRM_Core_Exception(E::ts('The recurring Zoom meeting is missing or is no longer recurring.'));
    }
    $registrationType = (int) ($details['settings']['registration_type'] ?? 1);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_zoomzoom_series SET registration_type = %1 WHERE id = %2', [1 => [$registrationType, 'Integer'], 2 => [$series['id'], 'Integer']]);
    $occurrences = array_values(array_filter($details['occurrences'] ?? [], static function($occurrence) {
      return !empty($occurrence['occurrence_id']) && !empty($occurrence['start_time']);
    }));
    usort($occurrences, static function($a, $b) {
      return strcmp($a['start_time'], $b['start_time']);
    });
    $now = time();
    $future = array_values(array_filter($occurrences, static function($occurrence) use ($now) {
      return strtotime($occurrence['start_time']) >= $now && ($occurrence['status'] ?? 'available') === 'available';
    }));
    $wantedIds = array_column(array_slice($future, 0, 30), 'occurrence_id');
    $remoteIds = array_map('strval', array_column($occurrences, 'occurrence_id'));

    foreach ($occurrences as $occurrence) {
      $existing = self::getOccurrence((int) $series['id'], (string) $occurrence['occurrence_id']);
      // Initial import creates only the rolling future horizon. Existing rows
      // continue to be reconciled after they become historical.
      if (!in_array($occurrence['occurrence_id'], $wantedIds, TRUE) && !$existing) {
        continue;
      }
      self::upsertZoomOwnedOccurrence($series, $details, $occurrence, $existing);
    }
    $stored = CRM_Core_DAO::executeQuery(
      "SELECT id, event_id, occurrence_id FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND scheduled_start >= UTC_TIMESTAMP() AND availability_status = 'available'",
      [1 => [$series['id'], 'Integer']]
    );
    while ($stored->fetch()) {
      if (!in_array((string) $stored->occurrence_id, $remoteIds, TRUE)) {
        CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_occurrence SET availability_status = 'deleted', modified_date = NOW() WHERE id = %1", [1 => [$stored->id, 'Integer']]);
        if ($stored->event_id) {
          \Civi\Api4\Event::update(FALSE)->addWhere('id', '=', (int) $stored->event_id)->addValue('is_active', FALSE)->execute();
        }
      }
    }
    self::normalizeZoomOwnedAnchor((int) $series['id']);
    self::syncZoomOwnedRegistrants($series);
    self::syncPastAttendance($series);
    self::finishSync((int) $series['id'], hash('sha256', json_encode($details['recurrence'] ?? [])));
  }

  private static function detachDeletedZoomOwnedSeries(array $series) {
    $dao = CRM_Core_DAO::executeQuery('SELECT event_id FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL AND scheduled_start >= UTC_TIMESTAMP()', [1 => [$series['id'], 'Integer']]);
    while ($dao->fetch()) {
      \Civi\Api4\Event::update(FALSE)->addWhere('id', '=', (int) $dao->event_id)->addValue('is_active', FALSE)->execute();
    }
    self::clearOperationalEventFields((int) $series['id']);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_series SET sync_enabled = 0, status = 'detached', last_error = %1, modified_date = NOW() WHERE id = %2", [
      1 => [E::ts('The Zoom-owned recurring meeting was deleted remotely.'), 'String'],
      2 => [$series['id'], 'Integer'],
    ]);
  }

  private static function syncZoomOwnedRegistrants(array $series) {
    $registrants = CRM_Zoomzoom_Zoom::getRegistrants('meetings', substr($series['civicrm_zoom_id'], 1));
    if (!$registrants) {
      return;
    }
    $events = CRM_Core_DAO::executeQuery(
      "SELECT event_id, scheduled_start FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL AND scheduled_start >= UTC_TIMESTAMP() AND availability_status = 'available'",
      [1 => [$series['id'], 'Integer']]
    );
    while ($events->fetch()) {
      $batch = [];
      foreach ($registrants as $registrant) {
        $batch[] = [
          'registration_date' => strtotime($registrant['create_time'] ?? 'now'),
          'first_name' => $registrant['first_name'] ?? '',
          'last_name' => $registrant['last_name'] ?? '',
          'email' => $registrant['email'] ?? '',
          'zoom_id' => $registrant['id'] ?? '',
          'zoom_join_url' => $registrant['join_url'] ?? '',
          'event' => ['id' => (int) $events->event_id, 'start_date' => $events->scheduled_start],
          'status_id' => Civi::settings()->get('zoom_import_status_registration'),
        ];
      }
      CRM_Zoomzoom_Zoom::bulkUpdateCiviCRMParticipants($batch, (int) $events->event_id);
    }
  }

  private static function mapCiviOccurrencesAtomically(array $series, array $parent, array $remoteOccurrences, array $details) {
    $recurringEntities = CRM_Core_BAO_RecurringEntity::getEntitiesForParent((int) $series['parent_event_id'], 'civicrm_event', TRUE);
    $eventIds = array_column($recurringEntities ?: [], 'id');
    if (!$eventIds) {
      $eventIds = [(int) $series['parent_event_id']];
    }
    $localByTime = [];
    foreach ($eventIds as $eventId) {
      $event = self::getEvent((int) $eventId);
      $key = self::utcMinute($event['start_date'], $event['event_tz'] ?? NULL);
      if (isset($localByTime[$key])) {
        throw new CRM_Core_Exception(E::ts('Two CiviCRM occurrences share the same normalized start time.'));
      }
      $localByTime[$key] = $event;
    }
    $remoteByTime = [];
    foreach ($remoteOccurrences as $remote) {
      if (($remote['status'] ?? 'available') !== 'available') {
        continue;
      }
      $key = self::utcMinute($remote['start_time'], 'UTC');
      if (isset($remoteByTime[$key])) {
        throw new CRM_Core_Exception(E::ts('Zoom returned ambiguous occurrence start times.'));
      }
      $remoteByTime[$key] = $remote;
    }
    if (count($localByTime) !== count($remoteByTime) || array_diff_key($localByTime, $remoteByTime) || array_diff_key($remoteByTime, $localByTime)) {
      throw new CRM_Core_Exception(E::ts('CiviCRM and Zoom occurrence dates do not match; no occurrence mappings were changed.'));
    }

    $transaction = new CRM_Core_Transaction();
    try {
      foreach ($localByTime as $key => $event) {
        $remote = $remoteByTime[$key];
        self::upsertOccurrence((int) $series['id'], (int) $event['id'], $remote);
        self::setZoomEventFields((int) $event['id'], $series['civicrm_zoom_id'], $details);
      }
      $transaction->commit();
    }
    catch (Throwable $e) {
      $transaction->rollback();
      throw $e;
    }
  }

  private static function upsertZoomOwnedOccurrence(array $series, array $details, array $occurrence, $existing) {
    $eventId = !empty($existing['event_id']) ? (int) $existing['event_id'] : NULL;
    $status = $occurrence['status'] ?? 'available';
    if (!$eventId && $status === 'available') {
      $eventId = self::createZoomOwnedEvent($series, $details, $occurrence);
    }
    elseif ($eventId) {
      self::updateZoomOwnedEvent($eventId, $details, $occurrence);
    }
    self::upsertOccurrence((int) $series['id'], $eventId, $occurrence);
    if ($eventId) {
      self::setZoomEventFields($eventId, $series['civicrm_zoom_id'], $details);
    }
  }

  /**
   * A Zoom-owned series has no CiviCRM recurrence master. Keep a stable,
   * human-useful anchor pointing at its earliest retained occurrence instead.
   */
  private static function normalizeZoomOwnedAnchor($seriesId) {
    $anchor = self::fetchOne(
      'SELECT event_id FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL ORDER BY scheduled_start ASC, id ASC LIMIT 1',
      [1 => [$seriesId, 'Integer']]
    );
    if ($anchor) {
      CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_zoomzoom_series SET parent_event_id = %1, modified_date = NOW() WHERE id = %2',
        [1 => [$anchor['event_id'], 'Integer'], 2 => [$seriesId, 'Integer']]
      );
    }
    else {
      CRM_Core_DAO::executeQuery('UPDATE civicrm_zoomzoom_series SET parent_event_id = NULL, modified_date = NOW() WHERE id = %1', [1 => [$seriesId, 'Integer']]);
    }
  }

  private static function createZoomOwnedEvent(array $series, array $details, array $occurrence) {
    $eventType = Civi::settings()->get('zoom_import_meeting');
    $tz = self::supportedTimezone($details['timezone'] ?? NULL);
    $start = self::localDate($occurrence['start_time'], $tz);
    $duration = (int) ($occurrence['duration'] ?? $details['duration'] ?? 0);
    $create = \Civi\Api4\Event::create(FALSE)
      ->addValue('title', $details['topic'] ?? E::ts('Zoom Meeting'))
      ->addValue('summary', $details['topic'] ?? E::ts('Zoom Meeting'))
      ->addValue('event_type_id', $eventType)
      ->addValue('is_public', FALSE)
      ->addValue('is_active', ($occurrence['status'] ?? 'available') === 'available')
      ->addValue('start_date', $start)
      ->addValue('end_date', date('Y-m-d H:i:s', strtotime($start) + ($duration * 60)));
    if ($tz) {
      $create->addValue('event_tz', $tz);
    }
    return (int) $create->execute()->first()['id'];
  }

  private static function updateZoomOwnedEvent($eventId, array $details, array $occurrence) {
    $existing = self::getEvent($eventId);
    $tz = self::supportedTimezone($details['timezone'] ?? ($existing['event_tz'] ?? NULL));
    $start = self::localDate($occurrence['start_time'], $tz);
    $duration = (int) ($occurrence['duration'] ?? $details['duration'] ?? 0);
    // Zoom owns scheduling fields; local title/summary/description remain editable.
    \Civi\Api4\Event::update(FALSE)
      ->addWhere('id', '=', $eventId)
      ->addValue('start_date', $start)
      ->addValue('end_date', date('Y-m-d H:i:s', strtotime($start) + ($duration * 60)))
      ->addValue('is_active', ($occurrence['status'] ?? 'available') === 'available')
      ->execute();
  }

  private static function translateCiviRecurrence(array $event) {
    $schedule = self::fetchOne(
      "SELECT * FROM civicrm_action_schedule WHERE name = %1 OR (entity_value = %2 AND name LIKE 'repeat_civicrm_event_%') ORDER BY id DESC LIMIT 1",
      [1 => ['repeat_civicrm_event_' . $event['id'], 'String'], 2 => [$event['id'], 'Integer']]
    );
    if (!$schedule) {
      throw new CRM_Core_Exception(E::ts('No native CiviCRM Repeat schedule was found.'));
    }
    $unit = $schedule['repetition_frequency_unit'];
    $interval = max(1, (int) $schedule['repetition_frequency_interval']);
    $recurrence = ['repeat_interval' => $interval];
    if ($unit === 'day') {
      $recurrence['type'] = 1;
    }
    elseif ($unit === 'week') {
      $recurrence['type'] = 2;
      $dayMap = ['sunday' => 1, 'monday' => 2, 'tuesday' => 3, 'wednesday' => 4, 'thursday' => 5, 'friday' => 6, 'saturday' => 7];
      $days = [];
      foreach (explode(',', (string) $schedule['start_action_condition']) as $day) {
        $day = strtolower(trim($day));
        if (!isset($dayMap[$day])) {
          throw new CRM_Core_Exception(E::ts('The weekly Repeat schedule contains an unsupported weekday.'));
        }
        $days[] = $dayMap[$day];
      }
      if (!$days) {
        throw new CRM_Core_Exception(E::ts('The weekly Repeat schedule has no weekdays.'));
      }
      $recurrence['weekly_days'] = implode(',', array_unique($days));
    }
    elseif ($unit === 'month') {
      $recurrence['type'] = 3;
      if (!empty($schedule['entity_status'])) {
        $bits = preg_split('/\s+/', strtolower(trim($schedule['entity_status'])));
        $weekMap = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1];
        $dayMap = ['sunday' => 1, 'monday' => 2, 'tuesday' => 3, 'wednesday' => 4, 'thursday' => 5, 'friday' => 6, 'saturday' => 7];
        if (count($bits) !== 2 || !isset($weekMap[$bits[0]], $dayMap[$bits[1]])) {
          throw new CRM_Core_Exception(E::ts('The monthly nth-weekday Repeat schedule is unsupported.'));
        }
        $recurrence['monthly_week'] = $weekMap[$bits[0]];
        $recurrence['monthly_week_day'] = $dayMap[$bits[1]];
      }
      else {
        $day = (int) $schedule['limit_to'];
        if ($day < 1 || $day > 31) {
          $day = (int) (new DateTimeImmutable($event['start_date']))->format('j');
        }
        $recurrence['monthly_day'] = $day;
      }
    }
    else {
      throw new CRM_Core_Exception(E::ts('Only daily, weekly, and monthly fixed-time Repeat schedules are supported.'));
    }
    if (!empty($schedule['start_action_offset'])) {
      $count = (int) $schedule['start_action_offset'];
      if ($count < 1 || $count > 60) {
        throw new CRM_Core_Exception(E::ts('Zoom supports between 1 and 60 occurrences per recurring meeting.'));
      }
      $recurrence['end_times'] = $count;
    }
    elseif (!empty($schedule['absolute_date'])) {
      $eventTimezone = self::supportedTimezone($event['event_tz'] ?? NULL) ?: CRM_Core_Config::singleton()->userSystem->getTimeZoneString();
      $end = new DateTimeImmutable(substr($schedule['absolute_date'], 0, 10) . ' 23:59:59', new DateTimeZone($eventTimezone));
      $recurrence['end_date_time'] = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    else {
      throw new CRM_Core_Exception(E::ts('The Repeat schedule must have an occurrence count or end date.'));
    }
    return $recurrence;
  }

  private static function buildMeetingPayload(array $event, array $recurrence) {
    $tz = self::supportedTimezone($event['event_tz'] ?? NULL) ?: CRM_Core_Config::singleton()->userSystem->getTimeZoneString();
    $start = new DateTimeImmutable($event['start_date'], new DateTimeZone($tz));
    $duration = 60;
    if (!empty($event['end_date'])) {
      $duration = max(1, (int) ceil((strtotime($event['end_date']) - strtotime($event['start_date'])) / 60));
    }
    return [
      'topic' => $event['title'],
      'agenda' => strip_tags((string) ($event['summary'] ?? '')),
      'type' => 8,
      'start_time' => $start->format('Y-m-d\TH:i:s'),
      'timezone' => $tz,
      'duration' => $duration,
      'recurrence' => $recurrence,
      'settings' => ['approval_type' => 0, 'registration_type' => 1],
    ];
  }

  private static function getEvent($eventId) {
    $result = \Civi\Api4\Event::get(FALSE)
      ->addSelect('id', 'title', 'summary', 'start_date', 'end_date', 'event_tz', 'is_active', 'zoom.zoom_id')
      ->addWhere('id', '=', (int) $eventId)
      ->setLimit(1)
      ->execute();
    if (!$result->count()) {
      throw new CRM_Core_Exception(E::ts('CiviCRM Event %1 was not found.', [1 => $eventId]));
    }
    return $result->first();
  }

  private static function setZoomEventFields($eventId, $civicrmZoomId, array $details) {
    $update = \Civi\Api4\Event::update(FALSE)
      ->addWhere('id', '=', $eventId)
      ->addValue('zoom.zoom_id', $civicrmZoomId);
    foreach (['join_url', 'registration_url', 'password'] as $field) {
      if (array_key_exists($field, $details)) {
        $update->addValue('zoom.' . $field, $details[$field]);
      }
    }
    // Never persist a recurring master start_url. Host access retrieves it fresh.
    $update->addValue('zoom.start_url', NULL)->execute();
    if (!empty($details['settings']['global_dial_in_numbers'])) {
      $dialIn = '';
      foreach ($details['settings']['global_dial_in_numbers'] as $number) {
        $dialIn .= ($number['number'] ?? '') . ' (' . ($number['country_name'] ?? '') . ')<br/>';
      }
      $fieldId = CRM_Core_BAO_CustomField::getCustomFieldID('global_dial_in_numbers', 'zoom', TRUE);
      civicrm_api3('Event', 'create', ['id' => $eventId, $fieldId => $dialIn]);
    }
  }

  private static function clearOperationalEventFields($seriesId) {
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT event_id FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL UNION SELECT parent_event_id FROM civicrm_zoomzoom_series WHERE id = %1 AND parent_event_id IS NOT NULL',
      [1 => [$seriesId, 'Integer']]
    );
    while ($dao->fetch()) {
      \Civi\Api4\Event::update(FALSE)
        ->addWhere('id', '=', (int) $dao->event_id)
        ->addValue('zoom.zoom_id', NULL)
        ->addValue('zoom.start_url', NULL)
        ->addValue('zoom.join_url', NULL)
        ->addValue('zoom.registration_url', NULL)
        ->addValue('zoom.password', NULL)
        ->addValue('zoom.global_dial_in_numbers', NULL)
        ->execute();
    }
  }

  private static function upsertOccurrence($seriesId, $eventId, array $occurrence) {
    $start = gmdate('Y-m-d H:i:s', strtotime($occurrence['start_time']));
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_zoomzoom_occurrence (series_id, event_id, occurrence_id, scheduled_start, duration, availability_status, last_synced_at, created_date, modified_date)
       VALUES (%1, %2, %3, %4, %5, %6, NOW(), NOW(), NOW())
       ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), occurrence_id = VALUES(occurrence_id), scheduled_start = VALUES(scheduled_start), duration = VALUES(duration), availability_status = VALUES(availability_status), last_synced_at = NOW(), modified_date = NOW()",
      [
        1 => [$seriesId, 'Integer'],
        2 => [$eventId ?: NULL, 'Integer'],
        3 => [(string) $occurrence['occurrence_id'], 'String'],
        4 => [$start, 'String'],
        5 => [(int) ($occurrence['duration'] ?? 0), 'Integer'],
        6 => [$occurrence['status'] ?? 'available', 'String'],
      ]
    );
  }

  private static function getOccurrence($seriesId, $occurrenceId) {
    return self::fetchOne(
      'SELECT * FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND occurrence_id = %2',
      [1 => [$seriesId, 'Integer'], 2 => [$occurrenceId, 'String']]
    );
  }

  public static function handleEventDeletion($eventId) {
    $series = self::getByEventId($eventId);
    if (!$series || $series['status'] === self::STATUS_DETACHED) {
      return;
    }
    if ($series['origin'] === self::ORIGIN_CIVI && (int) $series['parent_event_id'] === (int) $eventId) {
      // Generic parent deletion is a detach. Explicit deletion is separately queued.
      self::clearOperationalEventFields((int) $series['id']);
      CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_series SET sync_enabled = 0, status = 'detached', parent_event_id = NULL, modified_date = NOW() WHERE id = %1", [1 => [$series['id'], 'Integer']]);
      return;
    }
    $occurrence = self::getOccurrenceByEventId($eventId);
    if ($occurrence && $series['origin'] === self::ORIGIN_CIVI && !empty($occurrence['occurrence_id'])) {
      CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_occurrence SET event_id = NULL, availability_status = 'cancel_pending', modified_date = NOW() WHERE id = %1", [1 => [$occurrence['id'], 'Integer']]);
      self::markDirtyForEvent($series['parent_event_id']);
    }
  }

  private static function cancelPendingOccurrences(array $series) {
    $dao = CRM_Core_DAO::executeQuery("SELECT id, occurrence_id FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND availability_status = 'cancel_pending'", [1 => [$series['id'], 'Integer']]);
    while ($dao->fetch()) {
      if (!CRM_Zoomzoom_Zoom::deleteMeetingOccurrence(substr($series['civicrm_zoom_id'], 1), $dao->occurrence_id)) {
        throw new CRM_Core_Exception(E::ts('Zoom rejected an occurrence cancellation.'));
      }
      CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_occurrence SET availability_status = 'deleted', modified_date = NOW() WHERE id = %1", [1 => [$dao->id, 'Integer']]);
    }
  }

  private static function deleteRemoteSeries(array $series) {
    if (!empty($series['civicrm_zoom_id']) && !CRM_Zoomzoom_Zoom::deleteZoom($series['civicrm_zoom_id'])) {
      throw new CRM_Core_Exception(E::ts('Zoom rejected deletion of the recurring meeting series.'));
    }
    CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_series SET sync_enabled = 0, status = 'detached', modified_date = NOW() WHERE id = %1", [1 => [$series['id'], 'Integer']]);
    self::clearOperationalEventFields((int) $series['id']);
  }

  /**
   * Propagate parent membership to current/future Civi-owned occurrences.
   */
  public static function propagateParentParticipants($seriesId) {
    if (self::$participantPropagation) {
      return;
    }
    $series = self::fetchOne('SELECT * FROM civicrm_zoomzoom_series WHERE id = %1', [1 => [$seriesId, 'Integer']]);
    if (!$series || $series['origin'] !== self::ORIGIN_CIVI || empty($series['parent_event_id'])) {
      return;
    }
    self::$participantPropagation = TRUE;
    try {
      $parents = \Civi\Api4\Participant::get(FALSE)
        ->addSelect('id', 'contact_id', 'role_id', 'status_id', 'register_date', 'source')
        ->addWhere('event_id', '=', (int) $series['parent_event_id'])
        ->execute();
      $parentContacts = array_map('intval', array_column($parents->getArrayCopy(), 'contact_id'));
      $occurrences = CRM_Core_DAO::executeQuery('SELECT event_id FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL AND scheduled_start >= UTC_TIMESTAMP()', [1 => [$seriesId, 'Integer']]);
      while ($occurrences->fetch()) {
        $eventId = (int) $occurrences->event_id;
        if ($eventId === (int) $series['parent_event_id']) {
          continue;
        }
        foreach ($parents as $parentParticipant) {
          $existing = \Civi\Api4\Participant::get(FALSE)
            ->addSelect('id')
            ->addWhere('event_id', '=', $eventId)
            ->addWhere('contact_id', '=', $parentParticipant['contact_id'])
            ->setLimit(1)->execute();
          if ($existing->count()) {
            $childParticipantId = (int) $existing->first()['id'];
            $childParticipant = \Civi\Api4\Participant::get(FALSE)
              ->addSelect('status_id')->addWhere('id', '=', $childParticipantId)->execute()->first();
            $update = \Civi\Api4\Participant::update(FALSE)
              ->addWhere('id', '=', $childParticipantId)
              ->addValue('role_id', $parentParticipant['role_id']);
            if ((int) $childParticipant['status_id'] !== (int) Civi::settings()->get('zoom_import_status_participant')) {
              $update->addValue('status_id', $parentParticipant['status_id']);
            }
            $update->execute();
          }
          else {
            $created = \Civi\Api4\Participant::create(FALSE)
              ->addValue('event_id', $eventId)
              ->addValue('contact_id', $parentParticipant['contact_id'])
              ->addValue('role_id', $parentParticipant['role_id'])
              ->addValue('status_id', $parentParticipant['status_id'])
              ->addValue('register_date', $parentParticipant['register_date'] ?? date('Y-m-d H:i:s'))
              ->addValue('source', $parentParticipant['source'] ?? E::ts('Recurring Zoom series'))->execute();
            $childParticipantId = (int) $created->first()['id'];
          }
          // Reuse the one series-wide Zoom registration on each local child;
          // no additional Zoom registrant POST is made.
          CRM_Core_DAO::executeQuery(
            "REPLACE INTO civicrm_value_zoom_registrant (zoom_id, registrant_id, join_url, entity_id) SELECT zoom_id, registrant_id, join_url, %1 FROM civicrm_value_zoom_registrant WHERE entity_id = %2",
            [1 => [$childParticipantId, 'Integer'], 2 => [$parentParticipant['id'], 'Integer']]
          );
        }

        // Removing membership on the parent removes only future participants
        // which have not acquired the configured attended status.
        $attendedStatus = (int) Civi::settings()->get('zoom_import_status_participant');
        $children = \Civi\Api4\Participant::get(FALSE)
          ->addSelect('id', 'contact_id', 'status_id')
          ->addWhere('event_id', '=', $eventId)->execute();
        foreach ($children as $child) {
          if (!in_array((int) $child['contact_id'], $parentContacts, TRUE) && (int) $child['status_id'] !== $attendedStatus) {
            \Civi\Api4\Participant::delete(FALSE)->addWhere('id', '=', $child['id'])->execute();
          }
        }
      }
    }
    finally {
      self::$participantPropagation = FALSE;
    }
  }

  public static function participantChanged($eventId) {
    $series = self::getByParentEventId($eventId);
    if ($series && $series['sync_enabled']) {
      CRM_Core_DAO::executeQuery("UPDATE civicrm_zoomzoom_series SET status = 'dirty', modified_date = NOW() WHERE id = %1", [1 => [$series['id'], 'Integer']]);
    }
  }

  /**
   * Reconcile one Zoom registrant per parent member, never per occurrence.
   */
  private static function syncCiviOwnedRegistrations(array $series) {
    $participants = \Civi\Api4\Participant::get(FALSE)
      ->addSelect('id', 'contact_id', 'zoom_registrant.registrant_id')
      ->addWhere('event_id', '=', (int) $series['parent_event_id'])->execute();
    $memberEmails = [];
    foreach ($participants as $participant) {
      $contact = civicrm_api3('Contact', 'getsingle', ['id' => $participant['contact_id']]);
      $email = strtolower(trim($contact['email'] ?? ''));
      if (!$email) {
        continue;
      }
      $memberEmails[$email] = TRUE;
      if (empty($participant['zoom_registrant.registrant_id'])) {
        $params = [
          'email' => $email,
          'first_name' => $contact['first_name'] ?? '',
          'last_name' => $contact['last_name'] ?? '',
          'custom_questions' => [
            ['title' => 'participant_id', 'value' => $participant['id']],
            ['title' => 'contact_id', 'value' => $participant['contact_id']],
          ],
        ];
        $code = CRM_Zoomzoom_Zoom::createZoomRegistration($series['civicrm_zoom_id'], $participant['id'], $params);
        if (!in_array((int) $code, [200, 201], TRUE)) {
          throw new CRM_Core_Exception(E::ts('Zoom rejected a series registration.'));
        }
      }
    }
    foreach (CRM_Zoomzoom_Zoom::getRegistrants('meetings', substr($series['civicrm_zoom_id'], 1)) as $registrant) {
      $email = strtolower(trim($registrant['email'] ?? ''));
      if ($email && empty($memberEmails[$email]) && !empty($registrant['id'])) {
        $code = CRM_Zoomzoom_Zoom::deleteZoomRegistration($series['civicrm_zoom_id'], $registrant['id']);
        if (!in_array((int) $code, [200, 204], TRUE)) {
          throw new CRM_Core_Exception(E::ts('Zoom rejected removal of a series registration.'));
        }
      }
    }
  }

  private static function syncPastAttendance(array $series) {
    $instances = CRM_Zoomzoom_Zoom::getPastMeetingInstances(substr($series['civicrm_zoom_id'], 1));
    if (!$instances) {
      return;
    }
    $byMinute = [];
    foreach ($instances as $instance) {
      if (!empty($instance['start_time']) && !empty($instance['uuid'])) {
        $byMinute[self::utcMinute($instance['start_time'], 'UTC')][] = $instance;
      }
    }
    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_zoomzoom_occurrence WHERE series_id = %1 AND event_id IS NOT NULL AND scheduled_start < UTC_TIMESTAMP() AND meeting_uuid IS NULL', [1 => [$series['id'], 'Integer']]);
    while ($dao->fetch()) {
      $key = self::utcMinute($dao->scheduled_start, 'UTC');
      if (count($byMinute[$key] ?? []) !== 1) {
        continue;
      }
      $uuid = $byMinute[$key][0]['uuid'];
      CRM_Core_DAO::executeQuery('UPDATE civicrm_zoomzoom_occurrence SET meeting_uuid = %1, modified_date = NOW() WHERE id = %2', [1 => [$uuid, 'String'], 2 => [$dao->id, 'Integer']]);
      $participants = CRM_Zoomzoom_Zoom::getPastMeetingParticipantsByUuid($uuid);
      $batch = [];
      foreach ($participants as $participant) {
        $parts = preg_split('/\s+/', trim($participant['name'] ?? ''), 2);
        $batch[] = [
          'registration_date' => time(),
          'first_name' => $parts[0] ?? '',
          'last_name' => $parts[1] ?? '',
          'email' => $participant['user_email'] ?? '',
          'event' => ['id' => (int) $dao->event_id, 'start_date' => $dao->scheduled_start],
          'status_id' => Civi::settings()->get('zoom_import_status_participant'),
        ];
      }
      CRM_Zoomzoom_Zoom::bulkUpdateCiviCRMParticipants($batch, (int) $dao->event_id);
    }
  }

  private static function finishSync($seriesId, $hash) {
    $series = self::fetchOne('SELECT * FROM civicrm_zoomzoom_series WHERE id = %1', [1 => [$seriesId, 'Integer']]);
    if ($series && $series['origin'] === self::ORIGIN_CIVI && !empty($series['civicrm_zoom_id'])) {
      self::cancelPendingOccurrences($series);
    }
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_zoomzoom_series SET status = 'active', recurrence_hash = %1, last_error = NULL, last_synced_at = NOW(), modified_date = NOW() WHERE id = %2",
      [1 => [$hash, 'String'], 2 => [$seriesId, 'Integer']]
    );
  }

  private static function recordError($seriesId, Throwable $e) {
    $message = trim(strip_tags($e->getMessage()));
    $message = preg_replace('/(?:https?:\/\/|eyJ)[^\s]+/i', '[redacted]', $message);
    $message = mb_substr($message ?: E::ts('Recurring series synchronization failed.'), 0, 1000);
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_zoomzoom_series SET status = 'error', last_error = %1, modified_date = NOW() WHERE id = %2",
      [1 => [$message, 'String'], 2 => [$seriesId, 'Integer']]
    );
    CRM_Core_Error::debug_log_message('ZoomZoom recurring series ' . $seriesId . ' failed: ' . $message);
  }

  private static function acquireLock($seriesId) {
    $dao = CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_zoomzoom_series SET locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = %1 AND (locked_until IS NULL OR locked_until < NOW())',
      [1 => [$seriesId, 'Integer']]
    );
    return (int) $dao->affectedRows() === 1;
  }

  private static function releaseLock($seriesId) {
    CRM_Core_DAO::executeQuery('UPDATE civicrm_zoomzoom_series SET locked_until = NULL WHERE id = %1', [1 => [$seriesId, 'Integer']]);
  }

  private static function utcMinute($date, $timezone) {
    $tz = new DateTimeZone($timezone ?: CRM_Core_Config::singleton()->userSystem->getTimeZoneString());
    return (new DateTimeImmutable($date, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
  }

  private static function localDate($date, $timezone) {
    $target = new DateTimeZone($timezone ?: CRM_Core_Config::singleton()->userSystem->getTimeZoneString());
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone($target)->format('Y-m-d H:i:s');
  }

  private static function supportedTimezone($timezone) {
    try {
      return $timezone ? (new DateTimeZone($timezone))->getName() : NULL;
    }
    catch (Exception $e) {
      return CRM_Core_Config::singleton()->userSystem->getTimeZoneString();
    }
  }

  private static function fetchOne($sql, array $params = []) {
    $dao = CRM_Core_DAO::executeQuery($sql, $params);
    if (!$dao->fetch()) {
      return NULL;
    }
    return $dao->toArray();
  }

}
