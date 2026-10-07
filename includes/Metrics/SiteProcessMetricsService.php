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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient listing is cached per site detail section.
        $rows = $wpdb->get_results("SELECT option_name, CASE WHEN option_name LIKE '\\_transient\\_timeout\\_%' THEN option_value ELSE NULL END AS option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%' ORDER BY option_name ASC");
        $timeouts = [];
        $transients = [];

        foreach ($rows as $row) {
            $name = (string)($row->option_name ?? '');

            if (str_starts_with($name, '_transient_timeout_')) {
                $timeouts[substr($name, strlen('_transient_timeout_'))] = (int)($row->option_value ?? 0);
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
