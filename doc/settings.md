# Settings

## Purpose

The **Settings** page configures Multisite Manager. It is visible only to super admins and has the **General**, **Monitoring**, and **Views** tabs.

## General

This tab maintains defaults for dashboard tables, inactivity marking, and optional logging of background processes. The settings do not control WordPress core options outside Multisite Manager.

## Monitoring

The tab configures:

* intervals for dashboard metrics and availability checks
* batch size for network-wide processes
* grace period for new sites and DNS/HTTP failure thresholds
* intervals, execution limits, and assignment criteria for storage analyses
* intervals and execution limits for shortcode/block analyses
* number of retained log and event entries

Larger batches shorten the entire run but increase the load and execution time of an individual Cron request. Values should therefore match PHP limits, the Cron cadence, and network size.

## Views

Views determine which widgets are visible in the dashboard and their default order. A user's individual order is stored separately in the browser.

## Saving and effect

When saving, the plugin validates value ranges and reschedules changed recurring processes. A page request cannot start parallel batches that are already running. Use [Monitoring](monitoring.md) to manually start, reset, or remove tasks.

## Implementation

`Settings` reads and writes the network option `rrze-multisite-manager`. Form handlers are registered as both `admin_post_*` and `network_admin_edit_*` so that the interface works in normal admin and network-admin contexts. All writing handlers check `is_super_admin()` and the appropriate nonce.

Frequency changes do not run a synchronous network loop. They write signature and progress data such as `rrze_msm_storage_analysis_schedule_signature`, `rrze_msm_shortcode_block_analysis_schedule_signature`, and the respective `*_frequency_reschedule_state` options. [Runtime state and scheduler](runtime-state.md) documents the downstream migration hooks.
