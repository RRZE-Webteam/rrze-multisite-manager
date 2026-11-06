# Theme details

## Purpose

The **Theme details** page describes one theme and its use in the network.

## Contents

It shows metadata such as name, version, author, description, and screenshot, as well as the sites that use the theme. For block themes, the detail view can link to the Site Editor of the respective site.

The configured default theme is determined live from `rrze_settings.general.default_theme` with `WP_DEFAULT_THEME` as fallback and is marked accordingly. The Multisite Manager does not offer network deactivation or deletion actions for this theme.

## Source-code analysis

Static source findings (shortcodes, blocks, patterns, image sizes, hooks) are read from `theme_source_metrics`. They are collected by the plugin and theme usage index only when **Static source analysis** is enabled (disabled by default), with the same limits as for plugins (see [Plugin details](plugin-details.md#source-code-analysis)). The detail page never analyses source code itself.

## Implementation

`Dashboard::renderThemeDetailsPage()` renders `templates/theme-details-page.php`. The cache key follows the pattern `rrze_msm_theme_details_<detail-version>_<hash>` and combines stylesheet, the legacy analysis-mode flag and the theme fingerprint (source-analysis version, configured limits, file timestamps). The website rows come from the last completed dashboard metrics run; websites created afterwards are shown with basic data only.

The list of websites using the theme comes from the central theme-usage index. It never scans every website live. Before the first completed index run, the detail page links to Monitoring, where the index task can be started.
