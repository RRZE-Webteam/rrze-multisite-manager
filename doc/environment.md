# Environment

## Purpose

The **Environment** page provides a technical inventory of the central WordPress multisite installation. It is visible only to super admins.

## Contents

The output groups configuration values from WordPress, PHP, and the network, including:

* WordPress, PHP, and database information
* active plugins, themes, and must-use plugins
* PHP limits for memory, uploads, and execution time
* network configuration such as registration, user creation, and allowed plugin activation
* storage per site and maximum upload file size
* scheduler and Cron information

The page is read-only. It changes no settings and starts no analysis.

## Implementation

`Dashboard::renderEnvironmentOverviewPage()` uses `EnvironmentMetricsService`. Network values are read with `get_site_option()`; site-specific values are not obtained through a network-wide loop when the page loads. The table is therefore a central-environment inventory even for large networks.

## Usage

Use Environment primarily for troubleshooting and aligning operational parameters. Configuration values controlled by WordPress or the server environment are not changed in Multisite Manager itself.
