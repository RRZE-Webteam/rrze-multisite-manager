<?php

namespace RRZE\MultisiteManager\Support;

use RRZE\MultisiteManager\Config;

defined('ABSPATH') || exit;

/**
 * Time-limited sampling of server-side response times for editorial
 * requests: opening the editor, saving, autosaving and uploading media.
 *
 * Each sample records whether heavy background analyses occupied execution
 * slots at that moment, so response times with and without concurrent
 * analyses can be compared. It also records the PHP-FPM pool state and the
 * number of running database threads when they are readable.
 *
 * Sampling is off by default and is started for a fixed period on the
 * Environment page. Recording happens on shutdown, is best effort and never
 * waits for a lock; a sample that cannot be written is dropped.
 */
class ResponseTimeSampler {
    public const STATE_OPTION = 'rrze_msm_response_sampling';
    public const STATS_OPTION = 'rrze_msm_response_time_stats';
    public const CATEGORIES = ['editor_load', 'save', 'autosave', 'upload'];
    public const BUCKETS_MS = [250, 500, 1000, 2000, 5000];
    public const MAX_RECENT = 100;
    public const DURATIONS = [1, 3, 7];
    protected const LOCK_KEY = 'response_time_stats';

    protected static string $restCategory = '';

    public static function register(): void {
        add_filter('rest_post_dispatch', [self::class, 'captureRestRequest'], 10, 3);
        add_action('shutdown', [self::class, 'recordCurrentRequest'], 1);
    }

    /**
     * Remembers the category of a REST request. The response is returned
     * unchanged.
     *
     * @param mixed $response
     * @return mixed
     */
    public static function captureRestRequest($response, $server, $request) {
        if ($request instanceof \WP_REST_Request) {
            self::$restCategory = self::classifyRestRequest($request->get_method(), $request->get_route());
        }

        return $response;
    }

    public static function classifyRestRequest(string $method, string $route): string {
        $method = strtoupper($method);

        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return '';
        }

        if (preg_match('#^/wp/v2/(posts|pages)/\d+/autosaves/?$#', $route)) {
            return 'autosave';
        }

        if (preg_match('#^/wp/v2/(posts|pages)(/\d+)?/?$#', $route)) {
            return 'save';
        }

        if (preg_match('#^/wp/v2/media/?$#', $route)) {
            return 'upload';
        }

        return '';
    }

    public static function classifyAdminRequest(string $page, string $method): string {
        $method = strtoupper($method);

        if (in_array($page, ['post.php', 'post-new.php'], true)) {
            return $method === 'POST' ? 'save' : 'editor_load';
        }

        if (in_array($page, ['async-upload.php', 'media-new.php'], true) && $method === 'POST') {
            return 'upload';
        }

        return '';
    }

    public static function recordCurrentRequest(): void {
        global $wpdb;
        $category = self::$restCategory;
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
        $milliseconds = 0.0;

        if ($category === '' && is_admin() && !wp_doing_ajax()) {
            $category = self::classifyAdminRequest(
                isset($GLOBALS['pagenow']) ? (string)$GLOBALS['pagenow'] : '',
                isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET'
            );
        }

        // Only matching requests read the sampling state.
        if ($category === '' || $start <= 0.0 || !self::isActive()) {
            return;
        }

        // Taken before the additional diagnostic queries below.
        $milliseconds = (microtime(true) - $start) * 1000;

        try {
            self::store([
                't' => time(),
                'category' => $category,
                'ms' => round($milliseconds, 1),
                'queries' => isset($wpdb->num_queries) ? (int)$wpdb->num_queries : 0,
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'workers' => WorkerBudget::countOccupiedSlots((new Config())->getMaxConcurrentWorkers()),
                'site_id' => get_current_blog_id(),
            ] + self::getFpmSample() + self::getDatabaseSample());
        } catch (\Throwable $error) {
            // Diagnostics must never affect the measured request.
        }
    }

    /** @return array{active: bool, started: int, until: int} */
    public static function getState(): array {
        $state = get_site_option(self::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        $until = max(0, (int)($state['until'] ?? 0));

        return [
            'active' => $until > time(),
            'started' => max(0, (int)($state['started'] ?? 0)),
            'until' => $until,
        ];
    }

    public static function isActive(): bool {
        return self::getState()['active'];
    }

    public static function start(int $days): void {
        $days = in_array($days, self::DURATIONS, true) ? $days : 1;
        update_site_option(self::STATE_OPTION, ['started' => time(), 'until' => time() + $days * DAY_IN_SECONDS]);
    }

    public static function stop(): void {
        $state = self::getState();
        update_site_option(self::STATE_OPTION, ['started' => $state['started'], 'until' => min($state['until'], time())]);
    }

    public static function reset(): void {
        delete_site_option(self::STATS_OPTION);
    }

    /** @return array<string, mixed> */
    public static function getStats(): array {
        $stats = get_site_option(self::STATS_OPTION, []);

        return self::normalizeStats(is_array($stats) ? $stats : []);
    }

    /**
     * Adds one sample to the aggregate. Kept free of WordPress calls so the
     * aggregation can be tested in isolation.
     *
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    public static function addSample(array $stats, array $sample): array {
        $stats = self::normalizeStats($stats);
        $category = (string)($sample['category'] ?? '');
        $state = (int)($sample['workers'] ?? 0) > 0 ? 'during_analysis' : 'idle';
        $milliseconds = max(0.0, (float)($sample['ms'] ?? 0.0));
        $entry = [];
        $bucket = 0;

        if (!in_array($category, self::CATEGORIES, true)) {
            return $stats;
        }

        if ($stats['since'] === 0) {
            $stats['since'] = (int)($sample['t'] ?? 0);
        }

        $entry = is_array($stats['categories'][$category][$state] ?? null) ? $stats['categories'][$category][$state] : [];
        $entry['count'] = max(0, (int)($entry['count'] ?? 0)) + 1;
        $entry['total_ms'] = max(0.0, (float)($entry['total_ms'] ?? 0.0)) + $milliseconds;
        $entry['max_ms'] = max((float)($entry['max_ms'] ?? 0.0), $milliseconds);
        $entry['total_queries'] = max(0, (int)($entry['total_queries'] ?? 0)) + max(0, (int)($sample['queries'] ?? 0));
        $entry['buckets'] = array_pad(array_values((array)($entry['buckets'] ?? [])), count(self::BUCKETS_MS) + 1, 0);
        $bucket = self::getBucketIndex($milliseconds);
        $entry['buckets'][$bucket] = (int)$entry['buckets'][$bucket] + 1;
        $stats['categories'][$category][$state] = $entry;

        array_unshift($stats['recent'], $sample);
        $stats['recent'] = array_slice($stats['recent'], 0, self::MAX_RECENT);

        return $stats;
    }

    /**
     * Upper bucket bound below which at least the given share of the samples
     * lies, or 0 when the share falls into the open last bucket.
     *
     * @param array<int, int> $buckets
     */
    public static function getPercentileBound(array $buckets, float $share): int {
        $total = array_sum($buckets);
        $cumulative = 0;
        $index = 0;
        $count = 0;

        if ($total <= 0) {
            return 0;
        }

        foreach (array_values($buckets) as $index => $count) {
            $cumulative += (int)$count;

            if ($cumulative / $total >= $share) {
                return self::BUCKETS_MS[$index] ?? 0;
            }
        }

        return 0;
    }

    protected static function getBucketIndex(float $milliseconds): int {
        $index = 0;
        $bound = 0;

        foreach (self::BUCKETS_MS as $index => $bound) {
            if ($milliseconds < $bound) {
                return $index;
            }
        }

        return count(self::BUCKETS_MS);
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    protected static function normalizeStats(array $stats): array {
        return [
            'since' => max(0, (int)($stats['since'] ?? 0)),
            'categories' => is_array($stats['categories'] ?? null) ? $stats['categories'] : [],
            'recent' => is_array($stats['recent'] ?? null) ? array_values($stats['recent']) : [],
        ];
    }

    /** @param array<string, mixed> $sample */
    protected static function store(array $sample): void {
        $token = AtomicLock::acquire(self::LOCK_KEY);

        if ($token === '') {
            return;
        }

        try {
            update_site_option(self::STATS_OPTION, self::addSample(self::getStats(), $sample));
        } finally {
            AtomicLock::release(self::LOCK_KEY, $token);
        }
    }

    /**
     * PHP-FPM pool state of the measuring process. Available only when the
     * request runs under PHP-FPM.
     *
     * @return array<string, int>
     */
    public static function getFpmSample(): array {
        $status = function_exists('fpm_get_status') ? fpm_get_status() : false;

        if (!is_array($status)) {
            return [];
        }

        return [
            'fpm_active' => (int)($status['active-processes'] ?? 0),
            'fpm_total' => (int)($status['total-processes'] ?? 0),
            'fpm_listen_queue' => (int)($status['listen-queue'] ?? 0),
            'fpm_max_children_reached' => (int)($status['max-children-reached'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    protected static function getDatabaseSample(): array {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One status value per sampled request.
        $row = $wpdb->get_row("SHOW GLOBAL STATUS LIKE 'Threads_running'", ARRAY_A);
        $wpdb->suppress_errors($suppressed);

        return is_array($row) && isset($row['Value']) ? ['db_threads_running' => (int)$row['Value']] : [];
    }
}
