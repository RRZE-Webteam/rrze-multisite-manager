<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Config;

defined('ABSPATH') || exit;

/**
 * Provides cached, site-local process details such as transients and cron jobs.
 */
class SiteProcessMetricsService {
    protected Config $config;
    protected MetricsCacheService $cache;
    protected int $maxRows;

    public function __construct(Config $config, MetricsCacheService $cache, int $maxRows) {
        $this->config = $config;
        $this->cache = $cache;
        $this->maxRows = $maxRows;
    }

    public function getStats(): array {
        $cached = $this->cache->getCurrentSection('process_stats');

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Process stats are cached per detail section and require a direct transient count query.
        $transientCount = (int)$wpdb->get_var("SELECT COUNT(option_name) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_%'");
        $cronEventCount = 0;

        foreach ((array)_get_cron_array() as $hooks) {
            foreach (is_array($hooks) ? $hooks : [] as $hook => $events) {
                foreach (is_array($events) ? $events : [] as $event) {
                    if (is_array($event) && $this->isCurrentSiteEvent((string)$hook, $event)) {
                        $cronEventCount++;
                    }
                }
            }
        }

        $result = ['transients' => max(0, $transientCount), 'cron_events' => max(0, $cronEventCount)];
        $this->cache->setCurrentSection('process_stats', $result);

        return $result;
    }

    public function getTransients(): array {
        $cached = $this->cache->getCurrentSection('transients');

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $limit = max(1, $this->maxRows);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The UI has a strict row limit, so only that many transient names are loaded.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name ASC LIMIT %d",
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_transient_timeout_') . '%',
                $limit
            )
        );
        $timeouts = [];
        $transients = [];
        $timeoutNames = [];

        foreach ($rows as $row) {
            $name = (string)($row->option_name ?? '');

            if (str_starts_with($name, '_transient_')) {
                $timeoutNames[] = '_transient_timeout_' . substr($name, strlen('_transient_'));
            }
        }

        if (!empty($timeoutNames)) {
            $placeholders = implode(', ', array_fill(0, count($timeoutNames), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Timeout names originate only from the bounded transient-name query above.
            $timeoutRows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
                    ...$timeoutNames
                )
            );

            foreach ($timeoutRows as $timeoutRow) {
                $timeoutName = (string)($timeoutRow->option_name ?? '');
                $timeouts[substr($timeoutName, strlen('_transient_timeout_'))] = (int)($timeoutRow->option_value ?? 0);
            }
        }

        foreach ($rows as $row) {
            $name = (string)($row->option_name ?? '');

            if (!str_starts_with($name, '_transient_') || str_starts_with($name, '_transient_timeout_')) {
                continue;
            }

            $transientName = substr($name, strlen('_transient_'));
            $timestamp = (int)($timeouts[$transientName] ?? 0);
            $transients[] = [
                'name' => $transientName,
                'expires_at' => $timestamp > 0 ? wp_date('d.m.Y H:i', $timestamp) : __('No expiration set', 'rrze-multisite-manager'),
            ];

            if (count($transients) >= $this->maxRows) {
                break;
            }
        }

        $this->cache->setCurrentSection('transients', $transients);

        return $transients;
    }

    public function getCronEvents(): array {
        $cached = $this->cache->getCurrentSection('cron_events');

        if (is_array($cached)) {
            return $cached;
        }

        $results = [];

        foreach ((array)_get_cron_array() as $timestamp => $hooks) {
            foreach (is_array($hooks) ? $hooks : [] as $hook => $events) {
                foreach (is_array($events) ? $events : [] as $event) {
                    if (!is_array($event) || !$this->isCurrentSiteEvent((string)$hook, $event)) {
                        continue;
                    }

                    $results[] = [
                        'hook' => (string)$hook,
                        'next_run' => wp_date('d.m.Y H:i', (int)$timestamp),
                        'next_run_timestamp' => (int)$timestamp,
                        'schedule' => !empty($event['schedule']) ? (string)$event['schedule'] : __('one-time', 'rrze-multisite-manager'),
                    ];
                }
            }
        }

        usort($results, static function (array $left, array $right): int {
            $leftTimestamp = (int)($left['next_run_timestamp'] ?? 0);
            $rightTimestamp = (int)($right['next_run_timestamp'] ?? 0);

            return $leftTimestamp === $rightTimestamp
                ? strcmp((string)($left['hook'] ?? ''), (string)($right['hook'] ?? ''))
                : $leftTimestamp <=> $rightTimestamp;
        });
        $results = array_slice($results, 0, $this->maxRows);
        $this->cache->setCurrentSection('cron_events', $results);

        return $results;
    }

    /** @param array<string, mixed> $event */
    protected function isCurrentSiteEvent(string $hook, array $event): bool {
        if ($hook === $this->config->getShortcodeBlockAnalysisBatchHook()) {
            return false;
        }

        if (!in_array($hook, [
            $this->config->getStorageAnalysisHook(),
            $this->config->getShortcodeBlockAnalysisHook(),
            $this->config->getLegacyShortcodeBlockAnalysisHook(),
        ], true)) {
            return true;
        }

        return (int)(((array)($event['args'] ?? []))[0] ?? 0) === get_current_blog_id();
    }
}
