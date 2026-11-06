# Theme overview

## Purpose

The Theme overview lists themes available in the network and their usage on sites.

## Contents

The page combines theme metadata, screenshots, versions, and the number of sites using each theme. Links lead to theme details and the appropriate site details. This makes unused themes easy to identify.

The usage counts come from the central theme-usage index (`theme_metrics`), which is collected in the background and updated immediately when a website switches its theme. The configured default theme is read live from `rrze_settings.general.default_theme`; when that setting is empty, `WP_DEFAULT_THEME` is used as the fallback. It is marked as **Configured default theme** without requiring a metrics run. Loading the overview does not start a synchronous theme scan across all sites.

## Management

Network-wide theme management remains part of WordPress network administration. Multisite Manager links to the appropriate WordPress functions and makes their network impact visible.

## Implementation

`Dashboard::renderThemeOverviewPage()` combines installed-theme metadata with the counts from the theme-usage index. Complete site lists are shown only in the detail view; see [Runtime state and scheduler](runtime-state.md#plugin-and-theme-usage-index).
