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
use RRZE\MultisiteManager\Widgets\NewMonitoringAlertsWidget;
use RRZE\MultisiteManager\Widgets\OperationalStatusWidget;
use RRZE\MultisiteManager\Widgets\PluginUsageWidget;
use RRZE\MultisiteManager\Widgets\ProblemSitesWidget;
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
            add_action('admin_post_' . $this->optionName, [$this, 'saveNetworkOptions']);
            add_action('admin_post_rrze_multisite_manager_refresh_metrics', [$this, 'refreshMetrics']);
            add_action('admin_post_rrze_multisite_manager_run_monitoring', [$this, 'runMonitoringNow']);
            add_action('admin_post_rrze_multisite_manager_reset_metrics', [$this, 'resetMetrics']);
            add_action('admin_post_rrze_multisite_manager_reset_monitoring', [$this, 'resetMonitoring']);
            add_action('network_admin_edit_' . $this->optionName, [$this, 'saveNetworkOptions']);
            add_action('network_admin_edit_rrze_multisite_manager_refresh_metrics', [$this, 'refreshMetrics']);
            add_action('network_admin_edit_rrze_multisite_manager_run_monitoring', [$this, 'runMonitoringNow']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_metrics', [$this, 'resetMetrics']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_monitoring', [$this, 'resetMonitoring']);
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
        $settingsTab = isset($_POST['settings_tab']) ? sanitize_key((string)wp_unslash($_POST['settings_tab'])) : 'general';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer($this->optionName . '_save');

        $rawOptions = $_POST[$this->optionName] ?? [];
        $options = $this->sanitizeOptions($rawOptions);

        update_site_option($this->optionName, $options);
        (new MetricsService($this, $this->config))->startDashboardRefreshRun(false);
        MonitoringService::clearScheduledEvent($this->config);
        (new MonitoringService($this->plugin, $this->config))->ensureScheduledEvent();

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'tab' => in_array($settingsTab, ['general', 'monitoring'], true) ? $settingsTab : 'general',
                'updated' => 'true',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function renderOptionsPage(): void {
        $currentTab = $this->getSettingsTab();

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
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
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Die Aktualisierung der Kennzahlen wurde gestartet und läuft jetzt in Batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['views-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Die Ansichten wurden gespeichert.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-ran'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Das Monitoring wurde gestartet und arbeitet die Websites jetzt in Batches ab.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Der Metrics-Prozess wurde zurückgesetzt.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Der Monitoring-Prozess wurde zurückgesetzt.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-status-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Der Betriebsstatus der Website wurde aktualisiert.', 'rrze-multisite-manager') . '</p></div>';
        }

        $this->renderSettingsTabs($currentTab);

        if ($currentTab === 'views') {
            $this->renderViewsTab();
        } elseif ($currentTab === 'monitoring') {
            $this->renderMonitoringTab();
        } else {
            $this->renderGeneralTab();
        }

        echo '</div>';
        echo '</div>';
    }

    public function refreshMetrics(): void {
        $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw((string)wp_unslash($_POST['redirect_to'])) : '';
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_refresh_metrics');
        (new MetricsService($this, $this->config))->startDashboardRefreshRun(true);

        $redirectUrl = $redirectTo !== ''
            ? $redirectTo
            : add_query_arg(
                [
                    'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                    'metrics-refreshed' => 'true',
                ],
                admin_url('admin.php')
            );

        $redirectUrl = add_query_arg(
            [
                'metrics-refreshed' => 'true',
            ],
            $redirectUrl
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function runMonitoringNow(): void {
        $monitoringService = null;
        $redirectUrl = '';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_run_monitoring');

        $monitoringService = new MonitoringService($this->plugin, $this->config);
        $monitoringService->startMonitoringRun(true);

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'tab' => 'monitoring',
                'monitoring-ran' => 'true',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function resetMetrics(): void {
        $restart = !empty($_POST['restart']);
        $redirectUrl = '';
        $metricsService = null;

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_reset_metrics');

        $metricsService = new MetricsService($this, $this->config);
        $metricsService->resetDashboardRefreshState();

        if ($restart) {
            $metricsService->startDashboardRefreshRun(true);
        }

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'tab' => 'monitoring',
                'metrics-reset' => 'true',
                'metrics-refreshed' => $restart ? 'true' : null,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function resetMonitoring(): void {
        $restart = !empty($_POST['restart']);
        $redirectUrl = '';
        $monitoringService = null;

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_reset_monitoring');

        $monitoringService = new MonitoringService($this->plugin, $this->config);
        $monitoringService->resetMonitoringRunState();

        if ($restart) {
            $monitoringService->startMonitoringRun(true);
        }

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'tab' => 'monitoring',
                'monitoring-reset' => 'true',
                'monitoring-ran' => $restart ? 'true' : null,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    protected function renderFields(array $allowedSections = []): void {
        $section = [];
        $sectionId = '';
        $sectionTitle = '';
        $field = [];
        $fieldName = '';
        $fieldId = '';
        $value = null;

        foreach ($this->settingsSections as $section) {
            $sectionId = (string)$section['id'];

            if (!empty($allowedSections) && !in_array($sectionId, $allowedSections, true)) {
                continue;
            }

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

    protected function currentUserCanUseNetworkAdminFeatures(): bool {
        return is_super_admin();
    }

    protected function getAdminPostActionUrl(string $action): string {
        return add_query_arg(
            [
                'action' => $action,
            ],
            admin_url('admin-post.php')
        );
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

        if (!in_array($tab, ['general', 'monitoring', 'views'], true)) {
            return 'general';
        }

        return $tab;
    }

    protected function renderSettingsTabs(string $currentTab): void {
        $baseUrl = add_query_arg(
            [
                'page' => $this->getSettingsSlug(),
            ],
            admin_url('admin.php')
        );

        echo '<nav class="nav-tab-wrapper">';
        echo '<a class="nav-tab' . ($currentTab === 'general' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'general'], $baseUrl)) . '">' . esc_html__('Allgemeines', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'monitoring' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'monitoring'], $baseUrl)) . '">' . esc_html__('Monitoring', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'views' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'views'], $baseUrl)) . '">' . esc_html__('Ansichten', 'rrze-multisite-manager') . '</a>';
        echo '</nav>';
    }

    protected function renderGeneralTab(): void {
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl($this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        echo '<input type="hidden" name="settings_tab" value="general">';
        $this->renderFields(['dashboard']);
        submit_button();
        echo '</form>';
        echo '<hr>';
        echo '<h2>' . esc_html__('Kennzahlen aktualisieren', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Markiert die Kennzahlen als veraltet und startet eine neue Hintergrundberechnung in Batches über das gesamte Netzwerk.', 'rrze-multisite-manager') . '</p>';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_refresh_metrics')) . '">';
        wp_nonce_field('rrze_multisite_manager_refresh_metrics');
        submit_button(__('Kennzahlen aktualisieren', 'rrze-multisite-manager'), 'secondary', 'submit', false);
        echo '</form>';
    }

    protected function renderMonitoringTab(): void {
        $monitoringService = new MonitoringService($this->plugin, $this->config);
        $metricsService = new MetricsService($this, $this->config);
        $processes = array_merge($monitoringService->getProcessesOverview(), $metricsService->getProcessesOverview());
        $runHistory = $monitoringService->getRunHistory();
        $process = [];
        $run = [];

        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl($this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        echo '<input type="hidden" name="settings_tab" value="monitoring">';
        $this->renderFields(['monitoring']);
        submit_button();
        echo '</form>';
        echo '<hr>';
        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Monitoring-Prozesse', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Hier siehst du, welche Monitoring-Prozesse geplant sind, wann sie zuletzt liefen und kannst sie bei Bedarf sofort starten.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';

        if (!empty($processes)) {
            echo '<table class="widefat striped rrze-msm-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Prozess', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Beschreibung', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Intervall (Std.)', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Fortschritt', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Rest', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Letzte Laufdauer', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Aktuelle Laufdauer', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Zuletzt aktiv', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Letzte Site-Anzahl', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Nächster Lauf', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Aktion', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                echo '<tr>';
                echo '<td>' . esc_html((string)($process['title'] ?? '')) . '</td>';
                echo '<td>' . $this->renderProcessDescriptionHtml($process) . '</td>';
                echo '<td>' . $this->renderProcessStatusHtml($process) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(!empty($process['interval_label']) ? (string)$process['interval_label'] : number_format_i18n((int)($process['interval_hours'] ?? 0))) . '</td>';
                echo '<td>' . $this->renderProcessProgressHtml($process) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html($this->formatProcessRemaining($process)) . '</td>';
                echo '<td>' . esc_html($this->formatProcessDuration((int)($process['last_duration_seconds'] ?? 0))) . '</td>';
                echo '<td>' . esc_html($this->formatProcessDuration((int)($process['current_duration_seconds'] ?? 0), empty($process['is_running']))) . '</td>';
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($process['last_run'] ?? ''))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($process['last_site_count'] ?? 0))) . '</td>';
                echo '<td>' . esc_html($this->formatScheduledTimestamp((int)($process['next_run_timestamp'] ?? 0))) . '</td>';
                echo '<td>' . $this->renderProcessActionsHtml($process) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('Derzeit sind keine Monitoring-Prozesse registriert.', 'rrze-multisite-manager') . '</p>';
        }

        echo '</section>';

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Letzte Monitoring-Läufe', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Das ist das kurze Laufprotokoll der letzten kompletten Monitoring-Durchgänge.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';

        if (!empty($runHistory)) {
            echo '<table class="widefat striped rrze-msm-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Ende', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Dauer', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Auslöser', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Sites geprüft', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Statuswechsel', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('DNS-Probleme', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('HTTP-Probleme', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('DNS fehlt', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Nicht erreichbar', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($runHistory as $run) {
                echo '<tr>';
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($run['started_at'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($run['finished_at'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($this->formatProcessDuration($this->calculateTimestampDuration((string)($run['started_at'] ?? ''), (string)($run['finished_at'] ?? '')))) . '</td>';
                echo '<td>' . esc_html((string)($run['trigger'] ?? '')) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($run['checked_sites'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($run['status_changes'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($run['dns_issues'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($run['http_issues'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($run['dns_missing_sites'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($run['unreachable_sites'] ?? 0))) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('Bisher wurde noch kein vollständiger Monitoring-Lauf protokolliert.', 'rrze-multisite-manager') . '</p>';
        }

        echo '</section>';
    }

    protected function renderViewsTab(): void {
        $viewManager = new ViewManager();
        $widgets = $this->getWidgetInstances();
        $views = $viewManager->getViews(array_keys($widgets));
        $widgetOptions = $this->getWidgetOptions($widgets);
        $view = [];
        $widgetOption = [];

        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_save_views')) . '" class="rrze-msm-views-form">';
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
            'operational_status' => new OperationalStatusWidget($this->plugin, $this->config),
            'network_storage_usage' => new NetworkStorageUsageWidget($this->plugin, $this->config),
            'new_monitoring_alerts' => new NewMonitoringAlertsWidget($this->plugin, $this->config),
            'problem_sites' => new ProblemSitesWidget($this->plugin, $this->config),
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

    protected function formatProcessTimestamp(string $timestamp): string {
        if ($timestamp === '' || $timestamp === '0000-00-00 00:00:00') {
            return __('Noch nicht gelaufen', 'rrze-multisite-manager');
        }

        return get_date_from_gmt($timestamp, get_option('date_format') . ' ' . get_option('time_format'));
    }

    protected function formatScheduledTimestamp(int $timestamp): string {
        if ($timestamp <= 0) {
            return __('Nicht geplant', 'rrze-multisite-manager');
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    protected function formatProcessDuration(int $seconds, bool $treatZeroAsEmpty = true): string {
        $hours = 0;
        $minutes = 0;
        $remainingSeconds = 0;
        $parts = [];

        if ($seconds <= 0) {
            return $treatZeroAsEmpty ? __('-', 'rrze-multisite-manager') : __('0 Sek.', 'rrze-multisite-manager');
        }

        $hours = (int)floor($seconds / HOUR_IN_SECONDS);
        $minutes = (int)floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
        $remainingSeconds = $seconds % MINUTE_IN_SECONDS;

        if ($hours > 0) {
            $parts[] = sprintf(_n('%d Std.', '%d Std.', $hours, 'rrze-multisite-manager'), $hours);
        }

        if ($minutes > 0) {
            $parts[] = sprintf(_n('%d Min.', '%d Min.', $minutes, 'rrze-multisite-manager'), $minutes);
        }

        if ($remainingSeconds > 0 || empty($parts)) {
            $parts[] = sprintf(_n('%d Sek.', '%d Sek.', $remainingSeconds, 'rrze-multisite-manager'), $remainingSeconds);
        }

        return implode(' ', $parts);
    }

    protected function formatProcessProgress(array $process): string {
        $checkedSites = (int)($process['checked_sites'] ?? 0);
        $batchTotal = (int)($process['batch_total'] ?? 0);
        $progressPercent = (int)($process['progress_percent'] ?? 0);

        if ($batchTotal > 0) {
            return sprintf(
                __('%1$s / %2$s (%3$d%%)', 'rrze-multisite-manager'),
                number_format_i18n($checkedSites),
                number_format_i18n($batchTotal),
                $progressPercent
            );
        }

        if ((int)($process['last_site_count'] ?? 0) > 0) {
            return sprintf(
                __('%1$s / %2$s', 'rrze-multisite-manager'),
                number_format_i18n($checkedSites),
                number_format_i18n((int)($process['last_site_count'] ?? 0))
            );
        }

        return __('-', 'rrze-multisite-manager');
    }

    protected function formatProcessRemaining(array $process): string {
        $remainingSites = (int)($process['remaining_sites'] ?? 0);

        if ((int)($process['batch_total'] ?? 0) <= 0 && (int)($process['last_site_count'] ?? 0) <= 0) {
            return __('-', 'rrze-multisite-manager');
        }

        return number_format_i18n($remainingSites);
    }

    protected function getProcessStatusLabel(array $process): string {
        if (!empty($process['is_running'])) {
            return __('Läuft', 'rrze-multisite-manager');
        }

        if (!empty($process['run_state']['needs_refresh']) || !empty($process['run_state']['is_dirty'])) {
            return __('Eingeplant', 'rrze-multisite-manager');
        }

        if (!empty($process['last_run'])) {
            return __('Bereit', 'rrze-multisite-manager');
        }

        return __('Noch nicht gelaufen', 'rrze-multisite-manager');
    }

    protected function renderProcessDescriptionHtml(array $process): string {
        $description = (string)($process['description'] ?? '');
        $meta = [];

        if ((int)($process['batch_size'] ?? 0) > 0) {
            $meta[] = sprintf(
                __('Batch-Größe: %s', 'rrze-multisite-manager'),
                number_format_i18n((int)($process['batch_size'] ?? 0))
            );
        }

        if ((int)($process['checked_sites'] ?? 0) > 0) {
            $meta[] = sprintf(
                __('Zuletzt verarbeitet: %s Sites', 'rrze-multisite-manager'),
                number_format_i18n((int)($process['checked_sites'] ?? 0))
            );
        }

        $html = '<div class="rrze-msm-process-description">';
        $html .= '<div>' . esc_html($description) . '</div>';

        if (!empty($meta)) {
            $html .= '<div class="rrze-msm-process-meta">' . esc_html(implode(' | ', $meta)) . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function renderProcessStatusHtml(array $process): string {
        $label = $this->getProcessStatusLabel($process);
        $warning = $this->getProcessWarningLabel($process);
        $className = 'rrze-msm-badge rrze-msm-badge-neutral';
        $html = '';

        if (!empty($process['is_running'])) {
            $className = 'rrze-msm-badge rrze-msm-badge-info';
        } elseif ($warning !== '') {
            $className = 'rrze-msm-badge rrze-msm-badge-danger';
        } elseif (!empty($process['run_state']['needs_refresh']) || !empty($process['run_state']['is_dirty'])) {
            $className = 'rrze-msm-badge rrze-msm-badge-warning';
        } elseif (!empty($process['last_run'])) {
            $className = 'rrze-msm-badge rrze-msm-badge-positive';
        }

        $html .= '<div class="rrze-msm-process-status">';
        $html .= '<span class="' . esc_attr($className) . '">' . esc_html($label) . '</span>';

        if ($warning !== '') {
            $html .= '<div class="rrze-msm-process-warning">' . esc_html($warning) . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function renderProcessProgressHtml(array $process): string {
        $label = $this->formatProcessProgress($process);
        $progressPercent = max(0, min(100, (int)($process['progress_percent'] ?? 0)));
        $html = '<div class="rrze-msm-process-progress">';
        $html .= '<div class="rrze-msm-process-progress-label">' . esc_html($label) . '</div>';

        if ((int)($process['batch_total'] ?? 0) > 0) {
            $html .= '<div class="rrze-msm-process-progress-bar" aria-hidden="true">';
            $html .= '<span class="rrze-msm-process-progress-fill" style="width:' . esc_attr((string)$progressPercent) . '%;"></span>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function renderProcessActionsHtml(array $process): string {
        $processId = (string)($process['id'] ?? '');
        $html = '<div class="rrze-msm-process-actions">';

        if ($processId === 'dashboard-metrics') {
            $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_refresh_metrics')) . '">';
            $html .= wp_nonce_field('rrze_multisite_manager_refresh_metrics', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Jetzt aktualisieren', 'rrze-multisite-manager') . '</button>';
            $html .= '</form>';

            if (!empty($process['is_stale'])) {
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_metrics')) . '">';
                $html .= wp_nonce_field('rrze_multisite_manager_reset_metrics', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Zurücksetzen', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_metrics')) . '">';
                $html .= '<input type="hidden" name="restart" value="1">';
                $html .= wp_nonce_field('rrze_multisite_manager_reset_metrics', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Zurücksetzen und neu starten', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
            }
        } else {
            $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_run_monitoring')) . '">';
            $html .= wp_nonce_field('rrze_multisite_manager_run_monitoring', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Jetzt starten', 'rrze-multisite-manager') . '</button>';
            $html .= '</form>';

            if (!empty($process['is_stale'])) {
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_monitoring')) . '">';
                $html .= wp_nonce_field('rrze_multisite_manager_reset_monitoring', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Zurücksetzen', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_monitoring')) . '">';
                $html .= '<input type="hidden" name="restart" value="1">';
                $html .= wp_nonce_field('rrze_multisite_manager_reset_monitoring', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Zurücksetzen und neu starten', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    protected function getProcessWarningLabel(array $process): string {
        $isRunning = !empty($process['is_running']);
        $currentDurationSeconds = (int)($process['current_duration_seconds'] ?? 0);
        $nextRunTimestamp = (int)($process['next_run_timestamp'] ?? 0);
        $lastRun = (string)($process['last_run'] ?? '');
        $thresholdSeconds = $this->getProcessWarningThresholdSeconds($process);

        if ($isRunning && $currentDurationSeconds > $thresholdSeconds) {
            return __('Lauf dauert auffällig lange', 'rrze-multisite-manager');
        }

        if (!$isRunning && $nextRunTimestamp > 0 && $nextRunTimestamp < (time() - 300)) {
            return __('Nächster Lauf ist überfällig', 'rrze-multisite-manager');
        }

        if ($lastRun === '' && empty($process['run_state']['has_data'])) {
            return __('Es gibt noch keine fertigen Prozessdaten', 'rrze-multisite-manager');
        }

        return '';
    }

    protected function getProcessWarningThresholdSeconds(array $process): int {
        $intervalHours = (int)($process['interval_hours'] ?? 0);

        if ($intervalHours > 0) {
            return max(900, (int)floor(($intervalHours * HOUR_IN_SECONDS) / 2));
        }

        return 900;
    }

    protected function calculateTimestampDuration(string $startedAt, string $finishedAt): int {
        $startedTimestamp = ($startedAt !== '' && $startedAt !== '0000-00-00 00:00:00')
            ? (int)strtotime($startedAt . ' GMT')
            : 0;
        $finishedTimestamp = ($finishedAt !== '' && $finishedAt !== '0000-00-00 00:00:00')
            ? (int)strtotime($finishedAt . ' GMT')
            : 0;

        if ($startedTimestamp <= 0 || $finishedTimestamp <= 0 || $finishedTimestamp < $startedTimestamp) {
            return 0;
        }

        return $finishedTimestamp - $startedTimestamp;
    }
}
