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
        $colors = $this->config->getSiteStatusColors();
        $items = array_values((array)($dashboardData['status_distribution'] ?? []));
        $colorKeys = ['active_public', 'active_private', 'archived', 'blocked', 'deleted'];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $colorKey = sanitize_key((string)($item['status_key'] ?? ''));

            if (!in_array($colorKey, $colorKeys, true)) {
                $colorKey = $colorKeys[$index] ?? '';
            }

            if ($colorKey === '') {
                continue;
            }

            $items[$index]['color'] = $this->config->getSiteStatusColor($colorKey);
            $items[$index]['color_key'] = $colorKey;
            $items[$index]['status_class'] = $this->getSiteStatusCssClass($colorKey);
        }

        return [
            'items' => $items,
            'empty_message' => __('No status data available.', 'rrze-multisite-manager'),
            'status_explanations' => [
                'active_public' => [
                    'css_class' => 'rrze-msm-status-active',
                    'label' => __('Active and public', 'rrze-multisite-manager'),
                    'text' => __('Status badges in the system: "Active" and "Public" (`archived=0`, `spam=0`, `deleted=0`, `public=1`). Frontend reachable: yes. Site dashboard reachable: yes. Reachable for super admins: yes. Generally intended for search engines.', 'rrze-multisite-manager'),
                    'color' => $colors['active_public'] ?? '',
                    'color_key' => 'active_public',
                    'status_class' => $this->getSiteStatusCssClass('active_public'),
                ],
                'active_private' => [
                    'css_class' => 'rrze-msm-status-active-nobots',
                    'label' => __('Active, excluded from search engines', 'rrze-multisite-manager'),
                    'text' => __('Status badges in the system: "Active" and "Not public" (`archived=0`, `spam=0`, `deleted=0`, `public=0`). Frontend reachable: yes. Site dashboard reachable: yes. Reachable for super admins: yes. Not intended for search engines or publicly indexable.', 'rrze-multisite-manager'),
                    'color' => $colors['active_private'] ?? '',
                    'color_key' => 'active_private',
                    'status_class' => $this->getSiteStatusCssClass('active_private'),
                ],
                'archived' => [
                    'css_class' => 'rrze-msm-status-archive',
                    'label' => __('Archived', 'rrze-multisite-manager'),
                    'text' => __('Status badge in the system: "Archived" (`archived=1`). Frontend reachable: no. Site dashboard for regular editors: no. Reachable for super admins: yes. Blocked in core.', 'rrze-multisite-manager'),
                    'color' => $colors['archived'] ?? '',
                    'color_key' => 'archived',
                    'status_class' => $this->getSiteStatusCssClass('archived'),
                ],
                'blocked' => [
                    'css_class' => 'rrze-msm-status-blocked',
                    'label' => __('Blocked (spam)', 'rrze-multisite-manager'),
                    'text' => __('Status badge in the system: "Blocked" (`spam=1`). Frontend reachable: no. Site dashboard for regular editors: no. Reachable for super admins: yes. Blocked in core like archived, but marked as an abuse/blocking case.', 'rrze-multisite-manager'),
                    'color' => $colors['blocked'] ?? '',
                    'color_key' => 'blocked',
                    'status_class' => $this->getSiteStatusCssClass('blocked'),
                ],
                'deleted' => [
                    'css_class' => 'rrze-msm-status-deleted',
                    'label' => __('Marked for deletion', 'rrze-multisite-manager'),
                    'text' => __('Status badge in the system: "Deleted" (`deleted=1`). Frontend reachable: no. Site dashboard for regular editors: no. Reachable for super admins: yes. Deletion flag set, but no automatic final deletion.', 'rrze-multisite-manager'),
                    'color' => $colors['deleted'] ?? '',
                    'color_key' => 'deleted',
                    'status_class' => $this->getSiteStatusCssClass('deleted'),
                ],
            ],
        ];
    }
}
