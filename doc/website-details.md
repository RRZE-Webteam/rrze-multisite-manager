# Website details

## Purpose

The **Website details** page combines technical and content information for one site. It is the starting point for detailed analysis and related WordPress administration pages.

## Areas

Depending on the site and permission, areas for overview, options, Cron entries, content types, image sizes, transients, media, and debug data are available. Sensitive options are hidden from non-privileged users.

The page links, among other things, to site administration, the media library, theme settings, menus, the Customizer and, where appropriate, the Site Editor.

## Management functions

Super admins can maintain status information and access further network actions. Changing options, deleting data, or changing a status is protected and is not performed as part of merely viewing details.

## Caching

Detail data is cached per site so that extensive options or media information do not need to be collected again on every request. Changes to relevant WordPress data invalidate the appropriate detail and dashboard caches. Version-invalidated detail cache transients are removed by bounded hourly maintenance; this also removes their timeout rows when an external object cache would otherwise leave database rows behind.

## Implementation

The page is provided by `Dashboard::renderSiteDetailsPage()` and `templates/site-details-page.php`. `SiteDetailMetricsService` collects the individual sections; `MetricsCacheService` creates the site transient keys `rrze_msm_site_details_<format-version>_<detail-version>_<site-version>_<hash>` and `rrze_msm_site_detail_section_<format-version>_<detail-version>_<site-version>_<hash>`. The global detail version is stored in `rrze_msm_detail_cache_version`; a site's version is stored in site meta `rrze_msm_site_detail_cache_version`.
