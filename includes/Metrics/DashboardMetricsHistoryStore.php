<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Analysis\AnalysisStore;

defined('ABSPATH') || exit;

/**
 * Stores compact dashboard snapshots independently from the dashboard cache.
 *
 * A current row is updated for every completed dashboard run. Statistic rows
 * are immutable copies created by the scheduled compaction process.
 */
class DashboardMetricsHistoryStore {
    protected AnalysisStore $store;

    public function __construct(?AnalysisStore $store = null) {
        $this->store = $store ?? new AnalysisStore();
    }

    /** @param array<int, array<string, int|string|null>> $snapshots */
    public function saveCurrentSnapshots(array $snapshots, int $recordedAt): void {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $networkId = get_current_network_id();

        foreach ($snapshots as $snapshot) {
            $blogId = max(0, (int)($snapshot['blog_id'] ?? 0));
            $data = [
                'network_id' => $networkId,
                'blog_id' => $blogId,
                'entry_key' => 'current',
                'snapshot_status' => 'current',
                'recorded_at' => max(1, $recordedAt),
                'site_status' => substr(sanitize_key((string)($snapshot['site_status'] ?? '')), 0, 24),
                'websites_total' => max(0, (int)($snapshot['websites_total'] ?? 0)),
                'websites_active' => max(0, (int)($snapshot['websites_active'] ?? 0)),
                'websites_archived' => max(0, (int)($snapshot['websites_archived'] ?? 0)),
                'websites_blocked' => max(0, (int)($snapshot['websites_blocked'] ?? 0)),
                'websites_deleted' => max(0, (int)($snapshot['websites_deleted'] ?? 0)),
                'media_bytes' => $this->nullableInt($snapshot['media_bytes'] ?? null),
                'database_bytes' => $this->nullableInt($snapshot['database_bytes'] ?? null),
                'plugin_count' => max(0, (int)($snapshot['plugin_count'] ?? 0)),
                'theme_count' => max(0, (int)($snapshot['theme_count'] ?? 0)),
                'user_count' => max(0, (int)($snapshot['user_count'] ?? 0)),
                'page_count' => max(0, (int)($snapshot['page_count'] ?? 0)),
                'post_count' => max(0, (int)($snapshot['post_count'] ?? 0)),
                'media_count' => max(0, (int)($snapshot['media_count'] ?? 0)),
                'custom_post_type_count' => max(0, (int)($snapshot['custom_post_type_count'] ?? 0)),
            ];
            $formats = [
                '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d',
                $data['media_bytes'] === null ? null : '%d', $data['database_bytes'] === null ? null : '%d',
                '%d', '%d', '%d', '%d', '%d', '%d', '%d',
            ];

            // The fixed table name comes from AnalysisStore; current rows are
            // intentionally overwritten only after a complete dashboard run.
            $sql = "INSERT INTO $table (network_id,blog_id,entry_key,snapshot_status,recorded_at,site_status,websites_total,websites_active,websites_archived,websites_blocked,websites_deleted,media_bytes,database_bytes,plugin_count,theme_count,user_count,page_count,post_count,media_count,custom_post_type_count)
                VALUES (%d,%d,%s,%s,%d,%s,%d,%d,%d,%d,%d," . ($data['media_bytes'] === null ? 'NULL' : '%d') . ',' . ($data['database_bytes'] === null ? 'NULL' : '%d') . ",%d,%d,%d,%d,%d,%d,%d)
                ON DUPLICATE KEY UPDATE snapshot_status = VALUES(snapshot_status), recorded_at = VALUES(recorded_at), site_status = VALUES(site_status), websites_total = VALUES(websites_total), websites_active = VALUES(websites_active), websites_archived = VALUES(websites_archived), websites_blocked = VALUES(websites_blocked), websites_deleted = VALUES(websites_deleted), media_bytes = VALUES(media_bytes), database_bytes = VALUES(database_bytes), plugin_count = VALUES(plugin_count), theme_count = VALUES(theme_count), user_count = VALUES(user_count), page_count = VALUES(page_count), post_count = VALUES(post_count), media_count = VALUES(media_count), custom_post_type_count = VALUES(custom_post_type_count)";
            $values = [
                $data['network_id'], $data['blog_id'], $data['entry_key'], $data['snapshot_status'], $data['recorded_at'],
                $data['site_status'], $data['websites_total'], $data['websites_active'], $data['websites_archived'],
                $data['websites_blocked'], $data['websites_deleted'],
            ];
            if ($data['media_bytes'] !== null) {
                $values[] = $data['media_bytes'];
            }
            if ($data['database_bytes'] !== null) {
                $values[] = $data['database_bytes'];
            }
            $values = array_merge($values, [
                $data['plugin_count'], $data['theme_count'], $data['user_count'], $data['page_count'],
                $data['post_count'], $data['media_count'], $data['custom_post_type_count'],
            ]);
            unset($formats);

            $result = $wpdb->query($wpdb->prepare($sql, ...$values));
            if ($result === false || $wpdb->last_error) {
                throw new \RuntimeException(__('Dashboard metric snapshots could not be saved.', 'rrze-multisite-manager'));
            }
        }
    }

    /** Copies current snapshots that are old enough into immutable statistic rows. */
    public function compact(int $intervalSeconds): int {
        global $wpdb;

        $intervalSeconds = max(DAY_IN_SECONDS, $intervalSeconds);
        $table = $this->store->table('dashboard_metrics');
        $networkId = get_current_network_id();
        $currentRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE network_id = %d AND snapshot_status = 'current' ORDER BY blog_id ASC",
            $networkId
        ), ARRAY_A);

        if ($wpdb->last_error) {
            throw new \RuntimeException(__('Dashboard metric snapshots could not be read.', 'rrze-multisite-manager'));
        }

        $created = 0;
        foreach ((array)$currentRows as $row) {
            $lastStatisticAt = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT MAX(recorded_at) FROM $table WHERE network_id = %d AND blog_id = %d AND snapshot_status = 'statistic'",
                $networkId,
                (int)$row['blog_id']
            ));
            if ($lastStatisticAt > 0 && (int)$row['recorded_at'] < ($lastStatisticAt + $intervalSeconds)) {
                continue;
            }

            $entryKey = 'statistic-' . (int)$row['recorded_at'] . '-' . (int)$row['blog_id'];
            $insert = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (network_id,blog_id,entry_key,snapshot_status,recorded_at,site_status,websites_total,websites_active,websites_archived,websites_blocked,websites_deleted,media_bytes,database_bytes,plugin_count,theme_count,user_count,page_count,post_count,media_count,custom_post_type_count)
                SELECT network_id,blog_id,%s,'statistic',recorded_at,site_status,websites_total,websites_active,websites_archived,websites_blocked,websites_deleted,media_bytes,database_bytes,plugin_count,theme_count,user_count,page_count,post_count,media_count,custom_post_type_count
                FROM $table WHERE network_id = %d AND blog_id = %d AND entry_key = 'current'",
                $entryKey,
                $networkId,
                (int)$row['blog_id']
            ));
            if ($insert === false || $wpdb->last_error) {
                throw new \RuntimeException(__('Dashboard metric statistics could not be compacted.', 'rrze-multisite-manager'));
            }
            $created += (int)$insert;
        }

        return $created;
    }

    public function getLastStatisticTimestamp(): int {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $timestamp = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(recorded_at) FROM $table WHERE network_id = %d AND blog_id = 0 AND snapshot_status = 'statistic'",
            get_current_network_id()
        ));

        return is_numeric($timestamp) ? max(0, (int)$timestamp) : 0;
    }

    /** Returns the network-summary row plus all current website snapshots. */
    public function getCurrentSnapshotCount(): int {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE network_id = %d AND entry_key = 'current' AND snapshot_status = 'current'",
            get_current_network_id()
        ));

        if ($wpdb->last_error) {
            throw new \RuntimeException(__('Dashboard metric snapshots could not be read.', 'rrze-multisite-manager'));
        }

        return is_numeric($count) ? max(0, (int)$count) : 0;
    }

    /**
     * Returns the current snapshot rows, keyed by site ID.  The rows are the
     * durable source for dashboard counters; the dashboard cache only keeps
     * presentation and runtime details that are not part of this schema.
     *
     * @return array<int, array<string, int|string|null>>
     */
    public function getCurrentSnapshots(): array {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE network_id = %d AND entry_key = 'current' AND snapshot_status = 'current' ORDER BY blog_id ASC",
            get_current_network_id()
        ), ARRAY_A);

        if ($wpdb->last_error) {
            throw new \RuntimeException(__('Dashboard metric snapshots could not be read.', 'rrze-multisite-manager'));
        }

        $snapshots = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $snapshots[max(0, (int)($row['blog_id'] ?? 0))] = $row;
        }

        return $snapshots;
    }

    /**
     * Returns the most recent historical snapshot for every website at or
     * before the supplied timestamp. This is used for report comparisons and
     * deliberately never falls back to transient dashboard data.
     *
     * @return array<int, array<string, int|string|null>>
     */
    public function getLatestStatisticSnapshotsAtOrBefore(int $timestamp): array {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $networkId = get_current_network_id();
        $timestamp = max(1, $timestamp);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT snapshot.* FROM $table snapshot
            INNER JOIN (
                SELECT blog_id, MAX(recorded_at) AS recorded_at
                FROM $table
                WHERE network_id = %d AND snapshot_status = 'statistic' AND recorded_at <= %d
                GROUP BY blog_id
            ) latest ON latest.blog_id = snapshot.blog_id AND latest.recorded_at = snapshot.recorded_at
            WHERE snapshot.network_id = %d AND snapshot.snapshot_status = 'statistic'
            ORDER BY snapshot.blog_id ASC",
            $networkId,
            $timestamp,
            $networkId
        ), ARRAY_A);

        if ($wpdb->last_error) {
            throw new \RuntimeException(__('Dashboard metric statistics could not be read.', 'rrze-multisite-manager'));
        }

        $snapshots = [];
        foreach ((array)$rows as $row) {
            if (is_array($row)) {
                $snapshots[max(0, (int)($row['blog_id'] ?? 0))] = $row;
            }
        }

        return $snapshots;
    }

    /** Deletes only immutable historical rows; current dashboard snapshots remain intact. */
    public function purgeStatistics(): int {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE network_id = %d AND snapshot_status = 'statistic'",
            get_current_network_id()
        ));
        if ($deleted === false || $wpdb->last_error) {
            throw new \RuntimeException(__('Dashboard metric statistics could not be removed.', 'rrze-multisite-manager'));
        }

        return max(0, (int)$deleted);
    }

    /** Deletes a bounded number of history rows for the explicit full-data cleanup. */
    public function purgeBatch(int $limit = 1000): bool {
        global $wpdb;

        $table = $this->store->table('dashboard_metrics');
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE network_id = %d LIMIT %d", get_current_network_id(), max(1, $limit)));
        if ($deleted === false || $wpdb->last_error) {
            throw new \RuntimeException(__('Dashboard metric snapshots could not be removed.', 'rrze-multisite-manager'));
        }

        return (int)$deleted >= max(1, $limit);
    }

    protected function nullableInt(mixed $value): ?int {
        return is_numeric($value) ? max(0, (int)$value) : null;
    }
}
