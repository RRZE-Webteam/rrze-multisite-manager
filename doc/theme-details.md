# Theme details

## Purpose

The **Theme details** page describes one theme and its use in the network.

## Contents

It shows metadata such as name, version, author, description, and screenshot, as well as the sites that use the theme. For block themes, the detail view can link to the Site Editor of the respective site.

The configured default theme is determined live from `rrze_settings.general.default_theme` with `WP_DEFAULT_THEME` as fallback and is marked accordingly. The Multisite Manager does not offer network deactivation or deletion actions for this theme.

## Source-code analysis

An optional source-code analysis is only an explicitly requested detail function. It is not started during plugin initialisation, when the dashboard loads, or by regular metric batches.

## Implementation

`Dashboard::renderThemeDetailsPage()` uses the nonce namespace `rrze_msm_source_analysis_theme_<stylesheet>`. The cache key follows the pattern `rrze_msm_theme_details_<detail-version>_<hash>` and combines stylesheet, analysis mode, and theme fingerprint.
