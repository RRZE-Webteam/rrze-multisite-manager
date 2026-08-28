<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

trait MetricsServiceEnvironmentTrait {
    public function getEnvironmentOverview(): array {
        global $wpdb, $wp_version;

        $dashboardData = $this->getDashboardData();
        $summary = is_array($dashboardData['summary'] ?? null) ? $dashboardData['summary'] : [];
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];
        $themes = is_array($dashboardData['themes'] ?? null) ? $dashboardData['themes'] : [];
        $networkStorage = is_array($dashboardData['network_storage_usage'] ?? null) ? $dashboardData['network_storage_usage'] : [];
        $network = get_network();
        $uploadDir = wp_get_upload_dir();
        $pluginUpdates = get_site_transient('update_plugins');
        $themeUpdates = get_site_transient('update_themes');
        $coreUpdates = get_site_transient('update_core');
        $coreUpgradeCount = 0;
        $availablePluginUpdates = is_object($pluginUpdates) && is_array($pluginUpdates->response ?? null) ? count($pluginUpdates->response) : 0;
        $availableThemeUpdates = is_object($themeUpdates) && is_array($themeUpdates->response ?? null) ? count($themeUpdates->response) : 0;
        $installedPlugins = [];
        $muPlugins = [];
        $dropins = [];
        $registrationMode = (string)get_site_option('registration', 'none');
        $defaultSiteQuota = (int)get_site_option('blog_upload_space', 100);
        $siteUserCount = function_exists('get_user_count') ? (int)get_user_count() : 0;
        $enabledThemeCount = count(array_filter($themes, [self::class, 'isNetworkEnabledTheme']));
        $unusedThemeCount = count(array_filter($themes, [self::class, 'isUnusedTheme']));
        $sections = [];
        $warnings = [];
        $update = null;
        $inactivePlugins = is_array($pluginUsage['inactive_plugins'] ?? null) ? $pluginUsage['inactive_plugins'] : [];

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $installedPlugins = get_plugins();
        $muPlugins = function_exists('get_mu_plugins') ? get_mu_plugins() : [];
        $dropins = function_exists('get_dropins') ? get_dropins() : [];

        if (is_object($coreUpdates) && !empty($coreUpdates->updates) && is_array($coreUpdates->updates)) {
            foreach ($coreUpdates->updates as $update) {
                if (is_object($update) && (($update->response ?? '') === 'upgrade')) {
                    $coreUpgradeCount++;
                }
            }
        }

        $sections[] = [
            'title' => __('System foundation', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('WordPress version', 'rrze-multisite-manager'), 'value' => (string)$wp_version],
                ['label' => __('Multisite', 'rrze-multisite-manager'), 'value' => __('Yes', 'rrze-multisite-manager')],
                ['label' => __('Network ID', 'rrze-multisite-manager'), 'value' => (string)get_current_network_id()],
                ['label' => __('Network name', 'rrze-multisite-manager'), 'value' => $network instanceof \WP_Network ? (string)$network->site_name : ''],
                ['label' => __('Network URL', 'rrze-multisite-manager'), 'value' => network_home_url('/')],
                ['label' => __('WordPress environment', 'rrze-multisite-manager'), 'value' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : ''],
                ['label' => __('PHP version', 'rrze-multisite-manager'), 'value' => PHP_VERSION],
                ['label' => __('Database server', 'rrze-multisite-manager'), 'value' => method_exists($wpdb, 'db_server_info') ? (string)$wpdb->db_server_info() : ''],
                ['label' => __('Webserver', 'rrze-multisite-manager'), 'value' => isset($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : ''],
                ['label' => __('Locale', 'rrze-multisite-manager'), 'value' => get_locale()],
                ['label' => __('Timezone', 'rrze-multisite-manager'), 'value' => wp_timezone_string()],
            ],
        ];

        $sections[] = [
            'title' => __('Paths and infrastructure', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => 'ABSPATH', 'value' => ABSPATH, 'code' => true],
                ['label' => 'WP_CONTENT_DIR', 'value' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '', 'code' => true],
                ['label' => 'WP_PLUGIN_DIR', 'value' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '', 'code' => true],
                ['label' => __('Upload base directory', 'rrze-multisite-manager'), 'value' => (string)($uploadDir['basedir'] ?? ''), 'code' => true],
                ['label' => __('Upload base URL', 'rrze-multisite-manager'), 'value' => (string)($uploadDir['baseurl'] ?? '')],
            ],
        ];

        $sections[] = [
            'title' => __('Size and runtime limits', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => 'memory_limit', 'value' => (string)ini_get('memory_limit')],
                ['label' => 'max_execution_time', 'value' => (string)ini_get('max_execution_time')],
                ['label' => 'upload_max_filesize', 'value' => (string)ini_get('upload_max_filesize')],
                ['label' => 'post_max_size', 'value' => (string)ini_get('post_max_size')],
                ['label' => 'max_input_vars', 'value' => (string)ini_get('max_input_vars')],
                [
                    'label' => __('Default quota per site', 'rrze-multisite-manager'),
                    'value' => $defaultSiteQuota > 0
                        ? sprintf(
                            /* translators: %d: default site quota in megabytes. */
                            __('%d MB', 'rrze-multisite-manager'),
                            $defaultSiteQuota
                        )
                        : __('Not set', 'rrze-multisite-manager'),
                ],
                ['label' => __('Upload check disabled', 'rrze-multisite-manager'), 'value' => defined('UPLOADS') ? __('Configured via constant', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
            ],
        ];

        $sections[] = [
            'title' => __('Network configuration', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Installation type', 'rrze-multisite-manager'), 'value' => is_subdomain_install() ? __('Subdomain', 'rrze-multisite-manager') : __('Subdirectory', 'rrze-multisite-manager')],
                ['label' => __('Registration', 'rrze-multisite-manager'), 'value' => $this->getRegistrationModeLabel($registrationMode)],
                ['label' => __('Adding new users allowed', 'rrze-multisite-manager'), 'value' => !empty(get_site_option('add_new_users')) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('Forced admin SSL', 'rrze-multisite-manager'), 'value' => force_ssl_admin() ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('File editing disabled in backend', 'rrze-multisite-manager'), 'value' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('File modifications disabled', 'rrze-multisite-manager'), 'value' => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
            ],
        ];

        $sections[] = [
            'title' => __('Update and maintenance status', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Core updates available', 'rrze-multisite-manager'), 'value' => (string)$coreUpgradeCount, 'numeric' => true],
                ['label' => __('Plugin updates available', 'rrze-multisite-manager'), 'value' => (string)$availablePluginUpdates, 'numeric' => true],
                ['label' => __('Theme updates available', 'rrze-multisite-manager'), 'value' => (string)$availableThemeUpdates, 'numeric' => true],
                ['label' => __('WP-Cron disabled', 'rrze-multisite-manager'), 'value' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('Automatic updates globally disabled', 'rrze-multisite-manager'), 'value' => defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('WP_DEBUG', 'rrze-multisite-manager'), 'value' => defined('WP_DEBUG') && WP_DEBUG ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('WP_DEBUG_LOG', 'rrze-multisite-manager'), 'value' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
                ['label' => __('SCRIPT_DEBUG', 'rrze-multisite-manager'), 'value' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')],
            ],
        ];

        $sections[] = [
            'title' => __('Plugin and theme inventory', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Installed plugins', 'rrze-multisite-manager'), 'value' => (string)count($installedPlugins), 'numeric' => true],
                ['label' => __('Network-active plugins', 'rrze-multisite-manager'), 'value' => (string)((int)($pluginUsage['summary']['network_active_plugins'] ?? 0)), 'numeric' => true],
                ['label' => __('Inactive plugins', 'rrze-multisite-manager'), 'value' => (string)count($inactivePlugins), 'numeric' => true],
                ['label' => __('MU plugins', 'rrze-multisite-manager'), 'value' => (string)count($muPlugins), 'numeric' => true],
                ['label' => __('Drop-ins', 'rrze-multisite-manager'), 'value' => (string)count($dropins), 'numeric' => true],
                ['label' => __('Installed themes', 'rrze-multisite-manager'), 'value' => (string)count($themes), 'numeric' => true],
                ['label' => __('Themes enabled network-wide', 'rrze-multisite-manager'), 'value' => (string)$enabledThemeCount, 'numeric' => true],
                ['label' => __('Inactive themes', 'rrze-multisite-manager'), 'value' => (string)$unusedThemeCount, 'numeric' => true],
            ],
        ];

        $sections[] = [
            'title' => __('Network metrics', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Total websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['total_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Active websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['active_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Archived websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['archived_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Blocked websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['spam_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Websites marked for deletion', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['deleted_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Total users', 'rrze-multisite-manager'), 'value' => (string)$siteUserCount, 'numeric' => true],
                ['label' => __('Used network storage', 'rrze-multisite-manager'), 'value' => (string)($networkStorage['total_used_label'] ?? '')],
                ['label' => __('Maximum network storage', 'rrze-multisite-manager'), 'value' => !empty($networkStorage['has_unlimited_site']) ? __('Not meaningfully calculable (unlimited sites present)', 'rrze-multisite-manager') : (string)($networkStorage['total_max_label'] ?? '')],
            ],
        ];

        if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
            $warnings[] = __('Debug mode or debug log is active. That is not ideal for a production network.', 'rrze-multisite-manager');
        }

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $warnings[] = __('WP-Cron is disabled. In that case, execution of scheduled tasks must be ensured elsewhere.', 'rrze-multisite-manager');
        }

        if ($coreUpgradeCount > 0 || $availablePluginUpdates > 0 || $availableThemeUpdates > 0) {
            $warnings[] = sprintf(
                /* translators: 1: core update count, 2: plugin update count, 3: theme update count. */
                __('Updates are available: core %1$d, plugins %2$d, themes %3$d.', 'rrze-multisite-manager'),
                $coreUpgradeCount,
                $availablePluginUpdates,
                $availableThemeUpdates
            );
        }

        if ((int)($summary['spam_sites'] ?? 0) > 0 || (int)($summary['archived_sites'] ?? 0) > 0 || (int)($summary['deleted_sites'] ?? 0) > 0) {
            $warnings[] = sprintf(
                /* translators: 1: archived site count, 2: blocked site count, 3: deleted site count. */
                __('There are notable site statuses in the network: archived %1$d, blocked %2$d, marked for deletion %3$d.', 'rrze-multisite-manager'),
                (int)($summary['archived_sites'] ?? 0),
                (int)($summary['spam_sites'] ?? 0),
                (int)($summary['deleted_sites'] ?? 0)
            );
        }

        if (!empty($networkStorage['percent']) && (int)$networkStorage['percent'] >= 90) {
            $warnings[] = sprintf(
                /* translators: %d: network storage usage percentage. */
                __('The calculable network storage is already %d%% used.', 'rrze-multisite-manager'),
                (int)$networkStorage['percent']
            );
        }

        if ($defaultSiteQuota <= 0) {
            $warnings[] = __('No default storage limit is set for new sites.', 'rrze-multisite-manager');
        }

        if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
            $warnings[] = __('File editing in the WordPress backend is not disabled.', 'rrze-multisite-manager');
        }

        return [
            'warnings' => $warnings,
            'sections' => $sections,
            'summary' => [
                'plugin_updates' => $availablePluginUpdates,
                'theme_updates' => $availableThemeUpdates,
                'core_updates' => $coreUpgradeCount,
                'total_sites' => (int)($summary['total_sites'] ?? 0),
            ],
        ];
    }

    protected function getRegistrationModeLabel(string $registrationMode): string {
        $labels = [
            'none' => __('No registration', 'rrze-multisite-manager'),
            'user' => __('User accounts only', 'rrze-multisite-manager'),
            'blog' => __('Websites only', 'rrze-multisite-manager'),
            'all' => __('User accounts and websites', 'rrze-multisite-manager'),
        ];

        return $labels[$registrationMode] ?? $registrationMode;
    }

    protected static function isNetworkEnabledTheme(array $theme): bool {
        return !empty($theme['network_enabled']);
    }
}
