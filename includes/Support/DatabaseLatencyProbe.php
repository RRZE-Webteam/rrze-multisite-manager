<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/**
 * Manual measurement of the database round trip from the answering web node.
 *
 * On request (button on the Environment page) the current request sends a
 * fixed number of "SELECT 1" statements through $wpdb, i.e. the same path as
 * all other WordPress queries including a database drop-in. The statement
 * needs no table access, so the measured time is essentially the round trip
 * between PHP and the database server plus the overhead of $wpdb.
 *
 * Each run records the host name of the web node, so runs on different nodes
 * (behind a load balancer) can be compared. Only the last runs are kept.
 */
class DatabaseLatencyProbe {
    public const OPTION = 'rrze_msm_db_latency_probes';
    public const QUERIES = 20;
    public const MAX_RUNS = 20;

    /**
     * Runs the measurement and stores the result.
     *
     * @return array<string, mixed>
     */
    public static function run(): array {
        $durations = self::measure(self::QUERIES);
        $entry = [
            't' => time(),
            'host' => function_exists('gethostname') ? (string)gethostname() : '',
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
            'db_host' => defined('DB_HOST') ? (string)DB_HOST : '',
            'failed' => self::QUERIES - count($durations),
        ] + self::summarize($durations);

        update_site_option(self::OPTION, self::addRun(self::getRuns(), $entry));

        return $entry;
    }

    /** @return float[] Duration of each successful query in milliseconds */
    protected static function measure(int $count): array {
        global $wpdb;
        $durations = [];
        $index = 0;
        $start = 0.0;
        $value = null;
        $suppressed = $wpdb->suppress_errors(true);

        for ($index = 0; $index < $count; $index++) {
            $start = microtime(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed diagnostic statement that measures the database round trip; must not be cached.
            $value = $wpdb->get_var('SELECT 1');

            if ((string)$value === '1') {
                $durations[] = (microtime(true) - $start) * 1000;
            }
        }

        $wpdb->suppress_errors($suppressed);

        return $durations;
    }

    /**
     * Minimum, median, mean and maximum in milliseconds. Kept free of
     * WordPress calls so it can be tested in isolation.
     *
     * @param float[] $durations
     * @return array{count: int, min_ms: float, median_ms: float, avg_ms: float, max_ms: float}
     */
    public static function summarize(array $durations): array {
        $values = array_values(array_map('floatval', $durations));
        $count = count($values);
        $middle = 0;
        $median = 0.0;

        if ($count === 0) {
            return ['count' => 0, 'min_ms' => 0.0, 'median_ms' => 0.0, 'avg_ms' => 0.0, 'max_ms' => 0.0];
        }

        sort($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return [
            'count' => $count,
            'min_ms' => round($values[0], 3),
            'median_ms' => round($median, 3),
            'avg_ms' => round(array_sum($values) / $count, 3),
            'max_ms' => round($values[$count - 1], 3),
        ];
    }

    /**
     * Adds a run, newest first, keeping at most MAX_RUNS entries.
     *
     * @param array<int, array<string, mixed>> $runs
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>>
     */
    public static function addRun(array $runs, array $entry): array {
        array_unshift($runs, $entry);

        return array_slice(array_values($runs), 0, self::MAX_RUNS);
    }

    /** @return array<int, array<string, mixed>> */
    public static function getRuns(): array {
        $runs = get_site_option(self::OPTION, []);

        return is_array($runs) ? array_values(array_filter($runs, 'is_array')) : [];
    }

    public static function reset(): void {
        delete_site_option(self::OPTION);
    }
}
