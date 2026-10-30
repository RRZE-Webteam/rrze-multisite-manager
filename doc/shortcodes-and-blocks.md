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

When a site that was removed from the analysis is explicitly added again, the scheduler immediately creates its next recurring appointment and resets the monitoring status. If the site joins a collective batch whose regular appointment is still days away, the scheduler additionally creates a prompt one-off batch pass without shifting the regular cycle. Start, phase, runtime, and end values from the earlier run are not shown as the state of the new assignment. Removing an analysis marks all of its central content generations obsolete immediately; their shortcode/block rows are removed later in bounded maintenance batches.

## Implementation and persistence

`Dashboard::renderShortcodeBlockAnalysisPage()` uses `ShortcodeBlockAnalysisSchedulerService` and `templates/shortcode-block-analysis-page.php`. `AnalysisStore` and `ContentAnalysisStore` persist results centrally in `rrze_msm_analysis_shortcodes` and `rrze_msm_analysis_blocks`, using the installation base prefix. Intermediate findings and phase checkpoints are separated into these same two tables. The common run record contains only orchestration data and, after publication, a compact summary. The per-site option `rrze_msm_shortcode_block_analysis_status` remains for scheduler status.

The scheduler keeps its existing individual/collective hooks and per-site locks. A collective invocation processes one website with at most ten content sub-batches and a 20-second budget; an individual task permits twenty sub-batches and 60 seconds. Continuations are scheduled after five seconds, subject to external Cron cadence. Meta fields use pages of ten with a persistent cursor, so a large post can resume within its metadata.

Individual input values above 256 KiB are not loaded or parsed. Each finding is limited to 8 KiB, the pending buffer to 256 KiB, and cumulative stored finding payloads to 1 MiB per phase. The configured entry limit applies separately to blocks and shortcodes. Limits are enforced during collection; no repeated serialization of the entire result is needed. Omitted or shortened details are marked as incomplete in the UI.

The detail page filters and sorts in the database and shows up to 50 findings per page. Locations are grouped within that page; a block or shortcode may therefore appear on several pages. Summary counts cover the entire completed generation. Monitoring does not load the findings.

The central-storage upgrade deliberately removes old analyses and requests reanalysis, retaining schedules and unrelated settings. A failed new run keeps the last successful result created with the new storage format. [Runtime state and scheduler](runtime-state.md#central-analysis-tables) documents the schema and cleanup lifecycle.
