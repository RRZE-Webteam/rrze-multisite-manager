<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class StatusWidget extends Widgets {
    public function getId(): string {
        return 'status';
    }

    public function getTitle(): string {
        return __('Status distribution', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Distribution of site states within this network.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'status-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['status_distribution'] ?? [],
            'empty_message' => __('No status data available.', 'rrze-multisite-manager'),
            'status_explanations' => [
                [
                    'label' => __('Active and public', 'rrze-multisite-manager'),
                    'text' => __('Status badges in the system: "Active" and "Public" (`archived=0`, `spam=0`, `deleted=0`, `public=1`). Frontend reachable: yes. Site dashboard reachable: yes. Reachable for super admins: yes. Generally intended for search engines.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Active, excluded from search engines', 'rrze-multisite-manager'),
                    'text' => __('Status badges in the system: "Active" and "Not public" (`archived=0`, `spam=0`, `deleted=0`, `public=0`). Frontend reachable: yes. Site dashboard reachable: yes. Reachable for super admins: yes. Not intended for search engines or publicly indexable.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Archived', 'rrze-multisite-manager'),
                    'text' => __('Status badge in the system: "Archived" (`archived=1`). Frontend reachable: no. Site dashboard for regular editors: no. Reachable for super admins: yes. Blocked in core.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Blocked (spam)', 'rrze-multisite-manager'),
                    'text' => __('Status badge in the system: "Blocked" (`spam=1`). Frontend reachable: no. Site dashboard for regular editors: no. Reachable for super admins: yes. Blocked in core like archived, but marked as an abuse/blocking case.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Marked for deletion', 'rrze-multisite-manager'),
                    'text' => __('Status badge in the system: "Deleted" (`deleted=1`). Frontend reachable: no. Site dashboard for regular editors: no. Reachable for super admins: yes. Deletion flag set, but no automatic final deletion.', 'rrze-multisite-manager'),
                ],
            ],
        ];
    }
}
