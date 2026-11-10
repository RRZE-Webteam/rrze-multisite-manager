# Settings

## Purpose

The **Settings** page configures Multisite Manager. It is visible only to super admins and has the **General**, **Monitoring**, and **Views** tabs.

## General

This tab maintains defaults for dashboard tables, inactivity marking, and optional logging of background processes. The settings do not control WordPress core options outside Multisite Manager.

### Logging

The **Debugging** section has two independent switches, both disabled by default:

* **Logging** (`debugging_logging`) sends informational messages to the `rrze.log.info` channel: start and end of network processes (dashboard metrics, availability checks) and the start and end of each website analysis, plus failures, aborts and recoveries.
* **Fine-grained debugging** (`debugging_fine_grained`) additionally sends intermediate steps of background runs: storage-analysis phase transitions, per-slice continuation diagnostics, shared-batch invocations and queue handling, and assignment decisions. It has no effect while **Logging** is disabled.

`LoggingService::info()` is used for the first group, `LoggingService::debug()` for the second. Errors and warnings (`rrze.log.error`, `rrze.log.warning`) are not affected by either switch.

Multiple-choice settings (for example **Ignore websites** for availability checks or the default columns of the user list) are rendered as checkbox groups. A hidden empty value is submitted with each group, so deselecting every entry is stored as an empty selection; saving one tab never changes the fields of another tab. The settings forms use `autocomplete="off"` so that a browser reload shows the stored values instead of restoring unsaved form state.

## Monitoring

The tab is divided into sub-sections (URL parameter `monitoring_section`). Each sub-section is its own form with its own **Save** button; only its fields are submitted, and `Settings::sanitizeOptions()` merges them with the stored values, so the other sub-sections stay unchanged. After saving, the same sub-section is shown again. Option names are unchanged.

| Sub-section | Contents |
| --- | --- |
| General | Shared background process limit (default 3, range 1–16; availability checks are not counted), websites per step for dashboard metrics and the plugin/theme usage index (`monitoring_batch_size`), retained monitoring runs and visible anomaly entries |
| Dashboard metrics | Cycle of the dashboard metrics and of their historical statistics (daily, weekly, monthly, or disabled while retaining existing data; weekly by default) |
| Availability | Cycle, batch size, ignored website states, grace period for new websites and DNS/HTTP failure thresholds |
| Plugins & themes | Cycle of the plugin and theme usage index (twice weekly by default) |
| Storage analysis | Table of the batch tiers (small, medium, large, individual) with cycle, media threshold, runtime threshold and maximum active runtime; limit of checked orphaned files; collapsed **Advanced settings** with the index step sizes per tier, the per-type capacity and the browser-analysis size limit |
| Shortcodes & blocks | Table of the batch tiers with cycle, post/page threshold and runtime threshold; maximum runtime, input size and stored findings per website and type (default 1,000, range 100–5,000; a block finding is one block type per post) |

In the tier tables each input keeps its option name and has a screen-reader label "tier – column". The allowed range is shown below each number field; the original field descriptions are available under **Explanation of the values**. A warning appears when the media, post/page or runtime thresholds do not increase from the small to the large batch.

The setting **Show analysis request notice to local administrators** (`monitoring_show_local_analysis_request_notice`) is an access setting and is shown on the **General** tab.

Larger batches shorten the entire run but increase the load and execution time of an individual Cron request. Values should therefore match PHP limits, the Cron cadence, and network size.

Shortcode/block scheduling initially classifies a website by its combined post and page count. Defaults are small up to 100, medium up to 1,000, large up to 5,000, and individual above that. After a successful complete run, the higher class from content count and active runtime is used: defaults are small up to 3 seconds, medium up to 15 seconds, large up to 60 seconds, and individual above that. Saving changed thresholds starts a bounded rescheduling pass; it does not synchronously scan the complete network.

## Views

Views determine which widgets are visible in the dashboard and their default order. A user's individual order is stored separately in the browser.

## Saving and effect

When saving, the plugin validates value ranges and reschedules changed recurring processes. A page request cannot start parallel batches that are already running. Use [Monitoring](monitoring.md) to manually start, reset, or remove tasks.

## Implementation

`Settings` reads and writes the network option `rrze-multisite-manager`. Form handlers are registered as both `admin_post_*` and `network_admin_edit_*` so that the interface works in normal admin and network-admin contexts. All writing handlers check `is_super_admin()` and the appropriate nonce.

Frequency changes do not run a synchronous network loop. They write signature and progress data such as `rrze_msm_storage_analysis_schedule_signature`, `rrze_msm_shortcode_block_analysis_schedule_signature`, and the respective `*_frequency_reschedule_state` options. [Runtime state and scheduler](runtime-state.md) documents the downstream migration hooks.
