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
            'title' => __('Systembasis', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('WordPress-Version', 'rrze-multisite-manager'), 'value' => (string)$wp_version],
                ['label' => __('Multisite', 'rrze-multisite-manager'), 'value' => __('Ja', 'rrze-multisite-manager')],
                ['label' => __('Netzwerk-ID', 'rrze-multisite-manager'), 'value' => (string)get_current_network_id()],
                ['label' => __('Netzwerkname', 'rrze-multisite-manager'), 'value' => $network instanceof \WP_Network ? (string)$network->site_name : ''],
                ['label' => __('Netzwerk-URL', 'rrze-multisite-manager'), 'value' => network_home_url('/')],
                ['label' => __('WordPress-Umgebung', 'rrze-multisite-manager'), 'value' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : ''],
                ['label' => __('PHP-Version', 'rrze-multisite-manager'), 'value' => PHP_VERSION],
                ['label' => __('Datenbankserver', 'rrze-multisite-manager'), 'value' => method_exists($wpdb, 'db_server_info') ? (string)$wpdb->db_server_info() : ''],
                ['label' => __('Webserver', 'rrze-multisite-manager'), 'value' => isset($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : ''],
                ['label' => __('Locale', 'rrze-multisite-manager'), 'value' => get_locale()],
                ['label' => __('Zeitzone', 'rrze-multisite-manager'), 'value' => wp_timezone_string()],
            ],
        ];

        $sections[] = [
            'title' => __('Pfade und Infrastruktur', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => 'ABSPATH', 'value' => ABSPATH, 'code' => true],
                ['label' => 'WP_CONTENT_DIR', 'value' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '', 'code' => true],
                ['label' => 'WP_PLUGIN_DIR', 'value' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '', 'code' => true],
                ['label' => __('Upload-Basisverzeichnis', 'rrze-multisite-manager'), 'value' => (string)($uploadDir['basedir'] ?? ''), 'code' => true],
                ['label' => __('Upload-Basis-URL', 'rrze-multisite-manager'), 'value' => (string)($uploadDir['baseurl'] ?? '')],
            ],
        ];

        $sections[] = [
            'title' => __('Größen- und Laufzeitlimits', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => 'memory_limit', 'value' => (string)ini_get('memory_limit')],
                ['label' => 'max_execution_time', 'value' => (string)ini_get('max_execution_time')],
                ['label' => 'upload_max_filesize', 'value' => (string)ini_get('upload_max_filesize')],
                ['label' => 'post_max_size', 'value' => (string)ini_get('post_max_size')],
                ['label' => 'max_input_vars', 'value' => (string)ini_get('max_input_vars')],
                ['label' => __('Standard-Quota pro Site', 'rrze-multisite-manager'), 'value' => $defaultSiteQuota > 0 ? sprintf(__('%d MB', 'rrze-multisite-manager'), $defaultSiteQuota) : __('Nicht gesetzt', 'rrze-multisite-manager')],
                ['label' => __('Upload-Prüfung deaktiviert', 'rrze-multisite-manager'), 'value' => defined('UPLOADS') ? __('Konstantenbasiert konfiguriert', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
            ],
        ];

        $sections[] = [
            'title' => __('Netzwerk-Konfiguration', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Installationsart', 'rrze-multisite-manager'), 'value' => is_subdomain_install() ? __('Subdomain', 'rrze-multisite-manager') : __('Unterverzeichnis', 'rrze-multisite-manager')],
                ['label' => __('Registrierung', 'rrze-multisite-manager'), 'value' => $this->getRegistrationModeLabel($registrationMode)],
                ['label' => __('Neue Benutzer hinzufügen erlaubt', 'rrze-multisite-manager'), 'value' => !empty(get_site_option('add_new_users')) ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('Erzwungenes Admin-SSL', 'rrze-multisite-manager'), 'value' => force_ssl_admin() ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('Dateibearbeitung im Backend gesperrt', 'rrze-multisite-manager'), 'value' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('Dateimodifikationen gesperrt', 'rrze-multisite-manager'), 'value' => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
            ],
        ];

        $sections[] = [
            'title' => __('Update- und Wartungsstatus', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Core-Updates verfügbar', 'rrze-multisite-manager'), 'value' => (string)$coreUpgradeCount, 'numeric' => true],
                ['label' => __('Plugin-Updates verfügbar', 'rrze-multisite-manager'), 'value' => (string)$availablePluginUpdates, 'numeric' => true],
                ['label' => __('Theme-Updates verfügbar', 'rrze-multisite-manager'), 'value' => (string)$availableThemeUpdates, 'numeric' => true],
                ['label' => __('WP-Cron deaktiviert', 'rrze-multisite-manager'), 'value' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('Automatische Updates global deaktiviert', 'rrze-multisite-manager'), 'value' => defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('WP_DEBUG', 'rrze-multisite-manager'), 'value' => defined('WP_DEBUG') && WP_DEBUG ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('WP_DEBUG_LOG', 'rrze-multisite-manager'), 'value' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
                ['label' => __('SCRIPT_DEBUG', 'rrze-multisite-manager'), 'value' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')],
            ],
        ];

        $sections[] = [
            'title' => __('Plugin- und Theme-Bestand', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Installierte Plugins', 'rrze-multisite-manager'), 'value' => (string)count($installedPlugins), 'numeric' => true],
                ['label' => __('Netzwerkweit aktive Plugins', 'rrze-multisite-manager'), 'value' => (string)((int)($pluginUsage['summary']['network_active_plugins'] ?? 0)), 'numeric' => true],
                ['label' => __('Nicht aktivierte Plugins', 'rrze-multisite-manager'), 'value' => (string)count($inactivePlugins), 'numeric' => true],
                ['label' => __('MU-Plugins', 'rrze-multisite-manager'), 'value' => (string)count($muPlugins), 'numeric' => true],
                ['label' => __('Drop-ins', 'rrze-multisite-manager'), 'value' => (string)count($dropins), 'numeric' => true],
                ['label' => __('Installierte Themes', 'rrze-multisite-manager'), 'value' => (string)count($themes), 'numeric' => true],
                ['label' => __('Netzwerkweit verfügbare Themes', 'rrze-multisite-manager'), 'value' => (string)$enabledThemeCount, 'numeric' => true],
                ['label' => __('Nicht aktivierte Themes', 'rrze-multisite-manager'), 'value' => (string)$unusedThemeCount, 'numeric' => true],
            ],
        ];

        $sections[] = [
            'title' => __('Netzwerk-Kennzahlen', 'rrze-multisite-manager'),
            'rows' => [
                ['label' => __('Gesamtzahl Websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['total_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Aktive Websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['active_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Archivierte Websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['archived_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Gesperrte Websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['spam_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Zum Löschen markierte Websites', 'rrze-multisite-manager'), 'value' => (string)((int)($summary['deleted_sites'] ?? 0)), 'numeric' => true],
                ['label' => __('Gesamtzahl Benutzer', 'rrze-multisite-manager'), 'value' => (string)$siteUserCount, 'numeric' => true],
                ['label' => __('Genutzter Netzwerkspeicher', 'rrze-multisite-manager'), 'value' => (string)($networkStorage['total_used_label'] ?? '')],
                ['label' => __('Maximaler Netzwerkspeicher', 'rrze-multisite-manager'), 'value' => !empty($networkStorage['has_unlimited_site']) ? __('Nicht sinnvoll berechenbar (unbegrenzte Sites vorhanden)', 'rrze-multisite-manager') : (string)($networkStorage['total_max_label'] ?? '')],
            ],
        ];

        if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
            $warnings[] = __('Debug-Modus oder Debug-Log sind aktiv. Das ist für ein Produktivnetz nicht ideal.', 'rrze-multisite-manager');
        }

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $warnings[] = __('WP-Cron ist deaktiviert. Dann muss die Ausführung geplanter Aufgaben anderweitig sichergestellt sein.', 'rrze-multisite-manager');
        }

        if ($coreUpgradeCount > 0 || $availablePluginUpdates > 0 || $availableThemeUpdates > 0) {
            $warnings[] = sprintf(
                __('Es stehen Updates an: Core %1$d, Plugins %2$d, Themes %3$d.', 'rrze-multisite-manager'),
                $coreUpgradeCount,
                $availablePluginUpdates,
                $availableThemeUpdates
            );
        }

        if ((int)($summary['spam_sites'] ?? 0) > 0 || (int)($summary['archived_sites'] ?? 0) > 0 || (int)($summary['deleted_sites'] ?? 0) > 0) {
            $warnings[] = sprintf(
                __('Im Netzwerk gibt es auffällige Site-Status: archiviert %1$d, gesperrt %2$d, zum Löschen markiert %3$d.', 'rrze-multisite-manager'),
                (int)($summary['archived_sites'] ?? 0),
                (int)($summary['spam_sites'] ?? 0),
                (int)($summary['deleted_sites'] ?? 0)
            );
        }

        if (!empty($networkStorage['percent']) && (int)$networkStorage['percent'] >= 90) {
            $warnings[] = sprintf(
                __('Der berechenbare Netzwerkspeicher ist bereits zu %d%% belegt.', 'rrze-multisite-manager'),
                (int)$networkStorage['percent']
            );
        }

        if ($defaultSiteQuota <= 0) {
            $warnings[] = __('Für neue Sites ist kein Standard-Speicherlimit gesetzt.', 'rrze-multisite-manager');
        }

        if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
            $warnings[] = __('Die Dateibearbeitung im WordPress-Backend ist nicht gesperrt.', 'rrze-multisite-manager');
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
            'none' => __('Keine Registrierung', 'rrze-multisite-manager'),
            'user' => __('Nur Benutzerkonten', 'rrze-multisite-manager'),
            'blog' => __('Nur Websites', 'rrze-multisite-manager'),
            'all' => __('Benutzerkonten und Websites', 'rrze-multisite-manager'),
        ];

        return $labels[$registrationMode] ?? $registrationMode;
    }

    protected static function isNetworkEnabledTheme(array $theme): bool {
        return !empty($theme['network_enabled']);
    }
}
