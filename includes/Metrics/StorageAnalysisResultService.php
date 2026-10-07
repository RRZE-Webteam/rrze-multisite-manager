<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Persists storage-analysis results and their compact status metadata.
 */
class StorageAnalysisResultService {
    private const RESULT_OPTION = 'rrze_msm_site_storage_analysis_result';
    private const META_OPTION = 'rrze_msm_site_storage_analysis_result_meta';
    private const MEDIA_METADATA_RESULT_OPTION = 'rrze_msm_site_media_metadata_analysis_result';

    public function getResult(int $siteId): array {
        $result = $siteId > 0 ? get_blog_option($siteId, self::RESULT_OPTION, []) : [];
        return is_array($result) ? $result : [];
    }

    public function getCurrentResult(): array {
        $result = get_option(self::RESULT_OPTION, []);
        return is_array($result) ? $result : [];
    }

    /** @return array<int, string> */
    public function getPersistentOptionNames(): array {
        return [self::RESULT_OPTION, self::META_OPTION, self::MEDIA_METADATA_RESULT_OPTION];
    }

    public function saveCurrentResult(array $result): void {
        $this->updateCurrentOption(self::RESULT_OPTION, $result);
        $this->saveCurrentMeta($this->buildMeta($result));
    }

    public function getMeta(int $siteId): array {
        $meta = $siteId > 0 ? get_blog_option($siteId, self::META_OPTION, []) : [];
        return is_array($meta) ? $meta : [];
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
        $result = $siteId > 0 ? get_blog_option($siteId, self::MEDIA_METADATA_RESULT_OPTION, []) : [];
        return is_array($result) ? $result : [];
    }

    public function saveCurrentMediaMetadataResult(array $result): void {
        $this->updateCurrentOption(self::MEDIA_METADATA_RESULT_OPTION, $result);
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

    private function updateCurrentOption(string $option, array $value): void {
        if (get_option($option, null) === null) {
            add_option($option, $value, '', false);
            return;
        }

        update_option($option, $value, false);
    }
}
