<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class WebsiteStatusWidget extends Widgets {
    public function getId(): string {
        return 'website_status';
    }

    public function getTitle(): string {
        return __('Status der Websites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return '';
    }

    public function getWidth(): int {
        return 12;
    }

    protected function getTemplateName(): string {
        return 'website-status-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $statusItems = $this->getStatusItemMap(
            (array)($dashboardData['status_distribution'] ?? []),
            ['active_public', 'active_private', 'archived', 'blocked', 'deleted']
        );
        $operationalItems = $this->getStatusItemMap(
            (array)($dashboardData['operational_status_distribution'] ?? []),
            ['automatic', 'technically_reachable', 'provisioning', 'dns_missing', 'technically_unreachable', 'out_of_service']
        );

        return [
            'cards' => $this->getStatusCards(
                array_merge($statusItems, $operationalItems),
                [
                    'provisioning' => __('Provisioning in progress', 'rrze-multisite-manager'),
                    'active_private' => __('Active, excluded from search engines', 'rrze-multisite-manager'),
                    'active_public' => __('Active and public', 'rrze-multisite-manager'),
                    'archived' => __('Archived', 'rrze-multisite-manager'),
                    'deleted' => __('Marked for deletion', 'rrze-multisite-manager'),
                    'out_of_service' => __('Out of service', 'rrze-multisite-manager'),
                    'blocked' => __('Blocked', 'rrze-multisite-manager'),
                    'automatic' => __('Not set / automatic', 'rrze-multisite-manager'),
                    'technically_reachable' => __('Technically reachable', 'rrze-multisite-manager'),
                    'dns_missing' => __('DNS missing', 'rrze-multisite-manager'),
                    'technically_unreachable' => __('Technically unreachable', 'rrze-multisite-manager'),
                ]
            ),
        ];
    }

    private function getStatusItemMap(array $items, array $fallbackKeys): array {
        $itemsByStatus = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $statusKey = sanitize_key((string)($item['status_key'] ?? ($fallbackKeys[$index] ?? '')));

            if ($statusKey !== '') {
                $itemsByStatus[$statusKey] = $item;
            }
        }

        return $itemsByStatus;
    }

    private function getStatusCards(array $itemsByStatus, array $definitions): array {
        $cards = [];

        foreach ($definitions as $statusKey => $label) {
            $item = (array)($itemsByStatus[$statusKey] ?? []);
            $value = (int)($item['value'] ?? 0);

            if ($value === 0) {
                continue;
            }

            $cards[] = [
                'label' => (string)($item['label'] ?? $label),
                'value' => $value,
                'status_class' => $this->getSiteStatusCssClass($statusKey),
            ];
        }

        return $cards;
    }
}
