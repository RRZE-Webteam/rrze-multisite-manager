<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class EditorUsageWidget extends Widgets {
    public function getId(): string {
        return 'editor_usage';
    }

    public function getTitle(): string {
        return __('Editor usage', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Based on the default editor configured for each website in RRZE Settings.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'editor-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $networkSettings = get_site_option('rrze_settings', []);
        $networkSettings = is_array($networkSettings) || is_object($networkSettings)
            ? (array)$networkSettings
            : [];
        $writingSettings = $networkSettings['writing'] ?? [];
        $writingSettings = is_array($writingSettings) || is_object($writingSettings)
            ? (array)$writingSettings
            : [];

        return [
            'items' => $this->formatWebsiteUsageItems((array)($dashboardData['editor_usage'] ?? [])),
            'empty_message' => __('No editor data available.', 'rrze-multisite-manager'),
            'network_block_editor_enabled' => !empty($writingSettings['enable_block_editor']),
            'rrze_settings_writing_url' => network_admin_url('admin.php?page=rrze-settings-writing'),
        ];
    }
}
