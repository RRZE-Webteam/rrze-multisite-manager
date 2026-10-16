# Shortcodes and blocks

## Purpose

The page determines which shortcodes and blocks occur in a site's content. In addition to each entry, the output can include information about registration, provider, and occurrences.

## Access

Super admins access the network-wide page in Multisite Manager. Local administrators have a restricted page under **Tools > Shortcodes and blocks** on their site when they meet the requirements.

## Usage

Tabs separate shortcodes and blocks. An analysis can be requested for an individual site. The status indicates whether a result exists, whether a next run is scheduled, and which execution mode is used.

## Scheduling and scale

Tasks are managed under **Monitoring > Shortcodes and blocks**. Small sites can be assigned to collective batches; larger sites receive their own scheduled task. The analysis is resumable, time-bounded, and stores only result data, not fully rendered content. Its runtime limit counts active PHP processing across slices, not time spent waiting for the external Cron service. Each completed slice persists a checkpoint before handing off to its continuation. If a queued continuation is unexpectedly missing, the scheduler restores it from that checkpoint and continues the analysis; no result data is discarded.

## Implementation and persistence

`Dashboard::renderShortcodeBlockAnalysisPage()` uses `ShortcodeBlockAnalysisSchedulerService` and `templates/shortcode-block-analysis-page.php`. Per-site result, status, and intermediate state are stored in options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status`, and `rrze_msm_shortcode_block_analysis_state`. The result option is explicitly non-autoloaded, including when an existing result is replaced.

The scheduler uses separate individual and collective-batch hooks, a legacy hook for backward compatibility, and a per-site lock with the prefix `rrze_msm_shortcode_block_analysis_lock_`. [Runtime state and scheduler](runtime-state.md#shortcode-and-block-analysis) lists the scheduler options and hooks completely. The batch implementation is limited to 20 sites, at most five sub-batches per request, and a 20-second request budget; continuations are scheduled after five seconds. Post-content and post-meta values above 256 KiB are skipped instead of being parsed. The serialized result has a hard 1-MiB limit; excess entries are removed and the result is marked as truncated.
