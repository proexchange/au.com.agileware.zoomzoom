<div class="messages warning no-popup">
  <p>{ts domain='au.com.agileware.zoomzoom'}This is not the normal detach operation. The next recurring-series job will permanently delete the shared Zoom Meeting master and all of its future occurrences.{/ts}</p>
  <p>{ts domain='au.com.agileware.zoomzoom'}Zoom ID:{/ts} {$zoomzoom_delete_status.zoom_id|escape}</p>
</div>
<div class="crm-section">
  <div class="label">{$form.confirm_delete.label}</div>
  <div class="content">{$form.confirm_delete.html}</div>
  <div class="clear"></div>
</div>
<div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
