# Dashboard

## Purpose

The Dashboard is the central, configurable overview of the Multisite Manager. It combines the most recently calculated network metrics in widgets and avoids a full analysis on every page load.

## Contents

Depending on the selected view, widgets are available for site status, operational status, storage consumption, current issues, new monitoring notices, theme and plugin usage, and inactive or recently changed sites.

Editor usage follows RRZE Settings. If the Block Editor is configured as the network-wide default editor, a notice links to RRZE Settings instead of showing a pie chart because all sites use the same editor.

## Usage

* Widgets can be moved within a view. Their order is stored per user and view in the browser.
* Super admins manage views and their visible widgets under **Settings > Views**.
* Table widgets sort and paginate only their currently visible entries in the browser.
* The page displays the time of the latest metrics run and the progress of a running refresh.

## Data refresh

Dashboard metrics are calculated centrally on the network's main site in the background. A run processes sites in batches. Each batch writes an independent, bounded intermediate result; a separate composition task merges all intermediate results and writes one complete record to the network cache only after every batch has completed. The intermediate results are then removed. The previous complete record remains visible while a refresh is running.

The composition task has its own expiring lock and watchdog. A new metrics run is held back while composition is pending. If composition repeatedly fails, the run is marked as failed and is visible in Monitoring, where it can be reset. This prevents a crashed final task from leaving a permanent lock or allowing overlapping runs.

When the calculation logic changes, the cache detects its version change and schedules a new batch calculation. This avoids an expensive synchronous run in an admin request.

## Implementation

The entry point is `Dashboard::renderDashboardPage()`. Data comes from `MetricsService::getDashboardData()`; the facade delegates to `MetricsImplementationService`. Widget implementations are in `includes/Widgets/`, and templates are in `templates/widgets/`.

[Runtime state and scheduler](runtime-state.md#central-dashboard-metrics) lists dashboard persistence, Cron hooks, batches, and locks. The individual widget order is additionally stored as user meta `rrze_msm_widget_orders`; network view definitions are stored in the network option `rrze_multisite_manager_dashboard_views`.
