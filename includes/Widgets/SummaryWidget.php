<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class SummaryWidget extends Widgets {
    public function getId(): string {
        return 'summary';
    }

    public function getTitle(): string {
        return __('Kernzahlen', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Der schnelle Einstieg für diese Multisite.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    protected function getTemplateName(): string {
        return 'summary-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $summary = $dashboardData['summary'] ?? [];

        return [
            'cards' => [
                [
                    'label' => __('Sites gesamt', 'rrze-multisite-manager'),
                    'value' => $summary['total_sites'] ?? 0,
                    'accent' => 'neutral',
                ],
                [
                    'label' => __('Aktive Sites', 'rrze-multisite-manager'),
                    'value' => $summary['active_sites'] ?? 0,
                    'accent' => 'positive',
                ],
                [
                    'label' => __('Öffentliche Sites', 'rrze-multisite-manager'),
                    'value' => $summary['public_sites'] ?? 0,
                    'accent' => 'positive',
                ],
                [
                    'label' => __('Archiviert', 'rrze-multisite-manager'),
                    'value' => $summary['archived_sites'] ?? 0,
                    'accent' => 'warning',
                ],
                [
                    'label' => __('Zum Löschen markiert', 'rrze-multisite-manager'),
                    'value' => $summary['deleted_sites'] ?? 0,
                    'accent' => 'danger',
                ],
                [
                    'label' => __('Neue Sites (30 Tage)', 'rrze-multisite-manager'),
                    'value' => $summary['recent_sites_30'] ?? 0,
                    'accent' => 'info',
                ],
            ],
        ];
    }
}
