<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Support\BoundedArray;

defined('ABSPATH') || exit;

/** Owns the bounded temporary storage budget and its cleanup lifecycle. */
class StorageAnalysisResourceService {
    public const MAX_INDEX_BYTES = 33554432;
    public const MAX_BUCKET_BYTES = 131072;
    public const MAX_STATE_BYTES = 1048576;
    public const MAINTENANCE_HOOK = 'rrze_msm_storage_resource_maintenance';
    private const SLOT_PREFIX = 'rrze_msm_storage_resource_slot_';
    private const MUTEX = 'rrze_msm_storage_resource_mutex';
    private const MAINTENANCE_OFFSET = 'rrze_msm_storage_resource_maintenance_offset';
    private const MAX_SLOTS = Config::STORAGE_ANALYSIS_MAX_CONCURRENT_RUNS;
    private Config $config;
    private static array $liveWorkerTokens = [];

    public function __construct(?Config $config = null) {
        $this->config = $config ?? new Config();
    }

    public function register(): void {
        add_action(self::MAINTENANCE_HOOK, [$this, 'maintain']);
    }

    public function acquire(int $siteId): void {
        $this->locked(function () use ($siteId): void {
            $available = -1;
            $occupiedSiteIds = [];
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $record = get_option(self::SLOT_PREFIX . $slot, []);
                if (!empty($record['site_id']) && (int)($record['expires_at'] ?? 0) < time() && !$this->hasLiveWorker((int)$record['site_id'], false)) {
                    $this->deleteSiteKeys((int)$record['site_id']);
                    delete_option(self::SLOT_PREFIX . $slot);
                    $record = [];
                }
                if (is_array($record) && (int)($record['site_id'] ?? 0) === $siteId) {
                    $record['expires_at'] = time() + DAY_IN_SECONDS;
                    $this->writeSlot($slot, $record);
                    return;
                }
                if (!empty($record['site_id']) && $slot < $this->config->getStorageAnalysisMaxConcurrentRuns()) {
                    $occupiedSiteIds[] = (int)$record['site_id'];
                }
                if ($available < 0 && empty($record) && $slot < $this->config->getStorageAnalysisMaxConcurrentRuns()) {
                    $available = $slot;
                }
            }
            if ($available < 0) {
                if (empty($occupiedSiteIds)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                    throw new StorageAnalysisBusyException(__('Storage analysis capacity is occupied. The analysis will be retried.', 'rrze-multisite-manager'));
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new StorageAnalysisBusyException(sprintf(
                    /* translators: %s: comma-separated IDs of websites holding temporary storage-analysis reservations. */
                    _n(
                        'Storage analysis capacity is reserved by website ID %s. The analysis will be retried.',
                        'Storage analysis capacity is reserved by website IDs %s. The analysis will be retried.',
                        count($occupiedSiteIds),
                        'rrze-multisite-manager'
                    ),
                    implode(', ', $occupiedSiteIds)
                ));
            }
            $this->writeSlot($available, ['site_id' => $siteId, 'expires_at' => time() + DAY_IN_SECONDS, 'buckets' => []]);
        });
        $this->ensureMaintenance();
    }

    public function run(int $siteId, callable $callback): mixed {
        $token = wp_generate_uuid4();
        $selected = -1;
        $this->locked(function () use ($siteId, $token, &$selected): void {
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $key = 'rrze_msm_storage_worker_' . $slot;
                $record = get_option($key, []);
                if (!empty($record) && $this->canReclaimWorker($record)) {
                    delete_option($key);
                    $record = [];
                }
                if (!empty($record) && (int)($record['site_id'] ?? 0) === $siteId) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                    throw new StorageAnalysisBusyException(__('Another request is already processing this website.', 'rrze-multisite-manager'));
                }
                if ($selected < 0 && empty($record) && $slot < $this->config->getStorageAnalysisMaxConcurrentRuns()) {
                    $selected = $slot;
                }
            }
            if ($selected < 0 || !add_option('rrze_msm_storage_worker_' . $selected, ['site_id' => $siteId, 'token' => $token, 'expires_at' => time() + DAY_IN_SECONDS, 'host' => (string)gethostname(), 'pid' => getmypid()], '', false)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new StorageAnalysisBusyException(__('Storage analysis workers are occupied. The analysis will be retried.', 'rrze-multisite-manager'));
            }
        });
        self::$liveWorkerTokens[$token] = true;
        try {
            $this->ensureMaintenance();
            return $callback();
        } finally {
            unset(self::$liveWorkerTokens[$token]);
            $this->locked(function () use ($selected, $token): void {
                $key = 'rrze_msm_storage_worker_' . $selected;
                $record = get_option($key, []);
                if (($record['token'] ?? '') === $token) {
                    delete_option($key);
                }
            });
        }
    }

    /** A lease expiry alone never proves that a blocked PHP worker has stopped. */
    private function canReclaimWorker(array $record): bool {
        if (($record['host'] ?? '') !== (string)gethostname() || (int)($record['pid'] ?? 0) <= 0) {
            return false;
        }
        if ((int)$record['pid'] === getmypid() && !PHP_ZTS) {
            return empty(self::$liveWorkerTokens[(string)($record['token'] ?? '')]);
        }
        return function_exists('posix_kill') && !posix_kill((int)$record['pid'], 0)
            && function_exists('posix_get_last_error') && posix_get_last_error() === 3;
    }

    private function hasLiveWorker(int $siteId, bool $allowOwned): bool {
        for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
            $record = get_option('rrze_msm_storage_worker_' . $slot, []);
            if ((int)($record['site_id'] ?? 0) === $siteId && !$this->canReclaimWorker($record)) {
                if (!$allowOwned || empty(self::$liveWorkerTokens[(string)($record['token'] ?? '')])) {
                    return true;
                }
            }
        }
        return false;
    }

    public function reserveIndexBytes(int $siteId, string $bucket, array $entries): void {
        $this->writeIndexBucketGroup($siteId, [$bucket => $entries], static function (): void {});
    }

    /** Check and write a bounded group under one network budget lock. */
    public function writeIndexBucketGroup(int $siteId, array $buckets, callable $write): void {
        if ($siteId <= 0 || !$buckets) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new \RuntimeException(__('Invalid media index reservation.', 'rrze-multisite-manager'));
        }
        $sizes = [];
        foreach ($buckets as $bucket => $entries) {
            $bucket = (string)$bucket;
            if (preg_match('/^[a-f0-9]{2}$/', $bucket) !== 1 || !is_array($entries)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new \RuntimeException(__('Invalid media index reservation.', 'rrze-multisite-manager'));
            }
            if (!BoundedArray::fits($entries, self::MAX_BUCKET_BYTES)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new \RuntimeException(__('The media index exceeded its segment limit. The analysis was stopped without classifying additional files as orphaned.', 'rrze-multisite-manager'));
            }
            $sizes[$bucket] = strlen(serialize($entries)); // Each input is already bounded to 128 KiB.
        }
        $this->locked(function () use ($siteId, $sizes, $write): void {
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $record = get_option(self::SLOT_PREFIX . $slot, []);
                if ((int)($record['site_id'] ?? 0) !== $siteId) {
                    continue;
                }
                $record['buckets'] = array_replace((array)($record['buckets'] ?? []), $sizes);
                if (array_sum($record['buckets']) > self::MAX_INDEX_BYTES) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                    throw new \RuntimeException(__('The media index exceeded the temporary storage budget. The previous completed result is preserved.', 'rrze-multisite-manager'));
                }
                $record['expires_at'] = time() + DAY_IN_SECONDS;
                // Reserve before writing so an interrupted request cannot leave unaccounted segments.
                $this->writeSlot($slot, $record);
                $write();
                return;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new StorageAnalysisBusyException(__('The temporary analysis reservation is unavailable. The analysis will be retried.', 'rrze-multisite-manager'));
        });
    }

    public function assertBucketPresent(int $siteId, string $bucket, bool $present): void {
        if ($present) {
            return;
        }
        $expected = false;
        $this->inMainSite(function () use ($siteId, $bucket, &$expected): void {
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $record = get_option(self::SLOT_PREFIX . $slot, []);
                if ((int)($record['site_id'] ?? 0) === $siteId && !empty($record['buckets'][$bucket])) {
                    $expected = true;
                }
            }
        });
        if ($expected) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new \RuntimeException(__('A media index segment is missing or expired. The analysis was stopped to avoid incorrect orphan classifications.', 'rrze-multisite-manager'));
        }
    }

    public static function saveState(string $key, array $state, int $ttl): void {
        if (!BoundedArray::fits($state, self::MAX_STATE_BYTES)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new \RuntimeException(__('The analysis working state exceeded its storage limit. The previous completed result is preserved.', 'rrze-multisite-manager'));
        }
        if (get_site_transient($key) !== $state && !set_site_transient($key, $state, min($ttl, str_contains($key, 'storage_attachment_index_bucket_') ? 7 * DAY_IN_SECONDS : DAY_IN_SECONDS))) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new \RuntimeException(__('The temporary analysis state could not be saved.', 'rrze-multisite-manager'));
        }
    }

    public function release(int $siteId): void {
        $this->locked(function () use ($siteId): void {
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $record = get_option(self::SLOT_PREFIX . $slot, []);
                if ((int)($record['site_id'] ?? 0) === $siteId) {
                    delete_option(self::SLOT_PREFIX . $slot);
                }
            }
        });
    }

    private function writeSlot(int $slot, array $record): void {
        $key = self::SLOT_PREFIX . $slot;
        if (get_option($key, null) === $record) {
            return;
        }
        $saved = get_option($key, null) === null ? add_option($key, $record, '', false) : update_option($key, $record, false);
        if (!$saved) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
            throw new \RuntimeException(__('The temporary storage reservation could not be saved.', 'rrze-multisite-manager'));
        }
    }

    private function locked(callable $callback): void {
        $this->inMainSite(function () use ($callback): void {
            $record = get_option(self::MUTEX, []);
            if (is_array($record) && (int)($record['expires_at'] ?? 0) < time()) {
                delete_option(self::MUTEX);
            }
            $token = wp_generate_uuid4();
            if (!add_option(self::MUTEX, ['token' => $token, 'expires_at' => time() + MINUTE_IN_SECONDS], '', false)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new StorageAnalysisBusyException(__('The temporary storage budget is busy. The analysis will be retried.', 'rrze-multisite-manager'));
            }
            try {
                $callback();
            } finally {
                $record = get_option(self::MUTEX, []);
                if (($record['token'] ?? '') === $token) {
                    delete_option(self::MUTEX);
                }
            }
        });
    }

    private function inMainSite(callable $callback): void {
        $siteId = (int)get_main_site_id(get_current_network_id());
        $switched = $siteId !== get_current_blog_id();
        if ($switched) {
            switch_to_blog($siteId);
        }
        try {
            $callback();
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    public function ensureMaintenance(): void {
        $this->inMainSite(static function (): void {
            if (!wp_next_scheduled(self::MAINTENANCE_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::MAINTENANCE_HOOK);
            }
        });
    }

    /** Cleanup never reads large transient values into PHP. */
    public function clearSite(int $siteId): void {
        $this->locked(function () use ($siteId): void {
            if ($this->hasLiveWorker($siteId, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new StorageAnalysisBusyException(__('Another request is already processing this website.', 'rrze-multisite-manager'));
            }
            $this->deleteSiteKeys($siteId);
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $record = get_option(self::SLOT_PREFIX . $slot, []);
                if ((int)($record['site_id'] ?? 0) === $siteId) {
                    delete_option(self::SLOT_PREFIX . $slot);
                }
            }
        });
    }

    private function deleteSiteKeys(int $siteId): void {
        $keys = [];
        foreach (['v1', 'v2'] as $version) {
            $keys[] = 'rrze_msm_site_storage_analysis_base_state_resumable_' . $version . '_' . $siteId;
            $keys[] = 'rrze_msm_site_storage_analysis_orphan_state_resumable_' . $version . '_' . $siteId;
            foreach (range(0, 255) as $bucket) {
                $keys[] = 'rrze_msm_site_storage_attachment_index_bucket_resumable_' . $version . '_' . $siteId . '_' . str_pad(dechex($bucket), 2, '0', STR_PAD_LEFT);
            }
        }
        $this->deleteKeys($keys);
    }

    private function deleteKeys(array $keys): void {
        global $wpdb;
        foreach (array_chunk($keys, 50) as $chunk) {
            $names = [];
            foreach ($chunk as $key) {
                $names[] = '_site_transient_' . $key;
                $names[] = '_site_transient_timeout_' . $key;
            }
            $placeholders = implode(', ', array_fill(0, count($names), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact owned keys, bound placeholders and current-network scope; values are never loaded.
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key IN ({$placeholders})", ...array_merge([get_current_network_id()], $names)));
            if ($deleted === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new \RuntimeException(__('Temporary analysis data could not be removed from the database.', 'rrze-multisite-manager'));
            }
            foreach ($chunk as $key) {
                wp_cache_delete($key, 'site-transient');
                wp_cache_delete(get_current_network_id() . ':_site_transient_' . $key, 'site-options');
                wp_cache_delete(get_current_network_id() . ':_site_transient_timeout_' . $key, 'site-options');
            }
        }
    }

    public function maintain(): void {
        if (get_current_blog_id() !== (int)get_main_site_id(get_current_network_id())) {
            return;
        }
        $this->locked(function (): void {
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $record = get_option(self::SLOT_PREFIX . $slot, []);
                if (!empty($record['site_id']) && (int)($record['expires_at'] ?? 0) < time() && !$this->hasLiveWorker((int)$record['site_id'], false)) {
                    $this->deleteSiteKeys((int)$record['site_id']);
                    delete_option(self::SLOT_PREFIX . $slot);
                }
            }
        });
        $this->locked(function (): void {
            for ($slot = 0; $slot < self::MAX_SLOTS; $slot++) {
                $key = 'rrze_msm_storage_worker_' . $slot;
                $record = get_option($key, []);
                if (empty($record)) {
                    continue;
                }
                if ($this->canReclaimWorker($record)) {
                    delete_option($key);
                } elseif ((int)($record['expires_at'] ?? 0) < time() && empty($record['reported'])) {
                    $record['reported'] = true;
                    update_option($key, $record, false);
                    do_action('rrze.log.error', __('A stale storage worker cannot be verified as stopped. Its reservation remains occupied to prevent excess parallel work.', 'rrze-multisite-manager'), ['site_id' => (int)($record['site_id'] ?? 0), 'host' => (string)($record['host'] ?? ''), 'pid' => (int)($record['pid'] ?? 0)]);
                }
            }
        });
        global $wpdb;
        $base = $wpdb->esc_like('_site_transient_timeout_rrze_msm_site_storage_analysis_base_state_') . '%';
        $orphan = $wpdb->esc_like('_site_transient_timeout_rrze_msm_site_storage_analysis_orphan_state_') . '%';
        $index = $wpdb->esc_like('_site_transient_timeout_rrze_msm_site_storage_attachment_index_') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded expired-key cleanup, restricted to storage runtime data in this network.
        $keys = (array)$wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s) AND (CAST(meta_value AS UNSIGNED) < %d OR meta_key NOT LIKE %s) ORDER BY meta_id ASC LIMIT 250", get_current_network_id(), $base, $orphan, $index, time(), '%' . $wpdb->esc_like('_resumable_v2_') . '%'));
        $transients = [];
        foreach ($keys as $key) {
            $key = substr((string)$key, strlen('_site_transient_timeout_'));
            if (preg_match('/_resumable_v[12]_(\d+)(?:_|$)/', $key, $match) === 1 && $this->hasLiveWorker((int)$match[1], false)) {
                continue;
            }
            if (str_contains($key, 'storage_analysis_base_state_') || str_contains($key, 'storage_analysis_orphan_state_') || str_contains($key, 'storage_attachment_index_')) {
                $transients[] = $key;
            }
        }
        $this->deleteKeys($transients);
        $offset = (int)get_site_option(self::MAINTENANCE_OFFSET, 0);
        $siteIds = get_sites(['fields' => 'ids', 'network_id' => get_current_network_id(), 'number' => 25, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);
        foreach ($siteIds as $siteId) {
            switch_to_blog((int)$siteId);
            try {
                (new StorageAnalysisResultService())->repairCurrentOversizedResults();
            } finally {
                restore_current_blog();
            }
        }
        update_site_option(self::MAINTENANCE_OFFSET, count($siteIds) === 25 ? $offset + count($siteIds) : 0);
        if ((count($keys) >= 250 || count($siteIds) === 25) && !wp_next_scheduled(self::MAINTENANCE_HOOK, [true])) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::MAINTENANCE_HOOK, [true]);
        }
    }
}
