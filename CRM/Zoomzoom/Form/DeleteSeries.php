<?php

use CRM_Zoomzoom_ExtensionUtil as E;

/**
 * Explicit confirmation for deleting a remote recurring meeting master.
 */
class CRM_Zoomzoom_Form_DeleteSeries extends CRM_Core_Form {

  private $eventId;

  public function preProcess() {
    parent::preProcess();
    $this->eventId = (int) CRM_Utils_Request::retrieve('event_id', 'Positive', $this, TRUE);
    $status = CRM_Zoomzoom_Series::getStatusForEvent($this->eventId);
    if (!$status || empty($status['zoom_id'])) {
      CRM_Core_Error::statusBounce(E::ts('No managed Zoom series is associated with this Event.'));
    }
    CRM_Utils_System::setTitle(E::ts('Delete Zoom Meeting Series'));
    $this->assign('zoomzoom_delete_status', $status);
  }

  public function buildQuickForm() {
    $this->add('checkbox', 'confirm_delete', E::ts('I understand that this deletes the entire recurring meeting and all future occurrences from Zoom.'), NULL, TRUE);
    $this->addButtons([
      ['type' => 'submit', 'name' => E::ts('Queue Zoom Series Deletion'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
    parent::buildQuickForm();
  }

  public function postProcess() {
    CRM_Zoomzoom_Series::queueSeriesDeletion($this->eventId);
    CRM_Core_Session::setStatus(
      E::ts('The Zoom series deletion is queued for the recurring-series scheduled job.'),
      E::ts('Zoom series deletion queued'),
      'success'
    );
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/event/manage/repeat', 'reset=1&id=' . $this->eventId));
  }

}
