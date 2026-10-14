<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Analyses missing descriptive metadata on media-library attachments.
 */
class MediaMetadataAnalysisService {
    protected const BATCH_SIZE = 50;
    protected const RESULT_LIMIT = 500;

    protected StorageAnalysisStateService $state;
    protected StorageAnalysisResultService $results;
    protected int $cacheVersion;

    public function __construct(
        StorageAnalysisStateService $state,
        StorageAnalysisResultService $results,
        int $cacheVersion
    ) {
        $this->state = $state;
        $this->results = $results;
        $this->cacheVersion = max(1, $cacheVersion);
    }

    public function getAnalysis(int $siteId): array {
        if ($siteId <= 0) {
            return [];
        }

        // Legacy transient payloads have no trustworthy byte limit; start fresh instead of loading them.
        return $this->results->getMediaMetadataResult($siteId);
    }

    public function runBatch(int $siteId, bool $restart = false): array {
        if ($siteId <= 0) {
            return [
                'success' => false,
                'message' => __('Invalid website.', 'rrze-multisite-manager'),
            ];
        }

        switch_to_blog($siteId);

        try {
            $state = (new StorageAnalysisResourceService())->run($siteId, function () use ($siteId, $restart): array {
                $state = $this->getAnalysis($siteId);

                if ($restart || empty($state)) {
                    $state = $this->getDefaultState($siteId);
                }

                if (($state['status'] ?? '') === 'running') {
                    $state = $this->processState($state);
                }

                return $this->results->saveCurrentMediaMetadataResult($state);
            });
        } finally {
            restore_current_blog();
        }

        return [
            'success' => true,
            'message' => (string)($state['message'] ?? ''),
            'analysis' => $state,
        ];
    }

    protected function getDefaultState(int $siteId): array {
        return [
            'site_id' => $siteId,
            'status' => 'running',
            'message' => __('Media metadata analysis is running.', 'rrze-multisite-manager'),
            'last_attachment_id' => 0,
            'processed' => 0,
            'counts' => [
                'images' => 0,
                'documents' => 0,
                'spreadsheets' => 0,
                'audio_video' => 0,
            ],
            'results' => [
                'images' => [],
                'documents' => [],
                'spreadsheets' => [],
                'audio_video' => [],
            ],
            'started_at' => current_time('mysql', true),
            'finished_at' => '',
        ];
    }

    protected function processState(array $state): array {
        global $wpdb;

        $lastAttachmentId = (int)($state['last_attachment_id'] ?? 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded, site-local batch query is required for the scheduled analysis; its result is persisted with the analysis state.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, LEFT(p.post_title, 2048) AS post_title,
                    CASE WHEN TRIM(p.post_excerpt) <> '' THEN '1' ELSE '' END AS post_excerpt,
                    CASE WHEN TRIM(p.post_content) <> '' THEN '1' ELSE '' END AS post_content,
                    p.post_mime_type, p.post_modified_gmt,
                    CASE WHEN TRIM(pm_alt.meta_value) <> '' THEN '1' ELSE '' END AS image_alt,
                    CASE WHEN OCTET_LENGTH(pm_file.meta_value) <= 1024 THEN pm_file.meta_value ELSE '' END AS attached_file,
                    CASE WHEN OCTET_LENGTH(pm_metadata.meta_value) <= 262144 THEN pm_metadata.meta_value ELSE NULL END AS attachment_metadata,
                    (OCTET_LENGTH(p.post_title) > 2048 OR OCTET_LENGTH(pm_file.meta_value) > 1024 OR OCTET_LENGTH(pm_metadata.meta_value) > 262144) AS input_truncated
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_alt
                    ON pm_alt.post_id = p.ID AND pm_alt.meta_key = '_wp_attachment_image_alt'
                LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
                LEFT JOIN {$wpdb->postmeta} pm_metadata ON pm_metadata.post_id = p.ID AND pm_metadata.meta_key = '_wp_attachment_metadata'
                WHERE p.post_type = 'attachment' AND p.ID > %d
                ORDER BY p.ID ASC
                LIMIT %d",
                $lastAttachmentId,
                self::BATCH_SIZE
            )
        );

        foreach ($rows as $row) {
            if (!empty($row->input_truncated)) {
                $state['result_truncated'] = true;
            }
            $lastAttachmentId = (int)($row->ID ?? 0);
            $category = $this->getCategory((string)($row->post_mime_type ?? ''));
            $entry = $category === 'images'
                ? $this->buildImageEntry($row)
                : $this->buildNonImageEntry($row, $category);
            $resultCategory = in_array($category, ['audio', 'video'], true) ? 'audio_video' : $category;

            $state['counts'][$resultCategory]++;

            if (
                (int)($entry['missing_count'] ?? 0) > 0
                && count((array)$state['results'][$resultCategory]) < self::RESULT_LIMIT
            ) {
                $state['results'][$resultCategory][] = $entry;
            }
        }

        $state['last_attachment_id'] = $lastAttachmentId;
        $state['processed'] = (int)($state['processed'] ?? 0) + count($rows);

        if (count($rows) < self::BATCH_SIZE) {
            foreach ((array)$state['results'] as $category => $entries) {
                usort($entries, [$this, 'sortEntries']);
                $state['results'][$category] = $entries;
            }

            $state['status'] = 'complete';
            $state['finished_at'] = current_time('mysql', true);
            $state['message'] = __('The media metadata analysis has been completed.', 'rrze-multisite-manager');

            return $state;
        }

        $state['message'] = sprintf(
            /* translators: %s: processed media items. */
            __('%s media items have already been checked.', 'rrze-multisite-manager'),
            number_format_i18n((int)$state['processed'])
        );

        return $state;
    }

    public function sortEntries(array $left, array $right): int {
        $missingComparison = (int)($right['missing_count'] ?? 0) <=> (int)($left['missing_count'] ?? 0);

        if ($missingComparison !== 0) {
            return $missingComparison;
        }

        return strcasecmp((string)($left['title'] ?? ''), (string)($right['title'] ?? ''));
    }

    protected function buildImageEntry(object $row): array {
        $attachmentId = (int)($row->ID ?? 0);

        return $this->buildEntry($row, [
            'alt' => trim((string)($row->image_alt ?? '')) !== '',
            'caption' => trim((string)($row->post_excerpt ?? '')) !== '',
            'description' => trim((string)($row->post_content ?? '')) !== '',
        ]);
    }

    protected function buildNonImageEntry(object $row, string $category): array {
        return $this->buildEntry($row, [
            'caption' => trim((string)($row->post_excerpt ?? '')) !== '',
            'description' => trim((string)($row->post_content ?? '')) !== '',
        ], $category);
    }

    protected function buildEntry(object $row, array $fields, string $category = ''): array {
        $attachmentId = (int)($row->ID ?? 0);
        $filePath = (new \RRZE\MultisiteManager\Filesystem\Path())->normalizeRelative((string)($row->attached_file ?? ''));
        $date = (string)($row->post_modified_gmt ?? '');
        $missingCount = count(array_filter($fields, static fn(bool $isPresent): bool => !$isPresent));

        return [
            'attachment_id' => $attachmentId,
            'title' => trim((string)($row->post_title ?? '')) !== '' ? (string)$row->post_title : basename($filePath),
            'file_name' => basename($filePath),
            'mime_type' => (string)($row->post_mime_type ?? ''),
            'preview_url' => str_starts_with((string)($row->post_mime_type ?? ''), 'image/') ? $this->getBoundedPreviewUrl($row, $filePath) : '',
            'media_edit_url' => get_admin_url(get_current_blog_id(), 'post.php?post=' . $attachmentId . '&action=edit'),
            'modified' => $date,
            'modified_timestamp' => $this->parseDateTimestamp($date),
            'modified_label' => $this->formatDate($date),
            'fields' => $fields,
            'missing_count' => $missingCount,
        ];
    }

    /** Uses bounded query data; does not prime an unlimited WordPress post-meta cache. */
    protected function getBoundedPreviewUrl(object $row, string $filePath): string {
        $metadata = (new \RRZE\MultisiteManager\Support\SafeSerializedValue())->decode($row->attachment_metadata ?? '')['value'];
        $medium = is_array($metadata) ? ($metadata['sizes']['medium']['file'] ?? '') : '';
        if (is_string($medium) && $medium !== '') {
            $directory = dirname($filePath);
            $candidate = (new \RRZE\MultisiteManager\Filesystem\Path())->normalizeRelative(($directory === '.' ? '' : $directory . '/') . $medium);
            if ($candidate !== '') {
                $filePath = $candidate;
            }
        }
        $uploads = wp_get_upload_dir();
        return $filePath === '' ? '' : trailingslashit((string)($uploads['baseurl'] ?? '')) . $filePath;
    }

    protected function getCategory(string $mimeType): string {
        $mimeType = strtolower(trim($mimeType));

        if (str_starts_with($mimeType, 'image/')) {
            return 'images';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if ($mimeType === 'text/csv' || str_contains($mimeType, 'spreadsheet') || str_contains($mimeType, 'excel') || str_contains($mimeType, 'opendocument.spreadsheet')) {
            return 'spreadsheets';
        }

        return 'documents';
    }

    protected function parseDateTimestamp(string $date): int {
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return 0;
        }

        return (int)strtotime($date);
    }

    protected function formatDate(string $date): string {
        $timestamp = $this->parseDateTimestamp($date);

        return $timestamp > 0 ? wp_date('d.m.Y H:i', $timestamp) : __('Unknown', 'rrze-multisite-manager');
    }
}
