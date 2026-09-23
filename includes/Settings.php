<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- This controller assembles trusted internal admin settings markup and delegates escaping to templates and callbacks.

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
            add_action('admin_post_rrze_multisite_manager_start_site_storage_analysis', [$this, 'startSiteStorageAnalysis']);
            add_action('admin_post_rrze_multisite_manager_reset_site_storage_analysis_schedules', [$this, 'resetSiteStorageAnalysisSchedules']);
            add_action('admin_post_rrze_multisite_manager_initialize_site_storage_analysis_schedules', [$this, 'initializeSiteStorageAnalysisSchedules']);
            add_action('admin_post_rrze_multisite_manager_initialize_shortcode_block_analysis_schedules', [$this, 'initializeShortcodeBlockAnalysisSchedules']);
            add_action('admin_post_rrze_multisite_manager_reset_shortcode_block_analysis_schedules', [$this, 'resetShortcodeBlockAnalysisSchedules']);
            add_action('network_admin_edit_' . $this->optionName, [$this, 'saveNetworkOptions']);
            add_action('network_admin_edit_rrze_multisite_manager_refresh_metrics', [$this, 'refreshMetrics']);
            add_action('network_admin_edit_rrze_multisite_manager_run_monitoring', [$this, 'runMonitoringNow']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_metrics', [$this, 'resetMetrics']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_monitoring', [$this, 'resetMonitoring']);
            add_action('network_admin_edit_rrze_multisite_manager_start_site_storage_analysis', [$this, 'startSiteStorageAnalysis']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_site_storage_analysis_schedules', [$this, 'resetSiteStorageAnalysisSchedules']);
            add_action('network_admin_edit_rrze_multisite_manager_initialize_site_storage_analysis_schedules', [$this, 'initializeSiteStorageAnalysisSchedules']);
            add_action('network_admin_edit_rrze_multisite_manager_initialize_shortcode_block_analysis_schedules', [$this, 'initializeShortcodeBlockAnalysisSchedules']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_shortcode_block_analysis_schedules', [$this, 'resetShortcodeBlockAnalysisSchedules']);
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
        (new StorageAnalysisSchedulerService(new MetricsService($this, $this->config), $this->config))->syncRecurringSchedules();
        (new ShortcodeBlockAnalysisSchedulerService($this->config))->syncRecurringSchedules();

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                'tab' => in_array($settingsTab, ['general', 'monitoring', 'views'], true) ? $settingsTab : 'general',
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
        echo '<p>' . esc_html__('Central settings for dashboard, overviews, and cache behavior.', 'rrze-multisite-manager') . '</p>';
        echo '</div>';
        echo '<div class="rrze-msm-header-controls">';
        echo '<button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="' . esc_attr($this->getColorMode() === 'dark' ? 'light' : 'dark') . '">';
        echo esc_html($this->getColorMode() === 'dark' ? __('Light Mode', 'rrze-multisite-manager') : __('Dark Mode', 'rrze-multisite-manager'));
        echo '</button>';
        echo '</div>';
        echo '</div>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The settings have been saved.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-refreshed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The metrics refresh has been started and is now running in batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['views-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The views have been saved.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-ran'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Monitoring has been started and is now processing the websites in batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The metrics process has been reset.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The monitoring process has been reset.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-status-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The website operational status has been updated.', 'rrze-multisite-manager') . '</p></div>';
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
        $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw((string)wp_unslash($_POST['redirect_to'])) : '';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_run_monitoring');

        $monitoringService = new MonitoringService($this->plugin, $this->config);
        $monitoringService->startMonitoringRun(true);

        $redirectUrl = $redirectTo !== ''
            ? add_query_arg(
                [
                    'monitoring-ran' => 'true',
                ],
                $redirectTo
            )
            : add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring-ran' => 'true',
                ],
                admin_url('admin.php')
            );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function startSiteStorageAnalysis(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $scheduler = null;
        $started = false;
        $isEligible = false;

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_start_site_storage_analysis_' . $siteId);

        $scheduler = new StorageAnalysisSchedulerService(new MetricsService($this, $this->config), $this->config);
        $isEligible = $scheduler->isSiteEligible($siteId);
        $started = $isEligible && $scheduler->startAnalysisNow($siteId);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'websites',
                    'site-storage-started' => $started ? 'true' : null,
                    'site-storage-running' => $started || !$isEligible ? null : 'true',
                    'site-storage-inactive' => $isEligible ? null : 'true',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function resetSiteStorageAnalysisSchedules(): void {
        $scheduler = null;
        $siteCount = 0;

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_reset_site_storage_analysis_schedules');

        if (empty($_POST['confirm_reset'])) {
            wp_die(esc_html__('The security confirmation is missing.', 'rrze-multisite-manager'));
        }

        $scheduler = new StorageAnalysisSchedulerService(new MetricsService($this, $this->config), $this->config);
        $siteCount = $scheduler->resetAllSiteSchedules();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'websites',
                    'site-storage-schedules-reset' => $siteCount,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function initializeSiteStorageAnalysisSchedules(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_initialize_site_storage_analysis_schedules');
        $scheduler = new StorageAnalysisSchedulerService(new MetricsService($this, $this->config), $this->config);
        $siteCount = $scheduler->initializeActiveSiteSchedules();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'websites',
                    'site-storage-schedules-initialized' => $siteCount,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function initializeShortcodeBlockAnalysisSchedules(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_initialize_shortcode_block_analysis_schedules');
        $scheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $siteCount = $scheduler->initializeUnscheduledActiveSites();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'websites',
                    'shortcode-block-schedules-initialized' => $siteCount,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function resetShortcodeBlockAnalysisSchedules(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_reset_shortcode_block_analysis_schedules');

        if (empty($_POST['confirm_reset'])) {
            wp_die(esc_html__('The security confirmation is missing.', 'rrze-multisite-manager'));
        }

        $scheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $siteCount = $scheduler->resetAllSiteAnalyses();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'websites',
                    'shortcode-block-schedules-reset' => $siteCount,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function resetMetrics(): void {
        $restart = !empty($_POST['restart']);
        $redirectUrl = '';
        $metricsService = null;
        $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw((string)wp_unslash($_POST['redirect_to'])) : '';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_reset_metrics');

        $metricsService = new MetricsService($this, $this->config);
        $metricsService->resetDashboardRefreshState();

        if ($restart) {
            $metricsService->startDashboardRefreshRun(true);
        }

        $redirectUrl = $redirectTo !== ''
            ? add_query_arg(
                [
                    'metrics-reset' => 'true',
                    'metrics-refreshed' => $restart ? 'true' : null,
                ],
                $redirectTo
            )
            : add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
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
        $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw((string)wp_unslash($_POST['redirect_to'])) : '';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_reset_monitoring');

        $monitoringService = new MonitoringService($this->plugin, $this->config);
        $monitoringService->resetMonitoringRunState();

        if ($restart) {
            $monitoringService->startMonitoringRun(true);
        }

        $redirectUrl = $redirectTo !== ''
            ? add_query_arg(
                [
                    'monitoring-reset' => 'true',
                    'monitoring-ran' => $restart ? 'true' : null,
                ],
                $redirectTo
            )
            : add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
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
            echo ' ' . esc_html__('Active', 'rrze-multisite-manager');
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

        if ($type === 'select') {
            $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];

            echo '<select id="' . esc_attr($fieldId) . '" name="' . esc_attr($inputName) . '">';

            foreach ($choices as $choiceValue => $choiceLabel) {
                echo '<option value="' . esc_attr((string)$choiceValue) . '" ' . selected((string)$value, (string)$choiceValue, false) . '>' . esc_html((string)$choiceLabel) . '</option>';
            }

            echo '</select>';
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

        if ($type === 'select') {
            $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
            $value = sanitize_key((string)$value);

            return array_key_exists($value, $choices) ? $value : $default;
        }

        return sanitize_text_field((string)$value);
    }

    public function getSettingsSlug(): string {
        return (string)($this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings');
    }

    public function getMonitoringSlug(): string {
        return (string)($this->settingsMenu['monitoring_slug'] ?? 'rrze-multisite-manager-monitoring');
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
        echo '<a class="nav-tab' . ($currentTab === 'general' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'general'], $baseUrl)) . '">' . esc_html__('General', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'monitoring' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'monitoring'], $baseUrl)) . '">' . esc_html__('Monitoring', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'views' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['tab' => 'views'], $baseUrl)) . '">' . esc_html__('Views', 'rrze-multisite-manager') . '</a>';
        echo '</nav>';
    }

    protected function renderGeneralTab(): void {
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl($this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        echo '<input type="hidden" name="settings_tab" value="general">';
        $this->renderFields(['dashboard', 'debugging']);
        submit_button();
        echo '</form>';
        echo '<hr>';
        echo '<h2>' . esc_html__('Refresh metrics', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Marks the metrics as outdated and starts a new background calculation in batches across the entire network.', 'rrze-multisite-manager') . '</p>';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_refresh_metrics')) . '">';
        wp_nonce_field('rrze_multisite_manager_refresh_metrics');
        submit_button(__('Refresh metrics', 'rrze-multisite-manager'), 'secondary', 'submit', false);
        echo '</form>';
    }

    protected function renderMonitoringTab(): void {
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl($this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        echo '<input type="hidden" name="settings_tab" value="monitoring">';
        $this->renderFields(['monitoring']);
        submit_button();
        echo '</form>';
        echo '<hr>';
        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Monitoring', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('The monitoring results are now shown on a separate page.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';
        echo '<p><a class="button button-secondary" href="' . esc_url($this->getMonitoringPageUrl()) . '">' . esc_html__('Open monitoring page', 'rrze-multisite-manager') . '</a></p>';
        echo '</section>';
    }

    public function renderMonitoringPage(): void {
        $currentTab = isset($_GET['monitoring_tab']) ? sanitize_key((string)wp_unslash($_GET['monitoring_tab'])) : 'network';
        $baseUrl = $this->getMonitoringPageUrl();

        if (!in_array($currentTab, ['network', 'websites'], true)) {
            $currentTab = 'network';
        }

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        echo '<div class="wrap rrze-multisite-manager-admin rrze-msm-mode-' . esc_attr($this->getColorMode()) . '">';
        echo '<div class="rrze-msm-page-shell">';
        echo '<div class="rrze-msm-page-header">';
        echo '<div>';
        echo '<h1>' . esc_html__('Monitoring', 'rrze-multisite-manager') . '</h1>';
        echo '</div>';
        echo '<div class="rrze-msm-header-controls">';
        echo '<button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="' . esc_attr($this->getColorMode() === 'dark' ? 'light' : 'dark') . '">';
        echo esc_html($this->getColorMode() === 'dark' ? __('Light Mode', 'rrze-multisite-manager') : __('Dark Mode', 'rrze-multisite-manager'));
        echo '</button>';
        echo '</div>';
        echo '</div>';
        $this->renderMonitoringNotices();
        echo '<nav class="nav-tab-wrapper rrze-msm-monitoring-tabs">';
        echo '<a class="nav-tab' . ($currentTab === 'network' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['monitoring_tab' => 'network'], $baseUrl)) . '">' . esc_html__('Network-wide', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'websites' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['monitoring_tab' => 'websites'], $baseUrl)) . '">' . esc_html__('Websites', 'rrze-multisite-manager') . '</a>';
        echo '</nav>';

        if ($currentTab === 'websites') {
            $this->renderWebsiteStorageMonitoringTab();
        } else {
            $this->renderMonitoringOverviewSections();
        }
        echo '</div>';
        echo '</div>';
    }

    protected function renderMonitoringNotices(): void {
        if (!empty($_GET['monitoring-ran'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Monitoring has been started and is now processing the websites in batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The metrics process has been reset.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-refreshed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The metrics refresh has been started and is now running in batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The monitoring process has been reset.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['site-storage-started'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The storage analysis has been scheduled and will start shortly.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['site-storage-running'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('The storage analysis is already running for this website.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['site-storage-inactive'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Storage analysis is inactive for this website because its current website or monitoring status does not permit scheduled tasks.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (isset($_GET['site-storage-schedules-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('The scheduled storage analysis tasks for %d websites have been reinitialized.', 'rrze-multisite-manager'), absint(wp_unslash($_GET['site-storage-schedules-reset'])))) . '</p></div>';
        }

        if (isset($_GET['site-storage-schedules-initialized'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Storage analysis schedules were initialized for %d active websites.', 'rrze-multisite-manager'), absint(wp_unslash($_GET['site-storage-schedules-initialized'])))) . '</p></div>';
        }

        if (!empty($_GET['shortcode-block-analysis-requested'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The shortcode and block analysis has been scheduled and will start shortly.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (isset($_GET['shortcode-block-schedules-initialized'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Shortcode and block analysis schedules were initialized for %d active websites.', 'rrze-multisite-manager'), absint(wp_unslash($_GET['shortcode-block-schedules-initialized'])))) . '</p></div>';
        }

        if (isset($_GET['shortcode-block-schedules-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Shortcode and block analysis tasks for %d websites have been reinitialized.', 'rrze-multisite-manager'), absint(wp_unslash($_GET['shortcode-block-schedules-reset'])))) . '</p></div>';
        }

    }

    protected function getMonitoringTablePage(string $parameter): int {
        if (!in_array($parameter, ['storage_monitoring_page', 'shortcode_monitoring_page'], true)) {
            return 1;
        }

        return isset($_GET[$parameter]) ? max(1, absint(wp_unslash($_GET[$parameter]))) : 1;
    }

    protected function renderMonitoringTablePagination(string $parameter, int $currentPage, bool $hasMore): void {
        $arguments = [
            'page' => $this->getMonitoringSlug(),
            'monitoring_tab' => 'websites',
        ];

        foreach (['storage_monitoring_page', 'shortcode_monitoring_page'] as $pageParameter) {
            $page = $this->getMonitoringTablePage($pageParameter);

            if ($page > 1) {
                $arguments[$pageParameter] = $page;
            }
        }

        $baseUrl = admin_url('admin.php');
        echo '<div class="tablenav bottom"><div class="tablenav-pages" aria-label="' . esc_attr__('Pagination', 'rrze-multisite-manager') . '">';
        /* translators: %d: current table page number. */
        echo '<span class="displaying-num">' . esc_html(sprintf(__('Page %d', 'rrze-multisite-manager'), $currentPage)) . '</span>';
        echo '<span class="pagination-links">';

        if ($currentPage > 1) {
            $previousArguments = $arguments;
            $previousArguments[$parameter] = $currentPage - 1;
            echo '<a class="prev-page button" href="' . esc_url(add_query_arg($previousArguments, $baseUrl)) . '"><span class="screen-reader-text">' . esc_html__('Previous page', 'rrze-multisite-manager') . '</span><span aria-hidden="true">‹</span></a>';
        }

        if ($hasMore) {
            $nextArguments = $arguments;
            $nextArguments[$parameter] = $currentPage + 1;
            echo '<a class="next-page button" href="' . esc_url(add_query_arg($nextArguments, $baseUrl)) . '"><span class="screen-reader-text">' . esc_html__('Next page', 'rrze-multisite-manager') . '</span><span aria-hidden="true">›</span></a>';
        }

        echo '</span></div></div>';
    }

    protected function renderWebsiteStorageMonitoringTab(): void {
        $scheduler = new StorageAnalysisSchedulerService(new MetricsService($this, $this->config), $this->config);
        $process = [];
        $perPage = min(100, max(10, (int)$this->getOption('dashboard', 'activity_site_limit', 10)));
        $currentPage = $this->getMonitoringTablePage('storage_monitoring_page');
        $processPage = $scheduler->getSiteProcessesPage($currentPage, $perPage);
        $processes = $processPage['processes'];
        $hasMore = !empty($processPage['has_more']);

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Website storage analyses', 'rrze-multisite-manager') . '</h2>';
        echo '</header>';

        if (empty($processes)) {
            echo '<p>' . esc_html__('There are currently no websites registered.', 'rrze-multisite-manager') . '</p>';
            echo '</section>';
            return;
        }

        echo '<div class="rrze-msm-site-table-wrap rrze-msm-server-paginated" data-table-id="monitoring-site-storage" data-sort-key="url" data-sort-direction="asc">';
        echo '<div class="tablenav top"><div class="alignleft actions">';
        echo '<label for="rrze-msm-search-monitoring-site-storage">' . esc_html__('Search website:', 'rrze-multisite-manager') . '</label> ';
        echo '<input type="search" class="rrze-msm-site-table-search" id="rrze-msm-search-monitoring-site-storage" placeholder="' . esc_attr__('Search by URL', 'rrze-multisite-manager') . '"> ';
        echo '<label for="rrze-msm-status-filter-monitoring-site-storage">' . esc_html__('Website status:', 'rrze-multisite-manager') . '</label> ';
        echo '<select class="rrze-msm-site-table-status-filter" id="rrze-msm-status-filter-monitoring-site-storage">';
        echo '<option value="active">' . esc_html__('Active', 'rrze-multisite-manager') . '</option>';
        echo '<option value="inactive">' . esc_html__('Inactive', 'rrze-multisite-manager') . '</option>';
        echo '<option value="all">' . esc_html__('All websites', 'rrze-multisite-manager') . '</option>';
        echo '</select> ';
        echo '</div></div>';
        echo '<table class="widefat striped rrze-msm-table">';
        echo '<thead><tr>';
        echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="url" data-sort-direction="asc"><span>' . esc_html__('URL', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
        echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="status" data-sort-direction="asc"><span>' . esc_html__('Status', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
        echo '<th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('File index', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Orphans', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Metadata', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Runtime', 'rrze-multisite-manager') . '</th>';
        echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="last-run" data-sort-direction="desc"><span>' . esc_html__('Finished', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
        echo '<th>' . esc_html__('Next run', 'rrze-multisite-manager') . '</th>';
        echo '<th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($processes as $process) {
            $siteId = (int)($process['site_id'] ?? 0);
            $statusKey = (string)($process['status_key'] ?? '');
            $lastRun = (string)($process['last_run'] ?? '');
            $lastRunTimestamp = $lastRun !== '' ? (int)strtotime($lastRun . ' UTC') : 0;
            $statusClass = 'rrze-msm-badge-neutral';

            if (!empty($process['is_running'])) {
                $statusClass = 'rrze-msm-badge-info';
            } elseif (in_array($statusKey, ['scheduled', 'waiting_for_cron'], true)) {
                $statusClass = 'rrze-msm-badge-scheduled';
            } elseif ($statusKey === 'ok') {
                $statusClass = 'rrze-msm-badge-positive';
            } elseif ($statusKey === 'inactive') {
                $statusClass = 'rrze-msm-badge-inactive';
            } elseif (in_array($statusKey, ['error', 'aborted'], true)) {
                $statusClass = 'rrze-msm-badge-danger';
            }

            echo '<tr data-sort-name="' . esc_attr(strtolower((string)($process['url'] ?? ''))) . '" data-sort-url="' . esc_attr(strtolower((string)($process['url'] ?? ''))) . '" data-sort-status="' . esc_attr(strtolower((string)($process['status'] ?? ''))) . '" data-sort-last-run="' . esc_attr((string)$lastRunTimestamp) . '" data-site-status="' . esc_attr((string)($process['website_status_key'] ?? 'inactive')) . '">';
            echo '<td class="rrze-msm-monitoring-site-url">';
            echo '<span>' . esc_html((string)($process['url'] ?? '')) . '</span>';
            echo '<div class="row-actions">';
            echo '<span class="rrze-msm-row-action-details"><a href="' . esc_url($this->getSiteDetailsPageUrl($siteId)) . '">' . esc_html__('Details', 'rrze-multisite-manager') . '</a></span>';
            echo ' | ';
            echo '<span class="rrze-msm-row-action-storage"><a href="' . esc_url($this->getSiteStorageAnalysisPageUrl($siteId)) . '">' . esc_html__('Storage Analysis', 'rrze-multisite-manager') . '</a></span>';
            echo '</div>';
            echo '</td>';
            echo '<td><span class="rrze-msm-badge ' . esc_attr($statusClass) . '">' . esc_html((string)($process['status'] ?? '')) . '</span></td>';
            echo '<td>' . esc_html($this->formatMonitoringTimestamp((string)($process['last_started_at'] ?? ''))) . '</td>';
            echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'base', true);
            echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'orphan', true);
            echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'metadata', true);
            $durationSeconds = (int)($process['last_duration_seconds'] ?? 0);
            $durationLabel = !empty($process['last_was_aborted'])
                ? __('Aborted', 'rrze-multisite-manager')
                : ($lastRun !== '' && $durationSeconds <= 0
                    ? __('<1 sec.', 'rrze-multisite-manager')
                    : $this->formatProcessDuration($durationSeconds));
            echo '<td>' . esc_html($durationLabel) . '</td>';
            echo '<td>' . esc_html($this->formatMonitoringTimestamp($lastRun)) . '</td>';
            echo '<td>' . esc_html(!empty($process['is_due']) ? __('Waiting for cron', 'rrze-multisite-manager') : $this->formatMonitoringScheduledTimestamp((int)($process['next_run_timestamp'] ?? 0))) . '</td>';
            echo '<td class="rrze-msm-col-actions">';

            if ($statusKey === 'inactive' || empty($process['is_eligible'])) {
                echo '&mdash;';
            } elseif (!empty($process['is_running'])) {
                echo esc_html__('Running', 'rrze-multisite-manager');
            } else {
                $hasSuccessfulRun = $statusKey === 'ok'
                    && (int)($process['next_run_timestamp'] ?? 0) > time();
                echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_start_site_storage_analysis')) . '">';
                echo '<input type="hidden" name="site_id" value="' . esc_attr((string)$siteId) . '">';
                wp_nonce_field('rrze_multisite_manager_start_site_storage_analysis_' . $siteId);
                echo '<button type="submit" class="button button-secondary">' . esc_html($hasSuccessfulRun ? __('Start now', 'rrze-multisite-manager') : __('Start', 'rrze-multisite-manager')) . '</button>';
                echo '</form>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        $this->renderMonitoringTablePagination('storage_monitoring_page', $currentPage, $hasMore);
        echo '</div>';
        echo '<p class="rrze-msm-site-actions">';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_initialize_site_storage_analysis_schedules')) . '">';
        wp_nonce_field('rrze_multisite_manager_initialize_site_storage_analysis_schedules');
        echo '<button type="submit" class="button button-secondary">' . esc_html__('Initialize storage analysis for newly created websites', 'rrze-multisite-manager') . '</button>';
        echo '</form>';
        echo '<button type="button" class="button button-secondary rrze-msm-button-danger rrze-msm-open-storage-schedule-reset-modal">' . esc_html__('Reset storage analysis schedulers', 'rrze-multisite-manager') . '</button>';
        echo '</p>';
        echo '<div class="rrze-msm-modal" id="rrze-msm-storage-schedule-reset-modal" hidden>';
        echo '<div class="rrze-msm-modal-backdrop rrze-msm-close-storage-schedule-reset-modal"></div>';
        echo '<div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-storage-schedule-reset-title">';
        echo '<h3 id="rrze-msm-storage-schedule-reset-title">' . esc_html__('Reinitialize all scheduler tasks', 'rrze-multisite-manager') . '</h3>';
        echo '<p class="rrze-msm-modal-text">' . esc_html__('This removes all scheduled storage analysis tasks for individual websites and creates new recurring tasks. Network-wide monitoring and metrics tasks are not affected.', 'rrze-multisite-manager') . '</p>';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_site_storage_analysis_schedules')) . '">';
        wp_nonce_field('rrze_multisite_manager_reset_site_storage_analysis_schedules');
        echo '<label class="rrze-msm-modal-checkbox"><input type="checkbox" id="rrze-msm-storage-schedule-reset-confirm" name="confirm_reset" value="1"> <span>' . esc_html__('Yes, reinitialize the website scheduler tasks.', 'rrze-multisite-manager') . '</span></label>';
        echo '<div class="rrze-msm-modal-actions">';
        echo '<button type="button" class="button button-secondary rrze-msm-close-storage-schedule-reset-modal">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button>';
        echo '<button type="submit" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-storage-schedule-reset-submit" disabled>' . esc_html__('Reinitialize tasks', 'rrze-multisite-manager') . '</button>';
        echo '</div></form></div></div>';
        echo '</section>';
        $this->renderWebsiteShortcodeBlockMonitoringTable();
    }

    protected function renderWebsiteShortcodeBlockMonitoringTable(): void {
        $scheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $perPage = min(100, max(10, (int)$this->getOption('dashboard', 'activity_site_limit', 10)));
        $currentPage = $this->getMonitoringTablePage('shortcode_monitoring_page');
        $processPage = $scheduler->getSiteProcessesPage($currentPage, $perPage);
        $processes = $processPage['processes'];
        $hasMore = !empty($processPage['has_more']);
        $monitoringUrl = add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
                'monitoring_tab' => 'websites',
            ],
            admin_url('admin.php')
        );

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header"><h2>' . esc_html__('Website shortcode and block analyses', 'rrze-multisite-manager') . '</h2></header>';

        if (empty($processes)) {
            echo '<p>' . esc_html__('No shortcode or block analysis has been requested yet.', 'rrze-multisite-manager') . '</p>';
        } else {
            echo '<div class="rrze-msm-site-table-wrap rrze-msm-server-paginated" data-table-id="monitoring-shortcode-block" data-sort-key="url" data-sort-direction="asc">';
            echo '<div class="tablenav top"><div class="alignleft actions">';
            echo '<label for="rrze-msm-search-monitoring-shortcode-block">' . esc_html__('Search website:', 'rrze-multisite-manager') . '</label> ';
            echo '<input type="search" class="rrze-msm-site-table-search" id="rrze-msm-search-monitoring-shortcode-block" placeholder="' . esc_attr__('Search by URL', 'rrze-multisite-manager') . '"> ';
            echo '<label for="rrze-msm-status-filter-monitoring-shortcode-block">' . esc_html__('Website status:', 'rrze-multisite-manager') . '</label> ';
            echo '<select class="rrze-msm-site-table-status-filter" id="rrze-msm-status-filter-monitoring-shortcode-block">';
            echo '<option value="active">' . esc_html__('Active', 'rrze-multisite-manager') . '</option>';
            echo '<option value="inactive">' . esc_html__('Inactive', 'rrze-multisite-manager') . '</option>';
            echo '<option value="all">' . esc_html__('All websites', 'rrze-multisite-manager') . '</option>';
            echo '</select> ';
            echo '</div></div>';
            echo '<table class="widefat striped rrze-msm-table"><thead><tr>';
            echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="url" data-sort-direction="asc"><span>' . esc_html__('URL', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
            echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="status" data-sort-direction="asc"><span>' . esc_html__('Status', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
            echo '<th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Shortcodes', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Blocks', 'rrze-multisite-manager') . '</th>';
            echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="last-run" data-sort-direction="desc"><span>' . esc_html__('Finished', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
            echo '<th>' . esc_html__('Next run', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                $siteId = (int)($process['site_id'] ?? 0);
                $statusKey = (string)($process['status_key'] ?? ($process['status'] ?? 'not_scheduled'));

                if ($statusKey === 'complete') {
                    $statusKey = 'ok';
                }

                $statusLabels = [
                    'inactive' => __('Inactive', 'rrze-multisite-manager'),
                    'running' => __('Running', 'rrze-multisite-manager'),
                    'error' => __('Error', 'rrze-multisite-manager'),
                    'waiting_for_cron' => __('Waiting for cron', 'rrze-multisite-manager'),
                    'ok' => __('Ok', 'rrze-multisite-manager'),
                    'scheduled' => __('Scheduled', 'rrze-multisite-manager'),
                    'not_scheduled' => __('Not scheduled', 'rrze-multisite-manager'),
                ];
                $lastFinishedAt = (string)($process['last_finished_at'] ?? '');
                $lastRunTimestamp = $lastFinishedAt !== '' ? (int)strtotime($lastFinishedAt . ' UTC') : 0;
                $statusClass = in_array($statusKey, ['scheduled', 'waiting_for_cron'], true)
                    ? 'rrze-msm-badge-scheduled'
                    : ($statusKey === 'running'
                        ? 'rrze-msm-badge-info'
                        : ($statusKey === 'ok'
                            ? 'rrze-msm-badge-positive'
                            : ($statusKey === 'inactive'
                                ? 'rrze-msm-badge-inactive'
                                : ($statusKey === 'error' ? 'rrze-msm-badge-danger' : 'rrze-msm-badge-neutral'))));
                echo '<tr data-sort-name="' . esc_attr(strtolower((string)($process['url'] ?? ''))) . '" data-sort-url="' . esc_attr(strtolower((string)($process['url'] ?? ''))) . '" data-sort-status="' . esc_attr(strtolower((string)($process['status'] ?? ''))) . '" data-sort-last-run="' . esc_attr((string)$lastRunTimestamp) . '" data-site-status="' . esc_attr((string)($process['website_status_key'] ?? 'inactive')) . '">';
                echo '<td class="rrze-msm-monitoring-site-url">';
                echo '<span>' . esc_html((string)($process['url'] ?? '')) . '</span>';
                echo '<div class="row-actions">';
                echo '<span class="rrze-msm-row-action-details"><a href="' . esc_url($this->getSiteDetailsPageUrl($siteId)) . '">' . esc_html__('Details', 'rrze-multisite-manager') . '</a></span>';
                echo ' | ';
                echo '<span class="rrze-msm-row-action-shortcode-block"><a href="' . esc_url($this->getSiteShortcodeBlockAnalysisPageUrl($siteId)) . '">' . esc_html__('Shortcodes and Blocks', 'rrze-multisite-manager') . '</a></span>';
                echo '</div>';
                echo '</td>';
                echo '<td><span class="rrze-msm-badge ' . esc_attr($statusClass) . '">' . esc_html($statusLabels[$statusKey] ?? $statusLabels['not_scheduled']) . '</span></td>';
                echo '<td>' . esc_html($this->formatMonitoringTimestamp((string)($process['last_started_at'] ?? ''))) . '</td>';
                echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'shortcodes', true);
                echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'blocks', true);
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($process['last_finished_at'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($this->formatScheduledTimestamp((int)($process['next_run_timestamp'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-actions">';

                if (empty($process['is_active'])) {
                    echo '&mdash;';
                } elseif (!empty($process['is_running'])) {
                    echo esc_html__('Running', 'rrze-multisite-manager');
                } else {
                    $hasSuccessfulRun = $statusKey === 'ok'
                        && (int)($process['next_run_timestamp'] ?? 0) > time();
                    echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_request_shortcode_block_analysis')) . '">';
                    echo '<input type="hidden" name="site_id" value="' . esc_attr((string)$siteId) . '">';
                    echo '<input type="hidden" name="redirect_to" value="' . esc_url($monitoringUrl) . '">';
                    wp_nonce_field('rrze_msm_request_shortcode_block_analysis_' . $siteId);
                    echo '<button type="submit" class="button button-secondary">' . esc_html($hasSuccessfulRun ? __('Start now', 'rrze-multisite-manager') : __('Start', 'rrze-multisite-manager')) . '</button>';
                    echo '</form>';
                }

                echo '</td></tr>';
            }

            echo '</tbody></table>';
            $this->renderMonitoringTablePagination('shortcode_monitoring_page', $currentPage, $hasMore);
            echo '</div>';
        }

        echo '<p class="rrze-msm-site-actions">';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_initialize_shortcode_block_analysis_schedules')) . '">';
        wp_nonce_field('rrze_multisite_manager_initialize_shortcode_block_analysis_schedules');
        echo '<button type="submit" class="button button-secondary">' . esc_html__('Initialize shortcode and block analysis for newly created websites', 'rrze-multisite-manager') . '</button>';
        echo '</form>';
        echo '<button type="button" class="button button-secondary rrze-msm-button-danger rrze-msm-open-shortcode-block-schedule-reset-modal">' . esc_html__('Reset shortcode/block analysis schedulers', 'rrze-multisite-manager') . '</button>';
        echo '</p>';
        echo '<div class="rrze-msm-modal" id="rrze-msm-shortcode-block-schedule-reset-modal" hidden>';
        echo '<div class="rrze-msm-modal-backdrop rrze-msm-close-shortcode-block-schedule-reset-modal"></div>';
        echo '<div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-shortcode-block-schedule-reset-title">';
        echo '<h3 id="rrze-msm-shortcode-block-schedule-reset-title">' . esc_html__('Reinitialize all scheduler tasks', 'rrze-multisite-manager') . '</h3>';
        echo '<p class="rrze-msm-modal-text">' . esc_html__('This removes all scheduled shortcode and block analysis tasks and requests new analyses for active websites that are not currently running.', 'rrze-multisite-manager') . '</p>';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_shortcode_block_analysis_schedules')) . '">';
        wp_nonce_field('rrze_multisite_manager_reset_shortcode_block_analysis_schedules');
        echo '<label class="rrze-msm-modal-checkbox"><input type="checkbox" id="rrze-msm-shortcode-block-schedule-reset-confirm" name="confirm_reset" value="1"> <span>' . esc_html__('Yes, reinitialize the shortcode and block analysis tasks.', 'rrze-multisite-manager') . '</span></label>';
        echo '<div class="rrze-msm-modal-actions">';
        echo '<button type="button" class="button button-secondary rrze-msm-close-shortcode-block-schedule-reset-modal">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button>';
        echo '<button type="submit" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-shortcode-block-schedule-reset-submit" disabled>' . esc_html__('Reinitialize tasks', 'rrze-multisite-manager') . '</button>';
        echo '</div></form></div></div>';
        echo '</section>';
    }

    protected function renderMonitoringOverviewSections(): void {
        $monitoringService = new MonitoringService($this->plugin, $this->config);
        $metricsService = new MetricsService($this, $this->config);
        $processes = array_merge($monitoringService->getProcessesOverview(), $metricsService->getProcessesOverview());
        $runHistory = $monitoringService->getRunHistory();
        $recentEvents = $this->collectMonitoringRecentEvents($runHistory);
        $process = [];
        $run = [];
        $event = [];
        $websiteCount = (int)get_sites(['count' => true]);
        $batchSizes = array_values(array_filter(array_map(static fn(array $process): int => (int)($process['batch_size'] ?? 0), $processes)));
        $showProgressColumns = !empty($batchSizes) && $websiteCount > min($batchSizes);

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Monitoring processes', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Here you can see which monitoring processes are scheduled, when they last ran, and start them immediately if needed.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';

        if (!empty($processes)) {
            echo '<table class="widefat striped rrze-msm-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Process', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Description', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Interval (hrs.)', 'rrze-multisite-manager') . '</th>';
            if ($showProgressColumns) {
                echo '<th class="rrze-msm-col-numeric">' . esc_html__('Progress', 'rrze-multisite-manager') . '</th>';
                echo '<th class="rrze-msm-col-numeric">' . esc_html__('Remaining', 'rrze-multisite-manager') . '</th>';
            }
            echo '<th>' . esc_html__('Last active', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Last site count', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Next run', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                echo '<tr>';
                echo '<td>' . esc_html((string)($process['title'] ?? '')) . '</td>';
                echo '<td>' . $this->renderProcessDescriptionHtml($process) . '</td>';
                echo '<td>' . $this->renderProcessStatusHtml($process) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(!empty($process['interval_label']) ? (string)$process['interval_label'] : number_format_i18n((int)($process['interval_hours'] ?? 0))) . '</td>';
                if ($showProgressColumns) {
                    echo '<td>' . $this->renderProcessProgressHtml($process) . '</td>';
                    echo '<td class="rrze-msm-col-numeric">' . esc_html($this->formatProcessRemaining($process)) . '</td>';
                }
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($process['last_run'] ?? ''))) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($process['last_site_count'] ?? 0))) . '</td>';
                echo '<td>' . esc_html($this->formatScheduledTimestamp((int)($process['next_run_timestamp'] ?? 0))) . '</td>';
                echo '<td>' . $this->renderProcessActionsHtml($process, $this->getMonitoringPageUrl()) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('There are currently no monitoring processes registered.', 'rrze-multisite-manager') . '</p>';
        }

        echo '</section>';

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Last monitoring runs', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('This is the run log of the last complete monitoring passes, including notable status changes and technical issues.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';

        if (!empty($runHistory)) {
            echo '<table class="widefat striped rrze-msm-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('End', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Duration', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Trigger', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Sites checked', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Status change', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('DNS issues', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('HTTP problems', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('DNS missing', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric">' . esc_html__('Not reachable', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Details', 'rrze-multisite-manager') . '</th>';
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
                echo '<td>' . $this->renderMonitoringRunDetailsHtml($run) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No complete monitoring run has been logged yet.', 'rrze-multisite-manager') . '</p>';
        }

        echo '</section>';

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Most recently detected issues', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Compact history of the latest status changes and technical issues logged by monitoring.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';

        if (!empty($recentEvents)) {
            echo '<table class="widefat striped rrze-msm-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Timestamp', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Website', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Event', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('DNS', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('HTTP', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Run', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($recentEvents as $event) {
                echo '<tr>';
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($event['checked_at'] ?? ''))) . '</td>';
                echo '<td>' . $this->renderMonitoringEventSiteHtml($event) . '</td>';
                echo '<td>' . esc_html($this->getMonitoringEventTypeLabel((string)($event['type'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($this->formatMonitoringEventStatus($event)) . '</td>';
                echo '<td>' . esc_html($this->formatMonitoringStatusValue((string)($event['dns_status'] ?? ''), (string)($event['dns_status_detail'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($this->formatMonitoringStatusValue((string)($event['http_status'] ?? ''), (string)($event['http_status_detail'] ?? ''), (int)($event['http_status_code'] ?? 0))) . '</td>';
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($event['run_finished_at'] ?? ''))) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No notable monitoring events have been logged yet.', 'rrze-multisite-manager') . '</p>';
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
        echo '<h2>' . esc_html__('Create new view', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('New views start with all widgets. You can then narrow the selection directly below.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';
        echo '<input type="text" class="regular-text" name="new_view_name" value="" placeholder="' . esc_attr__('Name of the new view', 'rrze-multisite-manager') . '">';
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
                    echo '<p class="description">' . esc_html__('System view: always contains all available widgets and cannot be edited.', 'rrze-multisite-manager') . '</p>';
                } else {
                    echo '<p class="description">' . esc_html__('System view: name is fixed, widget assignment can be adjusted.', 'rrze-multisite-manager') . '</p>';
                }
            } else {
                echo '<p>';
                echo '<label>';
                echo '<span class="screen-reader-text">' . esc_html__('Name', 'rrze-multisite-manager') . '</span>';
                echo '<input type="text" class="regular-text" name="views[' . esc_attr((string)$view['slug']) . '][label]" value="' . esc_attr((string)$view['label']) . '">';
                echo '</label> ';
                echo '<label class="rrze-msm-delete-toggle">';
                echo '<input type="checkbox" name="views[' . esc_attr((string)$view['slug']) . '][delete]" value="1"> ';
                echo esc_html__('Delete view', 'rrze-multisite-manager');
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

        submit_button(__('Save views', 'rrze-multisite-manager'));
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

    protected function renderAnalysisPhaseCell(array $phases, string $phaseKey, bool $timeOnly = false): string {
        $phase = is_array($phases[$phaseKey] ?? null) ? $phases[$phaseKey] : [];
        $state = (string)($phase['status'] ?? 'idle');
        $iconClass = 'dashicons-minus';
        $iconStateClass = 'rrze-msm-phase-icon-idle';
        $iconLabel = __('Not started', 'rrze-multisite-manager');

        if ($state === 'complete') {
            $iconClass = 'dashicons-yes';
            $iconStateClass = 'rrze-msm-phase-icon-complete';
            $iconLabel = __('Completed', 'rrze-multisite-manager');
        } elseif ($state === 'running') {
            $iconClass = 'dashicons-update';
            $iconStateClass = 'rrze-msm-phase-icon-running';
            $iconLabel = __('Running', 'rrze-multisite-manager');
        } elseif ($state === 'scheduled') {
            $iconClass = 'dashicons-backup';
            $iconStateClass = 'rrze-msm-phase-icon-scheduled';
            $iconLabel = __('Scheduled', 'rrze-multisite-manager');
        }

        return '<td class="rrze-msm-phase-timestamp"><span class="dashicons ' . esc_attr($iconClass) . ' ' . esc_attr($iconStateClass) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($iconLabel) . '</span> ' . esc_html($this->formatMonitoringTimestamp((string)($phase['started_at'] ?? ''), $timeOnly)) . '</td>';
    }

    protected function formatMonitoringTimestamp(string $timestamp, bool $timeOnly = false): string {
        if ($timestamp === '' || $timestamp === '0000-00-00 00:00:00') {
            return '-';
        }

        return get_date_from_gmt($timestamp, $timeOnly ? 'H:i' : 'd.m.Y H:i');
    }

    protected function formatMonitoringScheduledTimestamp(int $timestamp): string {
        if ($timestamp <= 0) {
            return '-';
        }

        return wp_date('d.m.Y H:i', $timestamp);
    }

    protected function formatProcessTimestamp(string $timestamp): string {
        if ($timestamp === '' || $timestamp === '0000-00-00 00:00:00') {
            return __('Not run yet', 'rrze-multisite-manager');
        }

        return get_date_from_gmt($timestamp, get_option('date_format') . ' ' . get_option('time_format'));
    }

    protected function formatScheduledTimestamp(int $timestamp): string {
        if ($timestamp <= 0) {
            return __('Not scheduled', 'rrze-multisite-manager');
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    protected function formatProcessDuration(int $seconds, bool $treatZeroAsEmpty = true): string {
        $hours = 0;
        $minutes = 0;
        $remainingSeconds = 0;
        $parts = [];

        if ($seconds <= 0) {
            return $treatZeroAsEmpty ? __('-', 'rrze-multisite-manager') : __('0 sec.', 'rrze-multisite-manager');
        }

        $hours = (int)floor($seconds / HOUR_IN_SECONDS);
        $minutes = (int)floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
        $remainingSeconds = $seconds % MINUTE_IN_SECONDS;

        if ($hours > 0) {
            $parts[] = sprintf(_n('%d hr.', '%d hr.', $hours, 'rrze-multisite-manager'), $hours);
        }

        if ($minutes > 0) {
            $parts[] = sprintf(_n('%d min.', '%d min.', $minutes, 'rrze-multisite-manager'), $minutes);
        }

        if ($remainingSeconds > 0 || empty($parts)) {
            $parts[] = sprintf(_n('%d sec.', '%d sec.', $remainingSeconds, 'rrze-multisite-manager'), $remainingSeconds);
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
            return __('Running', 'rrze-multisite-manager');
        }

        if (!empty($process['run_state']['needs_refresh']) || !empty($process['run_state']['is_dirty'])) {
            return __('Scheduled', 'rrze-multisite-manager');
        }

        if (!empty($process['last_run'])) {
            return __('Ready', 'rrze-multisite-manager');
        }

        return __('Not run yet', 'rrze-multisite-manager');
    }

    protected function renderProcessDescriptionHtml(array $process): string {
        $description = (string)($process['description'] ?? '');
        $meta = [];

        if ((int)($process['batch_size'] ?? 0) > 0) {
            $meta[] = sprintf(
                __('Batch size: %s', 'rrze-multisite-manager'),
                number_format_i18n((int)($process['batch_size'] ?? 0))
            );
        }

        if ((int)($process['checked_sites'] ?? 0) > 0) {
            $meta[] = sprintf(
                __('Last processed: %s sites', 'rrze-multisite-manager'),
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

    protected function renderProcessActionsHtml(array $process, string $redirectTo = ''): string {
        $processId = (string)($process['id'] ?? '');
        $html = '<div class="rrze-msm-process-actions">';

        if ($processId === 'dashboard-metrics') {
            $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_refresh_metrics')) . '">';
            if ($redirectTo !== '') {
                $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
            }
            $html .= wp_nonce_field('rrze_multisite_manager_refresh_metrics', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Update now', 'rrze-multisite-manager') . '</button>';
            $html .= '</form>';

            if (!empty($process['is_stale'])) {
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_metrics')) . '">';
                if ($redirectTo !== '') {
                    $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
                }
                $html .= wp_nonce_field('rrze_multisite_manager_reset_metrics', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Reset', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_metrics')) . '">';
                $html .= '<input type="hidden" name="restart" value="1">';
                if ($redirectTo !== '') {
                    $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
                }
                $html .= wp_nonce_field('rrze_multisite_manager_reset_metrics', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Reset and restart', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
            }
        } else {
            $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_run_monitoring')) . '">';
            if ($redirectTo !== '') {
                $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
            }
            $html .= wp_nonce_field('rrze_multisite_manager_run_monitoring', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Start now', 'rrze-multisite-manager') . '</button>';
            $html .= '</form>';

            if (!empty($process['is_stale'])) {
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_monitoring')) . '">';
                if ($redirectTo !== '') {
                    $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
                }
                $html .= wp_nonce_field('rrze_multisite_manager_reset_monitoring', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Reset', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
                $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_reset_monitoring')) . '">';
                $html .= '<input type="hidden" name="restart" value="1">';
                if ($redirectTo !== '') {
                    $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
                }
                $html .= wp_nonce_field('rrze_multisite_manager_reset_monitoring', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Reset and restart', 'rrze-multisite-manager') . '</button>';
                $html .= '</form>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    protected function getMonitoringPageUrl(): string {
        return add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
            ],
            admin_url('admin.php')
        );
    }

    protected function getProcessWarningLabel(array $process): string {
        $isRunning = !empty($process['is_running']);
        $currentDurationSeconds = (int)($process['current_duration_seconds'] ?? 0);
        $nextRunTimestamp = (int)($process['next_run_timestamp'] ?? 0);
        $lastRun = (string)($process['last_run'] ?? '');
        $thresholdSeconds = $this->getProcessWarningThresholdSeconds($process);

        if ($isRunning && $currentDurationSeconds > $thresholdSeconds) {
            return __('Run is taking unusually long', 'rrze-multisite-manager');
        }

        if (!$isRunning && $nextRunTimestamp > 0 && $nextRunTimestamp < (time() - 300)) {
            return __('Next run is overdue', 'rrze-multisite-manager');
        }

        if ($lastRun === '' && empty($process['run_state']['has_data'])) {
            return __('No completed process data is available yet', 'rrze-multisite-manager');
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

    protected function renderMonitoringRunDetailsHtml(array $run): string {
        $statusChanges = isset($run['changed_sites']) && is_array($run['changed_sites']) ? $run['changed_sites'] : [];
        $issueSites = isset($run['issue_sites']) && is_array($run['issue_sites']) ? $run['issue_sites'] : [];
        $parts = [];

        if (!empty($statusChanges)) {
            $parts[] = '<div><strong>' . esc_html__('Status change', 'rrze-multisite-manager') . '</strong></div><ul>' . $this->renderMonitoringRunEventListItems($statusChanges) . '</ul>';
        }

        if (!empty($issueSites)) {
            $parts[] = '<div><strong>' . esc_html__('Technical issues', 'rrze-multisite-manager') . '</strong></div><ul>' . $this->renderMonitoringRunEventListItems($issueSites) . '</ul>';
        }

        if (empty($parts)) {
            return esc_html__('No notable issues have been logged.', 'rrze-multisite-manager');
        }

        return implode('', $parts);
    }

    protected function renderMonitoringRunEventListItems(array $events): string {
        $html = '';
        $event = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $html .= '<li>' . $this->renderMonitoringEventSummaryHtml($event) . '</li>';
        }

        return $html;
    }

    protected function renderMonitoringEventSummaryHtml(array $event): string {
        $siteHtml = $this->renderMonitoringEventSiteHtml($event);
        $summary = $this->formatMonitoringEventStatus($event);
        $details = [];

        if (!empty($event['dns_status'])) {
            $details[] = sprintf(
                __('DNS: %s', 'rrze-multisite-manager'),
                $this->formatMonitoringStatusValue(
                    (string)$event['dns_status'],
                    (string)($event['dns_status_detail'] ?? '')
                )
            );
        }

        if (!empty($event['http_status'])) {
            $details[] = sprintf(
                __('HTTP: %s', 'rrze-multisite-manager'),
                $this->formatMonitoringStatusValue(
                    (string)$event['http_status'],
                    (string)($event['http_status_detail'] ?? ''),
                    (int)($event['http_status_code'] ?? 0)
                )
            );
        }

        return $siteHtml . ' - ' . esc_html($summary . (!empty($details) ? ' (' . implode(', ', $details) . ')' : ''));
    }

    protected function collectMonitoringRecentEvents(array $runHistory): array {
        $events = [];
        $run = [];
        $entry = [];
        $limit = $this->getMonitoringRecentEventLimit();

        foreach ($runHistory as $run) {
            if (!is_array($run)) {
                continue;
            }

            foreach (['changed_sites', 'issue_sites'] as $key) {
                if (empty($run[$key]) || !is_array($run[$key])) {
                    continue;
                }

                foreach ($run[$key] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $entry['run_finished_at'] = (string)($run['finished_at'] ?? '');
                    $events[] = $entry;

                    if (count($events) >= $limit) {
                        break 3;
                    }
                }
            }
        }

        return $events;
    }

    protected function getMonitoringRecentEventLimit(): int {
        return max(10, min(500, (int)$this->getOption('monitoring', 'recent_event_entries', 30)));
    }

    protected function renderMonitoringEventSiteHtml(array $event): string {
        $siteId = (int)($event['site_id'] ?? 0);
        $label = trim((string)($event['site_label'] ?? ''));
        $siteUrl = trim((string)($event['site_url'] ?? ''));
        $detailsUrl = $this->getSiteDetailsPageUrl($siteId);
        $text = $label !== '' ? $label : $siteUrl;

        if ($text === '') {
            $text = sprintf(__('Site %d', 'rrze-multisite-manager'), $siteId);
        }

        $html = $detailsUrl !== ''
            ? '<a href="' . esc_url($detailsUrl) . '">' . esc_html($text) . '</a>'
            : esc_html($text);

        if ($siteUrl !== '') {
            $html .= '<div><code>' . esc_html(untrailingslashit($siteUrl)) . '</code></div>';
        }

        return $html;
    }

    protected function formatMonitoringEventStatus(array $event): string {
        $previous = (string)($event['previous_status'] ?? '');
        $current = (string)($event['status'] ?? '');

        if (!empty($event['status_changed']) && $previous !== '' && $current !== '') {
            return sprintf(
                __('%1$s -> %2$s', 'rrze-multisite-manager'),
                $this->getOperationalStatusLabel($previous),
                $this->getOperationalStatusLabel($current)
            );
        }

        if ($current !== '') {
            return $this->getOperationalStatusLabel($current);
        }

        return __('-', 'rrze-multisite-manager');
    }

    protected function getMonitoringEventTypeLabel(string $type): string {
        if ($type === 'status_change') {
            return __('Status change', 'rrze-multisite-manager');
        }

        if ($type === 'dns_issue') {
            return __('DNS issue', 'rrze-multisite-manager');
        }

        if ($type === 'http_issue') {
            return __('HTTP issue', 'rrze-multisite-manager');
        }

        return __('Monitoring event', 'rrze-multisite-manager');
    }

    protected function getMonitoringStatusLabel(string $status): string {
        if ($status === 'ok') {
            return __('OK', 'rrze-multisite-manager');
        }

        if ($status === 'missing') {
            return __('Missing', 'rrze-multisite-manager');
        }

        if ($status === 'timeout') {
            return __('Timeout', 'rrze-multisite-manager');
        }

        if ($status === 'pending') {
            return __('Pending', 'rrze-multisite-manager');
        }

        if ($status === 'error') {
            return __('Error', 'rrze-multisite-manager');
        }

        if ($status === 'unknown') {
            return __('Unknown', 'rrze-multisite-manager');
        }

        return $status !== '' ? $status : __('-', 'rrze-multisite-manager');
    }

    protected function formatMonitoringStatusValue(string $status, string $detail = '', int $code = 0): string {
        $label = $this->getMonitoringStatusLabel($status);
        $parts = [];

        if ($code > 0 && strpos($detail, (string)$code) === false) {
            $parts[] = (string)$code;
        }

        if ($detail !== '') {
            $parts[] = $detail;
        }

        if (empty($parts)) {
            return $label;
        }

        return sprintf(
            /* translators: 1: monitoring status label, 2: monitoring detail text. */
            __('%1$s (%2$s)', 'rrze-multisite-manager'),
            $label,
            implode(' | ', $parts)
        );
    }

    protected function getOperationalStatusLabel(string $status): string {
        if ($status === 'healthy') {
            return __('Technically reachable', 'rrze-multisite-manager');
        }

        if ($status === 'provisioning') {
            return __('Provisioning phase', 'rrze-multisite-manager');
        }

        if ($status === 'dns_missing') {
            return __('DNS missing', 'rrze-multisite-manager');
        }

        if ($status === 'unreachable') {
            return __('HTTP unreachable', 'rrze-multisite-manager');
        }

        if ($status === 'retired') {
            return __('Decommissioned', 'rrze-multisite-manager');
        }

        return $status !== '' ? $status : __('-', 'rrze-multisite-manager');
    }

    protected function getSiteDetailsPageUrl(int $siteId): string {
        if ($siteId <= 0) {
            return '';
        }

        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_details_slug'] ?? 'rrze-multisite-manager-site-details'),
                'site_id' => $siteId,
            ],
            admin_url('admin.php')
        );
    }

    protected function getSiteStorageAnalysisPageUrl(int $siteId): string {
        if ($siteId <= 0) {
            return '';
        }

        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_storage_analysis_slug'] ?? 'rrze-multisite-manager-site-storage-analysis'),
                'site_id' => $siteId,
            ],
            admin_url('admin.php')
        );
    }

    protected function getSiteShortcodeBlockAnalysisPageUrl(int $siteId): string {
        if ($siteId <= 0) {
            return '';
        }

        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['shortcode_block_analysis_slug'] ?? 'rrze-multisite-manager-shortcodes-blocks'),
                'site_id' => $siteId,
            ],
            admin_url('admin.php')
        );
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
