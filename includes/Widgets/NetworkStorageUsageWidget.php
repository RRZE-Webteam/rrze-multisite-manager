<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class NetworkStorageUsageWidget extends Widgets {
    public function getId(): string {
        return 'network_storage_usage';
    }

    public function getTitle(): string {
        return __('Speicherbelegung des Netzwerks', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Summierte Speicherbelegung aller Websites im Netzwerk.', 'rrze-multisite-manager');
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
                ? __('Verwendet', 'rrze-multisite-manager')
                : __('Maximal', 'rrze-multisite-manager'),
            'center_value' => !empty($storageUsage['has_unlimited_site'])
                ? (string)($storageUsage['total_used_label'] ?? '')
                : (string)($storageUsage['total_max_label'] ?? ''),
            'empty_message' => __('Keine Speicherverbrauchsdaten vorhanden.', 'rrze-multisite-manager'),
        ];
    }

    protected function normalizeStorageUsageItems(array $items): array {
        $freeStorageLabel = __('Freier Speicher', 'rrze-multisite-manager');
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
                __('Verwendeter Speicherplatz gesamt: %s', 'rrze-multisite-manager'),
                $usedLabel !== '' ? $usedLabel : __('Unbekannt', 'rrze-multisite-manager')
            );
        }

        if ($usedLabel !== '' && $maxLabel !== '' && $percent !== null) {
            return sprintf(
                __('%1$s von %2$s belegt (%3$d%%)', 'rrze-multisite-manager'),
                $usedLabel,
                $maxLabel,
                $percent
            );
        }

        if ($usedLabel !== '' && $maxLabel !== '') {
            return sprintf(
                __('%1$s von %2$s belegt', 'rrze-multisite-manager'),
                $usedLabel,
                $maxLabel
            );
        }

        return '';
    }

    protected function getModeNote(array $storageUsage): string {
        if (empty($storageUsage['has_unlimited_site'])) {
            return __('Die Tortengrafik zeigt die belegten Speicheranteile je Website bezogen auf die gesamte verfügbare Speicherkapazität des Netzwerks.', 'rrze-multisite-manager');
        }

        return __('Mindestens eine Website hat unbegrenzten Speicherplatz. Daher zeigt die Tortengrafik nur die Verteilung des aktuell verwendeten Speicherplatzes je Website.', 'rrze-multisite-manager');
    }
}
