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

These sections answer questions that can only be checked on the production system:

**Database and locking** is read live in the page request. It shows the server version, `DB_HOST`, the host name and server ID of the answering server, read-only state, Galera (`wsrep_on`, cluster size), an active `db.php` drop-in, whether two consecutive queries use the same connection ID, the persistent object cache, `max_connections`, `innodb_buffer_pool_size` and `information_schema_stats_expiry`. A named-lock check runs the same primitive as the background processes (`GET_LOCK`, ownership check via `IS_USED_LOCK = CONNECTION_ID()`, `RELEASE_LOCK`). The page also times the table count in `information_schema` and the per-website database size query that the dashboard collection runs once per website. Warnings are shown for a failed lock check, a Galera cluster with more than one node, changing connection IDs and an active database drop-in.

**Background processing** is written by the background runs themselves, because the external Cron script usually runs with a different PHP runtime (for example CLI with other `memory_limit` and `max_execution_time`) than the admin request that renders this page. `Support/WorkerRuntimeStats` stores the bounded network option `rrze_msm_worker_runtime_stats`: admitted runs and runs postponed because all execution slots were occupied, the highest number of simultaneously occupied slots, up to ten runtime contexts (host name, PHP SAPI, PHP version, limits, last run) and up to 40 hooks with run count, postponements, average and maximum duration and peak memory. Recording happens when a worker slot is released or refused; it uses its own non-blocking lock and drops a sample instead of waiting. The section also shows the configured worker limit and the slots occupied right now.

**Cron on the main site** shows `DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`, `WP_CRON_LOCK_TIMEOUT`, the number of scheduled events of the main site and how many are overdue by more than five minutes. Individual website analyses run in each website's own Cron table and are not counted here.

Interpretation: if no run was ever postponed and the highest parallelism stays at 1, the Cron script executes the heavy work serially and the worker limit never applies. Postponements show that the limit actively throttles. Duration and peak memory per hook support operational performance measurements. Keep actual measurements and infrastructure details in private operational records outside version control.

**Lock routing with database drop-ins.** HyperDB and LudicrousDB send `SELECT` statements to read servers. Named-lock statements (`GET_LOCK`, `IS_USED_LOCK`, `RELEASE_LOCK`) are `SELECT` statements but only work on one server and one connection. `Support/AtomicLock::query()` therefore sets the drop-in's send-reads-to-masters flag (`$wpdb->srtm`) for each lock statement and restores it afterwards; worker slots, process locks and the schema and upgrade locks of `AnalysisLifecycle` all use it. The section **Database and locking** shows the read and write servers configured in the drop-in (host names only) and how lock statements are routed. A warning appears only when replicas exist and the drop-in offers no such flag. Note that the other values of that section (answering host, connection ID) are ordinary reads and may come from a replica.

## Measurement during operation

The following sections support the measurement of the effect of background analyses on editorial work (Todo, item 3). They complement, but do not replace, the server's own slow query log and PHP-FPM status page.

**Database load** (`SHOW GLOBAL STATUS` / `SHOW VARIABLES`, read live): `slow_query_log`, `long_query_time`, `log_queries_not_using_indexes`, server uptime, `Questions` and `Slow_queries` since the server start, open and running connections (`Threads_connected`, `Threads_running`), `Max_used_connections` against `max_connections`, `Created_tmp_disk_tables` and `Aborted_connects`. These are cumulative counters: comparing two downloads taken before and after an analysis period shows the change in between.

**PHP-FPM pool of the web server** (`fpm_get_status()`, read live when the page runs under PHP-FPM): pool, process manager, active/idle/total processes, highest number of active processes, how often `pm.max_children` was reached, current and highest listen queue, slow requests and accepted connections. The per-process list is not shown or exported because it contains request URIs. The external Cron script runs in the CLI and is not part of this pool.

**Response times of editorial requests** (`Support/ResponseTimeSampler`): off by default, started on this page for 1, 3 or 7 days and stopped automatically (`rrze_msm_response_sampling`). While active, the following requests of posts and pages are measured on `shutdown` from `REQUEST_TIME_FLOAT`: opening the editor (`post.php`/`post-new.php`), saving (REST `POST/PUT/PATCH /wp/v2/posts|pages[/<id>]`, classic `post.php` POST), autosaving (`…/autosaves`) and media uploads (REST `POST /wp/v2/media`, `async-upload.php`, `media-new.php`). Each sample records the duration, the number of database queries, peak memory, the occupied analysis execution slots, the PHP-FPM pool state and `Threads_running`. Aggregation in `rrze_msm_response_time_stats` is split by request type and by whether at least one analysis slot was occupied: count, average, maximum, average queries and a histogram (bounds 250/500/1,000/2,000/5,000 ms) from which the 95 % bound is shown; the last 100 samples are kept for the download. The duration is measured before the additional diagnostic queries. Other requests do not read the sampling state. Writing uses a non-blocking lock and drops a sample instead of waiting.

**Download all values (JSON)** exports every section of the page, the warnings and the raw values (database status and variables, PHP-FPM status without process list, worker statistics, occupied slots, sampling state and response-time statistics) for later analysis.

## Implementation

`Dashboard::renderEnvironmentOverviewPage()` uses `EnvironmentMetricsService`. Network values are read with `get_site_option()`; site-specific values are not obtained through a network-wide loop when the page loads. The table is therefore a central-environment inventory even for large networks.

## Usage

Use Environment primarily for troubleshooting and aligning operational parameters. Configuration values controlled by WordPress or the server environment are not changed in Multisite Manager itself.
