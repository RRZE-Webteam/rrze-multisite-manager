<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class OperationalStatusWidget extends Widgets {
    public function getId(): string {
        return 'operational_status';
    }

    public function getTitle(): string {
        return __('Operational status of websites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Distribution of MSM operational states across all websites in the network.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'operational-status-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $colors = $this->config->getSiteStatusColors();
        $items = array_values((array)($dashboardData['operational_status_distribution'] ?? []));
        $colorKeys = ['automatic', 'technically_reachable', 'provisioning', 'dns_missing', 'technically_unreachable', 'out_of_service'];

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
            'empty_message' => __('No operational status data available.', 'rrze-multisite-manager'),
            'status_explanations' => [
                'automatic' => [
                    'css_class' => 'rrze-msm-status-automatic',
                    'label' => __('Not set / automatic', 'rrze-multisite-manager'),
                    'text' => __('No operational status has been set manually or by monitoring yet.', 'rrze-multisite-manager'),
                    'color' => $colors['automatic'] ?? '',
                    'color_key' => 'automatic',
                    'status_class' => $this->getSiteStatusCssClass('automatic'),
                ],
                'technically_reachable' => [
                    'css_class' => 'rrze-msm-status-reachable',
                    'label' => __('Technically reachable', 'rrze-multisite-manager'),
                    'text' => __('DNS and HTTP checks were successful.', 'rrze-multisite-manager'),
                    'color' => $colors['technically_reachable'] ?? '',
                    'color_key' => 'technically_reachable',
                    'status_class' => $this->getSiteStatusCssClass('technically_reachable'),
                ],
                'provisioning' => [
                    'css_class' => 'rrze-msm-status-provisioning',
                    'label' => __('Provisioning in progress', 'rrze-multisite-manager'),
                    'text' => __('The website is still within the configured setup phase.', 'rrze-multisite-manager'),
                    'color' => $colors['provisioning'] ?? '',
                    'color_key' => 'provisioning',
                    'status_class' => $this->getSiteStatusCssClass('provisioning'),
                ],
                'dns_missing' => [
                    'css_class' => 'rrze-msm-status-dns-missing',
                    'label' => __('DNS missing', 'rrze-multisite-manager'),
                    'text' => __('The configured domain could not be resolved by DNS checks.', 'rrze-multisite-manager'),
                    'color' => $colors['dns_missing'] ?? '',
                    'color_key' => 'dns_missing',
                    'status_class' => $this->getSiteStatusCssClass('dns_missing'),
                ],
                'technically_unreachable' => [
                    'css_class' => 'rrze-msm-status-unreachable',
                    'label' => __('Technically unreachable', 'rrze-multisite-manager'),
                    'text' => __('DNS may resolve, but the website could not be reached by HTTP checks.', 'rrze-multisite-manager'),
                    'color' => $colors['technically_unreachable'] ?? '',
                    'color_key' => 'technically_unreachable',
                    'status_class' => $this->getSiteStatusCssClass('technically_unreachable'),
                ],
                'out_of_service' => [
                    'css_class' => 'rrze-msm-status-out-of-service',
                    'label' => __('Out of service', 'rrze-multisite-manager'),
                    'text' => __('The website has been deliberately taken out of operation.', 'rrze-multisite-manager'),
                    'color' => $colors['out_of_service'] ?? '',
                    'color_key' => 'out_of_service',
                    'status_class' => $this->getSiteStatusCssClass('out_of_service'),
                ],
            ],
        ];
    }
}
