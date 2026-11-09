<?php

namespace RRZE\MultisiteManager\Analysis;

defined('ABSPATH') || exit;

/** Separate intermediate findings and checkpoints for the two content phases. */
class ContentAnalysisStore {
    private AnalysisStore $store;

    public function __construct() {
        $this->store = new AnalysisStore();
    }

    public function load(int $siteId): array {
        $run = $this->store->active($siteId, 'content');
        if (!$run) {
            return [];
        }
        $state = $this->store->decode($run->payload);
        foreach (['shortcodes', 'blocks'] as $kind) {
            $checkpoint = $this->store->readEntry($kind, $siteId, $run->run_id, 'checkpoint');
            if ($checkpoint === null && !empty($state['site_id'])) {
                throw new \RuntimeException(esc_html__('An analysis checkpoint is missing. Start a new analysis.', 'rrze-multisite-manager'));
            }
            $state = array_replace($state, $checkpoint ?? []);
        }
        $state['run_id'] = $run->run_id;
        $state['shortcodes'] = [];
        $state['blocks'] = [];
        return $state;
    }

    /** All findings and both cursors become durable together; replay is idempotent. */
    public function save(int $siteId, array $state): void {
        global $wpdb;
        $run = $this->store->ensureRun($siteId, 'content', 'content');
        if (!empty($state['run_id']) && $state['run_id'] !== $run->run_id) {
            throw new \RuntimeException(esc_html__('The analysis checkpoint expired. Start a new analysis.', 'rrze-multisite-manager'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table; table names come from the AnalysisStore::table() allowlist, values are prepared, results must not be object-cached.
        $this->store->check($wpdb->query('START TRANSACTION'));
        try {
            $runs = $this->store->table('runs');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table; table names come from the AnalysisStore::table() allowlist, values are prepared, results must not be object-cached.
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $runs WHERE network_id = %d AND blog_id = %d AND run_id = %s AND status = 'running' AND expires_at > %d FOR UPDATE", get_current_network_id(), $siteId, $run->run_id, time()))) {
                throw new \RuntimeException(esc_html__('The analysis checkpoint expired. Start a new analysis.', 'rrze-multisite-manager'));
            }
            foreach (['shortcodes' => 'shortcode_', 'blocks' => 'block_'] as $kind => $prefix) {
                foreach ((array)($state[$kind] ?? []) as $entry) {
                    $identity = [(int)$entry['post_id'], (string)($entry['metadata_key'] ?? ''), (string)($entry['raw_shortcode'] ?? $entry['block'] ?? ''), (int)($entry['occurrence'] ?? 0)];
                    $this->store->writeEntry($kind, $siteId, $run->run_id, (string)($entry['identity_hash'] ?? hash('sha256', serialize($identity))), $entry, (string)($entry['shortcode'] ?? $entry['block'] ?? ''), (int)$entry['post_id']);
                }
                unset($state[$kind]);
                $checkpoint = [];
                foreach (array_keys($state) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $checkpoint[$key] = $state[$key];
                        unset($state[$key]);
                    }
                }
                $this->store->writeEntry($kind, $siteId, $run->run_id, 'checkpoint', $checkpoint);
            }
            $this->store->saveCheckpoint($siteId, 'content', 'content', $state);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table; table names come from the AnalysisStore::table() allowlist, values are prepared, results must not be object-cached.
            $this->store->check($wpdb->query('COMMIT'));
        } catch (\Throwable $error) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table; table names come from the AnalysisStore::table() allowlist, values are prepared, results must not be object-cached.
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function finish(int $siteId, array $state): array {
        global $wpdb;
        $run = $this->store->active($siteId, 'content');
        if (!$run || (!empty($state['run_id']) && $state['run_id'] !== $run->run_id)) {
            throw new \RuntimeException(esc_html__('The analysis checkpoint expired. Start a new analysis.', 'rrze-multisite-manager'));
        }
        $summary = [
            'generated_at' => current_time('mysql', true),
            'total_posts' => (int)$state['total_posts'],
            'processed_posts' => (int)$state['block_processed_posts'],
            'truncated' => !empty($state['shortcode_truncated']) || !empty($state['block_truncated']),
        ];
        foreach (['shortcodes', 'blocks'] as $kind) {
            $table = $this->store->table($kind);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned central analysis table; table names come from the AnalysisStore::table() allowlist, values are prepared, results must not be object-cached.
            $counts = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS entries, COUNT(DISTINCT name) AS names FROM $table WHERE network_id = %d AND blog_id = %d AND run_id = %s AND post_id > 0", get_current_network_id(), $siteId, $run->run_id));
            if (!$counts || $wpdb->last_error) {
                AnalysisStorageException::raise($kind . '-counts', $siteId);
            }
            $summary[$kind . '_count'] = (int)$counts->entries;
            $summary[$kind . '_distinct'] = (int)$counts->names;
            $checkpoint = $this->store->readEntry($kind, $siteId, $run->run_id, 'checkpoint') ?? [];
            $prefix = $kind === 'blocks' ? 'block_' : 'shortcode_';
            $summary[$kind . '_truncated'] = !empty($checkpoint[$prefix . 'truncated']);
            $summary[$kind . '_omitted_count'] = (int)($checkpoint[$prefix . 'omitted_count'] ?? 0);
            $summary[$kind . '_omitted_sources'] = array_slice((array)($checkpoint[$prefix . 'omitted_sources'] ?? []), 0, 10);
            $summary[$kind . '_limit_omitted'] = (int)($checkpoint[$prefix . 'limit_omitted'] ?? 0);
        }
        $this->store->publish($siteId, $run->run_id, $summary);
        AnalysisLifecycle::completed($siteId, 'content');
        return $summary;
    }

    public function discard(int $siteId): void {
        $this->store->retire($siteId, 'content');
    }
}
