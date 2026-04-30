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
                    'label' => __('Sites', 'rrze-multisite-manager'),
                    'value' => $summary['total_sites'] ?? 0,
                    'detail' => sprintf(
                        __('davon %d aktiv', 'rrze-multisite-manager'),
                        (int)($summary['active_sites'] ?? 0)
                    ),
                    'accent' => 'neutral',
                ],
                [
                    'label' => __('Benutzer', 'rrze-multisite-manager'),
                    'value' => $summary['total_users'] ?? 0,
                    'detail' => sprintf(
                        __('davon %d Superadmins', 'rrze-multisite-manager'),
                        (int)($summary['super_admins'] ?? 0)
                    ),
                    'accent' => 'positive',
                ],
                [
                    'label' => __('Plugins', 'rrze-multisite-manager'),
                    'value' => $summary['total_plugins'] ?? 0,
                    'detail' => sprintf(
                        __('davon %d netzwerkweit aktiv', 'rrze-multisite-manager'),
                        (int)($summary['network_active_plugins'] ?? 0)
                    ),
                    'accent' => 'neutral',
                ],
                [
                    'label' => __('Themes', 'rrze-multisite-manager'),
                    'value' => $summary['total_themes'] ?? 0,
                    'detail' => sprintf(
                        __('davon %d netzwerkweit verfügbar', 'rrze-multisite-manager'),
                        (int)($summary['network_enabled_themes'] ?? 0)
                    ),
                    'accent' => 'warning',
                ],
                [
                    'label' => __('Speicherbelegung', 'rrze-multisite-manager'),
                    'value' => (string)($summary['total_storage_used_label'] ?? ''),
                    'detail' => !empty($summary['has_unlimited_storage_site'])
                        ? __('mindestens eine Site unbegrenzt', 'rrze-multisite-manager')
                        : sprintf(
                            __('von %s', 'rrze-multisite-manager'),
                            (string)($summary['total_storage_max_label'] ?? '')
                        ),
                    'accent' => 'info',
                ],
            ],
        ];
    }
}
