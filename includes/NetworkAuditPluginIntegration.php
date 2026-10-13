<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

/**
 * Provides the optional RRZE Netzwerk-Audit plugin enablement integration.
 */
class NetworkAuditPluginIntegration {
    private const PLUGIN_FILE = 'rrze-netzwerk-audit/rrze-netzwerk-audit.php';
    private const OPTION_NAME = 'rrze_netzwerk_audit';

    private ?bool $available = null;
    private ?array $enabledPlugins = null;

    public function isAvailable(): bool {
        if ($this->available !== null) {
            return $this->available;
        }

        $this->available = function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network(self::PLUGIN_FILE);

        return $this->available;
    }

    /**
     * @return array{available: bool, enabled: bool, manageable: bool, action_url: string, action_label: string}
     */
    public function getPluginState(string $pluginFile, string $redirectTo = ''): array {
        $pluginFile = plugin_basename($pluginFile);
        $available = $this->isAvailable();
        $manageable = $available && $this->isManageablePlugin($pluginFile);
        $enabled = $available && in_array($pluginFile, $this->getEnabledPlugins(), true);
        $action = $manageable && is_super_admin() ? ($enabled ? 'disable_plugin' : 'enable_plugin') : '';

        return [
            'available' => $available,
            'enabled' => $enabled,
            'manageable' => $manageable,
            'action_url' => $action !== '' ? $this->getActionUrl($pluginFile, $action, $redirectTo) : '',
            'action_label' => $enabled
                ? __('Disable', 'rrze-multisite-manager')
                : __('Enable', 'rrze-multisite-manager'),
        ];
    }

    public function isManageablePlugin(string $pluginFile): bool {
        if ($pluginFile === '' || !str_contains($pluginFile, '/')) {
            return false;
        }

        if (function_exists('is_network_only_plugin') && is_network_only_plugin($pluginFile)) {
            return false;
        }

        return !function_exists('is_plugin_active_for_network') || !is_plugin_active_for_network($pluginFile);
    }

    public function setPluginEnabled(string $pluginFile, bool $enabled): bool {
        $pluginFile = plugin_basename($pluginFile);

        if (!$this->isAvailable() || !$this->isManageablePlugin($pluginFile) || !$this->pluginFileExists($pluginFile)) {
            return false;
        }

        $enabledPlugins = $this->getEnabledPlugins();
        $isEnabled = in_array($pluginFile, $enabledPlugins, true);

        if ($enabled === $isEnabled) {
            return true;
        }

        return $this->setPluginsEnabled([$pluginFile], $enabled) === 1;
    }

    /**
     * @param string[] $pluginFiles
     */
    public function setPluginsEnabled(array $pluginFiles, bool $enabled): int {
        if (!$this->isAvailable()) {
            return 0;
        }

        $options = get_site_option(self::OPTION_NAME, []);
        $options = is_object($options) ? (array)$options : (is_array($options) ? $options : []);
        $enabledPlugins = $this->getEnabledPlugins();
        $updated = 0;
        foreach (array_unique($pluginFiles) as $pluginFile) {
            if (!is_string($pluginFile)) {
                continue;
            }

            $pluginFile = plugin_basename($pluginFile);

            if (!$this->isManageablePlugin($pluginFile) || !$this->pluginFileExists($pluginFile)) {
                continue;
            }

            $isEnabled = in_array($pluginFile, $enabledPlugins, true);

            if ($enabled === $isEnabled) {
                continue;
            }

            if ($enabled) {
                $enabledPlugins[] = $pluginFile;
            } else {
                $enabledPlugins = array_values(array_diff($enabledPlugins, [$pluginFile]));
            }

            $updated++;
        }

        if ($updated === 0) {
            return 0;
        }

        $options['enabled_plugins'] = array_values(array_unique($enabledPlugins));

        if (!update_site_option(self::OPTION_NAME, $options)) {
            return 0;
        }

        $this->enabledPlugins = $options['enabled_plugins'];

        return $updated;
    }

    private function pluginFileExists(string $pluginFile): bool {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        return isset($plugins[$pluginFile]);
    }

    private function getActionUrl(string $pluginFile, string $action, string $redirectTo): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'rrze_multisite_manager_toggle_network_audit_plugin',
                    'plugin' => $pluginFile,
                    'enabled' => $action === 'enable_plugin' ? '1' : '0',
                    'redirect_to' => $redirectTo,
                ],
                admin_url('admin-post.php')
            ),
            'rrze_multisite_manager_toggle_network_audit_plugin_' . ($action === 'enable_plugin' ? 'enable' : 'disable') . '_' . $pluginFile
        );
    }

    /**
     * @return string[]
     */
    private function getEnabledPlugins(): array {
        if ($this->enabledPlugins !== null) {
            return $this->enabledPlugins;
        }

        $options = get_site_option(self::OPTION_NAME, []);
        $enabledPlugins = is_array($options) && is_array($options['enabled_plugins'] ?? null)
            ? $options['enabled_plugins']
            : [];

        $this->enabledPlugins = array_values(
            array_filter(
                array_map(
                    'plugin_basename',
                    array_filter($enabledPlugins, 'is_string')
                ),
                static fn(string $pluginFile): bool => $pluginFile !== ''
            )
        );

        return $this->enabledPlugins;
    }
}
