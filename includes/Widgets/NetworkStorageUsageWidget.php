<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class NetworkStorageUsageWidget extends Widgets {
    public function getId(): string {
        return 'network_storage_usage';
    }

    public function getTitle(): string {
        return __('Network storage usage', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Summed storage usage of all websites in the network.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'network-storage-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $storageUsage = is_array($dashboardData['network_storage_usage'] ?? null)
            ? $dashboardData['network_storage_usage']
            : [];
        $items = is_array($storageUsage['items'] ?? null) ? $storageUsage['items'] : [];

        return [
            'items' => $this->normalizeStorageUsageItems($items),
            'summary_label' => $this->getSummaryLabel($storageUsage),
            'mode_note' => $this->getModeNote($storageUsage),
            'center_title' => !empty($storageUsage['has_unlimited_site'])
                ? __('Used', 'rrze-multisite-manager')
                : __('Maximum', 'rrze-multisite-manager'),
            'center_value' => !empty($storageUsage['has_unlimited_site'])
                ? (string)($storageUsage['total_used_label'] ?? '')
                : (string)($storageUsage['total_max_label'] ?? ''),
            'empty_message' => __('No storage usage data available.', 'rrze-multisite-manager'),
        ];
    }

    protected function normalizeStorageUsageItems(array $items): array {
        $freeStorageLabel = __('Free storage', 'rrze-multisite-manager');
        $item = [];
        $index = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string)($item['label'] ?? '') === $freeStorageLabel) {
                $items[$index]['accent'] = 'free-storage';
            }
        }

        return $items;
    }

    protected function getSummaryLabel(array $storageUsage): string {
        $usedLabel = (string)($storageUsage['total_used_label'] ?? '');
        $maxLabel = (string)($storageUsage['total_max_label'] ?? '');
        $percent = isset($storageUsage['percent']) && is_int($storageUsage['percent'])
            ? (int)$storageUsage['percent']
            : null;

        if (!empty($storageUsage['has_unlimited_site'])) {
            return sprintf(
                /* translators: %s: total used storage label. */
                __('Total used storage space: %s', 'rrze-multisite-manager'),
                $usedLabel !== '' ? $usedLabel : __('Unknown', 'rrze-multisite-manager')
            );
        }

        if ($usedLabel !== '' && $maxLabel !== '' && $percent !== null) {
            return sprintf(
                /* translators: 1: used storage label, 2: maximum storage label, 3: used percentage. */
                __('%1$s of %2$s used (%3$d%%)', 'rrze-multisite-manager'),
                $usedLabel,
                $maxLabel,
                $percent
            );
        }

        if ($usedLabel !== '' && $maxLabel !== '') {
            return sprintf(
                /* translators: 1: used storage label, 2: maximum storage label. */
                __('%1$s of %2$s used', 'rrze-multisite-manager'),
                $usedLabel,
                $maxLabel
            );
        }

        return '';
    }

    protected function getModeNote(array $storageUsage): string {
        if (empty($storageUsage['has_unlimited_site'])) {
            return __('The pie chart shows the used storage share per website relative to the total available storage capacity of the network.', 'rrze-multisite-manager');
        }

        return __('At least one website has unlimited storage. Therefore, the pie chart shows only the distribution of currently used storage per website.', 'rrze-multisite-manager');
    }
}
