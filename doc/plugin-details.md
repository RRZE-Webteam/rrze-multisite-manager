# Plugin details

## Purpose

The **Plugin details** page shows metadata and the network usage of a selected plugin.

## Contents

In addition to name, version, description, author, and technical requirements, the page shows whether a plugin is network-active and which sites actively use it. It links to the respective site details.

Where WordPress provides the information, update information and appropriate management links are also displayed. Network-wide changes are available only to super admins.

When RRZE Netzwerk-Audit is network-active, the action area also contains its Enable or Disable action for manageable plugins. The action is shown only to super admins. Multisite Manager updates the shared `enabled_plugins` state through its own protected endpoint and redirects back to the same detail page.

## Source-code analysis

An optional detail analysis can collect source-code information for a plugin. It runs only on explicit request and is not part of regular dashboard metrics or a recurring full scan.

## Implementation

`Dashboard::renderPluginDetailsPage()` verifies the request with the nonce namespace `rrze_msm_source_analysis_plugin_<plugin-file>`. The detail cache uses the pattern `rrze_msm_plugin_details_<detail-version>_<hash>`; the hash contains the plugin file, analysis mode, and plugin fingerprint. Thus, a relevant file or mode change invalidates the detail cache without building a global source-code index.
