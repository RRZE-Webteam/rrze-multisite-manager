# RRZE Multisite Manager documentation

This documentation describes the visible **Multisite Manager** menu pages from a developer perspective. It is intended for people responsible for operating, extending, or troubleshooting a large WordPress multisite network. It includes usage instructions only where they explain the technical workflow.

The shared reference for persistence, Cron hooks, batches, and locks is [Runtime state and scheduler](runtime-state.md).

General requirements for development, builds, tests, and translations are in [Development](development.md).

## Access model

The Manager may be visible to authorised web support users. Network-wide administrative functions for environment, monitoring, and settings remain limited to super admins. Individual analysis pages are also available in the dashboard of the respective site when the user has the required local permission.

## Menu pages

| Page | Description | Access |
| --- | --- | --- |
| [Dashboard](dashboard.md) | Configurable metrics and widgets | Authorised Manager users |
| [Environment](environment.md) | Server, WordPress, and network configuration | Super admin |
| [Website overview](website-overview.md) | Filterable list and status of all sites | Authorised Manager users |
| [Website details](website-details.md) | Detailed data and management actions for one site | Authorised Manager users; sensitive functions only for super admins |
| [Plugin overview](plugin-overview.md) | Network-wide plugin usage and management | Authorised Manager users; actions only for super admins |
| [Plugin details](plugin-details.md) | Details and usage of a plugin | Authorised Manager users |
| [Theme overview](theme-overview.md) | Network-wide theme usage | Authorised Manager users |
| [Theme details](theme-details.md) | Details and usage of a theme | Authorised Manager users |
| [Storage analysis](storage-analysis.md) | Upload, media, and metadata analysis for one site | Authorised local users or super admins |
| [Shortcodes and blocks](shortcodes-and-blocks.md) | Shortcodes and blocks used per site | Authorised local users or super admins |
| [Monitoring](monitoring.md) | Background processes, schedules, and maintenance | Super admin |
| [Settings](settings.md) | Global configuration, views, and logging | Super admin |

## Operational notes

Dashboard metrics, monitoring, and scheduled analyses run in bounded batches. Loading the user interface never starts a synchronous scan of the entire network. WordPress Cron must be triggered reliably for timely execution; large installations should use a server-triggered Cron request.

The pages store analysis and status data, not fully rendered admin pages. After changing relevant settings or updating the plugin, a background refresh may be required before aggregate metrics reflect the new state.
