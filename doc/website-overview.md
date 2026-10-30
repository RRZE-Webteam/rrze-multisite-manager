# Website overview

## Purpose

The Website overview lists network sites with their most recently known metrics and technical status information.

## Contents and filters

The page provides status views for active, archived, blocked, and marked-for-deletion sites, as well as technical states such as provisioning, missing DNS, or unavailability. Search filters by title or URL.

The table shows, among other things, site, registration, last activity, administrator, role and content counts, storage use, and quick actions. The exact columns can vary by view and permission.

## Actions

Links from a row lead to Website details, WordPress network administration, and appropriate analysis pages. Status changes and network-wide management actions are limited to super admins.

## Data state

The overview uses dashboard metrics. It is therefore an aggregated snapshot, not a live query of every individual site. Its stored counters, content totals and storage values come from the current rows in `rrze_msm_analysis_dashboard_metrics`; names, URLs, quotas and technical-status enrichment remain runtime presentation data. The dashboard or Monitoring shows the refresh timestamp and progress.

## Implementation

`Dashboard::renderSiteOverviewPage()` renders `templates/site-overview-page.php` using `site_overview` from the dashboard record. `MonitoringService` persists technical-status enrichment as site meta; [Runtime state and scheduler](runtime-state.md#availability-monitoring) lists these keys completely. Table interactions for sorting and page size stay in the browser and do not trigger server-side recalculation of the aggregate.
