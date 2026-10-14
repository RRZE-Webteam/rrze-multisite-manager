# Integrations and external services

This reference lists optional WordPress plugins and third-party services that Multisite Manager detects, reads, or links to. An integration is not a dependency unless stated otherwise. A missing optional plugin must not prevent Multisite Manager from loading.

## WordPress plugin integrations

| Integration | Detection and data | User-visible behaviour | Access and limits |
| --- | --- | --- | --- |
| RRZE Netzwerk-Audit | The network-active plugin file is `rrze-netzwerk-audit/rrze-netzwerk-audit.php`. Multisite Manager reads and updates its network option `rrze_netzwerk_audit`, specifically `enabled_plugins`. | Plugin overview and plugin details show the availability state and provide **Enable** or **Disable** actions for manageable plugins. The plugin overview also provides bulk enable and disable actions for selected plugins. | Actions are available only to super admins. Multisite Manager uses its own nonce-protected handlers and returns to the originating MSM page. Netzwerk-Audit remains responsible for enforcing activation restrictions for non-super-admins. Network-active, network-only, and single-file plugins are not manageable through this integration. |
| Plugin Check | The plugin file is `plugin-check/plugin.php`; it must exist and be active locally or network-wide. | Plugin hover actions include **Start PCP test**, linking to the Plugin Check page for that plugin on the network main site. | The link is omitted when Plugin Check is unavailable. The test is executed by Plugin Check, not by Multisite Manager. |
| WP Crontrol | The plugin file is `wp-crontrol/wp-crontrol.php`; it must be active network-wide or on the relevant site. | Monitoring task rows and process notices link to the site's WP Crontrol events, filtered for `rrze_msm` hooks. Central collective batches link to the network main site. | The link is omitted when WP Crontrol is unavailable. Multisite Manager neither embeds nor controls WP Crontrol. |
| RRZE Settings | Multisite Manager reads the network option `rrze_settings`, especially its `writing` section. | The editor-usage widget links to **RRZE Settings: Writing** when a network-wide editor default makes per-site editor metrics uninformative. | The integration expects RRZE Settings' option format but accepts both object and array persistence. The link targets the RRZE Settings network-admin page. |

## External services and browser links

| Service or target | Feature | Data sent and execution model |
| --- | --- | --- |
| TinEye | Media-file details offer an optional reverse-image-search link for public images. | The browser opens `tineye.com/search` with the public image URL as a query parameter. Multisite Manager does not make a server-side request and uses no TinEye API credentials. |
| Google Search | Storage analysis offers an optional search for a potentially orphaned file URL. | The browser opens a Google search with the public file URL as its query. Multisite Manager does not make a server-side request to Google. |
| Registered site domains | Availability monitoring performs DNS and unauthenticated HTTP checks for sites in the multisite network. | Requests originate from the WordPress/PHP host. DNS and HTTP request metadata can leave the local infrastructure. Time budgets and HTTP timeouts are bounded; see [Runtime state and scheduler](runtime-state.md#availability-monitoring). |

## Maintenance requirements

When changing an integration, update this file and the relevant feature documentation. Preserve optional-plugin checks before generating links, do not invoke a third-party plugin's write handler directly, and protect every MSM write action with both a capability check and a nonce.
