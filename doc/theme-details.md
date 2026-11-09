# Theme details

## Purpose

The **Theme details** page describes one theme and its use in the network.

## Contents

It shows metadata such as name, version, author, description, and screenshot, as well as the sites that use the theme. For block themes, the detail view can link to the Site Editor of the respective site.

The configured default theme is determined live from `rrze_settings.general.default_theme` with `WP_DEFAULT_THEME` as fallback and is marked accordingly. The Multisite Manager does not offer network deactivation or deletion actions for this theme.

The list of websites using the theme comes from the central theme-usage index. It never scans every website live. Before the first completed index run, the detail page links to Monitoring, where the index task can be started.

The website list is paginated and sorted on the server like the plugin detail page (`rrze_msm_<table>_sort`, `rrze_msm_<table>_order`). Its rows are rebuilt on every request from the indexed site IDs and the stored dashboard rows; the detail cache holds only the theme metadata.
