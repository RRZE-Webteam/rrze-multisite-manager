# Settings

## Purpose

The **Settings** page configures Multisite Manager. It is visible only to super admins and has the **General**, **Monitoring**, and **Views** tabs.

## General

This tab maintains defaults for dashboard tables, inactivity marking, and optional logging of background processes. The settings do not control WordPress core options outside Multisite Manager.

## Monitoring

The tab configures:

* intervals for dashboard metrics, their historical statistics (daily, weekly, monthly, or disabled while retaining existing data; weekly by default), plugin/theme usage indexing (twice weekly by default), and availability checks
* batch size for network-wide processes
* grace period for new sites and DNS/HTTP failure thresholds
* intervals, execution limits, and assignment criteria for storage analyses
* separate cycles and assignment thresholds for small, medium, and large shortcode/block batches plus individual tasks
* optional plugin-source indexing for shortcode and block registrations; it is disabled by default, so only post/page content and metadata are searched
* a shared network-wide background process limit (default 2, range 1–8) for storage/content analyses, dashboard metrics/statistics, plugin/theme usage indexing and availability checks
* number of retained log and event entries

Larger batches shorten the entire run but increase the load and execution time of an individual Cron request. Values should therefore match PHP limits, the Cron cadence, and network size.

Shortcode/block scheduling initially classifies a website by its combined post and page count. Defaults are small up to 100, medium up to 1,000, large up to 5,000, and individual above that. After a successful complete run, the higher class from content count and active runtime is used: defaults are small up to 3 seconds, medium up to 15 seconds, large up to 60 seconds, and individual above that. Saving changed thresholds starts a bounded rescheduling pass; it does not synchronously scan the complete network.

When plugin-source indexing is enabled, the index is built once per plugin version and network, then reused by all website analyses. The work is continued in bounded steps. Plugin updates invalidate the index; the plugin version, main-file metadata, plugin-directory timestamp, and a 30-day expiry provide additional invalidation without a full directory traversal on every cache lookup. A manual in-place edit of a subsidiary source file that changes neither the version nor the directory timestamp can therefore remain cached until expiry. Literal `add_shortcode()`, `register_block_type()` and `block.json` registrations are recognized. Dynamically assembled registration names cannot be derived reliably from source text.

## Views

Views determine which widgets are visible in the dashboard and their default order. A user's individual order is stored separately in the browser.

## Saving and effect

When saving, the plugin validates value ranges and reschedules changed recurring processes. A page request cannot start parallel batches that are already running. Use [Monitoring](monitoring.md) to manually start, reset, or remove tasks.

## Implementation

`Settings` reads and writes the network option `rrze-multisite-manager`. Form handlers are registered as both `admin_post_*` and `network_admin_edit_*` so that the interface works in normal admin and network-admin contexts. All writing handlers check `is_super_admin()` and the appropriate nonce.

Frequency changes do not run a synchronous network loop. They write signature and progress data such as `rrze_msm_storage_analysis_schedule_signature`, `rrze_msm_shortcode_block_analysis_schedule_signature`, and the respective `*_frequency_reschedule_state` options. [Runtime state and scheduler](runtime-state.md) documents the downstream migration hooks.
