<?php

namespace RRZE\MultisiteManager\Widgets;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Plugin;
use RRZE\MultisiteManager\StorageAnalysisSchedulerService;

defined('ABSPATH') || exit;

class StorageAnalysisMonitoringWidget extends Widgets {
    protected StorageAnalysisSchedulerService $scheduler;

    public function __construct(Plugin $plugin, Config $config, StorageAnalysisSchedulerService $scheduler) {
        parent::__construct($plugin, $config);
        $this->scheduler = $scheduler;
    }

    public function getId(): string {
        return 'storage_analysis_monitoring';
    }

    public function getTitle(): string {
        return __('Monitoring storage analyses', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Distribution of active websites by their storage-analysis schedule.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'monitoring-analysis-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $counts = $this->scheduler->getActiveSiteAssignmentCounts();

        return [
            'items' => [
                [
                    'label' => __('Small shared batch', 'rrze-multisite-manager'),
                    'value' => $counts['small-batch'],
                    'accent' => 'positive',
                ],
                [
                    'label' => __('Medium shared batch', 'rrze-multisite-manager'),
                    'value' => $counts['medium-batch'],
                    'accent' => 'info',
                ],
                [
                    'label' => __('Individual schedule', 'rrze-multisite-manager'),
                    'value' => $counts['site'],
                    'accent' => 'warning',
                ],
                [
                    'label' => __('No scheduled storage analysis', 'rrze-multisite-manager'),
                    'value' => $counts['unassigned'],
                    'accent' => 'danger',
                ],
            ],
            'empty_message' => __('No active websites available.', 'rrze-multisite-manager'),
            'center_title' => __('Active websites', 'rrze-multisite-manager'),
            'center_value' => (string)array_sum($counts),
        ];
    }
}
