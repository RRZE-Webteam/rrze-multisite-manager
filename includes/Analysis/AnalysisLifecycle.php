<?php

namespace RRZE\MultisiteManager\Analysis;

defined('ABSPATH') || exit;

/** Bounded upgrade and expiry cleanup; never loads legacy analysis payloads. */
class AnalysisLifecycle {
    public const HOOK = 'rrze_msm_analysis_maintenance';
    private const SITE_VERSION = 'rrze_msm_analysis_storage_version';
    private const CURSOR = 'rrze_msm_analysis_upgrade_cursor';

    public function register(): void {
        add_action(self::HOOK, [$this, 'runMaintenance']);
        add_action('wp_delete_site', [$this, 'deleteSite']);
        if ((int)get_site_option('rrze_msm_analysis_schema', 0) !== AnalysisStore::SCHEMA_VERSION) {
            global $wpdb;
            $lock = 'rrze_msm_schema_' . md5($wpdb->base_prefix);
            if ((int)\RRZE\MultisiteManager\Support\AtomicLock::query('SELECT GET_LOCK(%s, 0)', $lock) !== 1) {
                throw new \RuntimeException(esc_html__('Analysis storage is being updated. Retry shortly.', 'rrze-multisite-manager'));
            }
            try {
                (new AnalysisStore())->install();
            } finally {
                \RRZE\MultisiteManager\Support\AtomicLock::query('SELECT RELEASE_LOCK(%s)', $lock);
            }
        }
        // The maintenance event lives in the main site's Cron table. Checking it
        // from every other website would switch to the main site and load its
        // options on each request. Requests and Cron runs of the main site
        // itself are sufficient to restore a missing schedule.
        if (!is_main_site()) {
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void {
        switch_to_blog((int)get_main_site_id(get_current_network_id()));
        try {
            wp_clear_scheduled_hook(self::HOOK);
            wp_clear_scheduled_hook(self::HOOK, [true]);
        } finally {
            restore_current_blog();
        }
    }

    /** Request prompt, bounded cleanup after an explicit site-analysis removal. */
    public static function scheduleMaintenanceSoon(): void {
        $main = (int)get_main_site_id(get_current_network_id());
        switch_to_blog($main);
        try {
            if (!wp_next_scheduled(self::HOOK, [true])) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, [true]);
            }
        } finally {
            restore_current_blog();
        }
    }

    /** Called before any new site analysis; safe to repeat after an interrupted upgrade. */
    public static function prepareSite(int $siteId): void {
        if ((int)get_blog_option($siteId, self::SITE_VERSION, 0) === AnalysisStore::DATA_VERSION) {
            return;
        }
        global $wpdb;
        $lock = 'rrze_msm_upgrade_' . md5($wpdb->base_prefix . ':' . $siteId);
        if ((int)\RRZE\MultisiteManager\Support\AtomicLock::query('SELECT GET_LOCK(%s, 0)', $lock) !== 1) {
            throw new \RuntimeException(esc_html__('Analysis storage is being updated. Retry shortly.', 'rrze-multisite-manager'));
        }
        switch_to_blog($siteId);
        try {
            if ((int)get_option(self::SITE_VERSION, 0) === AnalysisStore::DATA_VERSION) {
                return;
            }
            // Respect live-worker admission while discarding old index reservations.
            (new \RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService())->clearSite($siteId);
            $names = [
                'rrze_msm_site_storage_analysis_result', 'rrze_msm_site_storage_analysis_result_meta',
                'rrze_msm_site_media_metadata_analysis_result', 'rrze_msm_shortcode_block_analysis_result',
                'rrze_msm_shortcode_block_analysis_state', '_transient_rrze_msm_shortcode_block_analysis_state',
                '_transient_timeout_rrze_msm_shortcode_block_analysis_state',
            ];
            $placeholders = implode(',', array_fill(0, count($names), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $existing = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)", ...$names));
            if ($wpdb->last_error) {
                throw new \RuntimeException(esc_html__('Old analysis data could not be removed.', 'rrze-multisite-manager'));
            }
            $flags = (array)get_option('rrze_msm_analysis_reanalysis', []);
            foreach ($existing as $name) {
                $flags[str_contains($name, 'shortcode') ? 'content' : 'storage'] = true;
            }
            foreach (['content' => 'rrze_msm_shortcode_block_analysis_status', 'storage' => 'rrze_msm_storage_analysis_scheduler_status'] as $kind => $option) {
                $status = get_option($option, []);
                if ($status) {
                    $flags[$kind] = true;
                    self::saveOption($option, ['status' => 'error', 'phase' => 'idle', 'last_error' => __('Analysis storage has changed. Run a new analysis.', 'rrze-multisite-manager')]);
                }
            }
            // Persist the internal reanalysis marker before deleting its evidence.
            if ($flags) {
                self::saveOption('rrze_msm_analysis_reanalysis', $flags);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            (new AnalysisStore())->check($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)", ...$names)));
            foreach ($names as $name) {
                wp_cache_delete($name, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete('rrze_msm_shortcode_block_analysis_state', 'transient');
            // Known old network-cache keys may exist only in an external cache.
            $cacheKeys = [];
            foreach (['v1', 'v2'] as $version) {
                foreach (['base', 'orphan'] as $phase) {
                    $cacheKeys[] = 'rrze_msm_site_storage_analysis_' . $phase . '_state_resumable_' . $version . '_' . $siteId;
                }
                for ($bucket = 0; $bucket < 256; $bucket++) {
                    $cacheKeys[] = 'rrze_msm_site_storage_attachment_index_bucket_resumable_' . $version . '_' . $siteId . '_' . sprintf('%02x', $bucket);
                }
            }
            wp_cache_delete_multiple($cacheKeys, 'site-transient');
            self::saveOption(self::SITE_VERSION, AnalysisStore::DATA_VERSION);
        } finally {
            restore_current_blog();
            \RRZE\MultisiteManager\Support\AtomicLock::query('SELECT RELEASE_LOCK(%s)', $lock);
        }
    }

    private static function saveOption(string $key, mixed $value): void {
        if (get_option($key, null) === $value) {
            return;
        }
        $saved = get_option($key, null) === null ? add_option($key, $value, '', false) : update_option($key, $value, false);
        (new AnalysisStore())->check($saved);
    }

    public static function completed(int $siteId, string $kind): void {
        $flags = (array)get_blog_option($siteId, 'rrze_msm_analysis_reanalysis', []);
        unset($flags[$kind]);
        update_blog_option($siteId, 'rrze_msm_analysis_reanalysis', $flags);
    }

    public function unavailableNotice(): void {
        if (current_user_can('manage_network_options')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Analysis storage could not be initialized. Check the database permissions and retry. Multisite Manager has been paused for this request.', 'rrze-multisite-manager') . '</p></div>';
        }
    }

    public function deleteSite(\WP_Site $site): void {
        global $wpdb;
        $table = (new AnalysisStore())->table('runs');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
        (new AnalysisStore())->check($wpdb->query($wpdb->prepare("UPDATE $table SET status = 'obsolete', slot = run_id, expires_at = %d WHERE network_id = %d AND blog_id = %d", time(), (int)$site->site_id, (int)$site->blog_id)));
    }

    public function runMaintenance(): void {
        try {
            $this->maintain();
        } catch (\Throwable $error) {
            do_action('rrze.log.error', 'RRZE-MSM: Analysis maintenance will be retried', ['network_id' => get_current_network_id(), 'message' => $error->getMessage()]);
            if (get_current_blog_id() === (int)get_main_site_id(get_current_network_id()) && !wp_next_scheduled(self::HOOK, [true])) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, [true]);
            }
        }
    }

    public function maintain(): void {
        if (get_current_blog_id() !== (int)get_main_site_id(get_current_network_id())) {
            return;
        }
        global $wpdb;
        $store = new AnalysisStore();
        $cursor = (int)get_site_option(self::CURSOR, 0);
        if ($cursor >= 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $ids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d ORDER BY blog_id LIMIT 10", get_current_network_id(), $cursor));
            if ($wpdb->last_error) {
                throw new \RuntimeException(esc_html__('Analysis data could not be read or saved. Retry the analysis.', 'rrze-multisite-manager'));
            }
            foreach ($ids as $id) {
                self::prepareSite((int)$id);
                update_site_option(self::CURSOR, (int)$id);
            }
            if (count($ids) < 10) {
                update_site_option(self::CURSOR, -1);
            }
        }
        // Exact plugin-owned prefixes only. No payload is fetched or unserialized.
        $patterns = [];
        foreach (['_site_transient_', '_site_transient_timeout_'] as $prefix) {
            // rrze_msm_plugin_source_: removed per-plugin registration index (1.3.36-4).
            foreach (['rrze_msm_site_storage_analysis_', 'rrze_msm_site_storage_attachment_index_', 'rrze_msm_site_media_metadata_analysis_', 'rrze_msm_plugin_source_'] as $suffix) {
                $patterns[] = $wpdb->prepare('meta_key LIKE %s', $wpdb->esc_like($prefix . $suffix) . '%');
            }
        }
        $where = implode(' OR ', $patterns);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND ($where) ORDER BY meta_id LIMIT 250", get_current_network_id()));
        if ($wpdb->last_error) {
            throw new \RuntimeException(esc_html__('Old analysis data could not be removed.', 'rrze-multisite-manager'));
        }
        foreach ($rows as $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $store->check($wpdb->delete($wpdb->sitemeta, ['meta_id' => (int)$row->meta_id, 'site_id' => get_current_network_id()]));
            wp_cache_delete(get_current_network_id() . ':' . $row->meta_key, 'site-options');
            $key = preg_replace('/^_site_transient_(?:timeout_)?/', '', $row->meta_key);
            wp_cache_delete($key, 'site-transient');
        }
        delete_site_option('rrze_msm_plugin_source_index_generation');
        $hasExpired = $this->cleanupExpired();
        if (((int)get_site_option(self::CURSOR, 0) >= 0 || count($rows) === 250 || $hasExpired) && !wp_next_scheduled(self::HOOK, [true])) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, [true]);
        }
    }
    /** Each invocation removes at most 250 rows per kind from at most ten expired runs. */
    public function cleanupExpired(): bool {
        global $wpdb;
        $store = new AnalysisStore();
        $runs = $store->table('runs');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
        $expired = $wpdb->get_results($wpdb->prepare("SELECT id, blog_id, run_id FROM $runs WHERE network_id = %d AND expires_at > 0 AND expires_at <= %d ORDER BY expires_at, id LIMIT 10", get_current_network_id(), time()));
        if ($wpdb->last_error) {
            throw new \RuntimeException(esc_html__('Analysis data could not be read or saved. Retry the analysis.', 'rrze-multisite-manager'));
        }
        foreach ($expired as $run) {
            // Claim expired runs before deleting children. Completed publications cannot be selected.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $runs SET status = 'obsolete', slot = run_id WHERE id = %d AND network_id = %d AND expires_at > 0 AND expires_at <= %d", $run->id, get_current_network_id(), time()));
            $store->check($claimed);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $runs WHERE id = %d AND network_id = %d", $run->id, get_current_network_id()));
            if ($status !== 'obsolete') {
                continue;
            }
            $remaining = false;
            foreach (['shortcodes', 'blocks', 'mediaindex'] as $kind) {
                $table = $store->table($kind);
                $scope = $wpdb->prepare('network_id = %d AND blog_id = %d AND run_id = %s', get_current_network_id(), $run->blog_id, $run->run_id);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
                $store->check($wpdb->query("DELETE FROM $table WHERE $scope LIMIT 250"));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
                $exists = $wpdb->get_var("SELECT 1 FROM $table WHERE $scope LIMIT 1");
                if ($wpdb->last_error) {
                    throw new \RuntimeException(esc_html__('Analysis data could not be read or saved. Retry the analysis.', 'rrze-multisite-manager'));
                }
                $remaining = $remaining || (bool)$exists;
            }
            if (!$remaining) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
                $store->check($wpdb->delete($runs, ['id' => (int)$run->id, 'network_id' => get_current_network_id(), 'status' => 'obsolete']));
            }
        }
        return !empty($expired);
    }

    /** Called only by the existing full-data cleanup after it has stopped active work. */
    public function purgeNetworkBatch(): bool {
        global $wpdb;
        $store = new AnalysisStore();
        $remaining = false;
        foreach (['plugin_metrics_staging', 'theme_metrics_staging', 'plugin_metrics', 'theme_metrics', 'shortcodes', 'blocks', 'mediaindex', 'runs'] as $kind) {
            $table = $store->table($kind);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $store->check($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE network_id = %d LIMIT 250", get_current_network_id())));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table or schema marker; table names come from the AnalysisStore::table() allowlist, values are prepared.
            $exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE network_id = %d LIMIT 1", get_current_network_id()));
            if ($wpdb->last_error) {
                throw new \RuntimeException(esc_html__('Analysis data could not be read or saved. Retry the analysis.', 'rrze-multisite-manager'));
            }
            $remaining = $remaining || (bool)$exists;
            if ($kind !== 'runs' && $remaining) {
                // Keep run identities until all child rows have been removed.
                break;
            }
        }
        return $remaining;
    }

}
