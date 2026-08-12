<div class="crm-accordion-wrapper crm-zoomzoom-repeat-series collapsed">
  <div class="crm-accordion-header">
    {if $zoomzoom_series_context.role eq 'zoom_occurrence'}
      {ts domain='au.com.agileware.zoomzoom'}Zoom-owned recurring Meeting{/ts}
    {elseif $zoomzoom_series_context.role eq 'civi_occurrence'}
      {ts domain='au.com.agileware.zoomzoom'}CiviCRM recurring Meeting occurrence{/ts}
    {else}
      {ts domain='au.com.agileware.zoomzoom'}CiviCRM-owned recurring Meeting series{/ts}
    {/if}
  </div>
  <div class="crm-accordion-body">
    {if $zoomzoom_series_context.role eq 'zoom_occurrence'}
      <div class="messages warning no-popup">
        {ts domain='au.com.agileware.zoomzoom'}Zoom owns this recurring Meeting schedule. This Event is one imported occurrence; edit recurrence, timing, and cancellations in Zoom. Saving this CiviCRM Repeat form is blocked.{/ts}
      </div>
      <div class="crm-section">
        <div class="label">{ts domain='au.com.agileware.zoomzoom'}Zoom Meeting ID{/ts}</div>
        <div class="content">{$zoomzoom_series_status.zoom_id|escape}</div>
        <div class="clear"></div>
      </div>
      {if $zoomzoom_series_context.occurrence.scheduled_start}
        <div class="crm-section">
          <div class="label">{ts domain='au.com.agileware.zoomzoom'}Occurrence start (UTC){/ts}</div>
          <div class="content">{$zoomzoom_series_context.occurrence.scheduled_start|escape}</div>
          <div class="clear"></div>
        </div>
      {/if}
    {elseif $zoomzoom_series_context.role eq 'civi_occurrence'}
      <div class="messages warning no-popup">
        {ts domain='au.com.agileware.zoomzoom'}This Event is one occurrence in a CiviCRM-owned recurring Meeting. Edit the repeating schedule on the parent Event; saving this Repeat form is blocked.{/ts}
        {if $zoomzoom_series_parent_url}<a href="{$zoomzoom_series_parent_url|escape}">{ts domain='au.com.agileware.zoomzoom'}Open the parent Event{/ts}</a>{/if}
      </div>
    {else}
      <div class="crm-section">
        <div class="label">{$form.zoomzoom_sync_series.label}</div>
        <div class="content">
          {$form.zoomzoom_sync_series.html}
          {$form.zoomzoom_sync_series_present.html}
          <div class="description">
            {ts domain='au.com.agileware.zoomzoom'}Creates one type-8 Zoom Meeting through the scheduled synchronization job. CiviCRM owns the schedule; no Zoom API call is made while saving this form.{/ts}
          </div>
        </div>
        <div class="clear"></div>
      </div>
    {/if}
    {if $zoomzoom_series_status}
      <div class="crm-section">
        <div class="label">{ts domain='au.com.agileware.zoomzoom'}Sync status{/ts}</div>
        <div class="content">
          {$zoomzoom_series_status.status|escape}
          {if $zoomzoom_series_status.last_synced_at} &mdash; {$zoomzoom_series_status.last_synced_at|escape}{/if}
          {if $zoomzoom_series_status.error}<div class="messages status no-popup">{$zoomzoom_series_status.error|escape}</div>{/if}
          {if $zoomzoom_delete_series_url}
            <div><a class="button" href="{$zoomzoom_delete_series_url|escape}">{ts domain='au.com.agileware.zoomzoom'}Delete Zoom Series&hellip;{/ts}</a></div>
          {/if}
        </div>
        <div class="clear"></div>
      </div>
    {/if}
  </div>
</div>
