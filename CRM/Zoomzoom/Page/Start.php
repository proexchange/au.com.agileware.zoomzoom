<?php

use CRM_Zoomzoom_ExtensionUtil as E;

/**
 * CiviCRM-controlled handoff to Zoom's current host URL.
 */
class CRM_Zoomzoom_Page_Start extends CRM_Core_Page {

  public function run() {
    $eventId = CRM_Utils_Request::retrieve('event_id', 'Positive', $this, TRUE);
    $event = CRM_Zoomzoom_Host::getEvent($eventId);
    $status = CRM_Zoomzoom_Host::getAccessStatus($eventId);

    if ($status !== 'allowed') {
      $messages = [
        'event_not_found' => E::ts('Event not found.'),
        'inactive' => E::ts('This event is not active.'),
        'no_zoom' => E::ts('This event does not have a Zoom session.'),
        'occurrence_unavailable' => E::ts('This recurring Zoom occurrence is missing or has been cancelled.'),
        'too_early' => E::ts('This Zoom session may be started beginning at %1.', [1 => $this->formatWindowStart($event)]),
        'expired' => E::ts('The host access period for this event has ended.'),
      ];
      CRM_Core_Error::statusBounce($messages[$status] ?? E::ts('You are not authorized to start this Zoom session.'));
    }

    $startUrl = CRM_Zoomzoom_Zoom::getStartUrl($event['zoom_id']);
    if (!$startUrl) {
      CRM_Core_Error::statusBounce(E::ts('Unable to retrieve the Zoom host URL. Please contact an administrator.'));
    }

    CRM_Utils_System::redirect($startUrl);
  }

  /**
   * @param array|null $event
   * @return string
   */
  protected function formatWindowStart($event) {
    $window = $event ? CRM_Zoomzoom_Host::getAccessWindow($event) : NULL;
    return $window ? CRM_Utils_Date::customFormat($window['start']->format('Y-m-d H:i:s'), '%E %f') : E::ts('the configured host access time');
  }
}
