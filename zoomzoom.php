<?php

require_once 'zoomzoom.civix.php';
// phpcs:disable
use Civi\Core\Container;
use CRM_Zoomzoom_ExtensionUtil as E;
use Symfony\Component\Config\Resource\FileResource;

// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function zoomzoom_civicrm_config(&$config) {
  _zoomzoom_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function zoomzoom_civicrm_install() {
  _zoomzoom_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function zoomzoom_civicrm_enable() {
  _zoomzoom_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function zoomzoom_civicrm_managed(&$entities) {
  $entities[] = [
    'module' => 'au.com.agileware.zoomzoom',
    'name' => 'ZoomZoom_ParticipantRole_ZoomHost',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 3,
      'option_group_id' => 'participant_role',
      'label' => E::ts('Zoom Host'),
      'name' => 'Zoom Host',
      'is_active' => 1,
    ],
  ];

  $entities[] = [
    'module' => 'au.com.agileware.zoomzoom',
    'name' => 'ZoomZoom_SyncSeries',
    'entity' => 'Job',
    'update' => 'clean',
    'params' => [
      'version' => 3,
      'run_frequency' => 'Always',
      'name' => 'Synchronize recurring Zoom Meeting series',
      'description' => 'Reconcile occurrence-aware recurring Zoom Meetings owned by CiviCRM or Zoom.',
      'api_entity' => 'Zoomzoom',
      'api_action' => 'syncseries',
      'parameters' => '',
      'is_active' => '1',
    ],
  ];

  $entities[] = [
    'module' => 'au.com.agileware.zoomzoom',
    'name' => 'ZoomZoom_ImportZooms',
    'entity' => 'Job',
    'update' => 'clean',
    'params' => [
      'version' => 3,
      'run_frequency' => 'Daily',
      'name' => 'Import Zoom Webinars and Meetings',
      'description' => 'Import Zoom Webinars and Zoom Meetings',
      'api_entity' => 'Zoomzoom',
      'api_action' => 'importzooms',
      'parameters' => "day_offset=-90",
      'is_active' => '0',
    ],
  ];

  $entities[] = [
    'module' => 'au.com.agileware.zoomzoom',
    'name' => 'ZoomZoom_ImportAttendees',
    'entity' => 'Job',
    'update' => 'clean',
    'params' => [
      'version' => 3,
      'run_frequency' => 'Daily',
      'name' => 'Import Zoom Registrations, Attendees, Absentees',
      'description' => 'Import Zoom registrations, attendees and absentees for those CiviCRM Events with a Zoom ID',
      'api_entity' => 'Zoomzoom',
      'api_action' => 'importattendees',
      'parameters' => "day_offset=-90",
      'is_active' => '0',
    ],
  ];

}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */

function zoomzoom_civicrm_navigationMenu(&$menu) {
  _zoomzoom_civix_insert_navigation_menu($menu, 'Administer', [
    'label' => E::ts('Zoom Settings'),
    'name' => 'zoomzoom_settings',
    'url' => 'civicrm/admin/setting/zoomzoom',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _zoomzoom_civix_navigationMenu($menu);
}

/**
 * Implements hook_civicrm_check().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_check
 *
 * @param $messages array
 * @param $statusNames array
 * @param $includeDisabled bool
 */
function zoomzoom_civicrm_check(&$messages, $statusNames, $includeDisabled) {
  $preferenceIsEnabled = static function($name) use ($includeDisabled) {
    return $includeDisabled || !Civi\Api4\StatusPreference::get(FALSE)
      ->addWhere('is_active', '=', FALSE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('name', '=', $name)
      ->execute()->count();
  };
  $wasRequested = static function($name) use ($statusNames) {
    return !$statusNames || in_array($name, $statusNames, TRUE);
  };

  $msgID = 'zoomzoomOAuthTokenSetup';
  $accountID = Civi::settings()->get('zoom_account_id') ?? FALSE;
  $clientKey = Civi::settings()->get('zoom_client_key') ?? FALSE;
  $clientSecret = Civi::settings()->get('zoom_client_secret') ?? FALSE;

  if ($wasRequested($msgID) && $preferenceIsEnabled($msgID) && !($accountID && $clientKey && $clientSecret)) {
    $message = new CRM_Utils_Check_Message(
      $msgID,
      E::ts(
        'Ensure that the Account ID, Client Key, and Client Secret are set in the <a href="%1">Zoom Settings</a>',
        ['1' => CRM_Utils_System::url('civicrm/admin/setting/zoomzoom')]
      ),
      E::ts('Zoom OAuth Credentials are not configured'),
      Psr\Log\LogLevel::ERROR,
      'fa-error'
    );
    $message->addHelp(E::ts(
      'For more information on getting started with the Zoom extension, see instructions in the <a href="%1">readme file</a>.',
      ['1' => 'https://github.com/agileware/au.com.agileware.zoomzoom/blob/master/README.md#getting-started']
    ));
    $messages[] = $message;
  }

  $legacyMsgID = 'zoomzoomLegacyRecurringImports';
  if ($wasRequested($legacyMsgID) && $preferenceIsEnabled($legacyMsgID)) {
    try {
      $legacy = \Civi\Api4\Event::get(FALSE)
        ->addSelect('id', 'start_date', 'zoom.zoom_id')
        ->addWhere('zoom.zoom_id', 'IS NOT EMPTY')
        ->addWhere('start_date', '>', date('Y-m-d H:i:s', strtotime('+5 years')))
        ->execute();
      $ids = [];
      foreach ($legacy as $event) {
        if (!CRM_Zoomzoom_Series::getByEventId($event['id'])) {
          $ids[] = (int) $event['id'];
        }
      }
      if ($ids) {
        $messages[] = new CRM_Utils_Check_Message(
          $legacyMsgID,
          E::ts('Possible legacy master-only recurring Zoom imports were found in Events: %1. They are intentionally not migrated; delete or correct them manually before re-importing.', [1 => implode(', ', $ids)]),
          E::ts('Legacy recurring Zoom imports require review'),
          Psr\Log\LogLevel::WARNING,
          'fa-calendar-times-o'
        );
      }
    }
    catch (Throwable $e) {
      // Diagnostics must never interfere with other system checks.
    }
  }
}

/**
 * Implements hook_civicrm_container()
 *
 * @return void
 */
function zoomzoom_civicrm_container($container) {
	$container->addResource(new FileResource(E::path('CRM/Zoomzoom/Tokens.php')));
	$dispatcher = $container->findDefinition('dispatcher');
	$dispatcher->addMethodCall('addListener', ['civi.token.eval', ['CRM_Zoomzoom_Tokens', 'evaluate']]);
	$dispatcher->addMethodCall('addListener', ['civi.token.list', ['CRM_Zoomzoom_Tokens', 'register']]);
}

/**
 * Implements hook_civicrm_buildForm()
 *
 * @param $page
 */
function zoomzoom_civicrm_buildForm($formName, &$form) {
  CRM_Core_Resources::singleton()->addStyleFile('au.com.agileware.zoomzoom', 'css/zoomzoom.css', -50, 'html-header');

  if ($formName === 'CRM_Event_Form_ManageEvent_Repeat') {
    $eventId = (int) (CRM_Utils_Request::retrieve('id', 'Positive', $form, FALSE, 0) ?: $form->getVar('_id'));
    $context = $eventId ? CRM_Zoomzoom_Series::getRepeatFormContext($eventId) : ['role' => 'unmanaged', 'is_locked' => FALSE, 'series' => NULL, 'occurrence' => NULL];
    $status = $context['series'] ? CRM_Zoomzoom_Series::getStatusForEvent($eventId) : NULL;
    if (in_array($context['role'], ['unmanaged', 'civi_parent'], TRUE)) {
      $form->add('advcheckbox', 'zoomzoom_sync_series', E::ts('Sync this repeating Event to Zoom'));
      $form->add('hidden', 'zoomzoom_sync_series_present', 1);
      $form->setDefaults(['zoomzoom_sync_series' => !empty($status['enabled'])]);
    }
    $form->assign('zoomzoom_series_context', $context);
    $form->assign('zoomzoom_series_status', $status);
    $form->assign('zoomzoom_series_event_id', $eventId);
    if (!empty($context['is_locked'])) {
      // The core Repeat form is still useful as context, but an occurrence is
      // never allowed to become the source of a second Civi repeat rule. Keep
      // its values visible while making every schedule control inert, and omit
      // the Save action. validateForm() below remains the authoritative guard.
      CRM_Core_Resources::singleton()->addScript(<<<'JS'
CRM.$(function($) {
  var $repeatForm = $('#crm-container form').filter(function() {
    return $(this).find('[name="repetition_frequency_interval"]').length;
  }).first();
  if (!$repeatForm.length) {
    return;
  }
  $repeatForm.find('input, select, textarea, button')
    .not('[type="hidden"], .crm-button-type-cancel')
    .prop('disabled', true)
    .attr('aria-disabled', 'true');
  $repeatForm.find('.crm-button-type-submit').remove();
});
JS
      , 100, 'html-header');
    }
    if ($context['role'] === 'civi_parent' && $status && !empty($status['zoom_id'])) {
      $form->assign('zoomzoom_delete_series_url', CRM_Utils_System::url('civicrm/event/zoomzoom/delete-series', 'reset=1&event_id=' . $eventId));
    }
    if ($context['role'] === 'civi_occurrence' && !empty($context['series']['parent_event_id'])) {
      $form->assign('zoomzoom_series_parent_url', CRM_Utils_System::url('civicrm/event/manage/settings', 'reset=1&action=update&id=' . (int) $context['series']['parent_event_id']));
    }
    CRM_Core_Region::instance('form-body')->add([
      'template' => E::path('templates/CRM/Zoomzoom/Form/RepeatSeries.tpl'),
    ]);
  }
}

/**
 * Implements hook_civicrm_post().
 */
function zoomzoom_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName === 'ActionSchedule' && !empty($_REQUEST['zoomzoom_sync_series_present'])) {
    $name = is_object($objectRef) ? ($objectRef->name ?? '') : ($objectRef['name'] ?? '');
    if (preg_match('/^repeat_civicrm_event_(\d+)$/', (string) $name, $matches)) {
      CRM_Zoomzoom_Series::setEnabled((int) $matches[1], !empty($_REQUEST['zoomzoom_sync_series']));
    }
  }

  if ($objectName === 'Event' && in_array($op, ['create', 'edit'], TRUE)) {
    CRM_Zoomzoom_Series::markDirtyForEvent((int) $objectId);
  }

  if ($objectName === 'Participant' && in_array($op, ['create', 'edit'], TRUE)) {
    $eventId = is_object($objectRef) ? ($objectRef->event_id ?? NULL) : ($objectRef['event_id'] ?? NULL);
    if ($eventId) {
      CRM_Zoomzoom_Series::participantChanged((int) $eventId);
    }
  }

  if ($objectName === 'Participant' && $op === 'delete' && !empty($GLOBALS['_zoomzoom_deleted_participant_event'])) {
    CRM_Zoomzoom_Series::participantChanged((int) $GLOBALS['_zoomzoom_deleted_participant_event']);
    unset($GLOBALS['_zoomzoom_deleted_participant_event']);
  }
}

/**
 * Implements hook_civicrm_pre().
 */
function zoomzoom_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName === 'Event' && $op === 'delete') {
    CRM_Zoomzoom_Series::handleEventDeletion((int) $id);
  }
  if ($objectName === 'Participant' && $op === 'delete') {
    try {
      $participant = \Civi\Api4\Participant::get(FALSE)
        ->addSelect('event_id')->addWhere('id', '=', (int) $id)->setLimit(1)->execute();
      if ($participant->count()) {
        $GLOBALS['_zoomzoom_deleted_participant_event'] = (int) $participant->first()['event_id'];
      }
    }
    catch (Throwable $e) {
      // Participant deletion must not be blocked by optional propagation.
    }
  }
}

/**
 * Implements hook_civicrm_validateForm().
 *
 * Keep the host-window settings within the values their calculations support.
 */
function zoomzoom_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName === 'CRM_Event_Form_ManageEvent_Repeat') {
    $eventId = (int) (CRM_Utils_Request::retrieve('id', 'Positive', $form, FALSE, 0) ?: $form->getVar('_id'));
    $context = $eventId ? CRM_Zoomzoom_Series::getRepeatFormContext($eventId) : NULL;
    if (!empty($context['is_locked'])) {
      $errors['repetition_frequency_interval'] = $context['role'] === 'zoom_occurrence'
        ? E::ts('This Event is one occurrence of a Zoom-owned recurring Meeting. Edit the schedule in Zoom; CiviCRM cannot save a local Repeat rule for it.')
        : E::ts('This Event is an occurrence in a CiviCRM-owned recurring Meeting. Edit the Repeat settings on the parent Event instead.');
    }
    return;
  }
  if ($formName !== 'CRM_Admin_Form_Setting') {
    return;
  }
  $validInteger = static function($value, $minimum) {
    return preg_match('/^\\d+$/', (string) $value) && (int) $value >= $minimum;
  };
  if (array_key_exists('zoom_host_start_lead_time', $fields) && !$validInteger($fields['zoom_host_start_lead_time'], 0)) {
    $errors['zoom_host_start_lead_time'] = E::ts('Host Start Lead Time must be a whole number of zero or greater.');
  }
  if (array_key_exists('zoom_host_access_default_duration', $fields) && !$validInteger($fields['zoom_host_access_default_duration'], 1)) {
    $errors['zoom_host_access_default_duration'] = E::ts('Default Host Access Duration must be a positive whole number.');
  }
}

/**
 * Implements hook_civicrm_pageRun().
 *
 * Adds an internal host-start action to the CiviCRM event information page.
 */
function zoomzoom_civicrm_pageRun(&$page) {
  if (get_class($page) !== 'CRM_Event_Page_EventInfo') {
    return;
  }

  $eventId = $page->getEventID();
  if (CRM_Zoomzoom_Host::getAccessStatus($eventId) !== 'allowed') {
    return;
  }
  $event = CRM_Zoomzoom_Host::getEvent($eventId);
  $isWebinar = !empty($event['zoom_id']) && strtolower(substr($event['zoom_id'], 0, 1)) === 'w';
  $page->assign('zoomzoom_host_start', [
    'url' => CRM_Zoomzoom_Host::getStartLink($eventId),
    'label' => $isWebinar ? E::ts('Start Zoom Webinar') : E::ts('Start Zoom Meeting'),
  ]);
  CRM_Core_Region::instance('page-footer')->add([
    'template' => E::path('templates/CRM/Zoomzoom/Page/HostStart.tpl'),
  ]);
}
