# Shortcodes and blocks

## Purpose

The page determines which shortcodes and blocks occur in a site's content. In addition to each entry, the output can include information about registration, provider, and occurrences.

## Access

Super admins access the network-wide page in Multisite Manager. Local administrators have a restricted page under **Tools > Shortcodes and blocks** when the website has a planned or saved analysis and they meet the requirements. The setting **Show analysis request notice to local administrators** can additionally expose the page for websites without an analysis and displays the network administrator contact; it is disabled by default.

## Usage

Tabs separate shortcodes and blocks. An analysis can be requested for an individual site. The status indicates whether a result exists, whether a next run is scheduled, and which execution mode is used.

## Scheduling and scale

Tasks are managed under **Monitoring > Shortcodes and blocks**. Small sites can be assigned to collective batches; larger sites receive their own scheduled task. The analysis is resumable, time-bounded, and stores only result data, not fully rendered content. Its runtime limit counts active PHP processing across slices, not time spent waiting for the external Cron service. Each completed slice persists a checkpoint before handing off to its continuation. If an individual task loses its continuation, the scheduler restores it from that checkpoint. If a collective-batch task loses its continuation, or makes no progress for two hours, it is ended, its checkpoint is deleted, and it starts again from the beginning with the next regular collective batch.

For an aborted or failed website in a collective batch, its monitoring row offers **Restart as individual task**. This removes only that website from the collective batch, clears its interrupted state, and schedules an individual task immediately. The remaining collective batch is not restarted or otherwise changed.

When a site that was removed from the analysis is explicitly added again, the scheduler immediately creates its next recurring appointment and resets the monitoring status. If the site joins a collective batch whose regular appointment is still days away, the scheduler additionally creates a prompt one-off batch pass without shifting the regular cycle. Start, phase, runtime, and end values from the earlier run are not shown as the state of the new assignment. The previous result remains stored until the newly scheduled analysis replaces it.

## Implementation and persistence

`Dashboard::renderShortcodeBlockAnalysisPage()` uses `ShortcodeBlockAnalysisSchedulerService` and `templates/shortcode-block-analysis-page.php`. Per-site result, status, and intermediate state are stored in options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status`, and `rrze_msm_shortcode_block_analysis_state`. The result option is explicitly non-autoloaded, including when an existing result is replaced.

The scheduler uses separate individual and collective-batch hooks, a legacy hook for backward compatibility, and a per-site lock with the prefix `rrze_msm_shortcode_block_analysis_lock_`. [Runtime state and scheduler](runtime-state.md#shortcode-and-block-analysis) lists the scheduler options and hooks completely. A collective batch is limited to 20 sites, with at most ten content sub-batches and a 20-second request budget per website. An individual task can use at most twenty content sub-batches and a 60-second request budget. Continuations are scheduled after five seconds; with an external Cron service, they run at its next invocation. Post-content and post-meta values above 256 KiB are skipped instead of being parsed. The serialized result has a hard 1-MiB limit; excess entries are removed and the result is marked as truncated.
