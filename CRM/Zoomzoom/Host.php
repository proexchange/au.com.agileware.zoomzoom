<?php

/**
 * Centralized authorization and access-window checks for delegated Zoom hosts.
 */
class CRM_Zoomzoom_Host {

  const ROLE_NAME = 'Zoom Host';
  const MANAGED_ROLE_NAME = 'ZoomZoom_ParticipantRole_ZoomHost';
  const EXTENSION_KEY = 'au.com.agileware.zoomzoom';

  /**
   * Return the event in the shape needed by host access, or NULL when missing.
   *
   * @param int $eventId
   * @return array|null
   */
  public static function getEvent($eventId) {
    if (!$eventId) {
      return NULL;
    }
    try {
      $event = civicrm_api3('Event', 'getsingle', [
        'id' => (int) $eventId,
        'return' => ['id', 'is_active', 'event_start_date', 'event_end_date', 'event_tz'],
      ]);
      $event['zoom_id'] = CRM_Zoomzoom_Zoom::getEventZoomMeetingId($event['id']);
      return $event;
    }
    catch (CRM_Core_Exception $e) {
      return NULL;
    }
  }

  /**
   * Check whether a contact is a participant with the extension's Zoom Host role.
   *
   * @param int $eventId
   * @param int $contactId
   * @return bool
   */
  public static function isZoomHost($eventId, $contactId) {
    if (!$eventId || !$contactId) {
      return FALSE;
    }
    $roleId = self::getZoomHostRoleId();
    if (!$roleId) {
      return FALSE;
    }

    try {
      $participants = civicrm_api3('Participant', 'get', [
        'event_id' => (int) $eventId,
        'contact_id' => (int) $contactId,
        'return' => ['id', 'role_id'],
        'options' => ['limit' => 0],
      ]);
      foreach ($participants['values'] as $participant) {
        $roles = $participant['role_id'] ?? [];
        if (!is_array($roles)) {
          $roles = explode(CRM_Core_DAO::VALUE_SEPARATOR, trim((string) $roles, CRM_Core_DAO::VALUE_SEPARATOR));
        }
        if (in_array((string) $roleId, array_map('strval', $roles), TRUE)) {
          return TRUE;
        }
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message('Unable to check Zoom Host participant role.');
    }
    return FALSE;
  }

  /**
   * Return the inclusive host access window, using the Event timezone when set.
   *
   * @param int|array $event Event ID or loaded event.
   * @return array|null Keys: start, end, timezone.
   */
  public static function getAccessWindow($event) {
    $event = is_array($event) ? $event : self::getEvent($event);
    if (empty($event['event_start_date'])) {
      return NULL;
    }

    $timezone = !empty($event['event_tz']) ? $event['event_tz'] : CRM_Core_Config::singleton()->userSystem->getTimeZoneString();
    try {
      $tz = new DateTimeZone($timezone ?: 'UTC');
      $start = new DateTimeImmutable($event['event_start_date'], $tz);
      $end = !empty($event['event_end_date'])
        ? new DateTimeImmutable($event['event_end_date'], $tz)
        : $start->modify('+' . self::durationSetting() . ' minutes');
      return [
        'start' => $start->modify('-' . self::leadTimeSetting() . ' minutes'),
        'end' => $end,
        'timezone' => $tz,
      ];
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Unable to calculate Zoom host access window.');
      return NULL;
    }
  }

  /**
   * Test the inclusive access window. $now permits deterministic tests.
   *
   * @param int|array $event
   * @param DateTimeInterface|null $now
   * @return bool
   */
  public static function isWithinAccessWindow($event, ?DateTimeInterface $now = NULL) {
    $window = self::getAccessWindow($event);
    if (!$window) {
      return FALSE;
    }
    $now = $now ?: new DateTimeImmutable('now', $window['timezone']);
    return $now >= $window['start'] && $now <= $window['end'];
  }

  /**
   * Check all event, timing, role and permission requirements.
   *
   * @param int $eventId
   * @param int|null $contactId
   * @return bool
   */
  public static function canStart($eventId, $contactId = NULL) {
    return self::getAccessStatus($eventId, $contactId) === 'allowed';
  }

  /**
   * Return a non-sensitive reason code suitable for the controller/UI.
   *
   * @param int $eventId
   * @param int|null $contactId
   * @return string
   */
  public static function getAccessStatus($eventId, $contactId = NULL) {
    $event = self::getEvent($eventId);
    if (!$event) {
      return 'event_not_found';
    }
    if (empty($event['is_active'])) {
      return 'inactive';
    }
    if (empty($event['zoom_id'])) {
      return 'no_zoom';
    }
    $series = CRM_Zoomzoom_Series::getByEventId($eventId);
    if ($series && $series['status'] !== CRM_Zoomzoom_Series::STATUS_DETACHED) {
      $occurrence = CRM_Zoomzoom_Series::getOccurrenceByEventId($eventId);
      if (!$occurrence || $occurrence['availability_status'] !== 'available') {
        return 'occurrence_unavailable';
      }
    }

    $window = self::getAccessWindow($event);
    if (!$window) {
      return 'invalid_window';
    }
    $now = new DateTimeImmutable('now', $window['timezone']);
    if ($now < $window['start']) {
      return 'too_early';
    }
    if ($now > $window['end']) {
      return 'expired';
    }

    $contactId = $contactId ?: CRM_Core_Session::getLoggedInContactID();
    if (CRM_Core_Permission::check('edit all events')) {
      return 'allowed';
    }
    if (!CRM_Core_Permission::check('access CiviEvent')) {
      return 'unauthorized';
    }
    return self::isZoomHost($event['id'], $contactId) ? 'allowed' : 'unauthorized';
  }

  /**
   * Create the stable host-start endpoint used in UI and tokens.
   *
   * @param int $eventId
   * @return string
   */
  public static function getStartLink($eventId) {
    return CRM_Utils_System::url('civicrm/zoomzoom/start', 'event_id=' . (int) $eventId, TRUE, NULL, FALSE, TRUE);
  }

  /**
   * @return int|null
   */
  protected static function getZoomHostRoleId() {
    try {
      // The managed entity is the canonical role. Looking it up first keeps
      // host authorization deterministic on sites with a legacy duplicate.
      $managedRoleId = CRM_Core_DAO::singleValueQuery(
        'SELECT entity_id FROM civicrm_managed WHERE module = %1 AND name = %2 AND entity_type = %3 LIMIT 1',
        [
          1 => [self::EXTENSION_KEY, 'String'],
          2 => [self::MANAGED_ROLE_NAME, 'String'],
          3 => ['OptionValue', 'String'],
        ]
      );
      if ($managedRoleId) {
        $roleValue = civicrm_api3('OptionValue', 'getvalue', [
          'id' => (int) $managedRoleId,
          'return' => 'value',
        ]);
        if ($roleValue !== NULL) {
          return (int) $roleValue;
        }
      }
      return civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'participant_role',
        'name' => self::ROLE_NAME,
        'return' => 'value',
      ]);
    }
    catch (CRM_Core_Exception $e) {
      return NULL;
    }
  }

  /**
   * Report, but never automatically remove, legacy duplicate role records.
   */
  public static function reportDuplicateZoomHostRoles(): void {
    try {
      $roles = civicrm_api3('OptionValue', 'get', [
        'option_group_id' => 'participant_role',
        'name' => self::ROLE_NAME,
        'return' => ['id', 'value'],
        'options' => ['limit' => 0],
      ]);
      if ((int) ($roles['count'] ?? 0) > 1) {
        $details = [];
        foreach ($roles['values'] as $role) {
          $details[] = sprintf('ID %d (value %s)', (int) $role['id'], (string) $role['value']);
        }
        Civi::log()->warning('Multiple Zoom Host participant roles were found: {roles}. Keep the managed role and merge or remove duplicates after reviewing participant usage.', [
          'roles' => implode(', ', $details),
        ]);
      }
    }
    catch (CRM_Core_Exception $e) {
      // A diagnostic must never interrupt extension installation or upgrade.
    }
  }

  protected static function leadTimeSetting() {
    $value = Civi::settings()->get('zoom_host_start_lead_time');
    return max(0, is_numeric($value) ? (int) $value : 30);
  }

  protected static function durationSetting() {
    $value = Civi::settings()->get('zoom_host_access_default_duration');
    return max(1, is_numeric($value) ? (int) $value : 60);
  }
}
