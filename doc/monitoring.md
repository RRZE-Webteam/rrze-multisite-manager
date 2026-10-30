# Monitoring

## Purpose

The **Monitoring** page is the central operations interface for dashboard metrics, availability checks, storage analyses, shortcode/block analyses, and maintenance tools. It is visible only to super admins.

## Tabs

### Network-wide

Displays dashboard metrics and site availability monitoring. Both processes can be scheduled, started, reset, or removed. Status pills consistently mean:

* **Not scheduled** in grey: no next run, regardless of whether a result already exists.
* **Scheduled** in yellow/orange: no result yet, but a future run is scheduled.
* **OK** in green: a result exists and a future run is scheduled.

Availability checks use DNS and HTTP checks. HTTP timeouts and the request time budget are bounded. DNS calls are no longer started when fewer than five seconds remain in the batch budget; [Runtime state and scheduler](runtime-state.md#server-operation-dns-timeouts) describes the unavoidable server-side resolver configuration.

Dashboard metric batches keep only bounded partial results until a separate composition task publishes the completed cache record. Once the site loop is at 100 percent, the row changes to **Composition pending** and states that all individual sites are complete; the next-run column names the composition or its watchdog check. The composition task has an expiring lock and watchdog. A failed composition is shown as a stale process state and must be reset before a new run can start. Completed cache data displays complete progress even though the transient batch offset has already been cleared. The separate **Dashboard metric statistics** task copies current snapshot rows into the history at the configured daily, weekly, or monthly cadence; it never recalculates sites.

### Storage analyses

Displays scheduled individual tasks and collective batches for storage analyses. Sites without scheduling are shown as a separate table only when such sites exist. Bulk actions can create or remove scheduled analyses for selected sites. Storage scheduling follows the WordPress site status only; a DNS, HTTP, or operational-monitoring value does not exclude an otherwise active site because the analysis works against its local database and uploads directory.

### Shortcodes and blocks

This corresponds to the Storage analyses tab for shortcode/block analysis: scheduled tasks, collective batches, status, start actions, and bulk actions are centrally managed.

While an analysis is being continued, a released request lock together with a queued one-time continuation is an expected handover state. Monitoring must not report this as an interruption. If no lock and no continuation remain, an individual task recreates the continuation from its saved checkpoint and records a warning. A collective-batch task instead ends as an error, deletes its checkpoint, and starts again from the beginning with the next regular collective batch. The same applies after two hours without progress, even if the shared batch is still processing other websites. This prevents an isolated lost batch continuation from leaving a website permanently marked as running. The row shows the failed phase, its last checkpoint progress and the diagnostic message; the error log records the corresponding site, phase and post counters.

### Database usage

Shows the allocated data and index footprint of the central analysis tables. The three tables formerly shown at the bottom of the Network-wide, Storage analyses, and Shortcodes and blocks tabs are grouped here. The shared analysis-runs and checkpoints table appears in both relevant functional groups because it contains the working state for both storage and shortcode/block analyses. InnoDB row counts are estimates.

The tab also lists the key/value footprint of MSM-owned network options, per-site options, and transient values and expiry rows. It scans only the plugin's `rrze_msm_*`, `rrze_multisite_manager_*`, and main settings-key prefixes and measures values in SQL without deserializing reports. This reveals persisted storage-analysis reports and bounded work state while excluding WordPress core and third-party data. The aggregation is cached for five minutes so opening the tab does not repeatedly issue one size query for every website. These byte counts exclude InnoDB allocation and index overhead, which remain visible in the central-table sections.

### Tools

Contains maintenance actions, such as removing obsolete local central Cron entries and cleaning up data. Extensive cleanups run as admin-AJAX batches. A cancellation leaves no permanent lock; stale run state is handled after expiry.

## Event links

For collective batches, links to WP Crontrol always point to the main network site because central collective events run there. Individual tasks link to the Cron context of their site.

## Technical execution

`Settings::renderMonitoringPage()` is exclusively a control and status interface. The actual runners are `MetricsImplementationService`, `MonitoringService`, `StorageAnalysisSchedulerService`, and `ShortcodeBlockAnalysisSchedulerService`.

All recurring network processes are registered in the Cron context of the main site. Individual storage and shortcode/block analysis tasks, however, run in the Cron table of their respective site. Collective batches verify their execution context and move or remove central events incorrectly registered on a subsite. In production, an external Cron service executes due WordPress events; the configured service cadence determines how closely a due event follows its scheduled timestamp.

[Runtime state and scheduler](runtime-state.md) completely lists the Cron hooks, schedule keys, continuation arguments, network options, locks, and TTLs. The page itself starts no hidden full scan while rendering.

## AJAX interfaces

The dialogs for schedule initialisation, task removal, and old central Cron cleanup call `admin-ajax.php`. Their registered hooks start with `wp_ajax_rrze_msm_`; they are protected by nonce and super-admin checks. Queue state is stored as network options and uses a 15-minute lease for browser-driven runs, so an abandoned dialog does not leave a permanent lock.
