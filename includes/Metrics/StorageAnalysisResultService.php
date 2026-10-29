<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

use RRZE\MultisiteManager\Support\BoundedArray;
use RRZE\MultisiteManager\Support\SafeSerializedValue;

/**
 * Persists storage-analysis results and their compact status metadata.
 */
class StorageAnalysisResultService {
    private const RESULT_OPTION = 'rrze_msm_site_storage_analysis_result';
    private const META_OPTION = 'rrze_msm_site_storage_analysis_result_meta';
    private const MEDIA_METADATA_RESULT_OPTION = 'rrze_msm_site_media_metadata_analysis_result';
    private const MAX_STORAGE_RESULT_BYTES = 2097152;
    private const MAX_MEDIA_METADATA_RESULT_BYTES = 2097152;
    private const MAX_META_BYTES = 16384;

    public function getResult(int $siteId): array {
        return $this->getSiteResult($siteId, self::RESULT_OPTION);
    }

    public function getCurrentResult(): array {
        return $this->readBoundedOption(self::RESULT_OPTION);
    }

    /** @return array<int, string> */
    public function getPersistentOptionNames(): array {
        return [self::RESULT_OPTION, self::META_OPTION, self::MEDIA_METADATA_RESULT_OPTION];
    }

    public function saveCurrentResult(array $result): void {
        $this->updateCurrentOption(self::RESULT_OPTION, $result);
        $this->saveCurrentMeta($this->buildMeta($result));
        \RRZE\MultisiteManager\Analysis\AnalysisLifecycle::completed(get_current_blog_id(), 'storage');
    }

    public function getMeta(int $siteId): array {
        return $this->getSiteResult($siteId, self::META_OPTION);
    }

    public function ensureMeta(int $siteId, array $result): void {
        $meta = $this->buildMeta($result);

        if ($siteId <= 0 || $this->getMeta($siteId) === $meta) {
            return;
        }

        switch_to_blog($siteId);

        try {
            $this->saveCurrentMeta($meta);
        } finally {
            restore_current_blog();
        }
    }

    public function saveCurrentMeta(array $meta): void {
        $this->updateCurrentOption(self::META_OPTION, $meta);
    }

    public function getMediaMetadataResult(int $siteId): array {
        return $this->getSiteResult($siteId, self::MEDIA_METADATA_RESULT_OPTION);
    }

    public function saveCurrentMediaMetadataResult(array $result): array {
        return $this->updateCurrentOption(self::MEDIA_METADATA_RESULT_OPTION, $result);
    }

    public function buildMeta(array $result): array {
        return [
            'generated_at' => is_string($result['generated_at'] ?? null) ? (string)$result['generated_at'] : '',
            'orphan_analysis_state' => is_string($result['orphan_analysis_state'] ?? null) ? (string)$result['orphan_analysis_state'] : '',
            'orphan_analysis_generated_at' => is_string($result['orphan_analysis_generated_at'] ?? null) ? (string)$result['orphan_analysis_generated_at'] : '',
            'actual_bytes' => max(0, (int)($result['actual_bytes'] ?? 0)),
            'total_files' => max(0, (int)($result['total_files'] ?? 0)),
            'total_directories' => max(0, (int)($result['total_directories'] ?? 0)),
            'orphan_file_count' => max(0, (int)($result['orphan_file_count'] ?? 0)),
            'unused_attachment_file_count' => max(0, (int)($result['unused_attachment_file_count'] ?? 0)),
        ];
    }

    /** Repairs only oversized derived options, without reading their complete payloads. */
    public function repairCurrentOversizedResults(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Three exact owned option names; metadata and result byte limits differ.
        $names = (array)$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE (option_name IN (%s, %s) AND OCTET_LENGTH(option_value) > %d) OR (option_name = %s AND OCTET_LENGTH(option_value) > %d)", self::RESULT_OPTION, self::MEDIA_METADATA_RESULT_OPTION, self::MAX_STORAGE_RESULT_BYTES, self::META_OPTION, self::MAX_META_BYTES));
        foreach ($names as $name) {
            if (in_array($name, [self::RESULT_OPTION, self::MEDIA_METADATA_RESULT_OPTION, self::META_OPTION], true)) {
                $this->readBoundedOption((string)$name);
            }
        }
    }

    private function getSiteResult(int $siteId, string $option): array {
        if ($siteId <= 0) {
            return [];
        }
        switch_to_blog($siteId);
        try {
            return $this->readBoundedOption($option);
        } finally {
            restore_current_blog();
        }
    }

    private function readBoundedOption(string $option): array {
        if ((int)get_blog_option(get_current_blog_id(), 'rrze_msm_analysis_storage_version', 0) !== \RRZE\MultisiteManager\Analysis\AnalysisStore::DATA_VERSION) {
            return [];
        }
        $row = $this->getBoundedRow($option);
        if (!$row) {
            return [];
        }
        $limit = $option === self::META_OPTION ? self::MAX_META_BYTES : self::MAX_STORAGE_RESULT_BYTES;
        if ((int)$row->value_bytes > $limit) {
            // Derived details can be regenerated; never fetch an oversized legacy payload.
            $summary = $option === self::RESULT_OPTION ? $this->readBoundedOption(self::META_OPTION) : [];
            $fallback = $option === self::META_OPTION ? [] : $summary + ['result_truncated' => true, 'requires_reanalysis' => true, 'status' => 'idle', 'error' => __('The stored analysis exceeded its storage limit. Run the analysis again to restore its details.', 'rrze-multisite-manager')];
            $this->replaceOversizedOption($option, $fallback);
            return $fallback;
        }
        $decoded = (new SafeSerializedValue())->decode((string)$row->bounded_value, $limit)['value'];
        return is_array($decoded) ? BoundedArray::compact($decoded, $limit) : [];
    }

    private function getBoundedRow(string $option): ?object {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Size-gated legacy reads must not prime an oversized option in WordPress/PHP caches.
        return $wpdb->get_row($wpdb->prepare("SELECT OCTET_LENGTH(option_value) AS value_bytes, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS bounded_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", ($option === self::META_OPTION ? self::MAX_META_BYTES : self::MAX_STORAGE_RESULT_BYTES), $option));
    }

    private function replaceOversizedOption(string $option, array $value): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned result option only, guarded against replacing an intervening bounded write; no original media/content is changed.
        $saved = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off' WHERE option_name = %s AND OCTET_LENGTH(option_value) > %d", serialize($value), $option, ($option === self::META_OPTION ? self::MAX_META_BYTES : self::MAX_STORAGE_RESULT_BYTES)));
        if ($saved === 0) {
            $current = $this->getBoundedRow($option);
            if (!$current || (string)$current->bounded_value !== serialize($value)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new \RuntimeException(__('The analysis result changed during its update. Retry the analysis.', 'rrze-multisite-manager'));
            }
        }
        if ($saved === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new \RuntimeException(__('The oversized legacy analysis result could not be replaced.', 'rrze-multisite-manager'));
        }
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private function updateCurrentOption(string $option, array $value): array {
        $value = $option === self::META_OPTION ? BoundedArray::compact($value, self::MAX_META_BYTES) : ($option === self::MEDIA_METADATA_RESULT_OPTION
            ? $this->compactMediaMetadataResult($value)
            : $this->compactStorageResult($value));
        $row = $this->getBoundedRow($option);
        $limit = $option === self::META_OPTION ? self::MAX_META_BYTES : self::MAX_STORAGE_RESULT_BYTES;
        if ($row && (int)$row->value_bytes > $limit) {
            $this->replaceOversizedOption($option, $value);
            return $value;
        }
        $previous = get_option($option, null);
        if ($previous === $value) {
            return $value;
        }
        $saved = $previous === null ? add_option($option, $value, '', false) : update_option($option, $value, false);
        if (!$saved) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by callers when displayed.
            throw new \RuntimeException(__('The analysis result could not be saved to the database.', 'rrze-multisite-manager'));
        }
        return $value;
    }

    private function compactStorageResult(array $result): array {
        return BoundedArray::compact($result, self::MAX_STORAGE_RESULT_BYTES);
    }

    private function compactMediaMetadataResult(array $result): array {
        return BoundedArray::compact($result, self::MAX_MEDIA_METADATA_RESULT_BYTES);
    }
}
