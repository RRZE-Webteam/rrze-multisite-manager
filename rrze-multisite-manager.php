<?php

/**
 * Plugin Name:     RRZE Multisite Manager
 * Plugin URI:
 * Description:     Multisite management for WordPress 
 * Version:         1.3.18
 * Requires at least: 6.9.4
 * Requires PHP:      8.3
 * Author:          RRZE-Webteam
 * Author URI:      https://www.wp.rrze.fau.de
 * License:         GNU General Public License v3
 * License URI:     http://www.gnu.org/licenses/gpl-3.0.html
 * Domain Path:     /languages
 * Text Domain:     rrze-multisite-manager
 * Network:         true
 */

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

use RRZE\MultisiteManager\Main;
use RRZE\MultisiteManager\Plugin;
use RRZE\MultisiteManager\Metrics\StorageAnalysisService;

spl_autoload_register(__NAMESPACE__ . '\autoload');

function autoload(string $class): void {
    $prefix = __NAMESPACE__;
    $baseDir = __DIR__ . '/includes/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $relativeClass = ltrim($relativeClass, '\\');
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
}

add_action('init', __NAMESPACE__ . '\loadTextdomain', 0);
add_action('init', __NAMESPACE__ . '\loaded', 5);
add_action('admin_init', __NAMESPACE__ . '\loadTextdomain', 0);
add_filter('load_textdomain_mofile', __NAMESPACE__ . '\preferBundledTextdomainMofile', 10, 2);
register_activation_hook(__FILE__, __NAMESPACE__ . '\activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivate');
add_action('admin_post_rrze_msm_confirm_network_deactivation', __NAMESPACE__ . '\confirmNetworkDeactivation');

function loadTextdomain(): void {
    $domain = 'rrze-multisite-manager';
    $bundledMofile = plugin_dir_path(__FILE__) . 'languages/' . $domain . '-' . determine_locale() . '.mo';

    if (is_readable($bundledMofile)) {
        // Replace a potentially stale global language pack with the bundled catalog.
        unload_textdomain($domain, true);
        load_textdomain($domain, $bundledMofile);
        return;
    }

    load_plugin_textdomain('rrze-multisite-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

function preferBundledTextdomainMofile(string $mofile, string $domain): string {
    if ($domain !== 'rrze-multisite-manager') {
        return $mofile;
    }

    $bundledMofile = plugin_dir_path(__FILE__) . 'languages/' . $domain . '-' . determine_locale() . '.mo';

    // Use the catalog shipped with this plugin when it is available and current.
    return is_readable($bundledMofile) ? $bundledMofile : $mofile;
}

function systemRequirements(Config $config): string {
    $error = '';
    $requiredPhpVersion = $config->getRequiredPhpVersion();
    $requiredWpVersion = $config->getRequiredWpVersion();

    if ($requiredPhpVersion !== '' && version_compare(PHP_VERSION, $requiredPhpVersion, '<')) {
        $error = sprintf(
            /* translators: 1: current PHP version, 2: minimum required PHP version. */
            __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-multisite-manager'),
            PHP_VERSION,
            $requiredPhpVersion
        );
    } elseif ($requiredWpVersion !== '' && version_compare($GLOBALS['wp_version'], $requiredWpVersion, '<')) {
        $error = sprintf(
            /* translators: 1: current WordPress version, 2: minimum required WordPress version. */
            __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-multisite-manager'),
            $GLOBALS['wp_version'],
            $requiredWpVersion
        );
    }

    return $error;
}

function loaded(): void {
    if (!isNetworkActivationValid()) {
        add_action('admin_notices', __NAMESPACE__ . '\\showNetworkActivationRequiredNotice');
        add_action('network_admin_notices', __NAMESPACE__ . '\\showNetworkActivationRequiredNotice');
        return;
    }

    $config = new Config();

    if ($error = systemRequirements($config)) {
        $GLOBALS['rrze_multisite_manager_system_requirement_error'] = $error;
        add_action('admin_init', __NAMESPACE__ . '\registerSystemRequirementNotice');
        return;
    }

    $plugin = new Plugin(__FILE__);
    $plugin->loaded();

    $main = new Main($plugin);
    $main->onLoaded();
}

/**
 * WordPress Core enforces the Network header during regular activation. This
 * guard also prevents execution if a database import or custom tooling has
 * created an invalid per-site activation entry.
 */
function isNetworkActivationValid(): bool {
    if (!is_multisite()) {
        return true;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active_for_network(plugin_basename(__FILE__));
}

function showNetworkActivationRequiredNotice(): void {
    if (!current_user_can('manage_network_plugins')) {
        return;
    }

    echo '<div class="notice notice-error"><p>' . esc_html__('RRZE Multisite Manager must be activated network-wide in a multisite installation.', 'rrze-multisite-manager') . '</p></div>';
}

function registerSystemRequirementNotice(): void {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $pluginData = get_plugin_data(__FILE__);
    $GLOBALS['rrze_multisite_manager_system_requirement_plugin_name'] = $pluginData['Name'];

    if (is_multisite() && is_network_admin()) {
        add_action('network_admin_notices', __NAMESPACE__ . '\showSystemRequirementNotice');
        return;
    }

    add_action('admin_notices', __NAMESPACE__ . '\showSystemRequirementNotice');
}

function showSystemRequirementNotice(): void {
    $pluginName = (string)($GLOBALS['rrze_multisite_manager_system_requirement_plugin_name'] ?? '');
    $error = (string)($GLOBALS['rrze_multisite_manager_system_requirement_error'] ?? '');

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        sprintf(
            /* translators: 1: plugin name, 2: system requirement error message. */
            esc_html__('Plugin %1$s: %2$s', 'rrze-multisite-manager'),
            esc_html($pluginName),
            esc_html($error)
        )
    );
}

function activate(bool $networkWide = false): void {
    // The four central tables are independent of individual sites. Create or
    // update them before this activation completes, rather than on first use.
    (new Analysis\AnalysisStore())->install();

    $config = new Config();
    $metrics = new MetricsService(null, $config);

    // Activation must never restore or create background work. Every process
    // must be explicitly started again from Monitoring.
    MonitoringService::disableScheduledChecks($config, false);
    $metrics->disableDashboardScheduling(false);
    MetricsService::disableMaintenanceScheduling(false);
    StorageAnalysisSchedulerService::clearScheduledEvents($config, false);
    ShortcodeBlockAnalysisSchedulerService::clearScheduledEvents($config, false);

    // Activation must not synchronously iterate over every site in a large network.
    (new StorageAnalysisSchedulerService(new StorageAnalysisService($metrics), $config))->markScheduleConfigurationCurrent();
    (new ShortcodeBlockAnalysisSchedulerService($config))->markScheduleConfigurationCurrent();
}

function deactivate(bool $networkWide = false): void {
    $choice = getConfirmedNetworkDeactivationChoice();
    if (shouldConfirmNetworkDeactivation($networkWide, $choice)) {
        renderNetworkDeactivationConfirmation();
    }

    Analysis\AnalysisLifecycle::unschedule();
    MonitoringService::disableScheduledChecks();
    (new MetricsService())->disableDashboardScheduling();
    MetricsService::disableMaintenanceScheduling();
    StorageAnalysisSchedulerService::clearScheduledEvents();
    ShortcodeBlockAnalysisSchedulerService::clearScheduledEvents();
    clearPluginCronEvents();

    if ($choice === 'delete-tables') {
        (new Analysis\AnalysisStore())->dropTables();
    }
}

function getConfirmedNetworkDeactivationChoice(): string {
    $choice = isset($_REQUEST['rrze_msm_deactivation_choice']) ? sanitize_key((string)wp_unslash($_REQUEST['rrze_msm_deactivation_choice'])) : '';
    $nonce = isset($_REQUEST['rrze_msm_deactivation_nonce']) ? sanitize_text_field((string)wp_unslash($_REQUEST['rrze_msm_deactivation_nonce'])) : '';
    if (!in_array($choice, ['keep-tables', 'delete-tables'], true) || !wp_verify_nonce($nonce, 'rrze_msm_network_deactivation_' . $choice)) {
        return '';
    }
    return $choice;
}

function shouldConfirmNetworkDeactivation(bool $networkWide, mixed $choice): bool {
    if (!is_multisite() || !$networkWide || $choice !== '' || !is_admin()) {
        return false;
    }
    if ((defined('WP_CLI') && WP_CLI) || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key((string)wp_unslash($_REQUEST['action'])) : '';
    return in_array($action, ['deactivate', 'deactivate-selected'], true);
}

function renderNetworkDeactivationConfirmation(): never {
    if (!current_user_can('manage_network_plugins')) {
        wp_die(esc_html__('You are not allowed to deactivate this plugin.', 'rrze-multisite-manager'));
    }
    $actionUrl = network_admin_url('admin-post.php');
    $cancelUrl = network_admin_url('plugins.php');
    ob_start();
    echo '<p>' . esc_html__('Deactivate RRZE Multisite Manager and remove all of its scheduled tasks?', 'rrze-multisite-manager') . '</p>';
    echo '<p>' . esc_html__('Choose whether the central analysis tables and all analysis data they contain should be retained or deleted.', 'rrze-multisite-manager') . '</p>';
    echo '<form method="post" action="' . esc_url($actionUrl) . '">';
    wp_nonce_field('rrze_msm_confirm_network_deactivation');
    echo '<input type="hidden" name="action" value="rrze_msm_confirm_network_deactivation">';
    submit_button(__('Deactivate and keep analysis tables', 'rrze-multisite-manager'), 'secondary', 'deactivation_choice', false, ['value' => 'keep-tables']);
    echo ' <button type="submit" class="button button-primary" name="deactivation_choice" value="delete-tables">' . esc_html__('Deactivate and delete analysis tables', 'rrze-multisite-manager') . '</button>';
    echo ' <a class="button-link" href="' . esc_url($cancelUrl) . '">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</a>';
    echo '</form>';
    wp_die(ob_get_clean(), esc_html__('Confirm plugin deactivation', 'rrze-multisite-manager'), ['response' => 200]);
}

function confirmNetworkDeactivation(): void {
    if (!current_user_can('manage_network_plugins')) {
        wp_die(esc_html__('You are not allowed to deactivate this plugin.', 'rrze-multisite-manager'));
    }
    check_admin_referer('rrze_msm_confirm_network_deactivation');
    $choice = isset($_POST['deactivation_choice']) ? sanitize_key((string)wp_unslash($_POST['deactivation_choice'])) : '';
    if (!in_array($choice, ['keep-tables', 'delete-tables'], true)) {
        wp_die(esc_html__('Select how the analysis tables should be handled.', 'rrze-multisite-manager'));
    }
    $plugin = plugin_basename(__FILE__);
    $url = wp_nonce_url(
        add_query_arg([
            'action' => 'deactivate',
            'plugin' => $plugin,
            'networkwide' => '1',
            'rrze_msm_deactivation_choice' => $choice,
            'rrze_msm_deactivation_nonce' => wp_create_nonce('rrze_msm_network_deactivation_' . $choice),
        ], network_admin_url('plugins.php')),
        'deactivate-plugin_' . $plugin
    );
    wp_safe_redirect($url);
    exit;
}

/** Remove every plugin-owned Cron hook from every site during network deactivation. */
function clearPluginCronEvents(): int {
    $removed = 0;
    $currentSiteId = get_current_blog_id();
    $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach (array_unique(array_map('absint', $siteIds)) as $siteId) {
        if ($siteId <= 0) {
            continue;
        }
        if ($siteId !== $currentSiteId) {
            switch_to_blog($siteId);
        }
        try {
            $cron = _get_cron_array();
            $changed = false;
            foreach ((array)$cron as $timestamp => $events) {
                foreach (array_keys((array)$events) as $hook) {
                    if (!str_starts_with((string)$hook, 'rrze_msm_')) {
                        continue;
                    }
                    $removed += count((array)$cron[$timestamp][$hook]);
                    unset($cron[$timestamp][$hook]);
                    $changed = true;
                }
                if (empty($cron[$timestamp])) {
                    unset($cron[$timestamp]);
                }
            }
            if ($changed) {
                _set_cron_array($cron);
            }
        } finally {
            if ($siteId !== $currentSiteId) {
                restore_current_blog();
            }
        }
    }
    return $removed;
}
