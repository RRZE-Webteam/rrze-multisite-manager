# Plugin overview

## Purpose

The Plugin overview lists all plugins available in the network and their usage on active sites.

## Table

The table follows the WordPress style and contains bulk selection, the plugin name with short description and hover actions, version, vendor information, and status columns. Status columns show the number of active sites, network activation, and automatic updates.

It can be sorted by plugin, number of active sites, vendor information, network activation, and automatic updates. Network-active plugins are not pre-sorted; the default order is the plugin name. A coloured left marker identifies them.

## Actions

Super admins can activate or deactivate one or more plugins network-wide, update them, and enable or disable automatic updates. Individual action availability depends on WordPress and the current plugin status.

When the optional plugin enablement is available (RRZE Netzwerk-Audit network-active, otherwise RRZE Settings), the **Available** status column reflects the shared `enabled_plugins` setting. It displays a marker for network-active plugins, a green check for enabled plugins, and a red cross for disabled plugins. For plugins that Netzwerk-Audit can manage, super admins also receive an Enable or Disable hover action. Multisite Manager changes the shared setting through its own protected endpoint and redirects back to the active overview tab. Plugins that are network-active, network-only, or do not have a directory component cannot be managed and do not receive a toggle action.

Hover actions link to plugin details and, if available, to plugin settings. If the Plugin Check Plugin is active, an additional link to its individual test is available for a plugin.

## Notes

The page uses aggregated dashboard data for site usage. Plugin actions themselves use WordPress network actions and their nonces.

## Implementation

`Dashboard::renderPluginOverviewPage()` uses `PluginUsageWidget` and `templates/plugin-overview-page.php`. Usage counts come from the central plugin-usage index (`plugin_metrics`, see [Runtime state and scheduler](runtime-state.md#plugin-and-theme-usage-index)); neither the overview nor the dashboard batch reads `active_plugins` of every website or plugin source code. Actions use the WordPress URLs and nonces from network plugin administration, not custom unprotected endpoints.
