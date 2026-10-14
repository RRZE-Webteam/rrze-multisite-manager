# Runtime state and scheduler

## Namespaces

The plugin's technical keys follow three conventions:

| Prefix | Use |
| --- | --- |
| `rrze_msm_` | Network options, site meta, site options, transients, locks, and Cron hooks for runtime logic |
| `rrze_msm_run_` / `rrze_msm_refresh_` | Executing or continuing Cron hooks |
| `rrze_multisite_manager_` | Dashboard cache and admin-post and network-admin actions |

Global plugin configuration is stored as the network option `rrze-multisite-manager`. Hook names and scheduling keys are centrally defined in `includes/Config.php`. Any change to these names always requires a migration or cleanup strategy for existing Cron entries.

## Central dashboard metrics

| Element | Name |
| --- | --- |
| Recurring hook | `rrze_msm_refresh_dashboard_metrics` |
| Composition hook | `rrze_msm_compose_dashboard_metrics` |
| Composition watchdog hook | `rrze_msm_watch_dashboard_metrics_composition` |
| Schedule key | `rrze_msm_dashboard_metrics_cycle` |
| Continuation argument | `['rrze_msm_dashboard_metrics_batch' => true]` |
| Enablement | Network option `rrze_msm_dashboard_metrics_scheduling_enabled` |
| Cache | Network option `rrze_multisite_manager_dashboard_metrics_v7_<network-id>` |
| Batch progress | `rrze_msm_dashboard_metrics_batch_offset`, `rrze_msm_dashboard_metrics_batch_total`, `rrze_msm_dashboard_metrics_batch_state` |
| Per-batch intermediate state | Network option `rrze_msm_dashboard_metrics_batch_partial_<run-hash>_<offset>` |
| Refresh lock | `rrze_msm_dashboard_metrics_refresh_lock_state`, TTL 900 seconds |
| Composition lock | `rrze_msm_dashboard_metrics_composition_lock`, TTL 900 seconds |
| Dirty marker | `rrze_msm_dashboard_metrics_dirty` |

Detail-cache maintenance runs hourly on the central network site through `rrze_msm_maintain_detail_cache`. It deletes at most 100 value keys per invocation from obsolete detail-cache versions, together with their matching timeout rows, and schedules one bounded continuation when further keys remain.

`MetricsImplementationService` creates the record, `DashboardMetricsBatchService` processes the site loop, and `DashboardMetricsRefreshService` manages cache, batch state, intermediate states, and locks. Each batch writes only its bounded intermediate state. Once all batches finish, the composition hook merges all intermediate states, publishes the complete cache record, and removes intermediate states.

The master batch state records the run ID, offsets, phase, composition attempt count, and any composition error; it does not contain the growing aggregate. The `composing` phase prevents another metrics run from starting. The composition watchdog runs after the lock TTL, reschedules a composition that no longer holds a valid lock, and marks the run failed after three attempts. A failed composition stays visible as stale state and requires a reset; reset removes all intermediate states, locks, and scheduled composition events.

The cache has its own data-version number. It is checked when read and schedules a new bounded run after a version change. Old transient cache versions with the prefix `rrze_multisite_manager_dashboard_metrics_v1_` through `_v6_` are cleaned up only for compatibility.

## Availability monitoring

| Element | Name |
| --- | --- |
| Hook | `rrze_msm_check_site_availability` |
| Default schedule key | `rrze_msm_every_six_hours` |
| Continuation argument | `['rrze_msm_monitoring_batch' => true]` |
| Enablement | `rrze_msm_monitoring_scheduling_enabled` |
| Run state | `rrze_msm_monitoring_run_state` |
| Batch progress | `rrze_msm_monitoring_batch_offset`, `rrze_msm_monitoring_batch_total` |
| Log | `rrze_msm_monitoring_run_log` |
| Lock | `rrze_msm_monitoring_lock_state`, fallback transient `rrze_msm_monitoring_lock`, TTL 900 seconds |

`MonitoringService` registers the hook on `init` only in the main site's Cron context. A continuation event is scheduled with a minimum five-second delay. HTTP checks use the WordPress HTTP API; static defaults are five sites per monitoring batch, a 45-second request budget, a four-second HTTP timeout, and a two-second fallback timeout.

PHP's native DNS functions (`dns_get_record()` and `checkdnsrr()`) have no timeout configurable per call. The plugin therefore no longer starts a DNS check when fewer than five seconds remain in the batch time budget. It stores the status as `unknown`; this does not count as a DNS failure and is checked again in the next regular run. This safeguard cannot prevent an already-started DNS call from blocking.

### Server operation: DNS timeouts

For large production networks, the DNS resolver of the PHP/Cron host must be bounded and available. A local cached resolver such as Unbound or systemd-resolved is recommended. Its upstream resolvers should use short operationally agreed time limits and a limited number of retries; on glibc-based hosts this can, for example, use `options timeout:1 attempts:1` in resolver configuration. The exact configuration depends on the operating system, container, and resolver and must be agreed with the infrastructure team because it affects all host processes.

If a guaranteed upper bound per DNS request is required, resolution must not occur in the WordPress/PHP process. It must run through a separately monitored worker or an operator-controlled DNS-over-HTTPS endpoint with a fixed HTTP timeout. Public DNS-over-HTTPS services are unsuitable for monitoring because they disclose all monitored domains to third parties.

The following site-meta keys are written per site: `rrze_msm_operational_status`, `rrze_msm_operational_status_source`, `rrze_msm_previous_operational_status`, `rrze_msm_operational_status_changed_at`, `rrze_msm_dns_status`, `rrze_msm_dns_status_detail`, `rrze_msm_http_status`, `rrze_msm_http_status_detail`, `rrze_msm_http_status_code`, `rrze_msm_last_availability_check`, `rrze_msm_last_dns_ok_at`, `rrze_msm_last_http_ok_at`, `rrze_msm_last_dns_error_at`, `rrze_msm_last_http_error_at`, `rrze_msm_dns_failure_count`, `rrze_msm_http_failure_count`, `rrze_msm_monitoring_note`, and `rrze_msm_monitoring_history`.

## Storage analysis

| Element | Name |
| --- | --- |
| Individual-analysis hook | `rrze_msm_run_site_storage_analysis` |
| Collective-batch hook | `rrze_msm_run_site_storage_analysis_batch` |
| Slice-continuation hook | `rrze_msm_continue_storage_analysis` |
| Central FIFO queue | Network option `rrze_msm_storage_analysis_continuation_queue`, site IDs mapped to run IDs |
| Frequency migration | `rrze_msm_reschedule_storage_analysis_frequency` |
| Obsolete removal hook | `rrze_msm_remove_storage_analysis_tasks` |
| Batch continuation argument | `['rrze_msm_storage_analysis_batch' => true]` |
| Site lock | `rrze_msm_storage_analysis_lock_<site-id>` |
| Collective-batch lock | `rrze_msm_storage_analysis_batch_lock` |
| Network-wide execution slots | `rrze_msm_storage_analysis_run_slots` |
| Lock for slot/queue updates | Non-autoloaded main-site option `rrze_msm_storage_analysis_run_slots_lock`; token and 60-second lease |

Assignment and progress data are network options, including `rrze_msm_storage_analysis_assignments`, `rrze_msm_storage_analysis_batch_site_ids`, `rrze_msm_storage_analysis_batch_last_site_id`, `rrze_msm_storage_analysis_schedule_initialization_state`, and `rrze_msm_storage_analysis_frequency_reschedule_state`. The persistent result is a non-autoloaded site option in `rrze_msm_site_storage_analysis_result`; summary metadata is in `rrze_msm_site_storage_analysis_result_meta`. Both storage and media-metadata results have a hard serialized 2-MiB cap; summary metadata has a 16-KiB cap. All variable lists and strings are compacted before serialization, failures are reported and legacy oversized values are replaced through size-gated SQL reads.

Active runtime keys are `rrze_msm_site_storage_analysis_base_state_resumable_v2_<site-id>`, `rrze_msm_site_storage_analysis_orphan_state_resumable_v2_<site-id>`, and `rrze_msm_site_storage_attachment_index_bucket_resumable_v2_<site-id>_<hex-bucket>`. Their format version is independent of the detail-cache version; invalidating a dashboard/detail cache does not lose progress. Base state holds paginated attachment/index/statistics cursors, directory cursors and compact accumulated statistics. Legacy runtime formats are no longer resumed and are removed by bounded resource maintenance; an old running scheduler status without a run ID starts a fresh analysis on its next scheduled execution. Persistent completed results remain available.

A scheduled slice processes at most two batches with a five-second soft budget, yielding before further work would exceed its 32-MiB incremental PHP memory budget or leave less than 16 MiB below 70% of PHP's configured memory limit. Each database-backed index read/write evicts its WordPress in-process copy; external caches advertising `flush_runtime` can clear in-process copies without deleting persistent data. Blocking calls may exceed the soft budget. Site, batch and execution-slot leases last 120 seconds; the slot/queue mutex uses the unique main-site `wp_options.option_name` index and a token to protect release.

The scheduler status stores `run_id`, `run_phase` and `active_duration_seconds`. The configured runtime limit counts active processing, not waiting between Cron requests; a seven-day wall-clock limit also bounds abandoned runs. Index segments use a seven-day TTL; base/orphan state and idle index reservations expire after 24 hours. Expired reservations delete their segments without loading payloads. Completion, handled errors and explicit aborts clear temporary state. The next event is reserved before processing, and queue items remain until completion, so an interrupted PHP process does not silently lose its continuation. Continuation queue entries are checked against the current run ID and assignment before executing; task removal also removes the continuation hook and queue. A brief mutex collision retries through the existing individual/targeted collective Cron path instead of discarding saved progress.

The `monitoring_storage_analysis_max_concurrent_runs` option limits concurrently executing storage slices across the network; its default is one and its hard maximum is two, including previously saved larger values. Independent atomic main-site worker options cover browser and metadata requests too. The central continuation queue processes one website per invocation. Runtime helpers retain their old method signatures for compatibility, but the cache-version argument no longer changes active state keys. Legacy `rrze_msm_site_storage_analysis_v3_<cache-version>_<site-id>` result caches and `rrze_msm_site_storage_attachment_index_v2_<cache-version>_<site-id>` monolithic indexes are only relevant to compatibility cleanup.

Resource reservations use non-autoloaded main-site options `rrze_msm_storage_resource_slot_0` and `_1`, guarded by `rrze_msm_storage_resource_mutex`. Limits are 32 MiB per temporary index, 128 KiB per segment and 1 MiB per state. The active worker options are `rrze_msm_storage_worker_0` and `_1`. Tokens, host and PID prevent expiry alone from overbooking a still-blocked worker. Only verified stopped local processes or a reused non-threaded current PID without a live token can be reclaimed. Unverifiable remote/stale reservations fail closed and log once after 24 hours; operator verification is required before clearing them.

Hook `rrze_msm_storage_resource_maintenance` is registered on load but first scheduled hourly after explicit analysis activity. It deletes only owned runtime values/timeout keys in the current network, selecting at most 250 timeout keys per invocation without loading payloads. It also size-checks and repairs the three derived result options for at most 25 sites per invocation. Cursor `rrze_msm_storage_resource_maintenance_offset` tracks traversal; argument `[true]` schedules bounded continuations after at least 60 seconds. Old runtime formats are removed regardless of their old TTL; expired current-format values are removed even with an external object cache. Completed bounded results remain available. See [Storage analysis](storage-analysis.md) for caps, legacy reanalysis and operational limits.

## Shortcode and block analysis

| Element | Name |
| --- | --- |
| Individual-analysis hook | `rrze_msm_run_shortcode_block_analysis_site` |
| Collective-batch hook | `rrze_msm_run_shortcode_block_analysis_batch` |
| Legacy hook | `rrze_msm_run_shortcode_block_analysis` |
| Frequency migration | `rrze_msm_reschedule_shortcode_block_analysis_frequency` |
| Obsolete removal hook | `rrze_msm_remove_shortcode_block_analysis_tasks` |
| Batch continuation argument | `['rrze_msm_shortcode_block_batch' => true]` |
| Site lock | `rrze_msm_shortcode_block_analysis_lock_<site-id>` |

Scheduler data is stored as network options, including `rrze_msm_shortcode_block_analysis_assignments`, `rrze_msm_shortcode_block_analysis_batch_site_ids`, `rrze_msm_shortcode_block_analysis_batch_offset`, `rrze_msm_shortcode_block_analysis_batch_lock`, and `rrze_msm_shortcode_block_analysis_schedule_initialization_state`. Result, status, and process state are stored per site in the site options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status`, and `rrze_msm_shortcode_block_analysis_state`. The result is explicitly non-autoloaded and is capped at 1 MiB serialized data; oversized input values above 256 KiB are not parsed. The maximum result count is limited by `Config::getShortcodeBlockResultEntryLimit()`.

## Admin AJAX and maintenance

Schedule initialisation, task removal, and cleanup of old local central Cron entries are browser-driven AJAX batches. The hooks are `wp_ajax_rrze_msm_start_analysis_schedule_initialization`, `wp_ajax_rrze_msm_run_analysis_schedule_initialization_batch`, `wp_ajax_rrze_msm_start_analysis_task_removal`, `wp_ajax_rrze_msm_run_analysis_task_removal_batch`, `wp_ajax_rrze_msm_start_legacy_central_cron_cleanup`, and `wp_ajax_rrze_msm_run_legacy_central_cron_cleanup_batch`.

Task removal uses the respective state options `rrze_msm_storage_analysis_task_removal_state` and `rrze_msm_shortcode_block_analysis_task_removal_state`. Old local central Cron entries use `rrze_msm_legacy_central_cron_cleanup_state`. `NetworkCronCleanupQueue` adds `mode = ajax`, `run_id`, and a 15-minute lease to AJAX runs. An abandoned browser run is then marked as abandoned and does not permanently block new regular analysis tasks.

All AJAX and admin-post actions check capability and nonce. New technical actions must retain both checks and store their state in network options when they operate network-wide.

## External communication

`MonitoringService` sends DNS and HTTP requests to site domains registered in the multisite. HTTP requests use the WordPress HTTP API without authentication. Target, timeout, and error behaviour are described in Availability monitoring. Depending on the target domain, request metadata can leave local infrastructure.

Reverse image search is a user-initiated browser action: the link to TinEye passes the publicly reachable image URL as a search parameter. The plugin does not make a server-side request to TinEye and uses no API credentials.

## Browser state and cookies

The plugin uses neither `localStorage` nor `sessionStorage`. JavaScript in `src/js/rrze-multisite-manager.js` sets cookies with `Path=/` and `SameSite=Lax`:

| Cookie | Content | Lifetime |
| --- | --- | --- |
| `rrze_msm_color_mode` | Selected admin colour mode | 365 days |
| `rrze_msm_widget_order_<view>` | Individual widget order for a dashboard view | 365 days |

Cookie values contain no credentials. The widget order is additionally stored as user meta `rrze_msm_widget_orders` so that server-rendered order is retained independently of the browser.
