# Storage analysis

## Purpose

Storage analysis examines the uploads directory and media library of one site. It is available in Multisite Manager and, with local permission, under **Media > Storage analysis** on the respective site.

## Areas

* **Analysis:** storage consumption, file types, largest files and directories, and potentially orphaned files.
* **Missing metadata:** media and files for which expected metadata is incomplete. Hover actions lead to media-file details and editing in the respective media library.
* **Media-file details:** technical data for a file, preview, link to edit it in the source site, and an optional TinEye reverse image search for images.

The displayed difference between the previously measured storage value and the found upload volume is shown directly next to the found storage size.

## Execution

Small sites with a known size can be examined in bounded browser batches. An unknown size requires background analysis, so the browser cannot accidentally start a large scan. Large sites are processed exclusively by resumable background tasks. Analysis progresses through upload structure, media index, metadata, and, where applicable, reference checks; it does not attempt to process tens of thousands of files in one request.

Each scheduled invocation processes at most two steps with a five-second soft budget, then persists the phase and resumes through the central continuation queue. Only one queue event resumes one website at a time; old run IDs cannot restart a completed analysis. The configured runtime limit counts active processing time, excluding Cron waiting time. A run also expires after seven days. Blocking database/filesystem operations can exceed the soft budget; server timeouts still apply.

Scheduled storage analyses are configured in **Monitoring > Storage analyses**. Depending on media count and measured execution time, a site is assigned to a collective batch or its own task.

## Caution with orphaned files

A potentially orphaned file is not automatically unused. References may exist in themes, plugins, widgets, block attributes, or unexamined meta fields. Deletion actions are therefore individually protected and should happen only after subject-matter review.

## Implementation and persistence

`Dashboard::renderSiteStorageAnalysisPage()` uses `StorageAnalysisService`, `StorageAnalysisStateService`, and `StorageAnalysisResultService`. The template is `templates/site-storage-analysis-page.php`; a file's detail view uses `templates/site-storage-attachment-debug.php`.

The persistent result is stored per site in the non-autoloaded options `rrze_msm_site_storage_analysis_result`, `rrze_msm_site_storage_analysis_result_meta`, and `rrze_msm_site_media_metadata_analysis_result`. Running process data is held in stable, format-versioned network transients that survive detail-cache invalidation; [Runtime state and scheduler](runtime-state.md#storage-analysis) lists complete key patterns, Cron hooks, scheduler options, and locks.

The analysis uses fixed sub-limits: 250 upload entries, 25 attachment-index entries, 50 metadata entries, ten files per orphan-check step, and at most 500 stored metadata-result rows. Index input paths are limited to 1,024 bytes, attachment metadata to 256 KiB and variants to 256 per attachment. Exceeding an index input limit stops analysis explicitly; registered files must never become orphan candidates because index entries were silently omitted. Metadata listings read bounded titles and metadata and determine text-field presence in SQL instead of loading complete descriptions into Core caches.

The orphan check builds a site-local reference index once, in resumable source batches, before classifying candidates. It examines bounded post content, post metadata, and the supported widget/theme options; individual values larger than 256 KiB are not parsed. Candidate checks then use this index rather than running a SQL `LIKE` query for every file. A missing indexed reference remains an indicator for manual review, never proof that a file can be deleted safely.

Each persistent storage or metadata result is capped at 2 MiB of PHP-serialized data, summary metadata at 16 KiB. Compaction bounds every variable list and string before serialization and preserves scalar totals and progress. Shortened details are indicated in the UI. Oversized identifiers are cleared rather than shortened into another actionable path. Database write failures are reported. For 800 sites, the two result types can still occupy up to 3.125 GiB plus summaries and database overhead; these caps are safety limits, not expected usage.

Legacy oversized derived results are size-gated in SQL before reading: their verbose details are replaced by a bounded reanalysis notice, preserving any available bounded storage summary. Original media and content remain intact. Reading a result or the bounded maintenance traversal performs this repair; details require a new analysis.

Attachment statistics are accumulated from paginated media-index segments before the file scan. Shared physical paths are deduplicated across attachments. Finalization uses that compact summary and does not reload the entire media library. Media-file debugging reads only the selected attachment and its metadata.

Index construction writes at most eight segments per step. Each segment has a hard serialized limit of 128 KiB, the complete temporary index 32 MiB per site, and each working state 1 MiB. Budgets are reserved before writes. An absent expected segment stops analysis to prevent incorrect orphan classifications. The plugin retains at most four segments in its own PHP cache. In-process WordPress copies are evicted for database-backed transients; external caches supporting `flush_runtime` are periodically cleared only in memory, preserving persistent cache contents. One temporary index and one active storage/metadata worker are admitted network-wide by default; saved settings are clamped to a hard maximum of two. These limits cover browser and background analysis. Two reserved indexes plus base/orphan states occupy at most about 68 MiB of serialized temporary payload, excluding database/cache overhead, WordPress runtime memory and previous legacy data awaiting cleanup. The limits do not reserve physical RAM.

Base/orphan states and idle reservations expire after 24 hours; index segments have a seven-day TTL but idle reservation cleanup removes them after 24 hours. Completion, restart and handled failure clear temporary state. Hourly maintenance is scheduled only after analysis activity, never during activation. It removes expired/obsolete runtime keys in batches of 250 timeout keys without reading payloads, and repairs oversized results for 25 sites per invocation. Continuations run after at least 60 seconds when needed.

Worker admission uses atomic main-site options and tokens. Expired worker leases are reclaimed only when local process termination can be verified, or a later non-threaded request reuses the same PID with no live callback token. An unverified remote worker remains reserved and is logged after 24 hours. Operators must verify it has stopped before clearing such a reservation. Server-enforced PHP-FPM and database/filesystem timeouts remain necessary: the five-second budget is checked between steps and cannot interrupt a blocking operation.

Administrative pages never call `get_space_used()` to recalculate usage. They prefer the compact last analysis measurement, fall back to an existing Core `dirsize_cache` entry, and show unknown when neither is available. Measured values are labelled as the last measurement. Unknown sites prevent a misleading free-capacity chart or utilization percentage for the network.
