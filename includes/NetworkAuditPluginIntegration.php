<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

/**
 * Provides the optional plugin enablement (allowlist) integration.
 *
 * Provider:
 * - RRZE Netzwerk-Audit, as long as it is network-active.
 * - Otherwise RRZE Settings (Governance\PluginAvailability), which has taken
 *   over the allowlist with the same network option.
 */
class NetworkAuditPluginIntegration {
    public const PROVIDER_NONE = '';
    public const PROVIDER_NETWORK_AUDIT = 'rrze-netzwerk-audit';
    public const PROVIDER_SETTINGS = 'rrze-settings';

    private const PLUGIN_FILE = 'rrze-netzwerk-audit/rrze-netzwerk-audit.php';
    private const OPTION_NAME = 'rrze_netzwerk_audit';
    private const SETTINGS_CLASS = 'RRZE\\Settings\\Governance\\PluginAvailability';

    private ?string $provider = null;
    private ?array $enabledPlugins = null;

    public function isAvailable(): bool {
        return $this->getProvider() !== self::PROVIDER_NONE;
    }

    /**
     * Return the component that currently provides the allowlist.
     */
    public function getProvider(): string {
        if ($this->provider !== null) {
            return $this->provider;
        }

        if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network(self::PLUGIN_FILE)) {
            $this->provider = self::PROVIDER_NETWORK_AUDIT;
        } elseif ($this->isSettingsProviderAvailable()) {
            $this->provider = self::PROVIDER_SETTINGS;
        } else {
            $this->provider = self::PROVIDER_NONE;
        }

        return $this->provider;
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
        $provider = $this->getProvider();

        if ($provider === self::PROVIDER_NONE) {
            return 0;
        }

        $enabledPlugins = $this->getEnabledPlugins();
        $changes = [];

        foreach (array_unique($pluginFiles) as $pluginFile) {
            if (!is_string($pluginFile)) {
                continue;
            }

            $pluginFile = plugin_basename($pluginFile);

            if (!$this->isManageablePlugin($pluginFile) || !$this->pluginFileExists($pluginFile)) {
                continue;
            }

            if ($enabled === in_array($pluginFile, $enabledPlugins, true)) {
                continue;
            }

            $changes[] = $pluginFile;
        }

        if (empty($changes)) {
            return 0;
        }

        if ($provider === self::PROVIDER_SETTINGS) {
            return $this->setPluginsEnabledViaSettings($changes, $enabled);
        }

        return $this->setPluginsEnabledInOption($changes, $enabled);
    }

    /**
     * Write the allowlist directly (RRZE Netzwerk-Audit is active).
     *
     * @param string[] $pluginFiles Plugins whose state changes
     */
    private function setPluginsEnabledInOption(array $pluginFiles, bool $enabled): int {
        $options = get_site_option(self::OPTION_NAME, []);
        $options = is_object($options) ? (array)$options : (is_array($options) ? $options : []);
        $enabledPlugins = $this->getEnabledPlugins();

        if ($enabled) {
            $enabledPlugins = array_merge($enabledPlugins, $pluginFiles);
        } else {
            $enabledPlugins = array_diff($enabledPlugins, $pluginFiles);
        }

        $options['enabled_plugins'] = array_values(array_unique($enabledPlugins));

        if (!update_site_option(self::OPTION_NAME, $options)) {
            return 0;
        }

        $this->enabledPlugins = $options['enabled_plugins'];

        return count($pluginFiles);
    }

    /**
     * Change the allowlist through RRZE Settings.
     *
     * @param string[] $pluginFiles Plugins whose state changes
     */
    private function setPluginsEnabledViaSettings(array $pluginFiles, bool $enabled): int {
        $updated = 0;

        foreach ($pluginFiles as $pluginFile) {
            if (call_user_func([self::SETTINGS_CLASS, 'setEnabled'], $pluginFile, $enabled)) {
                $updated++;
            }
        }

        $this->enabledPlugins = null;

        return $updated;
    }

    /**
     * Whether RRZE Settings provides the allowlist on this network.
     */
    private function isSettingsProviderAvailable(): bool {
        if (!class_exists(self::SETTINGS_CLASS)) {
            return false;
        }

        foreach (['isConfigured', 'getEnabledPlugins', 'setEnabled'] as $method) {
            if (!method_exists(self::SETTINGS_CLASS, $method)) {
                return false;
            }
        }

        return (bool)call_user_func([self::SETTINGS_CLASS, 'isConfigured']);
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

        if ($this->getProvider() === self::PROVIDER_SETTINGS) {
            $enabledPlugins = (array)call_user_func([self::SETTINGS_CLASS, 'getEnabledPlugins']);
        } else {
            $options = get_site_option(self::OPTION_NAME, []);
            $options = is_object($options) ? (array)$options : (is_array($options) ? $options : []);
            $enabledPlugins = is_array($options['enabled_plugins'] ?? null)
                ? $options['enabled_plugins']
                : [];
        }

        $normalized = [];
        foreach ($enabledPlugins as $pluginFile) {
            if (!is_string($pluginFile)) {
                continue;
            }
            $pluginFile = plugin_basename($pluginFile);
            if ($pluginFile !== '') {
                $normalized[] = $pluginFile;
            }
        }

        $this->enabledPlugins = $normalized;

        return $this->enabledPlugins;
    }
}
