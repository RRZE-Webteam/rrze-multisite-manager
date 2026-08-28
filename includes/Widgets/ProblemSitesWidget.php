<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class ProblemSitesWidget extends Widgets {
    public function getId(): string {
        return 'problem_sites';
    }

    public function getTitle(): string {
        return __('Problematic websites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Websites with provisioning status, missing DNS, or technical unreachability.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'problem-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['problem_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
