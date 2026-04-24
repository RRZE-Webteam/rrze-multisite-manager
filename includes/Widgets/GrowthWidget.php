<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class GrowthWidget extends Widgets {
    public function getId(): string {
        return 'growth';
    }

    public function getTitle(): string {
        return __('Neue Sites pro Monat', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Einfacher Trend der letzten sechs Monate auf Basis lokaler Netzwerkdaten.', 'rrze-multisite-manager');
    }

    protected function getTemplateName(): string {
        return 'growth-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $items = $dashboardData['monthly_growth'] ?? [];
        $maxValue = 0;
        $item = [];

        foreach ($items as $item) {
            if ((int)$item['value'] > $maxValue) {
                $maxValue = (int)$item['value'];
            }
        }

        return [
            'items' => $items,
            'max_value' => $maxValue,
        ];
    }
}
