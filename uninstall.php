<?php

/**
 * Removes central derived analysis data only when WordPress deletes the plugin.
 * Normal deactivation deliberately retains these tables.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

foreach (['potential_shortcodes', 'runs', 'shortcodes', 'blocks', 'mediaindex', 'dashboard_metrics', 'plugin_metrics', 'theme_metrics', 'plugin_source_metrics', 'theme_source_metrics', 'plugin_metrics_staging', 'theme_metrics_staging', 'plugin_source_metrics_staging', 'theme_source_metrics_staging'] as $kind) {
    // The base prefix is supplied by the active WordPress installation. Quote
    // the identifier because prepared statements cannot bind table names.
    $table = str_replace('`', '``', $wpdb->base_prefix . 'rrze_msm_analysis_' . $kind);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin uninstall must remove its own central tables.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

delete_site_option('rrze_msm_analysis_schema');
delete_site_option('rrze_msm_response_sampling');
delete_site_option('rrze_msm_response_time_stats');
delete_site_option('rrze_msm_db_latency_probes');

