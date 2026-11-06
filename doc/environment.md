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
* operational diagnostics for database locks, background processing and Cron (see below)

The page is read-only. It changes no settings and starts no analysis.

## Operational diagnostics

Three sections answer questions that can only be checked on the production system:

**Database and locking** is read live in the page request. It shows the server version, `DB_HOST`, the host name and server ID of the answering server, read-only state, Galera (`wsrep_on`, cluster size), an active `db.php` drop-in, whether two consecutive queries use the same connection ID, the persistent object cache, `max_connections`, `innodb_buffer_pool_size` and `information_schema_stats_expiry`. A named-lock check runs the same primitive as the background processes (`GET_LOCK`, ownership check via `IS_USED_LOCK = CONNECTION_ID()`, `RELEASE_LOCK`). The page also times the table count in `information_schema` and the per-website database size query that the dashboard collection runs once per website. Warnings are shown for a failed lock check, a Galera cluster with more than one node, changing connection IDs and an active database drop-in.

**Background processing** is written by the background runs themselves, because the external Cron script usually runs with a different PHP runtime (for example CLI with other `memory_limit` and `max_execution_time`) than the admin request that renders this page. `Support/WorkerRuntimeStats` stores the bounded network option `rrze_msm_worker_runtime_stats`: admitted runs and runs postponed because all execution slots were occupied, the highest number of simultaneously occupied slots, up to ten runtime contexts (host name, PHP SAPI, PHP version, limits, last run) and up to 40 hooks with run count, postponements, average and maximum duration and peak memory. Recording happens when a worker slot is released or refused; it uses its own non-blocking lock and drops a sample instead of waiting. The section also shows the configured worker limit and the slots occupied right now.

**Cron on the main site** shows `DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`, `WP_CRON_LOCK_TIMEOUT`, the number of scheduled events of the main site and how many are overdue by more than five minutes. Individual website analyses run in each website's own Cron table and are not counted here.

Interpretation: if no run was ever postponed and the highest parallelism stays at 1, the Cron script executes the heavy work serially and the worker limit never applies. Postponements show that the limit actively throttles. Duration and peak memory per hook support operational performance measurements. Keep actual measurements and infrastructure details in private operational records outside version control.

## Implementation

`Dashboard::renderEnvironmentOverviewPage()` uses `EnvironmentMetricsService`. Network values are read with `get_site_option()`; site-specific values are not obtained through a network-wide loop when the page loads. The table is therefore a central-environment inventory even for large networks.

## Usage

Use Environment primarily for troubleshooting and aligning operational parameters. Configuration values controlled by WordPress or the server environment are not changed in Multisite Manager itself.
