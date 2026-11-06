<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Support\AtomicLock;
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
            $this->getBackgroundSection($warnings),
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
        $server = (array)$wpdb->get_row(
            'SELECT VERSION() AS version, @@version_comment AS comment, @@hostname AS host, @@server_id AS server_id, @@read_only AS read_only, @@max_connections AS max_connections, @@innodb_buffer_pool_size AS buffer_pool',
            ARRAY_A
        );
        $firstConnectionId = (string)$wpdb->get_var('SELECT CONNECTION_ID()');
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

        if ($dbDropin !== '') {
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

    protected function getServerVariable(string $query, string $name): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only server diagnostics for super admins.
        $row = $wpdb->get_row($wpdb->prepare($query, $name), ARRAY_A);

        return is_array($row) ? (string)($row['Value'] ?? '') : '';
    }

    /** @return array{value: mixed, ms: float} */
    protected function timeQuery(string $query): array {
        global $wpdb;

        $start = microtime(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Constant diagnostic query.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic timing of the per-website size query.
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
