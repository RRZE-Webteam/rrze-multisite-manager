# Plugin details

## Purpose

The **Plugin details** page shows metadata and the network usage of a selected plugin.

## Contents

In addition to name, version, description, author, and technical requirements, the page shows whether a plugin is network-active and which sites actively use it. It links to the respective site details.

Where WordPress provides the information, update information and appropriate management links are also displayed. Network-wide changes are available only to super admins.

The list of websites using the plugin comes from the central plugin-usage index. It never scans every website live. Before the first completed index run, the detail page links to Monitoring, where the index task can be started.

When the plugin enablement is available (RRZE Netzwerk-Audit network-active, otherwise RRZE Settings), the action area also contains its Enable or Disable action for manageable plugins. The action is shown only to super admins. Multisite Manager updates the shared `enabled_plugins` state through its own protected endpoint and redirects back to the same detail page.

## Plugin metadata

Licence, tags, author e-mail, repository, compatibility, translations, installation date, date of the main file and the readme are read from exactly three sources: the header of the main plugin file (`get_file_data()`, at most 8 KiB), `readme.txt` and `package.json` in the plugin directory (each up to 512 KiB). No other plugin file is opened. Translations are determined by file name (`<text domain>-<locale>.mo`, `.po`, `.l10n.php`) in the plugin's Domain Path and in `WP_LANG_DIR/plugins`, without recursion. Single-file plugins in the plugin root use only their header. The readme is shown collapsed; `readme.txt` headings are converted to the page's Markdown subset. The data is stored with the detail cache, so the files are read only after the cache has expired or was invalidated.

## Live registrations

The detail page reads the shortcode registry, block render callbacks and action/filter callbacks for the current request. It attributes a registration when its callback belongs to the selected plugin. Inactive plugins and registrations available only in another website context can therefore have an empty list. Registration details are read on each visit, independently of the cached WordPress metadata and website usage rows.

The website list uses the rows of the last completed dashboard metrics run. It is paginated and sorted on the server: the sort buttons reload the page with `rrze_msm_<table>_sort` and `rrze_msm_<table>_order`, so the order applies to all websites and not only to the visible page. The rows are rebuilt on every request from the indexed site IDs and are not stored in the detail cache; the cache holds only the plugin metadata. Websites created afterwards are shown with their basic data until the next run; the page never measures website metrics itself.
