# Website details

## Purpose

The **Website details** page combines technical and content information for one site. It is the starting point for detailed analysis and related WordPress administration pages.

## Areas

Depending on the site and permission, areas for overview, options, Cron entries, content types, image sizes, transients, media, and debug data are available. Sensitive options are hidden from non-privileged users.

The page links, among other things, to site administration, the media library, theme settings, menus, the Customizer and, where appropriate, the Site Editor.

## Theme tab

Below the active theme, **Further allowed themes** lists every other theme the website may activate: network-enabled themes and themes enabled only for this website (`wp_get_themes(['allowed' => true, 'blog_id' => …])`). Themes with errors, for example a missing parent theme, are omitted, as on **Appearance > Themes**. The list is part of the cached theme section; it is invalidated by theme changes and by changes to the network or site option `allowedthemes`.

* **Activate** (super admins only) calls `admin_post_rrze_multisite_manager_activate_site_theme` with the nonce `rrze_multisite_manager_activate_site_theme_<site>_<stylesheet>`. The handler checks that the theme exists, has no errors and is allowed for the website (`WP_Theme::is_allowed('both', <site>)`), then runs `switch_theme()` in the website's context and returns to the theme tab. The theme's own `after_switch_theme` handling runs on the website's next request (`check_theme_switched()`), as with any theme switch outside that website.
* **Live Preview** opens the website's own preview: the Site Editor with `wp_theme_preview` for block themes, otherwise the Customizer with `theme=`. Access is checked by WordPress on that website.

Below that, **Themes available in the network but not enabled** lists the installed themes without errors that are neither enabled network-wide nor for this website (`wp_get_themes(['allowed' => false, 'blog_id' => …])`). For super admins each entry has **Enable for this website**. The handler `admin_post_rrze_multisite_manager_enable_site_theme` checks the super-admin capability and the nonce `rrze_multisite_manager_enable_site_theme_<site>_<stylesheet>`, then adds the stylesheet to the website option `allowedthemes` (not autoloaded), exactly as **Network Admin > Sites > Edit > Themes** does (`wp-admin/network/site-themes.php`). Changing that option also clears the cached theme section. The network-wide allow list is not changed and the theme is not activated; it then appears under **Further allowed themes**.

## Management functions

Super admins can maintain status information and access further network actions. Changing options, deleting data, or changing a status is protected and is not performed as part of merely viewing details.

## Caching

Detail data is cached per site so that extensive options or media information do not need to be collected again on every request. Changes to relevant WordPress data invalidate the appropriate detail and dashboard caches. Version-invalidated detail cache transients are removed by bounded hourly maintenance; this also removes their timeout rows when an external object cache would otherwise leave database rows behind.

## Implementation

The page is provided by `Dashboard::renderSiteDetailsPage()` and `templates/site-details-page.php`. `SiteDetailMetricsService` collects the individual sections; `MetricsCacheService` creates the site transient keys `rrze_msm_site_details_<format-version>_<detail-version>_<site-version>_<hash>` and `rrze_msm_site_detail_section_<format-version>_<detail-version>_<site-version>_<hash>`. The global detail version is stored in `rrze_msm_detail_cache_version`; a site's version is stored in site meta `rrze_msm_site_detail_cache_version`.
