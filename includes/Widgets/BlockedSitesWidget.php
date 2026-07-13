<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class BlockedSitesWidget extends Widgets {
    public function getId(): string {
        return 'blocked_sites';
    }

    public function getTitle(): string {
        return __('Gesperrte Websites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Websites mit gesetztem Status „Gesperrt“ inklusive Zeitpunkt, ausführendem Benutzer und Notiz.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'blocked-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['blocked_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
