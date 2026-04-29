<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class DeletedSitesWidget extends Widgets {
    public function getId(): string {
        return 'deleted_sites';
    }

    public function getTitle(): string {
        return __('Zum Löschen markierte Sites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Diese Instanzen sind bereits als gelöscht markiert.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'deleted-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['deleted_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
