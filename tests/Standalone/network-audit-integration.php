<?php
/**
 * Isolated tests for the plugin enablement integration.
 * Provider switch: RRZE Netzwerk-Audit (network-active) -> RRZE Settings.
 *
 * Run: php tests/Standalone/network-audit-integration.php [path to rrze-settings]
 * Without a path, a minimal stand-in for RRZE Settings is used.
 */
declare(strict_types=1);

namespace {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
        exit(1);
    }
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['options'] = [];
    $GLOBALS['networkActive'] = [];
    $GLOBALS['plugins'] = [
        'akismet/akismet.php' => [],
        'enabled/enabled.php' => [],
        'network/network.php' => [],
        'hello.php' => [],
    ];

    function __($text, $domain = '') {
        return $text;
    }
    function apply_filters($hook, $value) {
        return $value;
    }
    function is_multisite() {
        return true;
    }
    function get_site_option($key, $default = false) {
        return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
    }
    function update_site_option($key, $value) {
        $changed = get_site_option($key, null) !== $value;
        $GLOBALS['options'][$key] = $value;
        return $changed;
    }
    function plugin_basename($file) {
        return (string) $file;
    }
    function is_plugin_active_for_network($file) {
        return in_array($file, $GLOBALS['networkActive'], true);
    }
    function is_network_only_plugin($file) {
        return $file === 'network/network.php';
    }
    function get_plugins() {
        return $GLOBALS['plugins'];
    }
    function is_super_admin($userId = 0) {
        return true;
    }
    function add_query_arg($args, $url) {
        return $url . '?' . http_build_query($args);
    }
    function admin_url($path = '') {
        return 'https://example.org/wp-admin/' . $path;
    }
    function wp_nonce_url($url, $action) {
        return $url . '&_wpnonce=x';
    }
    function check($condition, $message) {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    require_once dirname(__DIR__, 2) . '/includes/NetworkAuditPluginIntegration.php';
}

namespace Test {
    use RRZE\MultisiteManager\NetworkAuditPluginIntegration as Integration;

    function integration() {
        return new Integration();
    }

    // 1. Neither RRZE Netzwerk-Audit nor RRZE Settings: nothing available.
    check(integration()->getProvider() === Integration::PROVIDER_NONE, 'no provider');
    check(integration()->setPluginsEnabled(['akismet/akismet.php'], true) === 0, 'no write without provider');
    check(integration()->getPluginState('akismet/akismet.php')['available'] === false, 'state unavailable');

    // 2. RRZE Netzwerk-Audit network-active: direct option write as before.
    $GLOBALS['networkActive'] = ['rrze-netzwerk-audit/rrze-netzwerk-audit.php'];
    $GLOBALS['options']['rrze_netzwerk_audit'] = (object) [
        'enabled_themes' => ['fau-theme'],
        'enabled_plugins' => ['enabled/enabled.php'],
    ];
    check(integration()->getProvider() === Integration::PROVIDER_NETWORK_AUDIT, 'netzwerk-audit provider');
    check(integration()->setPluginEnabled('akismet/akismet.php', true), 'enable via option');
    check($GLOBALS['options']['rrze_netzwerk_audit']['enabled_plugins'] === ['enabled/enabled.php', 'akismet/akismet.php'], 'option list');
    check($GLOBALS['options']['rrze_netzwerk_audit']['enabled_themes'] === ['fau-theme'], 'themes preserved (netzwerk-audit)');
    check(integration()->getPluginState('akismet/akismet.php')['enabled'] === true, 'state enabled');

    // 3. RRZE Netzwerk-Audit deactivated: switch to RRZE Settings.
    $GLOBALS['networkActive'] = [];
    $settingsDir = $argv[1] ?? '';
    if ($settingsDir !== '') {
        require_once rtrim($settingsDir, '/') . '/includes/Governance/PluginAvailability.php';
        echo "Using RRZE Settings from $settingsDir\n";
    } else {
        eval('namespace RRZE\Settings\Governance; class PluginAvailability {
            public static $calls = 0;
            public static function isConfigured(): bool { return get_site_option("rrze_netzwerk_audit", false) !== false; }
            public static function getEnabledPlugins(): array { $o = (array) get_site_option("rrze_netzwerk_audit", []); return array_values((array) ($o["enabled_plugins"] ?? [])); }
            public static function setEnabled(string $file, bool $enabled): bool { self::$calls++; $o = (array) get_site_option("rrze_netzwerk_audit", []); $l = self::getEnabledPlugins(); $l = $enabled ? array_merge($l, [$file]) : array_diff($l, [$file]); $o["enabled_plugins"] = array_values(array_unique($l)); return update_site_option("rrze_netzwerk_audit", $o); }
        }');
    }
    check(integration()->getProvider() === Integration::PROVIDER_SETTINGS, 'settings provider');
    check(integration()->getPluginState('akismet/akismet.php')['enabled'] === true, 'existing data read via settings');
    check(integration()->setPluginsEnabled(['akismet/akismet.php', 'enabled/enabled.php', 'hello.php', 'network/network.php', 'missing/x.php'], false) === 2, 'bulk disable via settings');
    check(\RRZE\Settings\Governance\PluginAvailability::getEnabledPlugins() === [], 'settings list empty');
    check(integration()->setPluginEnabled('akismet/akismet.php', true), 'enable via settings');
    check(integration()->setPluginEnabled('akismet/akismet.php', true), 'repeat enable is a no-op success');
    check($GLOBALS['options']['rrze_netzwerk_audit']['enabled_plugins'] === ['akismet/akismet.php'], 'same option written');
    check($GLOBALS['options']['rrze_netzwerk_audit']['enabled_themes'] === ['fau-theme'], 'themes preserved (settings)');

    // 4. Settings without option (feature unused on this network).
    unset($GLOBALS['options']['rrze_netzwerk_audit']);
    check(integration()->getProvider() === Integration::PROVIDER_NONE, 'settings not configured');

    echo "network audit integration: ok\n";
}
