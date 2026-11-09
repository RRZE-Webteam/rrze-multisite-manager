<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Analysis\AnalysisStore;
use RRZE\MultisiteManager\Analysis\ContentAnalysisStore;

/** Requires the disposable WordPress/MySQL test installation; never use production data. */
class AnalysisStoreTest extends \WP_UnitTestCase {
    public function testTablesReturnsEverySupportedCentralAnalysisTable(): void {
        global $wpdb;
        $store = new AnalysisStore();

        self::assertSame([
            $wpdb->base_prefix . 'rrze_msm_analysis_runs',
            $wpdb->base_prefix . 'rrze_msm_analysis_shortcodes',
            $wpdb->base_prefix . 'rrze_msm_analysis_blocks',
            $wpdb->base_prefix . 'rrze_msm_analysis_mediaindex',
            $wpdb->base_prefix . 'rrze_msm_analysis_dashboard_metrics',
            $wpdb->base_prefix . 'rrze_msm_analysis_plugin_metrics',
            $wpdb->base_prefix . 'rrze_msm_analysis_theme_metrics',
            $wpdb->base_prefix . 'rrze_msm_analysis_plugin_metrics_staging',
            $wpdb->base_prefix . 'rrze_msm_analysis_theme_metrics_staging',
        ], $store->tables());
    }

    public function testSchemaReplayPublicationAndCheckpointSeparation(): void {
        global $wpdb;
        $store = new AnalysisStore();
        $store->install();
        $siteId = get_current_blog_id();
        $content = new ContentAnalysisStore();
        $content->discard($siteId);
        $run = $store->ensureRun($siteId, 'content', 'content');
        $state = [
            'site_id' => $siteId, 'run_id' => $run->run_id, 'phase' => 'blocks',
            'shortcode_last_post_id' => 7, 'block_last_post_id' => 7,
            'shortcode_processed_posts' => 1, 'block_processed_posts' => 1, 'total_posts' => 1,
            'shortcodes' => [['post_id' => 7, 'shortcode' => 'test', 'raw_shortcode' => '[test]', 'registered' => true]],
            'blocks' => [['post_id' => 7, 'block' => 'core/image', 'occurrence' => 0]],
        ];
        try {
            $content->save($siteId, $state);
            $content->save($siteId, $state);
            self::assertCount(1, $store->findings($siteId, $run->run_id, 'shortcodes')['entries']);
            self::assertCount(1, $store->findings($siteId, $run->run_id, 'blocks')['entries']);
            $checkpoint = $content->load($siteId);
            self::assertSame([], $checkpoint['blocks']);
            self::assertSame(7, $checkpoint['shortcode_last_post_id']);
            self::assertArrayNotHasKey('shortcode_last_post_id', $store->readEntry('blocks', $siteId, $run->run_id, 'checkpoint'));
            $content->finish($siteId, $state);
            self::assertSame($run->run_id, $store->summary($siteId)['run_id']);
            self::assertSame([], $content->load($siteId));
            foreach (['runs', 'shortcodes', 'blocks', 'mediaindex'] as $kind) {
                $table = $store->table($kind);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Integration test against the plugin-owned tables.
                $definition = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)));
                self::assertSame('innodb', strtolower($definition->Engine));
            }
        } finally {
            foreach (['shortcodes', 'blocks', 'mediaindex', 'runs'] as $kind) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Integration test against the plugin-owned tables.
                $wpdb->delete($store->table($kind), ['network_id' => get_current_network_id(), 'blog_id' => $siteId, 'run_id' => $run->run_id]);
            }
        }
    }
    public function testSchemaRepairAddsMissingColumnsWithoutReplacingAnalysisData(): void {
        global $wpdb;
        $store = new AnalysisStore();
        $store->install();
        $siteId = get_current_blog_id();
        $run = $store->ensureRun($siteId, 'schema-repair-test', 'test');
        $store->saveCheckpoint($siteId, 'schema-repair-test', 'test', ['preserved' => true]);
        update_blog_option($siteId, 'rrze_msm_analysis_storage_version', AnalysisStore::DATA_VERSION);
        try {
            foreach (['shortcodes', 'blocks'] as $kind) {
                $table = $store->table($kind);
                foreach (['registered', 'provider', 'category', 'origin', 'description'] as $column) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Integration test against the plugin-owned tables.
                    self::assertNotFalse($wpdb->query("ALTER TABLE $table DROP COLUMN $column"));
                }
            }
            update_site_option('rrze_msm_analysis_schema', 2);
            $store->install();
            self::assertSame(AnalysisStore::SCHEMA_VERSION, (int)get_site_option('rrze_msm_analysis_schema'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from AnalysisStore::table() allowlist.
            self::assertContains('plugin_file', $wpdb->get_col('SHOW COLUMNS FROM ' . $store->table('plugin_metrics')));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from AnalysisStore::table() allowlist.
            self::assertContains('stylesheet', $wpdb->get_col('SHOW COLUMNS FROM ' . $store->table('theme_metrics')));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from AnalysisStore::table() allowlist.
            self::assertContains('plugin_file', $wpdb->get_col('SHOW COLUMNS FROM ' . $store->table('plugin_metrics_staging')));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from AnalysisStore::table() allowlist.
            self::assertContains('stylesheet', $wpdb->get_col('SHOW COLUMNS FROM ' . $store->table('theme_metrics_staging')));
            self::assertSame(AnalysisStore::DATA_VERSION, (int)get_blog_option($siteId, 'rrze_msm_analysis_storage_version'));
            self::assertSame(['preserved' => true], $store->checkpoint($siteId, 'schema-repair-test'));
            foreach (['shortcodes', 'blocks'] as $kind) {
                $store->writeEntry($kind, $siteId, $run->run_id, 'repair', ['post_id' => 7, 'registered' => true], 'test', 7);
                self::assertTrue($store->readEntry($kind, $siteId, $run->run_id, 'repair')['registered']);
            }
        } finally {
            $store->install();
            foreach (['shortcodes', 'blocks', 'runs'] as $kind) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Integration test against the plugin-owned tables.
                $wpdb->delete($store->table($kind), ['network_id' => get_current_network_id(), 'blog_id' => $siteId, 'run_id' => $run->run_id]);
            }
        }
    }

}
