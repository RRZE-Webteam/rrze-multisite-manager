# Storage analysis

## Purpose

Storage analysis examines the uploads directory and media library of one site. It is available in Multisite Manager and, with local permission, under **Media > Storage analysis** on the respective site.

## Areas

* **Analysis:** storage consumption, file types, largest files and directories, and potentially orphaned files.
* **Missing metadata:** media and files for which expected metadata is incomplete. Hover actions lead to media-file details and editing in the respective media library.
* **Media-file details:** technical data for a file, preview, link to edit it in the source site, and an optional TinEye reverse image search for images.

The displayed difference between WordPress's storage value and the found upload volume is shown directly next to the found storage size.

## Execution

Small sites can be examined in bounded browser batches. Large sites are processed exclusively by resumable background tasks. Analysis progresses through upload structure, media index, metadata, and, where applicable, reference checks; it does not attempt to process tens of thousands of files in one request.

Scheduled storage analyses are configured in **Monitoring > Storage analyses**. Depending on media count and measured execution time, a site is assigned to a collective batch or its own task.

## Caution with orphaned files

A potentially orphaned file is not automatically unused. References may exist in themes, plugins, widgets, block attributes, or unexamined meta fields. Deletion actions are therefore individually protected and should happen only after subject-matter review.

## Implementation and persistence

`Dashboard::renderSiteStorageAnalysisPage()` uses `StorageAnalysisService`, `StorageAnalysisStateService`, and `StorageAnalysisResultService`. The template is `templates/site-storage-analysis-page.php`; a file's detail view uses `templates/site-storage-attachment-debug.php`.

The persistent result is stored per site in the non-autoloaded options `rrze_msm_site_storage_analysis_result`, `rrze_msm_site_storage_analysis_result_meta`, and `rrze_msm_site_media_metadata_analysis_result`. Running process data is held in versioned network transients; [Runtime state and scheduler](runtime-state.md#storage-analysis) lists complete key patterns, Cron hooks, scheduler options, and locks.

The analysis uses fixed sub-limits: 250 upload or attachment-index entries, 50 metadata entries, ten files per orphan-check step, and at most 500 stored metadata-result rows. The persistent storage result is compacted when serialised data exceeds 2 MiB.
