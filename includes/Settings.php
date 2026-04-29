<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

use RRZE\MultisiteManager\Widgets\ArchivedSitesWidget;
use RRZE\MultisiteManager\Widgets\BlockedSitesWidget;
use RRZE\MultisiteManager\Widgets\DeletedSitesWidget;
use RRZE\MultisiteManager\Widgets\EditorUsageWidget;
use RRZE\MultisiteManager\Widgets\InactivePluginsWidget;
use RRZE\MultisiteManager\Widgets\InactiveSitesWidget;
use RRZE\MultisiteManager\Widgets\InactiveThemesWidget;
use RRZE\MultisiteManager\Widgets\NetworkStorageUsageWidget;
use RRZE\MultisiteManager\Widgets\PluginUsageWidget;
use RRZE\MultisiteManager\Widgets\RecentSitesWidget;
use RRZE\MultisiteManager\Widgets\RecentlyUpdatedSitesWidget;
use RRZE\MultisiteManager\Widgets\SiteOverviewWidget;
use RRZE\MultisiteManager\Widgets\StatusWidget;
use RRZE\MultisiteManager\Widgets\SummaryWidget;
use RRZE\MultisiteManager\Widgets\ThemeOverviewWidget;
use RRZE\MultisiteManager\Widgets\ThemeUsageWidget;

class Settings {
    protected Plugin $plugin;
    protected string $optionName = '';
    public array $options = [];
    public string $optionsPage = '';
    protected array $settingsMenu = [];
    protected array $settingsSections = [];
    protected array $settingsFields = [];
    protected array $allTabs = [];
    protected string $defaultTab = '';
    protected string $currentTab = '';
    protected string $settingsPrefix = '';
    protected Config $config;
    protected array $sectionDescriptions = [];

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        $this->config = new Config();
        $this->settingsPrefix = $this->plugin->getSlug() . '-';
    }

    public function onLoaded(): void {
        $this->setMenu();
        $this->setSections();
        $this->setFields();

        $this->optionName = $this->config->getOptionName();
        $this->options = $this->getOptions();

        if (is_admin()) {
            add_action('network_admin_edit_' . $this->optionName, [$this, 'saveNetworkOptions']);
            add_action('network_admin_edit_rrze_multisite_manager_refresh_metrics', [$this, 'refreshMetrics']);
        }
    }

    protected function setMenu(): void {
        $this->settingsMenu = $this->config->getMenuSettings();
    }

    protected function setSections(): void {
        $this->settingsSections = $this->config->getSections();
    }

    protected function setFields(): void {
        $this->settingsFields = $this->config->getFields();
    }

    protected function defaultOptions(): array {
        $options = [];
        $sectionName = '';
        $fields = [];
        $option = [];
        $name = '';
        $default = '';

        foreach ($this->settingsFields as $sectionName => $fields) {
            foreach ($fields as $option) {
                $name = (string)$option['name'];
                $default = $option['default'] ?? '';
                $options[$sectionName . '_' . $name] = $default;
            }
        }

        return $options;
    }

    public function getOptions(): array {
        $defaults = $this->defaultOptions();
        $options = get_site_option($this->optionName, []);

        if (!is_array($options)) {
            $options = [];
        }

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return $this->normalizeOptions($options);
    }

    public function getOption(string $section, string $name, mixed $default = ''): mixed {
        $option = $section . '_' . $name;

        if (array_key_exists($option, $this->options)) {
            return $this->options[$option];
        }

        return $default;
    }

    public function sanitizeOptions(mixed $options): array {
        if (!is_array($options)) {
            return $this->options;
        }

        $merged = array_merge($this->defaultOptions(), $options);
        return $this->normalizeOptions($merged);
    }

    public function saveNetworkOptions(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer($this->optionName . '_save');

        $rawOptions = $_POST[$this->optionName] ?? [];
        $options = $this->sanitizeOptions($rawOptions);

        update_site_option($this->optionName, $options);
        (new MetricsService($this, $this->config))->clearCache();

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'updated' => 'true',
            ],
            network_admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function renderOptionsPage(): void {
        $currentTab = $this->getSettingsTab();

        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        echo '<div class="wrap rrze-multisite-manager-admin rrze-msm-mode-' . esc_attr($this->getColorMode()) . '">';
        echo '<div class="rrze-msm-page-shell">';
        echo '<div class="rrze-msm-page-header">';
        echo '<div>';
        echo '<h1>' . esc_html__('RRZE Multisite Manager', 'rrze-multisite-manager') . '</h1>';
        echo '<p>' . esc_html__('Zentrale Einstellungen für Dashboard, Übersichten und Cache-Verhalten.', 'rrze-multisite-manager') . '</p>';
        echo '</div>';
        echo '<div class="rrze-msm-header-controls">';
        echo '<button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="' . esc_attr($this->getColorMode() === 'dark' ? 'light' : 'dark') . '">';
        echo esc_html($this->getColorMode() === 'dark' ? __('Light Mode', 'rrze-multisite-manager') : __('Dark Mode', 'rrze-multisite-manager'));
        echo '</button>';
        echo '</div>';
        echo '</div>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Die Einstellungen wurden gespeichert.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-refreshed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Die Kennzahlen wurden neu aufgebaut.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['views-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Die Ansichten wurden gespeichert.', 'rrze-multisite-manager') . '</p></div>';
        }

        $this->renderSettingsTabs($currentTab);

        if ($currentTab === 'views') {
            $this->renderViewsTab();
        } else {
            $this->renderGeneralTab();
        }

        echo '</div>';
        echo '</div>';
    }

    public function refreshMetrics(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_refresh_metrics');
        (new MetricsService($this, $this->config))->clearCache();

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'metrics-refreshed' => 'true',
            ],
            network_admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    protected function renderFields(): void {
        $section = [];
        $sectionId = '';
        $sectionTitle = '';
        $field = [];
        $fieldName = '';
        $fieldId = '';
        $value = null;

        foreach ($this->settingsSections as $section) {
            $sectionId = (string)$section['id'];
            $sectionTitle = (string)$section['title'];
            echo '<h2>' . esc_html($sectionTitle) . '</h2>';

            if (!empty($section['description'])) {
                echo '<p>' . esc_html((string)$section['description']) . '</p>';
            }

            if (empty($this->settingsFields[$sectionId]) || !is_array($this->settingsFields[$sectionId])) {
                continue;
            }

            echo '<table class="form-table" role="presentation"><tbody>';

            foreach ($this->settingsFields[$sectionId] as $field) {
                $fieldName = $sectionId . '_' . (string)$field['name'];
                $fieldId = $this->settingsPrefix . $fieldName;
                $value = $this->options[$fieldName] ?? ($field['default'] ?? '');

                echo '<tr>';
                echo '<th scope="row"><label for="' . esc_attr($fieldId) . '">' . esc_html((string)$field['label']) . '</label></th>';
                echo '<td>';
                $this->renderFieldInput($fieldId, $fieldName, $field, $value);

                if (!empty($field['desc'])) {
                    echo '<p class="description">' . esc_html((string)$field['desc']) . '</p>';
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    protected function renderFieldInput(string $fieldId, string $fieldName, array $field, mixed $value): void {
        $type = (string)($field['type'] ?? 'text');
        $inputName = $this->optionName . '[' . $fieldName . ']';

        if ($type === 'checkbox') {
            echo '<label for="' . esc_attr($fieldId) . '">';
            echo '<input type="hidden" name="' . esc_attr($inputName) . '" value="0">';
            echo '<input type="checkbox" id="' . esc_attr($fieldId) . '" name="' . esc_attr($inputName) . '" value="1" ' . checked((bool)$value, true, false) . '>';
            echo ' ' . esc_html__('Aktiv', 'rrze-multisite-manager');
            echo '</label>';
            return;
        }

        if ($type === 'number') {
            echo '<input class="small-text" type="number" id="' . esc_attr($fieldId) . '" name="' . esc_attr($inputName) . '" value="' . esc_attr((string)$value) . '"';

            if (isset($field['min'])) {
                echo ' min="' . esc_attr((string)$field['min']) . '"';
            }

            if (isset($field['max'])) {
                echo ' max="' . esc_attr((string)$field['max']) . '"';
            }

            echo ' step="1">';
            return;
        }

        echo '<input class="regular-text" type="text" id="' . esc_attr($fieldId) . '" name="' . esc_attr($inputName) . '" value="' . esc_attr((string)$value) . '">';
    }

    protected function normalizeOptions(array $options): array {
        $sectionFields = [];
        $field = [];
        $compoundName = '';
        $value = null;

        foreach ($this->settingsFields as $sectionName => $sectionFields) {
            foreach ($sectionFields as $field) {
                $compoundName = $sectionName . '_' . (string)$field['name'];
                $value = $options[$compoundName] ?? ($field['default'] ?? '');
                $options[$compoundName] = $this->sanitizeFieldValue($field, $value);
            }
        }

        return $options;
    }

    protected function sanitizeFieldValue(array $field, mixed $value): mixed {
        $type = (string)($field['type'] ?? 'text');
        $default = $field['default'] ?? '';
        $min = isset($field['min']) ? (int)$field['min'] : null;
        $max = isset($field['max']) ? (int)$field['max'] : null;
        $number = 0;

        if ($type === 'checkbox') {
            return $this->sanitizeBoolean($value);
        }

        if ($type === 'number') {
            $number = is_numeric($value) ? (int)$value : (int)$default;

            if ($min !== null && $number < $min) {
                $number = $min;
            }

            if ($max !== null && $number > $max) {
                $number = $max;
            }

            return $number;
        }

        return sanitize_text_field((string)$value);
    }

    public function getSettingsSlug(): string {
        return (string)($this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings');
    }

    public function setOptionsPage(string $optionsPage): void {
        $this->optionsPage = $optionsPage;
    }

    protected function sanitizeBoolean(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }

    protected function getColorMode(): string {
        $mode = isset($_COOKIE['rrze_msm_color_mode']) ? sanitize_key((string)wp_unslash($_COOKIE['rrze_msm_color_mode'])) : 'light';
        return $mode === 'dark' ? 'dark' : 'light';
    }

    protected function getSettingsTab(): string {
        $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'general';

        if (!in_array($tab, ['general', 'views'], true)) {
            return 'general';
        }

        return $tab;
    }

    protected function renderSettingsTabs(string $currentTab): void {
        $baseUrl = add_query_arg(
            [
                'page' => $this->getSettingsSlug(),
            ],
            network_admin_url('admin.php')
        );

        echo '<nav class="nav-tab-wrapper">';
        echo '<a class="nav-tab' . ($currentTab === 'general' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'general'], $baseUrl)) . '">' . esc_html__('Allgemeines', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'views' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'views'], $baseUrl)) . '">' . esc_html__('Ansichten', 'rrze-multisite-manager') . '</a>';
        echo '</nav>';
    }

    protected function renderGeneralTab(): void {
        echo '<form method="post" action="' . esc_url(network_admin_url('edit.php?action=' . $this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        $this->renderFields();
        submit_button();
        echo '</form>';
        echo '<hr>';
        echo '<h2>' . esc_html__('Kennzahlen aktualisieren', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Leert den Kennzahlen-Cache. Die Daten werden beim nächsten Aufruf der Übersichten neu berechnet.', 'rrze-multisite-manager') . '</p>';
        echo '<form method="post" action="' . esc_url(network_admin_url('edit.php?action=rrze_multisite_manager_refresh_metrics')) . '">';
        wp_nonce_field('rrze_multisite_manager_refresh_metrics');
        submit_button(__('Kennzahlen aktualisieren', 'rrze-multisite-manager'), 'secondary', 'submit', false);
        echo '</form>';
    }

    protected function renderViewsTab(): void {
        $viewManager = new ViewManager();
        $widgets = $this->getWidgetInstances();
        $views = $viewManager->getViews(array_keys($widgets));
        $widgetOptions = $this->getWidgetOptions($widgets);
        $view = [];
        $widgetOption = [];

        echo '<form method="post" action="' . esc_url(network_admin_url('edit.php?action=rrze_multisite_manager_save_views')) . '" class="rrze-msm-views-form">';
        wp_nonce_field('rrze_multisite_manager_save_views');
        echo '<input type="hidden" name="settings_tab" value="views">';

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Neue Ansicht anlegen', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Neue Ansichten starten mit allen Widgets. Danach kannst du die Auswahl direkt darunter einschränken.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';
        echo '<input type="text" class="regular-text" name="new_view_name" value="" placeholder="' . esc_attr__('Name der neuen Ansicht', 'rrze-multisite-manager') . '">';
        echo '</section>';

        foreach ($views as $view) {
            echo '<section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-view-editor">';
            echo '<header class="rrze-msm-widget-header">';
            echo '<h2>' . esc_html((string)$view['label']) . '</h2>';
            echo '<p><code>' . esc_html((string)$view['slug']) . '</code></p>';
            echo '</header>';

            if (!empty($view['system'])) {
                echo '<input type="hidden" name="views[' . esc_attr((string)$view['slug']) . '][label]" value="' . esc_attr((string)$view['label']) . '">';

                if ((string)$view['slug'] === 'all_widgets') {
                    echo '<p class="description">' . esc_html__('System-Ansicht: enthält immer alle verfügbaren Widgets und ist nicht bearbeitbar.', 'rrze-multisite-manager') . '</p>';
                } else {
                    echo '<p class="description">' . esc_html__('System-Ansicht: Name fest, Widget-Zuordnung anpassbar.', 'rrze-multisite-manager') . '</p>';
                }
            } else {
                echo '<p>';
                echo '<label>';
                echo '<span class="screen-reader-text">' . esc_html__('Name', 'rrze-multisite-manager') . '</span>';
                echo '<input type="text" class="regular-text" name="views[' . esc_attr((string)$view['slug']) . '][label]" value="' . esc_attr((string)$view['label']) . '">';
                echo '</label> ';
                echo '<label class="rrze-msm-delete-toggle">';
                echo '<input type="checkbox" name="views[' . esc_attr((string)$view['slug']) . '][delete]" value="1"> ';
                echo esc_html__('Ansicht löschen', 'rrze-multisite-manager');
                echo '</label>';
                echo '</p>';
            }

            echo '<div class="rrze-msm-widget-selector">';

            foreach ($widgetOptions as $widgetOption) {
                echo '<label class="rrze-msm-widget-check">';
                echo '<input type="checkbox" name="views[' . esc_attr((string)$view['slug']) . '][widgets][]" value="' . esc_attr((string)$widgetOption['id']) . '" ' . checked(in_array((string)$widgetOption['id'], $view['widgets'], true), true, false) . ' ' . disabled((string)$view['slug'] === 'all_widgets', true, false) . '>';
                echo '<span>' . esc_html((string)$widgetOption['label']) . '</span>';
                echo '</label>';
            }

            echo '</div>';
            echo '</section>';
        }

        submit_button(__('Ansichten speichern', 'rrze-multisite-manager'));
        echo '</form>';
    }

    protected function getWidgetInstances(): array {
        return [
            'summary' => new SummaryWidget($this->plugin, $this->config),
            'status' => new StatusWidget($this->plugin, $this->config),
            'network_storage_usage' => new NetworkStorageUsageWidget($this->plugin, $this->config),
            'theme_usage' => new ThemeUsageWidget($this->plugin, $this->config),
            'editor_usage' => new EditorUsageWidget($this->plugin, $this->config),
            'recent_sites' => new RecentSitesWidget($this->plugin, $this->config),
            'recently_updated_sites' => new RecentlyUpdatedSitesWidget($this->plugin, $this->config),
            'inactive_sites' => new InactiveSitesWidget($this->plugin, $this->config),
            'site_overview' => new SiteOverviewWidget($this->plugin, $this->config),
            'archived_sites' => new ArchivedSitesWidget($this->plugin, $this->config),
            'blocked_sites' => new BlockedSitesWidget($this->plugin, $this->config),
            'deleted_sites' => new DeletedSitesWidget($this->plugin, $this->config),
            'theme_overview' => new ThemeOverviewWidget($this->plugin, $this->config),
            'plugin_usage' => new PluginUsageWidget($this->plugin, $this->config),
            'inactive_plugins' => new InactivePluginsWidget($this->plugin, $this->config),
            'inactive_themes' => new InactiveThemesWidget($this->plugin, $this->config),
        ];
    }

    protected function getWidgetOptions(array $widgets): array {
        $options = [];
        $widgetId = '';

        foreach ($widgets as $widgetId => $widget) {
            $options[] = [
                'id' => $widgetId,
                'label' => $widget->getTitle(),
            ];
        }

        return $options;
    }
}
