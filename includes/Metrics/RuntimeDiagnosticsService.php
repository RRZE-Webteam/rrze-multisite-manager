<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Support\AtomicLock;
use RRZE\MultisiteManager\Support\DatabaseLatencyProbe;
use RRZE\MultisiteManager\Support\ResponseTimeSampler;
use RRZE\MultisiteManager\Support\SiteHealthLinks;
use RRZE\MultisiteManager\Support\WorkerBudget;
use RRZE\MultisiteManager\Support\WorkerRuntimeStats;

defined('ABSPATH') || exit;

/**
 * Operational diagnostics for the Environment page.
 *
 * Database values are read live in the admin request. Background values are
 * written by the background processes themselves (see WorkerRuntimeStats),
 * because their PHP runtime usually differs from the admin request.
 */
class RuntimeDiagnosticsService {
    protected const CRON_OVERDUE_SECONDS = 300;
    protected const MYSQL_STATUS_NAMES = ['Uptime', 'Questions', 'Slow_queries', 'Threads_connected', 'Threads_running', 'Max_used_connections', 'Aborted_connects', 'Created_tmp_disk_tables'];
    protected const MYSQL_VARIABLE_NAMES = ['slow_query_log', 'long_query_time', 'log_queries_not_using_indexes', 'max_connections', 'wait_timeout'];

    protected Config $config;

    public function __construct(?Config $config = null) {
        $this->config = $config ?? new Config();
    }

    /**
     * @param array<string, array<string, mixed>> $dropins
     * @return array{sections: array<int, array<string, mixed>>, warnings: string[]}
     */
    public function collect(array $dropins): array {
        $warnings = [];
        $sections = [
            $this->getDatabaseSection($dropins, $warnings),
            $this->getDatabaseLoadSection(),
            $this->getBackgroundSection($warnings),
            $this->getFpmSection($warnings),
            $this->getResponseTimeSection(),
            $this->getDatabaseLatencySection(),
            $this->getCronSection($warnings),
        ];

        return ['sections' => $sections, 'warnings' => $warnings];
    }

    /**
     * @param array<string, array<string, mixed>> $dropins
     * @param string[] $warnings
     * @return array<string, mixed>
     */
    protected function getDatabaseSection(array $dropins, array &$warnings): array {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Live, read-only server diagnostics for super admins; must not be cached.
        $server = (array)$wpdb->get_row(
            'SELECT VERSION() AS version, @@version_comment AS comment, @@hostname AS host, @@server_id AS server_id, @@read_only AS read_only, @@max_connections AS max_connections, @@innodb_buffer_pool_size AS buffer_pool',
            ARRAY_A
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Live, read-only server diagnostics for super admins; must not be cached.
        $firstConnectionId = (string)$wpdb->get_var('SELECT CONNECTION_ID()');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Live, read-only server diagnostics for super admins; must not be cached.
        $secondConnectionId = (string)$wpdb->get_var('SELECT CONNECTION_ID()');
        $wsrepOn = $this->getServerVariable('SHOW VARIABLES LIKE %s', 'wsrep_on');
        $clusterSize = $wsrepOn !== '' ? $this->getServerVariable('SHOW GLOBAL STATUS LIKE %s', 'wsrep_cluster_size') : '';
        $statsExpiry = $this->getServerVariable('SHOW VARIABLES LIKE %s', 'information_schema_stats_expiry');
        $tableTiming = $this->timeQuery('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $siteTiming = $this->timeSiteSizeQuery();
        $lockCheck = $this->checkNamedLocks();
        $wpdb->suppress_errors($suppressed);

        $dbDropin = (string)($dropins['db.php']['Name'] ?? '');
        $objectCacheDropin = (string)($dropins['object-cache.php']['Name'] ?? '');
        $galeraActive = strtoupper($wsrepOn) === 'ON';
        $connectionStable = $firstConnectionId !== '' && $firstConnectionId === $secondConnectionId;

        if (!$lockCheck['ok']) {
            $warnings[] = sprintf(
                /* translators: %s: failed step of the named lock check. */
                __('The database lock check failed (%s). Background analyses cannot obtain execution slots and stay idle.', 'rrze-multisite-manager'),
                $lockCheck['message']
            );
        }

        if ($galeraActive && (int)$clusterSize > 1) {
            $warnings[] = __('The database is a Galera cluster. Named locks only apply to one cluster node: all web and Cron servers must write to the same node, otherwise the worker limit and process locks are not reliable.', 'rrze-multisite-manager');
        }

        if (!$connectionStable) {
            $warnings[] = __('Two consecutive queries reported different database connection IDs. A connection-multiplexing proxy breaks connection-bound locks.', 'rrze-multisite-manager');
        }

        $primaryRouting = AtomicLock::supportsPrimaryRouting();
        $dropinServers = $this->getDropinServerSummary();

        if ($dbDropin !== '' && !$primaryRouting && $dropinServers['replicas']) {
            $warnings[] = __('The database drop-in sends reads to replica servers, and lock statements cannot be forced to the primary server. Named locks may then be set on different servers and stop excluding each other.', 'rrze-multisite-manager');
        } elseif ($dbDropin !== '' && !$primaryRouting) {
            $warnings[] = sprintf(
                /* translators: %s: name of the db.php drop-in. */
                __('A database drop-in is active (%s). Make sure that lock statements (GET_LOCK, IS_USED_LOCK, RELEASE_LOCK) are sent to the primary server on one connection.', 'rrze-multisite-manager'),
                $dbDropin
            );
        }

        return [
            'title' => __('Database and locking', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Database server', 'rrze-multisite-manager'), 'value' => trim((string)($server['version'] ?? '') . ' ' . (string)($server['comment'] ?? ''))],
                ['label' => 'DB_HOST', 'value' => defined('DB_HOST') ? (string)DB_HOST : '', 'code' => true],
                [
                    'label' => __('Answering server (host / server ID)', 'rrze-multisite-manager'),
                    'value' => (string)($server['host'] ?? '') . ' / ' . (string)($server['server_id'] ?? ''),
                    'code' => true,
                ],
                ['label' => __('Read-only server', 'rrze-multisite-manager'), 'value' => $this->yesNo((int)($server['read_only'] ?? 0) === 1)],
                [
                    'label' => __('Galera cluster', 'rrze-multisite-manager'),
                    'value' => $wsrepOn === ''
                        ? __('Not present', 'rrze-multisite-manager')
                        : sprintf(
                            /* translators: 1: wsrep_on value, 2: number of cluster nodes. */
                            __('wsrep_on: %1$s, cluster nodes: %2$s', 'rrze-multisite-manager'),
                            $wsrepOn,
                            $clusterSize !== '' ? $clusterSize : '?'
                        ),
                ],
                ['label' => __('Database drop-in (db.php)', 'rrze-multisite-manager'), 'value' => $dbDropin !== '' ? $dbDropin : __('None', 'rrze-multisite-manager')],
                [
                    'label' => __('Database servers of the drop-in', 'rrze-multisite-manager'),
                    'value' => $dropinServers['text'] !== '' ? $dropinServers['text'] : __('Not reported by the database class', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Routing of lock statements', 'rrze-multisite-manager'),
                    'value' => $primaryRouting
                        ? __('Forced to the primary server for each lock statement (send-reads-to-masters flag of the drop-in)', 'rrze-multisite-manager')
                        : ($dbDropin !== ''
                            ? __('Like other reads; the drop-in offers no primary routing', 'rrze-multisite-manager')
                            : __('Single WordPress database connection', 'rrze-multisite-manager')),
                ],
                ['label' => __('Stable connection ID', 'rrze-multisite-manager'), 'value' => $this->yesNo($connectionStable) . ' (' . $firstConnectionId . ' / ' . $secondConnectionId . ')'],
                ['label' => __('Named lock check', 'rrze-multisite-manager'), 'value' => $lockCheck['message']],
                [
                    'label' => __('Persistent object cache', 'rrze-multisite-manager'),
                    'value' => wp_using_ext_object_cache()
                        ? ($objectCacheDropin !== '' ? $objectCacheDropin : __('Yes', 'rrze-multisite-manager'))
                        : __('No', 'rrze-multisite-manager'),
                ],
                ['label' => 'max_connections', 'value' => (string)($server['max_connections'] ?? '')],
                [
                    'label' => 'innodb_buffer_pool_size',
                    'value' => is_numeric($server['buffer_pool'] ?? null) ? size_format((int)$server['buffer_pool'], 1) : '',
                ],
                ['label' => 'information_schema_stats_expiry', 'value' => $statsExpiry !== '' ? $statsExpiry : __('Not present', 'rrze-multisite-manager')],
                [
                    'label' => __('Tables in the database', 'rrze-multisite-manager'),
                    'value' => sprintf(
                        /* translators: 1: number of tables, 2: query duration in milliseconds. */
                        __('%1$s (counted in %2$s ms)', 'rrze-multisite-manager'),
                        number_format_i18n((int)$tableTiming['value']),
                        number_format_i18n($tableTiming['ms'], 1)
                    ),
                ],
                [
                    'label' => __('Database size query for one website', 'rrze-multisite-manager'),
                    'value' => $siteTiming['site_id'] > 0
                        ? sprintf(
                            /* translators: 1: query duration in milliseconds, 2: website ID. */
                            __('%1$s ms (website ID %2$d; the dashboard collection runs this query once per website)', 'rrze-multisite-manager'),
                            number_format_i18n($siteTiming['ms'], 1),
                            $siteTiming['site_id']
                        )
                        : __('Not available', 'rrze-multisite-manager'),
                ],
            ],
            'site_health_links' => SiteHealthLinks::getLinks([
                'wp-database' => __('Database', 'rrze-multisite-manager'),
                'wp-dropins' => __('Drop-ins', 'rrze-multisite-manager'),
            ]),
        ];
    }

    /**
     * @param string[] $warnings
     * @return array<string, mixed>
     */
    protected function getBackgroundSection(array &$warnings): array {
        $stats = WorkerRuntimeStats::get();
        $limit = $this->config->getMaxConcurrentWorkers();
        $rows = [
            ['label' => __('Configured worker limit', 'rrze-multisite-manager'), 'value' => (string)$limit],
            ['label' => __('Execution slots in use now', 'rrze-multisite-manager'), 'value' => (string)WorkerBudget::countOccupiedSlots($limit)],
            [
                'label' => __('Recorded since', 'rrze-multisite-manager'),
                'value' => $stats['since'] > 0 ? wp_date('d.m.Y H:i', $stats['since']) : __('No background run recorded yet', 'rrze-multisite-manager'),
            ],
            [
                'label' => __('Admitted / postponed heavy runs', 'rrze-multisite-manager'),
                'value' => sprintf(
                    /* translators: 1: admitted runs, 2: runs postponed because all slots were occupied. */
                    __('%1$s admitted, %2$s postponed because all slots were occupied', 'rrze-multisite-manager'),
                    number_format_i18n($stats['admissions']),
                    number_format_i18n($stats['denials'])
                ),
            ],
            [
                'label' => __('Highest observed parallelism', 'rrze-multisite-manager'),
                'value' => $stats['max_concurrent'] > 0
                    ? sprintf(
                        /* translators: 1: number of concurrent heavy runs, 2: date and time. */
                        __('%1$d at the same time (%2$s)', 'rrze-multisite-manager'),
                        $stats['max_concurrent'],
                        wp_date('d.m.Y H:i', $stats['max_concurrent_at'])
                    )
                    : __('Not recorded yet', 'rrze-multisite-manager'),
            ],
        ];
        $context = [];
        $hook = '';
        $hookStats = [];
        $runs = 0;

        foreach ($stats['contexts'] as $context) {
            $rows[] = [
                'label' => sprintf(
                    /* translators: 1: host name, 2: PHP SAPI. */
                    __('Runtime %1$s (%2$s)', 'rrze-multisite-manager'),
                    (string)($context['host'] ?? ''),
                    (string)($context['sapi'] ?? '')
                ),
                'value' => sprintf(
                    /* translators: 1: PHP version, 2: memory_limit, 3: max_execution_time, 4: run count, 5: last seen date and time. */
                    __('PHP %1$s, memory_limit %2$s, max_execution_time %3$s, %4$s runs, last %5$s', 'rrze-multisite-manager'),
                    (string)($context['php'] ?? ''),
                    (string)($context['memory_limit'] ?? ''),
                    (string)($context['max_execution_time'] ?? ''),
                    number_format_i18n((int)($context['runs'] ?? 0)),
                    wp_date('d.m.Y H:i', (int)($context['last_seen'] ?? 0))
                ),
            ];
        }

        ksort($stats['hooks']);

        foreach ($stats['hooks'] as $hook => $hookStats) {
            $runs = max(0, (int)($hookStats['runs'] ?? 0));
            $rows[] = [
                'label' => (string)$hook,
                'code' => true,
                'value' => sprintf(
                    /* translators: 1: run count, 2: postponed count, 3: average seconds, 4: maximum seconds, 5: peak memory. */
                    __('%1$s runs, %2$s postponed, average %3$s s, maximum %4$s s, peak memory %5$s', 'rrze-multisite-manager'),
                    number_format_i18n($runs),
                    number_format_i18n((int)($hookStats['denials'] ?? 0)),
                    number_format_i18n($runs > 0 ? (float)($hookStats['total_seconds'] ?? 0) / $runs : 0, 1),
                    number_format_i18n((float)($hookStats['max_seconds'] ?? 0), 1),
                    size_format((int)($hookStats['max_memory'] ?? 0), 0)
                ),
            ];
        }

        if (count($stats['contexts']) > 1) {
            $warnings[] = __('Background work was executed on more than one host or PHP runtime. Verify that all of them use the same database server for locks.', 'rrze-multisite-manager');
        }

        return [
            'title' => __('Background processing (recorded by the background runs)', 'rrze-multisite-manager'),
            'rows' => $rows,
        ];
    }

    /**
     * @param string[] $warnings
     * @return array<string, mixed>
     */
    protected function getCronSection(array &$warnings): array {
        $mainSiteId = (int)get_main_site_id(get_current_network_id());
        $switched = get_current_blog_id() !== $mainSiteId;
        $events = [];
        $now = time();
        $total = 0;
        $overdue = 0;
        $oldestOverdue = 0;
        $timestamp = 0;
        $hooks = [];
        $instances = [];

        if ($switched) {
            switch_to_blog($mainSiteId);
        }

        try {
            $events = function_exists('_get_cron_array') ? (array)_get_cron_array() : [];
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }

        foreach ($events as $timestamp => $hooks) {
            foreach ((array)$hooks as $instances) {
                $total += count((array)$instances);

                if ((int)$timestamp < $now - self::CRON_OVERDUE_SECONDS) {
                    $overdue += count((array)$instances);
                    $oldestOverdue = max($oldestOverdue, $now - (int)$timestamp);
                }
            }
        }

        if ($oldestOverdue >= HOUR_IN_SECONDS) {
            $warnings[] = sprintf(
                /* translators: %s: human-readable time span. */
                __('Scheduled events on the main site are overdue by up to %s. Check whether the Cron script still runs and how often it is started.', 'rrze-multisite-manager'),
                human_time_diff($now - $oldestOverdue, $now)
            );
        }

        return [
            'title' => __('Cron on the main site', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => 'DISABLE_WP_CRON', 'value' => $this->yesNo(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)],
                ['label' => 'ALTERNATE_WP_CRON', 'value' => $this->yesNo(defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON)],
                ['label' => 'WP_CRON_LOCK_TIMEOUT', 'value' => defined('WP_CRON_LOCK_TIMEOUT') ? (string)WP_CRON_LOCK_TIMEOUT : __('Default (60 s)', 'rrze-multisite-manager')],
                ['label' => __('Scheduled events', 'rrze-multisite-manager'), 'value' => number_format_i18n($total)],
                [
                    'label' => __('Overdue events (more than 5 minutes)', 'rrze-multisite-manager'),
                    'value' => $overdue > 0
                        ? sprintf(
                            /* translators: 1: number of overdue events, 2: human-readable time span. */
                            __('%1$s, oldest overdue by %2$s', 'rrze-multisite-manager'),
                            number_format_i18n($overdue),
                            human_time_diff($now - $oldestOverdue, $now)
                        )
                        : '0',
                ],
            ],
        ];
    }

    /**
     * Read and write servers configured in HyperDB or LudicrousDB
     * ($wpdb->hyper_servers / $wpdb->ludicrous_servers). Only host names are
     * read; credentials are never accessed.
     *
     * @return array{text: string, replicas: bool}
     */
    protected function getDropinServerSummary(): array {
        global $wpdb;
        $servers = [];
        $parts = [];
        $replicas = false;
        $dataset = '';
        $operations = [];
        $hosts = [];
        $readHosts = [];
        $writeHosts = [];
        $replicaHosts = [];

        foreach (['hyper_servers', 'ludicrous_servers'] as $property) {
            if (is_object($wpdb) && isset($wpdb->$property) && is_array($wpdb->$property)) {
                $servers = $wpdb->$property;
                break;
            }
        }

        foreach ($servers as $dataset => $operations) {
            $hosts = ['read' => [], 'write' => []];

            foreach (['read', 'write'] as $operation) {
                $hosts[$operation] = $this->collectDropinHosts((array)($operations[$operation] ?? []));
            }

            $readHosts = $hosts['read'];
            $writeHosts = $hosts['write'];
            $replicaHosts = array_values(array_diff($readHosts, $writeHosts));
            $replicas = $replicas || !empty($replicaHosts);
            $parts[] = sprintf(
                /* translators: 1: dataset name, 2: writing hosts, 3: reading hosts, 4: read-only replica hosts. */
                __('%1$s: writing %2$s; reading %3$s; replicas %4$s', 'rrze-multisite-manager'),
                (string)$dataset,
                $writeHosts ? implode(', ', $writeHosts) : '-',
                $readHosts ? implode(', ', $readHosts) : '-',
                $replicaHosts ? implode(', ', $replicaHosts) : __('none', 'rrze-multisite-manager')
            );
        }

        return ['text' => implode(' | ', $parts), 'replicas' => $replicas];
    }

    /**
     * @param array<mixed> $groups
     * @return string[]
     */
    protected function collectDropinHosts(array $groups): array {
        $hosts = [];
        $group = [];
        $server = [];
        $host = '';

        foreach ($groups as $group) {
            foreach ((array)$group as $server) {
                $host = is_array($server) ? (string)($server['host'] ?? ($server['dbhost'] ?? '')) : '';

                if ($host !== '') {
                    $hosts[$host] = $host;
                }
            }
        }

        return array_values($hosts);
    }

    /**
     * Raw values for the JSON export on the Environment page.
     *
     * @return array<string, mixed>
     */
    public function collectRaw(): array {
        return [
            'mysql_status' => $this->getServerValues('SHOW GLOBAL STATUS', self::MYSQL_STATUS_NAMES),
            'mysql_variables' => $this->getServerValues('SHOW VARIABLES', self::MYSQL_VARIABLE_NAMES),
            'php_fpm' => function_exists('fpm_get_status') ? $this->getFpmStatusWithoutProcesses() : null,
            'worker_runtime_stats' => WorkerRuntimeStats::get(),
            'occupied_execution_slots' => WorkerBudget::countOccupiedSlots($this->config->getMaxConcurrentWorkers()),
            'lock_primary_routing' => AtomicLock::supportsPrimaryRouting(),
            'dropin_servers' => $this->getDropinServerSummary(),
            'response_sampling' => ResponseTimeSampler::getState(),
            'response_time_stats' => ResponseTimeSampler::getStats(),
            'database_latency_probes' => DatabaseLatencyProbe::getRuns(),
        ];
    }

    /**
     * Cumulative server counters since the last database restart. Comparing
     * two exports taken before and after an analysis period shows the load
     * caused in between (for example the growth of Slow_queries).
     *
     * @return array<string, mixed>
     */
    protected function getDatabaseLoadSection(): array {
        $status = $this->getServerValues('SHOW GLOBAL STATUS', self::MYSQL_STATUS_NAMES);
        $variables = $this->getServerValues('SHOW VARIABLES', self::MYSQL_VARIABLE_NAMES);
        $uptime = max(0, (int)($status['Uptime'] ?? 0));
        $rows = [];

        $rows[] = [
            'label' => __('Slow query log', 'rrze-multisite-manager'),
            'value' => sprintf(
                /* translators: 1: slow_query_log value, 2: long_query_time in seconds, 3: log_queries_not_using_indexes value. */
                __('slow_query_log: %1$s, long_query_time: %2$s s, log_queries_not_using_indexes: %3$s', 'rrze-multisite-manager'),
                (string)($variables['slow_query_log'] ?? '?'),
                (string)($variables['long_query_time'] ?? '?'),
                (string)($variables['log_queries_not_using_indexes'] ?? '?')
            ),
        ];
        $rows[] = [
            'label' => __('Server running for', 'rrze-multisite-manager'),
            'value' => $uptime > 0 ? human_time_diff(time() - $uptime, time()) : __('Not available', 'rrze-multisite-manager'),
        ];
        $rows[] = [
            'label' => __('Queries / slow queries since server start', 'rrze-multisite-manager'),
            'value' => sprintf(
                /* translators: 1: number of queries, 2: average queries per second, 3: number of slow queries. */
                __('%1$s queries (%2$s per second), %3$s slow queries', 'rrze-multisite-manager'),
                number_format_i18n((int)($status['Questions'] ?? 0)),
                number_format_i18n($uptime > 0 ? (int)($status['Questions'] ?? 0) / $uptime : 0, 1),
                number_format_i18n((int)($status['Slow_queries'] ?? 0))
            ),
        ];
        $rows[] = [
            'label' => __('Database connections', 'rrze-multisite-manager'),
            'value' => sprintf(
                /* translators: 1: open connections, 2: connections executing a query, 3: highest number of connections since start, 4: max_connections. */
                __('%1$s open, %2$s running now, highest since start %3$s of %4$s', 'rrze-multisite-manager'),
                number_format_i18n((int)($status['Threads_connected'] ?? 0)),
                number_format_i18n((int)($status['Threads_running'] ?? 0)),
                number_format_i18n((int)($status['Max_used_connections'] ?? 0)),
                number_format_i18n((int)($variables['max_connections'] ?? 0))
            ),
        ];
        $rows[] = [
            'label' => __('Temporary tables on disk / refused connections', 'rrze-multisite-manager'),
            'value' => sprintf(
                /* translators: 1: Created_tmp_disk_tables, 2: Aborted_connects. */
                __('%1$s temporary tables on disk, %2$s refused connection attempts', 'rrze-multisite-manager'),
                number_format_i18n((int)($status['Created_tmp_disk_tables'] ?? 0)),
                number_format_i18n((int)($status['Aborted_connects'] ?? 0))
            ),
        ];

        return [
            'title' => __('Database load (counters since server start)', 'rrze-multisite-manager'),
            'rows' => $rows,
        ];
    }

    /**
     * State of the PHP-FPM pool that serves this admin request. The
     * external Cron script runs in the CLI and is not part of this pool.
     *
     * @param string[] $warnings
     * @return array<string, mixed>
     */
    protected function getFpmSection(array &$warnings): array {
        $status = function_exists('fpm_get_status') ? $this->getFpmStatusWithoutProcesses() : [];

        if (empty($status)) {
            return [
                'title' => __('PHP-FPM pool of the web server', 'rrze-multisite-manager'),
                'rows' => [
                    [
                        'label' => __('Status', 'rrze-multisite-manager'),
                        'value' => sprintf(
                            /* translators: %s: PHP SAPI name. */
                            __('Not available: this request runs as %s, not under PHP-FPM.', 'rrze-multisite-manager'),
                            PHP_SAPI
                        ),
                    ],
                ],
            ];
        }

        if ((int)($status['max-children-reached'] ?? 0) > 0) {
            $warnings[] = sprintf(
                /* translators: %d: how often the pool reached pm.max_children. */
                __('The PHP-FPM pool reached its process limit (pm.max_children) %d times since its start. Requests then wait in the listen queue.', 'rrze-multisite-manager'),
                (int)$status['max-children-reached']
            );
        }

        return [
            'title' => __('PHP-FPM pool of the web server', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Pool / process manager', 'rrze-multisite-manager'), 'value' => (string)($status['pool'] ?? '') . ' / ' . (string)($status['process-manager'] ?? ''), 'code' => true],
                [
                    'label' => __('Running since', 'rrze-multisite-manager'),
                    'value' => (int)($status['start-time'] ?? 0) > 0 ? wp_date('d.m.Y H:i', (int)$status['start-time']) : '',
                ],
                [
                    'label' => __('Processes now', 'rrze-multisite-manager'),
                    'value' => sprintf(
                        /* translators: 1: active processes, 2: idle processes, 3: total processes. */
                        __('%1$s active, %2$s idle, %3$s in total', 'rrze-multisite-manager'),
                        number_format_i18n((int)($status['active-processes'] ?? 0)),
                        number_format_i18n((int)($status['idle-processes'] ?? 0)),
                        number_format_i18n((int)($status['total-processes'] ?? 0))
                    ),
                ],
                [
                    'label' => __('Highest number of active processes', 'rrze-multisite-manager'),
                    'value' => number_format_i18n((int)($status['max-active-processes'] ?? 0)),
                ],
                [
                    'label' => __('Process limit reached (pm.max_children)', 'rrze-multisite-manager'),
                    'value' => number_format_i18n((int)($status['max-children-reached'] ?? 0)),
                ],
                [
                    'label' => __('Listen queue now / highest', 'rrze-multisite-manager'),
                    'value' => number_format_i18n((int)($status['listen-queue'] ?? 0)) . ' / ' . number_format_i18n((int)($status['max-listen-queue'] ?? 0)),
                ],
                [
                    'label' => __('Slow requests (request_slowlog_timeout)', 'rrze-multisite-manager'),
                    'value' => number_format_i18n((int)($status['slow-requests'] ?? 0)),
                ],
                ['label' => __('Accepted connections', 'rrze-multisite-manager'), 'value' => number_format_i18n((int)($status['accepted-conn'] ?? 0))],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function getFpmStatusWithoutProcesses(): array {
        $status = fpm_get_status();

        if (!is_array($status)) {
            return [];
        }

        // The per-process list can be long and contains request URIs.
        unset($status['procs']);

        return $status;
    }

    /**
     * Results of the manual round-trip measurement, newest first. Each row
     * names the web node that answered, so nodes can be compared.
     *
     * @return array<string, mixed>
     */
    protected function getDatabaseLatencySection(): array {
        $rows = [];
        $run = [];

        foreach (DatabaseLatencyProbe::getRuns() as $run) {
            $rows[] = [
                'label' => sprintf(
                    /* translators: 1: date and time, 2: host name of the web node, 3: PHP SAPI. */
                    __('%1$s, %2$s (%3$s)', 'rrze-multisite-manager'),
                    wp_date('d.m.Y H:i:s', (int)($run['t'] ?? 0)),
                    (string)($run['host'] ?? ''),
                    (string)($run['sapi'] ?? '')
                ),
                'value' => sprintf(
                    /* translators: 1: median, 2: mean, 3: minimum, 4: maximum (all in milliseconds), 5: number of successful queries, 6: number of failed queries. */
                    __('Median %1$s ms, mean %2$s ms, minimum %3$s ms, maximum %4$s ms (%5$d queries, %6$d failed)', 'rrze-multisite-manager'),
                    number_format_i18n((float)($run['median_ms'] ?? 0), 2),
                    number_format_i18n((float)($run['avg_ms'] ?? 0), 2),
                    number_format_i18n((float)($run['min_ms'] ?? 0), 2),
                    number_format_i18n((float)($run['max_ms'] ?? 0), 2),
                    (int)($run['count'] ?? 0),
                    (int)($run['failed'] ?? 0)
                ),
            ];
        }

        if (empty($rows)) {
            $rows[] = [
                'label' => __('Measurements', 'rrze-multisite-manager'),
                'value' => __('No measurement yet. Start one with the button under "Measurement during operation".', 'rrze-multisite-manager'),
            ];
        }

        return [
            'title' => __('Database round trip (SELECT 1)', 'rrze-multisite-manager'),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    protected function getResponseTimeSection(): array {
        $state = ResponseTimeSampler::getState();
        $stats = ResponseTimeSampler::getStats();
        $categoryLabels = [
            'editor_load' => __('Open editor', 'rrze-multisite-manager'),
            'save' => __('Save', 'rrze-multisite-manager'),
            'autosave' => __('Autosave', 'rrze-multisite-manager'),
            'upload' => __('Media upload', 'rrze-multisite-manager'),
        ];
        $stateLabels = [
            'idle' => __('without running analysis', 'rrze-multisite-manager'),
            'during_analysis' => __('during an analysis', 'rrze-multisite-manager'),
        ];
        $rows = [];
        $category = '';
        $categoryLabel = '';
        $stateKey = '';
        $stateLabel = '';
        $entry = [];
        $count = 0;
        $bound = 0;

        $rows[] = [
            'label' => __('Sampling', 'rrze-multisite-manager'),
            'value' => $state['active']
                ? sprintf(
                    /* translators: 1: start date and time, 2: end date and time. */
                    __('Active since %1$s until %2$s', 'rrze-multisite-manager'),
                    wp_date('d.m.Y H:i', $state['started']),
                    wp_date('d.m.Y H:i', $state['until'])
                )
                : __('Not active', 'rrze-multisite-manager'),
        ];
        $rows[] = [
            'label' => __('Recorded since', 'rrze-multisite-manager'),
            'value' => $stats['since'] > 0 ? wp_date('d.m.Y H:i', $stats['since']) : __('No samples yet', 'rrze-multisite-manager'),
        ];

        foreach ($categoryLabels as $category => $categoryLabel) {
            foreach ($stateLabels as $stateKey => $stateLabel) {
                $entry = is_array($stats['categories'][$category][$stateKey] ?? null) ? $stats['categories'][$category][$stateKey] : [];
                $count = max(0, (int)($entry['count'] ?? 0));

                if ($count === 0) {
                    continue;
                }

                $bound = ResponseTimeSampler::getPercentileBound(array_map('intval', (array)($entry['buckets'] ?? [])), 0.95);
                $rows[] = [
                    'label' => $categoryLabel . ' – ' . $stateLabel,
                    'value' => sprintf(
                        /* translators: 1: number of requests, 2: average milliseconds, 3: 95th percentile statement, 4: maximum milliseconds, 5: average database queries. */
                        __('%1$s requests, average %2$s ms, %3$s, maximum %4$s ms, average %5$s database queries', 'rrze-multisite-manager'),
                        number_format_i18n($count),
                        number_format_i18n((float)($entry['total_ms'] ?? 0) / $count, 0),
                        $bound > 0
                            ? sprintf(
                                /* translators: %s: bucket bound in milliseconds. */
                                __('95 %% below %s ms', 'rrze-multisite-manager'),
                                number_format_i18n($bound)
                            )
                            : sprintf(
                                /* translators: %s: largest bucket bound in milliseconds. */
                                __('more than 5 %% above %s ms', 'rrze-multisite-manager'),
                                number_format_i18n((int)max(ResponseTimeSampler::BUCKETS_MS))
                            ),
                        number_format_i18n((float)($entry['max_ms'] ?? 0), 0),
                        number_format_i18n((int)($entry['total_queries'] ?? 0) / $count, 0)
                    ),
                ];
            }
        }

        return [
            'title' => __('Response times of editorial requests', 'rrze-multisite-manager'),
            'rows' => $rows,
        ];
    }

    /**
     * Reads selected server status values or variables in one query.
     *
     * @param string[] $names
     * @return array<string, string>
     */
    protected function getServerValues(string $statement, array $names): array {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $values = [];
        $row = [];
        $suppressed = $wpdb->suppress_errors(true);

        if (!in_array($statement, ['SHOW GLOBAL STATUS', 'SHOW VARIABLES'], true)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed SHOW statement from the allowlist above; names are prepared.
        $rows = $wpdb->get_results($wpdb->prepare($statement . " WHERE Variable_name IN ($placeholders)", ...$names), ARRAY_A);
        $wpdb->suppress_errors($suppressed);

        foreach ((array)$rows as $row) {
            if (is_array($row) && isset($row['Variable_name'])) {
                $values[(string)$row['Variable_name']] = (string)($row['Value'] ?? '');
            }
        }

        return $values;
    }

    protected function getServerVariable(string $query, string $name): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Constant SHOW statement from this class; the name is prepared.
        $row = $wpdb->get_row($wpdb->prepare($query, $name), ARRAY_A);

        return is_array($row) ? (string)($row['Value'] ?? '') : '';
    }

    /** @return array{value: mixed, ms: float} */
    protected function timeQuery(string $query): array {
        global $wpdb;

        $start = microtime(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Constant diagnostic query from this class.
        $value = $wpdb->get_var($query);

        return ['value' => $value, 'ms' => (microtime(true) - $start) * 1000];
    }

    /**
     * Times the same kind of information_schema query that the dashboard
     * collection runs for every website.
     *
     * @return array{site_id: int, ms: float}
     */
    protected function timeSiteSizeQuery(): array {
        global $wpdb;

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 1,
            'site__not_in' => [(int)get_main_site_id(get_current_network_id())],
            'orderby' => 'id',
            'order' => 'DESC',
        ]);
        $siteId = (int)($siteIds[0] ?? 0);
        $start = 0.0;

        if ($siteId <= 0) {
            return ['site_id' => 0, 'ms' => 0.0];
        }

        $start = microtime(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Diagnostic timing of the per-website size query.
        $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
            $wpdb->esc_like($wpdb->get_blog_prefix($siteId)) . '%'
        ));

        return ['site_id' => $siteId, 'ms' => (microtime(true) - $start) * 1000];
    }

    /**
     * Runs the same lock primitive the background processes use: acquire,
     * verify ownership on this connection, release.
     *
     * @return array{ok: bool, message: string}
     */
    protected function checkNamedLocks(): array {
        $key = 'environment_lock_check_' . wp_generate_uuid4();
        $token = AtomicLock::acquire($key);

        if ($token === '') {
            return ['ok' => false, 'message' => __('GET_LOCK was refused or a second simultaneous lock is not supported', 'rrze-multisite-manager')];
        }

        if (!AtomicLock::owns($key, $token)) {
            AtomicLock::release($key, $token);

            return ['ok' => false, 'message' => __('the lock was not visible as owned by this connection', 'rrze-multisite-manager')];
        }

        if (!AtomicLock::release($key, $token)) {
            return ['ok' => false, 'message' => __('RELEASE_LOCK did not release the lock', 'rrze-multisite-manager')];
        }

        return ['ok' => true, 'message' => __('Works (acquired, verified on the same connection, released)', 'rrze-multisite-manager')];
    }

    protected function yesNo(bool $value): string {
        return $value ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager');
    }
}
