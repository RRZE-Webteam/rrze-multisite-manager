<?php

namespace RRZE\MultisiteManager\Presentation;

defined('ABSPATH') || exit;

/**
 * Provides consistent labels and accents for technical status values.
 */
class StatusFormatter {
    public function getOperationalLabel(string $status, string $emptyLabel = ''): string {
        $labels = [
            'provisioning' => __('Provisioning in progress', 'rrze-multisite-manager'),
            'healthy' => __('Technically reachable', 'rrze-multisite-manager'),
            'dns_missing' => __('DNS missing', 'rrze-multisite-manager'),
            'unreachable' => __('Technically unreachable', 'rrze-multisite-manager'),
            'retired' => __('Out of service', 'rrze-multisite-manager'),
        ];

        return $labels[$status] ?? ($status !== '' ? $status : $emptyLabel);
    }

    public function getOperationalAccent(string $status): string {
        $accents = [
            'provisioning' => 'info',
            'healthy' => 'positive',
            'dns_missing' => 'danger',
            'unreachable' => 'warning',
            'retired' => 'neutral',
        ];

        return $accents[$status] ?? 'neutral';
    }

    public function getMonitoringLabel(string $status, ?string $emptyLabel = null): string {
        $labels = [
            'ok' => __('OK', 'rrze-multisite-manager'),
            'missing' => __('Missing', 'rrze-multisite-manager'),
            'error' => __('Error', 'rrze-multisite-manager'),
            'timeout' => __('Timeout', 'rrze-multisite-manager'),
            'unknown' => __('Unknown', 'rrze-multisite-manager'),
            'pending' => __('Pending', 'rrze-multisite-manager'),
        ];

        return $labels[$status] ?? ($status !== '' ? $status : ($emptyLabel ?? __('Not set', 'rrze-multisite-manager')));
    }

    public function formatMonitoringValue(string $status, string $detail = '', int $code = 0, ?string $emptyLabel = null): string {
        $label = $this->getMonitoringLabel($status, $emptyLabel);
        $parts = [];

        if ($code > 0 && strpos($detail, (string)$code) === false) {
            $parts[] = (string)$code;
        }

        if ($detail !== '') {
            $parts[] = $detail;
        }

        if (empty($parts)) {
            return $label;
        }

        return sprintf(
            /* translators: 1: monitoring status label, 2: monitoring detail text. */
            __('%1$s (%2$s)', 'rrze-multisite-manager'),
            $label,
            implode(' | ', $parts)
        );
    }
}
