<?php

/**
 * Plugin Name:     RRZE Multisite Manager
 * Plugin URI:
 * Description:     Multisite-Management für WordPress im RRZE-Kontext
 * Version:         1.1.2
 * Requires at least: 6.9.4
 * Requires PHP:      8.3
 * Author:          RRZE-Webteam
 * Author URI:      https://blogs.fau.de/webworking/
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

const RRZE_PHP_VERSION = '8.3';
const RRZE_WP_VERSION = '6.9.4';

add_action('init', __NAMESPACE__ . '\loadTextdomain');
add_action('plugins_loaded', __NAMESPACE__ . '\loaded');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivate');

function loadTextdomain(): void {
    load_plugin_textdomain('rrze-multisite-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

function systemRequirements(): string {
    $error = '';

    if (version_compare(PHP_VERSION, RRZE_PHP_VERSION, '<')) {
        $error = sprintf(
            __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-multisite-manager'),
            PHP_VERSION,
            RRZE_PHP_VERSION
        );
    } elseif (version_compare($GLOBALS['wp_version'], RRZE_WP_VERSION, '<')) {
        $error = sprintf(
            __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-multisite-manager'),
            $GLOBALS['wp_version'],
            RRZE_WP_VERSION
        );
    }

    return $error;
}

function loaded(): void {
    if ($error = systemRequirements()) {
        $GLOBALS['rrze_multisite_manager_system_requirement_error'] = $error;
        add_action('admin_init', __NAMESPACE__ . '\registerSystemRequirementNotice');
        return;
    }

    $plugin = new Plugin(__FILE__);
    $plugin->loaded();

    $main = new Main($plugin);
    $main->onLoaded();
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
        '<div class="notice notice-error"><p>' . __('Plugin %1$s: %2$s', 'rrze-multisite-manager') . '</p></div>',
        esc_html($pluginName),
        esc_html($error)
    );
}

function deactivate(): void {
    MonitoringService::clearScheduledEvent();
}
