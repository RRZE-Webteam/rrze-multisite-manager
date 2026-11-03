<?php

namespace RRZE\MultisiteManager\Widgets;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Plugin;
use RRZE\MultisiteManager\StorageAnalysisSchedulerService;

defined('ABSPATH') || exit;

class StorageAnalysisBatchPerformanceWidget extends Widgets {
    protected StorageAnalysisSchedulerService $scheduler;

    public function __construct(Plugin $plugin, Config $config, StorageAnalysisSchedulerService $scheduler) {
        parent::__construct($plugin, $config);
        $this->scheduler = $scheduler;
    }

    public function getId(): string {
        return 'storage_analysis_batch_performance';
    }

    public function getTitle(): string {
        return __('Storage-analysis batch performance', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Completed per-website analysis runtimes. Cron waiting time between individual runs is not included.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'storage-analysis-batch-performance-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $summary = $this->scheduler->getPerformanceSummary();
        $labels = [
            'small-batch' => __('Small shared batch', 'rrze-multisite-manager'),
            'medium-batch' => __('Medium shared batch', 'rrze-multisite-manager'),
            'large-batch' => __('Large shared batch', 'rrze-multisite-manager'),
            'site' => __('Individual tasks', 'rrze-multisite-manager'),
        ];
        $rows = [];

        foreach ($labels as $mode => $label) {
            $row = is_array($summary[$mode] ?? null) ? $summary[$mode] : [];
            $latestDurations = array_map([$this, 'formatDuration'], (array)($row['latest_durations'] ?? []));
            $rows[] = [
                'title' => $label,
                'site_count' => number_format_i18n((int)($row['site_count'] ?? 0)),
                'latest_durations' => empty($latestDurations) ? __('No completed runs', 'rrze-multisite-manager') : implode(' · ', $latestDurations),
                'average_duration' => $this->formatDurationValue($row['average_duration_seconds'] ?? null),
                'fastest_duration' => $this->formatDurationValue($row['fastest_duration_seconds'] ?? null),
                'slowest_duration' => $this->formatDurationValue($row['slowest_duration_seconds'] ?? null),
                'last_execution' => !empty($row['last_execution_timestamp'])
                    ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int)$row['last_execution_timestamp'])
                    : __('No completed runs', 'rrze-multisite-manager'),
            ];
        }

        return ['rows' => $rows];
    }

    protected function formatDurationValue(mixed $seconds): string {
        if (!is_numeric($seconds)) {
            return __('-', 'rrze-multisite-manager');
        }

        return $this->formatDuration((int)round((float)$seconds));
    }

    protected function formatDuration(int $seconds): string {
        $seconds = max(0, $seconds);
        if ($seconds < MINUTE_IN_SECONDS) {
            return sprintf(
                /* translators: %d: number of seconds. */
                _n('%d sec.', '%d sec.', $seconds, 'rrze-multisite-manager'),
                $seconds
            );
        }

        $minutes = intdiv($seconds, MINUTE_IN_SECONDS);
        $remainingSeconds = $seconds % MINUTE_IN_SECONDS;
        if ($minutes < 60) {
            return sprintf(
                /* translators: 1: minutes, 2: seconds. */
                __('%1$d min. %2$d sec.', 'rrze-multisite-manager'),
                $minutes,
                $remainingSeconds
            );
        }

        return sprintf(
            /* translators: 1: hours, 2: minutes. */
            __('%1$d h %2$d min.', 'rrze-multisite-manager'),
            intdiv($minutes, 60),
            $minutes % 60
        );
    }
}
