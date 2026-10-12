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
| Frequency migration | `rrze_msm_reschedule_storage_analysis_frequency` |
| Obsolete removal hook | `rrze_msm_remove_storage_analysis_tasks` |
| Batch continuation argument | `['rrze_msm_storage_analysis_batch' => true]` |
| Site lock | `rrze_msm_storage_analysis_lock_<site-id>` |
| Collective-batch lock | `rrze_msm_storage_analysis_batch_lock` |
| Network-wide execution slots | `rrze_msm_storage_analysis_run_slots` |
| Lock for slot updates | `rrze_msm_storage_analysis_run_slots_lock` |

Assignment and progress data are network options, including `rrze_msm_storage_analysis_assignments`, `rrze_msm_storage_analysis_batch_site_ids`, `rrze_msm_storage_analysis_batch_last_site_id`, `rrze_msm_storage_analysis_schedule_initialization_state`, and `rrze_msm_storage_analysis_frequency_reschedule_state`. The persistent result is a non-autoloaded site option in `rrze_msm_site_storage_analysis_result`; summary metadata is in `rrze_msm_site_storage_analysis_result_meta`. The result is truncated when serialised data exceeds 2 MiB.

Resumable process data uses network transients with the patterns `rrze_msm_site_storage_analysis_v3_<cache-version>_<site-id>`, `rrze_msm_site_storage_analysis_base_state_<cache-version>_<site-id>`, `rrze_msm_site_storage_analysis_orphan_state_<cache-version>_<site-id>`, `rrze_msm_site_storage_attachment_index_v2_<cache-version>_<site-id>`, and `rrze_msm_site_storage_attachment_index_bucket_v1_<cache-version>_<site-id>_<hex-bucket>`. Buckets prevent one unboundedly large media-index transient. The `monitoring_storage_analysis_max_concurrent_runs` option limits concurrent storage analyses across the network; its default is 10. Slots that cannot be acquired are retried as one-off Cron events. Orphaned slots expire after the configured maximum execution time plus one minute.

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

Scheduler data is stored as network options, including `rrze_msm_shortcode_block_analysis_assignments`, `rrze_msm_shortcode_block_analysis_batch_site_ids`, `rrze_msm_shortcode_block_analysis_batch_offset`, `rrze_msm_shortcode_block_analysis_batch_lock`, and `rrze_msm_shortcode_block_analysis_schedule_initialization_state`. Result, status, and process state are stored per site in the site options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status`, and `rrze_msm_shortcode_block_analysis_state`. The maximum result count is limited by `Config::getShortcodeBlockResultEntryLimit()`.

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
