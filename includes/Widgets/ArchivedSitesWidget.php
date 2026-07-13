<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class ArchivedSitesWidget extends Widgets {
    public function getId(): string {
        return 'archived_sites';
    }

    public function getTitle(): string {
        return __('Websites mit Status Archiv', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Archivierte Websites inklusive Zeitpunkt, ausführendem Benutzer und optionaler Notiz.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'archived-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['archived_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
