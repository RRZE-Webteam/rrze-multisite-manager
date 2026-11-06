# Plugin details

## Purpose

The **Plugin details** page shows metadata and the network usage of a selected plugin.

## Contents

In addition to name, version, description, author, and technical requirements, the page shows whether a plugin is network-active and which sites actively use it. It links to the respective site details.

Where WordPress provides the information, update information and appropriate management links are also displayed. Network-wide changes are available only to super admins.

The list of websites using the plugin comes from the central plugin-usage index. It never scans every website live. Before the first completed index run, the detail page links to Monitoring, where the index task can be started.

When the plugin enablement is available (RRZE Netzwerk-Audit network-active, otherwise RRZE Settings), the action area also contains its Enable or Disable action for manageable plugins. The action is shown only to super admins. Multisite Manager updates the shared `enabled_plugins` state through its own protected endpoint and redirects back to the same detail page.

## Source-code analysis

Static source findings (shortcodes, blocks, patterns, post types, taxonomies, image sizes, hooks) are collected by the plugin and theme usage index when **Static source analysis** is enabled (disabled by default), one plugin or theme per Cron request, and stored in `plugin_source_metrics`. A detail page only reads that stored result. Each analysis is bounded. Two limits are configurable under **Settings > Monitoring > Plugin and theme usage index**: the subdirectory depth below the plugin or theme directory (`monitoring_source_analysis_max_depth`, default 3: the main directory and three subdirectory levels, which covers block metadata in `build/blocks/*/block.json`) and the number of PHP files read (`monitoring_source_analysis_max_php_files`, default 100). Files are read breadth-first and alphabetically, so the main directory comes first. The depth defines the scope and does not mark a result as incomplete; registrations in deeper directories are then not found. Fixed technical limits apply in addition: at most 20 seconds, 2,000 directories, 5,000 files, 1 MiB per file and 32 MiB in total. Both configurable values are part of the stored fingerprint, so changing them analyses every plugin and theme again in the next run. Hidden directories, `node_modules`, `bower_components` and symlinked directories are not entered; a single-file plugin in `WP_PLUGIN_DIR` is analysed as that one file. When a limit is reached, the stored result is marked incomplete and the detail page shows a warning. An analysis that ends its request twice (timeout or memory) is skipped for the current run and counted as a source error.

The website list uses the rows of the last completed dashboard metrics run. Websites created afterwards are shown with their basic data until the next run; the page never measures website metrics itself.

## Implementation

`Dashboard::renderPluginDetailsPage()` renders `templates/plugin-details-page.php`. Stored source findings are shown whenever they exist; the former nonce-protected `source_analysis` URL parameter only still distinguishes cache keys and starts no analysis. The detail cache uses the pattern `rrze_msm_plugin_details_<detail-version>_<hash>`; the hash contains the plugin file, that flag and the plugin fingerprint (source-analysis version, configured limits, main-file and directory timestamps). Each completed usage-index pass increases the global detail-cache version.
