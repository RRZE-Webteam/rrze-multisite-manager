<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- This controller assembles trusted internal admin settings markup and delegates escaping to templates and callbacks.

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Support\ScheduleFrequency;
use RRZE\MultisiteManager\Presentation\StatusFormatter;
defined('ABSPATH') || exit;

use RRZE\MultisiteManager\Metrics\StorageAnalysisService;
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
    protected const LEGACY_CENTRAL_CRON_CLEANUP_STATE_OPTION = 'rrze_msm_legacy_central_cron_cleanup_state';
    protected const LEGACY_CENTRAL_CRON_CLEANUP_BATCH_SIZE = 100;
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
    protected ScheduleFrequency $scheduleFrequency;
    protected StatusFormatter $statusFormatter;
    protected array $sectionDescriptions = [];

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        $this->config = new Config();
        $this->scheduleFrequency = new ScheduleFrequency($this->config);
        $this->statusFormatter = new StatusFormatter();
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
            add_action('admin_post_rrze_multisite_manager_start_full_data_cleanup', [$this, 'startFullDataCleanup']);
            add_action('admin_post_rrze_multisite_manager_remove_dashboard_metrics_tasks', [$this, 'removeDashboardMetricsTasks']);
            add_action('admin_post_rrze_multisite_manager_remove_monitoring_tasks', [$this, 'removeMonitoringTasks']);
            add_action('admin_post_rrze_multisite_manager_remove_storage_analysis_tasks', [$this, 'removeStorageAnalysisTasks']);
            add_action('admin_post_rrze_multisite_manager_remove_shortcode_block_analysis_tasks', [$this, 'removeShortcodeBlockAnalysisTasks']);
            add_action('admin_post_rrze_multisite_manager_start_site_storage_analysis', [$this, 'startSiteStorageAnalysis']);
            add_action('admin_post_rrze_multisite_manager_initialize_site_storage_analysis_schedules', [$this, 'initializeSiteStorageAnalysisSchedules']);
            add_action('admin_post_rrze_multisite_manager_migrate_storage_analysis_schedules', [$this, 'migrateStorageAnalysisSchedules']);
            add_action('admin_post_rrze_multisite_manager_initialize_shortcode_block_analysis_schedules', [$this, 'initializeShortcodeBlockAnalysisSchedules']);
            add_action('admin_post_rrze_multisite_manager_schedule_selected_storage_analysis_sites', [$this, 'scheduleSelectedStorageAnalysisSites']);
            add_action('admin_post_rrze_multisite_manager_schedule_selected_shortcode_block_analysis_sites', [$this, 'scheduleSelectedShortcodeBlockAnalysisSites']);
            add_action('admin_post_rrze_multisite_manager_remove_selected_storage_analysis_sites', [$this, 'removeSelectedStorageAnalysisSites']);
            add_action('admin_post_rrze_multisite_manager_remove_selected_shortcode_block_analysis_sites', [$this, 'removeSelectedShortcodeBlockAnalysisSites']);
            add_action('admin_post_rrze_multisite_manager_start_shared_analysis_batch', [$this, 'startSharedAnalysisBatch']);
            add_action('wp_ajax_rrze_msm_start_analysis_schedule_initialization', [$this, 'ajaxStartAnalysisScheduleInitialization']);
            add_action('wp_ajax_rrze_msm_run_analysis_schedule_initialization_batch', [$this, 'ajaxRunAnalysisScheduleInitializationBatch']);
            add_action('wp_ajax_rrze_msm_start_analysis_task_removal', [$this, 'ajaxStartAnalysisTaskRemoval']);
            add_action('wp_ajax_rrze_msm_run_analysis_task_removal_batch', [$this, 'ajaxRunAnalysisTaskRemovalBatch']);
            add_action('wp_ajax_rrze_msm_start_legacy_central_cron_cleanup', [$this, 'ajaxStartLegacyCentralCronCleanup']);
            add_action('wp_ajax_rrze_msm_run_legacy_central_cron_cleanup_batch', [$this, 'ajaxRunLegacyCentralCronCleanupBatch']);
            add_action('network_admin_edit_' . $this->optionName, [$this, 'saveNetworkOptions']);
            add_action('network_admin_edit_rrze_multisite_manager_refresh_metrics', [$this, 'refreshMetrics']);
            add_action('network_admin_edit_rrze_multisite_manager_run_monitoring', [$this, 'runMonitoringNow']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_metrics', [$this, 'resetMetrics']);
            add_action('network_admin_edit_rrze_multisite_manager_reset_monitoring', [$this, 'resetMonitoring']);
            add_action('network_admin_edit_rrze_multisite_manager_start_full_data_cleanup', [$this, 'startFullDataCleanup']);
            add_action('network_admin_edit_rrze_multisite_manager_remove_dashboard_metrics_tasks', [$this, 'removeDashboardMetricsTasks']);
            add_action('network_admin_edit_rrze_multisite_manager_remove_monitoring_tasks', [$this, 'removeMonitoringTasks']);
            add_action('network_admin_edit_rrze_multisite_manager_remove_storage_analysis_tasks', [$this, 'removeStorageAnalysisTasks']);
            add_action('network_admin_edit_rrze_multisite_manager_remove_shortcode_block_analysis_tasks', [$this, 'removeShortcodeBlockAnalysisTasks']);
            add_action('network_admin_edit_rrze_multisite_manager_start_site_storage_analysis', [$this, 'startSiteStorageAnalysis']);
            add_action('network_admin_edit_rrze_multisite_manager_initialize_site_storage_analysis_schedules', [$this, 'initializeSiteStorageAnalysisSchedules']);
            add_action('network_admin_edit_rrze_multisite_manager_migrate_storage_analysis_schedules', [$this, 'migrateStorageAnalysisSchedules']);
            add_action('network_admin_edit_rrze_multisite_manager_initialize_shortcode_block_analysis_schedules', [$this, 'initializeShortcodeBlockAnalysisSchedules']);
            add_action('network_admin_edit_rrze_multisite_manager_schedule_selected_storage_analysis_sites', [$this, 'scheduleSelectedStorageAnalysisSites']);
            add_action('network_admin_edit_rrze_multisite_manager_schedule_selected_shortcode_block_analysis_sites', [$this, 'scheduleSelectedShortcodeBlockAnalysisSites']);
            add_action('network_admin_edit_rrze_multisite_manager_remove_selected_storage_analysis_sites', [$this, 'removeSelectedStorageAnalysisSites']);
            add_action('network_admin_edit_rrze_multisite_manager_remove_selected_shortcode_block_analysis_sites', [$this, 'removeSelectedShortcodeBlockAnalysisSites']);
            add_action('network_admin_edit_rrze_multisite_manager_start_shared_analysis_batch', [$this, 'startSharedAnalysisBatch']);
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
        $storedOptions = get_site_option($this->optionName, []);
        $options = $storedOptions;

        if (!is_array($options)) {
            $options = [];
        }

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        if (is_array($storedOptions) && !array_key_exists('monitoring_metrics_frequency', $storedOptions)) {
            $interval = isset($storedOptions['monitoring_metrics_interval_hours'])
                ? (int)$storedOptions['monitoring_metrics_interval_hours']
                : (int)ceil((int)($storedOptions['monitoring_metrics_interval_minutes'] ?? 120) / 60);
            $options['monitoring_metrics_frequency'] = $this->getCycleFrequencyFromHours($interval);
        }

        if (is_array($storedOptions) && !array_key_exists('monitoring_monitoring_frequency', $storedOptions)) {
            $options['monitoring_monitoring_frequency'] = $this->getCycleFrequencyFromHours((int)($storedOptions['monitoring_monitoring_interval_hours'] ?? 6));
        }

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

        // Each settings tab submits only its own fields. Start with the stored,
        // already normalized values so saving one tab cannot reset the others.
        $defaults = $this->defaultOptions();
        $stored = array_intersect_key($this->options, $defaults);
        $submitted = array_intersect_key($options, $defaults);
        $merged = array_merge($defaults, $stored, $submitted);
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
        $previousOptions = $this->options;

        $monitoringFrequencyChanged = (string)($previousOptions['monitoring_monitoring_frequency'] ?? '') !== (string)($options['monitoring_monitoring_frequency'] ?? '');
        $metricsFrequencyChanged = (string)($previousOptions['monitoring_metrics_frequency'] ?? '') !== (string)($options['monitoring_metrics_frequency'] ?? '');
        $storageAnalysisFrequencyChanged = (string)($previousOptions['monitoring_storage_analysis_frequency'] ?? '') !== (string)($options['monitoring_storage_analysis_frequency'] ?? '');
        $shortcodeAnalysisFrequencyChanged = (string)($previousOptions['monitoring_shortcode_block_analysis_frequency'] ?? '') !== (string)($options['monitoring_shortcode_block_analysis_frequency'] ?? '');

        update_site_option($this->optionName, $options);

        if ($monitoringFrequencyChanged) {
            (new MonitoringService($this->plugin, $this->config))->rescheduleRecurringEvent();
        }

        if ($metricsFrequencyChanged) {
            (new MetricsService($this, $this->config))->rescheduleDashboardRefreshCycle();
        }

        if ($storageAnalysisFrequencyChanged) {
            (new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config))->queueFrequencyReschedule();
        }

        if ($shortcodeAnalysisFrequencyChanged) {
            (new ShortcodeBlockAnalysisSchedulerService($this->config))->queueFrequencyReschedule();
        }

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

        if (!empty($_GET['full-data-cleanup-started'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('The complete MSM data cleanup has been started. Other MSM tasks remain paused until it is complete.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['full-data-cleanup-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the data cleanup before starting it.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (isset($_GET['storage-analysis-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled storage-analysis tasks. */
                __('Removed %d scheduled storage-analysis tasks.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['storage-analysis-tasks-removed']))
            )) . '</p></div>';
        }

        if (!empty($_GET['storage-analysis-task-removal-started']) || !empty($_GET['shortcode-block-analysis-task-removal-started'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of websites to process. */
                __('The scheduled-task cleanup has started and is processing %d websites in background Cron batches.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['analysis-task-removal-total'] ?? 0))
            )) . '</p></div>';
        }

        if (isset($_GET['shortcode-block-analysis-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled shortcode and block analysis tasks. */
                __('Removed %d scheduled shortcode and block analysis tasks.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['shortcode-block-analysis-tasks-removed']))
            )) . '</p></div>';
        }

        if (isset($_GET['dashboard-metrics-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled dashboard-metrics tasks. */
                __('Removed %d scheduled dashboard-metrics tasks. Automatic scheduling remains disabled until the process is started manually.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['dashboard-metrics-tasks-removed']))
            )) . '</p></div>';
        }

        if (isset($_GET['monitoring-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled website-availability tasks. */
                __('Removed %d scheduled website-availability tasks. Automatic scheduling remains disabled until the process is started manually.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['monitoring-tasks-removed']))
            )) . '</p></div>';
        }

        if (!empty($_GET['analysis-task-removal-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the removal of the scheduled tasks before continuing.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['monitoring-task-removal-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the removal of the scheduled monitoring tasks before continuing.', 'rrze-multisite-manager') . '</p></div>';
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

        $this->renderCentralScheduleActionNotices();

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
        $scheduleAction = (new MetricsService($this, $this->config))->scheduleDashboardRefreshFromNow();

        $redirectUrl = $redirectTo !== ''
            ? $redirectTo
            : add_query_arg(
                [
                    'page' => $this->settingsMenu['settings_slug'] ?? 'rrze-multisite-manager-settings',
                ],
                admin_url('admin.php')
            );

        $redirectUrl = add_query_arg(
            [
                'metrics-schedule-action' => $scheduleAction,
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
        $scheduleAction = $monitoringService->scheduleMonitoringFromNow();

        $redirectUrl = $redirectTo !== ''
            ? add_query_arg(
                [
                    'monitoring-schedule-action' => $scheduleAction,
                ],
                $redirectTo
            )
            : add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring-schedule-action' => $scheduleAction,
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

        $scheduler = new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config);
        $isEligible = $scheduler->isSiteEligible($siteId);
        $started = $isEligible && $scheduler->startAnalysisNow($siteId);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'storage',
                    'site-storage-started' => $started ? 'true' : null,
                    'site-storage-running' => $started || !$isEligible ? null : 'true',
                    'site-storage-inactive' => $isEligible ? null : 'true',
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

        if (empty($_POST['confirm_storage_schedule_initialization'])) {
            wp_safe_redirect(add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'storage',
                    'site-schedule-confirmation-required' => 'true',
                ],
                admin_url('admin.php')
            ));
            exit;
        }

        $scheduler = new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config);
        $batch = $scheduler->initializeActiveSiteSchedulesBatch();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'storage',
                    'site-storage-schedules-initialized' => $batch['initialized'],
                    'site-storage-schedules-processed' => $batch['processed'],
                    'site-storage-schedules-total' => $batch['total'],
                    'site-storage-schedules-complete' => $batch['complete'] ? 'true' : 'false',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function migrateStorageAnalysisSchedules(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_migrate_storage_analysis_schedules');

        if (empty($_POST['confirm_storage_schedule_migration'])) {
            wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => 'storage', 'storage-migration-confirmation-required' => 'true'], admin_url('admin.php')));
            exit;
        }

        $migration = (new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config))->migrateSchedulesBatch();
        wp_safe_redirect(add_query_arg([
            'page' => $this->getMonitoringSlug(),
            'monitoring_tab' => 'storage',
            'storage-migration-phase' => $migration['phase'],
            'storage-migration-processed' => $migration['processed'],
            'storage-migration-total' => $migration['total'],
            'storage-migration-scheduled' => $migration['scheduled'],
            'storage-migration-complete' => $migration['complete'] ? 'true' : 'false',
        ], admin_url('admin.php')));
        exit;
    }

    public function initializeShortcodeBlockAnalysisSchedules(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_initialize_shortcode_block_analysis_schedules');

        if (empty($_POST['confirm_shortcode_block_schedule_initialization'])) {
            wp_safe_redirect(add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'shortcode-block',
                    'site-schedule-confirmation-required' => 'true',
                ],
                admin_url('admin.php')
            ));
            exit;
        }

        $scheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $batch = $scheduler->initializeUnscheduledActiveSitesBatch();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                    'monitoring_tab' => 'shortcode-block',
                    'shortcode-block-schedules-initialized' => $batch['initialized'],
                    'shortcode-block-schedules-processed' => $batch['processed'],
                    'shortcode-block-schedules-total' => $batch['total'],
                    'shortcode-block-schedules-complete' => $batch['complete'] ? 'true' : 'false',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function ajaxStartAnalysisScheduleInitialization(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_send_json_error(['message' => __('You are not allowed to manage these settings.', 'rrze-multisite-manager')], 403);
        }

        check_ajax_referer('rrze_msm_analysis_schedule_initialization', 'nonce');
        $scheduler = $this->getAnalysisScheduleInitializationScheduler();

        if ($scheduler === null) {
            wp_send_json_error(['message' => __('The requested analysis type is invalid.', 'rrze-multisite-manager')], 400);
        }

        wp_send_json_success($scheduler->startScheduleInitialization());
    }

    public function ajaxRunAnalysisScheduleInitializationBatch(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_send_json_error(['message' => __('You are not allowed to manage these settings.', 'rrze-multisite-manager')], 403);
        }

        check_ajax_referer('rrze_msm_analysis_schedule_initialization', 'nonce');
        $scheduler = $this->getAnalysisScheduleInitializationScheduler();
        $runId = sanitize_text_field(wp_unslash($_POST['run_id'] ?? ''));

        if ($scheduler === null || $runId === '') {
            wp_send_json_error(['message' => __('The requested analysis setup is no longer available. Please start it again.', 'rrze-multisite-manager')], 400);
        }

        $progress = $scheduler->runScheduleInitializationBatch($runId);

        if (($progress['run_id'] ?? '') === '') {
            wp_send_json_error(['message' => __('The requested analysis setup is no longer available. Please start it again.', 'rrze-multisite-manager')], 409);
        }

        wp_send_json_success($progress);
    }

    public function ajaxStartAnalysisTaskRemoval(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_send_json_error(['message' => __('You are not allowed to manage these settings.', 'rrze-multisite-manager')], 403);
        }
        check_ajax_referer('rrze_msm_analysis_task_removal', 'nonce');
        $scheduler = $this->getAnalysisScheduleInitializationScheduler();
        if ($scheduler === null) {
            wp_send_json_error(['message' => __('The requested analysis type is invalid.', 'rrze-multisite-manager')], 400);
        }
        wp_send_json_success($scheduler->startAjaxTaskRemoval());
    }

    public function ajaxRunAnalysisTaskRemovalBatch(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_send_json_error(['message' => __('You are not allowed to manage these settings.', 'rrze-multisite-manager')], 403);
        }
        check_ajax_referer('rrze_msm_analysis_task_removal', 'nonce');
        $scheduler = $this->getAnalysisScheduleInitializationScheduler();
        $runId = sanitize_text_field(wp_unslash($_POST['run_id'] ?? ''));
        if ($scheduler === null || $runId === '') {
            wp_send_json_error(['message' => __('The requested task removal is no longer available. Please start it again.', 'rrze-multisite-manager')], 400);
        }
        $progress = $scheduler->runAjaxTaskRemoval($runId);
        if (empty($progress)) {
            wp_send_json_error(['message' => __('The requested task removal is no longer available. Please start it again.', 'rrze-multisite-manager')], 409);
        }
        wp_send_json_success($progress);
    }

    public function ajaxStartLegacyCentralCronCleanup(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_send_json_error(['message' => __('You are not allowed to manage these settings.', 'rrze-multisite-manager')], 403);
        }
        check_ajax_referer('rrze_msm_legacy_central_cron_cleanup', 'nonce');
        $state = [
            'run_id' => wp_generate_uuid4(),
            'offset' => 0,
            'total' => (int)get_sites(['count' => true, 'number' => 1]),
            'processed' => 0,
            'removed' => 0,
            'complete' => false,
        ];
        update_site_option(self::LEGACY_CENTRAL_CRON_CLEANUP_STATE_OPTION, $state);
        wp_send_json_success($state);
    }

    public function ajaxRunLegacyCentralCronCleanupBatch(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_send_json_error(['message' => __('You are not allowed to manage these settings.', 'rrze-multisite-manager')], 403);
        }
        check_ajax_referer('rrze_msm_legacy_central_cron_cleanup', 'nonce');
        $state = get_site_option(self::LEGACY_CENTRAL_CRON_CLEANUP_STATE_OPTION, []);
        $runId = sanitize_text_field(wp_unslash($_POST['run_id'] ?? ''));
        if (!is_array($state) || !hash_equals((string)($state['run_id'] ?? ''), $runId)) {
            wp_send_json_error(['message' => __('The requested cleanup is no longer available. Please start it again.', 'rrze-multisite-manager')], 409);
        }
        $siteIds = get_sites(['fields' => 'ids', 'number' => self::LEGACY_CENTRAL_CRON_CLEANUP_BATCH_SIZE, 'offset' => max(0, (int)($state['offset'] ?? 0)), 'orderby' => 'id', 'order' => 'ASC']);
        $centralSiteId = (int)get_main_site_id(get_current_network_id());
        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            if ($siteId === $centralSiteId) {
                continue;
            }
            switch_to_blog($siteId);
            try {
                $state['removed'] += wp_clear_scheduled_hook('rrze_msm_refresh_dashboard_metrics');
                $state['removed'] += wp_clear_scheduled_hook($this->config->getMonitoringHook());
            } finally {
                restore_current_blog();
            }
        }
        $state['processed'] = (int)($state['offset'] ?? 0) + count($siteIds);
        $state['offset'] = $state['processed'];
        $state['complete'] = empty($siteIds) || $state['processed'] >= (int)($state['total'] ?? 0);
        update_site_option(self::LEGACY_CENTRAL_CRON_CLEANUP_STATE_OPTION, $state);
        wp_send_json_success($state);
    }

    /** @return StorageAnalysisSchedulerService|ShortcodeBlockAnalysisSchedulerService|null */
    protected function getAnalysisScheduleInitializationScheduler() {
        $analysisType = sanitize_key(wp_unslash($_POST['analysis_type'] ?? ''));

        if ($analysisType === 'storage') {
            return new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config);
        }

        if ($analysisType === 'shortcode-block') {
            return new ShortcodeBlockAnalysisSchedulerService($this->config);
        }

        return null;
    }

    public function scheduleSelectedStorageAnalysisSites(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_schedule_selected_storage_analysis_sites');
        if (!$this->hasSelectedAnalysisBulkAction()) {
            wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => 'storage'], admin_url('admin.php')));
            exit;
        }
        $siteIds = $this->getSelectedAnalysisSiteIds();
        $scheduled = (new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config))
            ->scheduleSelectedActiveSites($siteIds);

        wp_safe_redirect(add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
                'monitoring_tab' => 'storage',
                'selected-storage-analysis-sites-scheduled' => $scheduled,
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    public function scheduleSelectedShortcodeBlockAnalysisSites(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_schedule_selected_shortcode_block_analysis_sites');
        if (!$this->hasSelectedAnalysisBulkAction()) {
            wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => 'shortcode-block'], admin_url('admin.php')));
            exit;
        }
        $siteIds = $this->getSelectedAnalysisSiteIds();
        $scheduled = (new ShortcodeBlockAnalysisSchedulerService($this->config))->scheduleSelectedActiveSites($siteIds);

        wp_safe_redirect(add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
                'monitoring_tab' => 'shortcode-block',
                'selected-shortcode-block-analysis-sites-scheduled' => $scheduled,
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    public function removeSelectedStorageAnalysisSites(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_remove_selected_storage_analysis_sites');
        if (!$this->hasSelectedAnalysisBulkAction('remove')) {
            wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => 'storage'], admin_url('admin.php')));
            exit;
        }

        $removed = (new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config))
            ->removeSelectedScheduledSites($this->getSelectedAnalysisSiteIds());
        wp_safe_redirect(add_query_arg([
            'page' => $this->getMonitoringSlug(),
            'monitoring_tab' => 'storage',
            'selected-storage-analysis-sites-removed' => $removed,
        ], admin_url('admin.php')));
        exit;
    }

    public function removeSelectedShortcodeBlockAnalysisSites(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_remove_selected_shortcode_block_analysis_sites');
        if (!$this->hasSelectedAnalysisBulkAction('remove')) {
            wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => 'shortcode-block'], admin_url('admin.php')));
            exit;
        }

        $removed = (new ShortcodeBlockAnalysisSchedulerService($this->config))
            ->removeSelectedScheduledSites($this->getSelectedAnalysisSiteIds());
        wp_safe_redirect(add_query_arg([
            'page' => $this->getMonitoringSlug(),
            'monitoring_tab' => 'shortcode-block',
            'selected-shortcode-block-analysis-sites-removed' => $removed,
        ], admin_url('admin.php')));
        exit;
    }

    /** @return array<int, int> */
    protected function getSelectedAnalysisSiteIds(): array {
        $siteIds = isset($_POST['site_ids']) && is_array($_POST['site_ids'])
            ? array_map('absint', wp_unslash($_POST['site_ids']))
            : [];

        return array_slice(array_values(array_unique(array_filter($siteIds))), 0, 100);
    }

    protected function hasSelectedAnalysisBulkAction(string $expectedAction = 'schedule'): bool {
        $actions = [
            isset($_POST['bulk_action_top']) ? sanitize_key((string)wp_unslash($_POST['bulk_action_top'])) : '',
            isset($_POST['bulk_action_bottom']) ? sanitize_key((string)wp_unslash($_POST['bulk_action_bottom'])) : '',
        ];

        return in_array($expectedAction, $actions, true);
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

    public function startFullDataCleanup(): void {
        $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw((string)wp_unslash($_POST['redirect_to'])) : '';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_start_full_data_cleanup');

        $redirectUrl = $redirectTo !== ''
            ? $redirectTo
            : add_query_arg(
                [
                    'page' => $this->getMonitoringSlug(),
                ],
                admin_url('admin.php')
            );

        if (empty($_POST['confirm_cleanup'])) {
            wp_safe_redirect(add_query_arg('full-data-cleanup-confirmation-required', 'true', $redirectUrl));
            exit;
        }

        (new MetricsService($this, $this->config))->startFullDataCleanup();

        wp_safe_redirect(add_query_arg('full-data-cleanup-started', 'true', $redirectUrl));
        exit;
    }

    public function removeStorageAnalysisTasks(): void {
        $this->removeWebsiteAnalysisTasks(
            'rrze_multisite_manager_remove_storage_analysis_tasks',
            'confirm_storage_task_removal',
            fn(Config $config): array => (new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $config)), $config))->queueScheduledTaskRemoval(),
            'storage-analysis-task-removal-started'
        );
    }

    public function removeDashboardMetricsTasks(): void {
        $this->removeNetworkMonitoringTasks(
            'rrze_multisite_manager_remove_dashboard_metrics_tasks',
            'confirm_dashboard_metrics_task_removal',
            fn(): int => (new MetricsService($this, $this->config))->disableDashboardScheduling(),
            'dashboard-metrics-tasks-removed'
        );
    }

    public function removeMonitoringTasks(): void {
        $this->removeNetworkMonitoringTasks(
            'rrze_multisite_manager_remove_monitoring_tasks',
            'confirm_monitoring_task_removal',
            fn(): int => (new MonitoringService($this->plugin, $this->config))->disableMonitoringScheduling(),
            'monitoring-tasks-removed'
        );
    }

    public function removeShortcodeBlockAnalysisTasks(): void {
        $this->removeWebsiteAnalysisTasks(
            'rrze_multisite_manager_remove_shortcode_block_analysis_tasks',
            'confirm_shortcode_block_task_removal',
            static fn(Config $config): array => (new ShortcodeBlockAnalysisSchedulerService($config))->queueScheduledTaskRemoval(),
            'shortcode-block-analysis-task-removal-started'
        );
    }

    public function startSharedAnalysisBatch(): void {
        $type = isset($_POST['analysis_type']) ? sanitize_key((string)wp_unslash($_POST['analysis_type'])) : '';
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }
        check_admin_referer('rrze_multisite_manager_start_shared_analysis_batch_' . $type);
        if (empty($_POST['confirm_start_shared_batch'])) {
            wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => $type], admin_url('admin.php')));
            exit;
        }
        $started = $type === 'storage'
            ? (new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config))->startBatchNow()
            : ($type === 'shortcode-block' ? (new ShortcodeBlockAnalysisSchedulerService($this->config))->startBatchNow() : false);
        wp_safe_redirect(add_query_arg(['page' => $this->getMonitoringSlug(), 'monitoring_tab' => $type, 'shared-batch-started' => $started ? 'true' : 'false'], admin_url('admin.php')));
        exit;
    }

    /**
     * @param callable(Config): array<string, mixed> $removeTasks
     */
    protected function removeWebsiteAnalysisTasks(string $nonceAction, string $confirmationField, callable $removeTasks, string $noticeParameter): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer($nonceAction);
        $defaultRedirectUrl = add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
                'monitoring_tab' => 'tools',
            ],
            admin_url('admin.php')
        );
        $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw((string)wp_unslash($_POST['redirect_to'])) : '';
        $redirectUrl = $redirectTo !== '' ? wp_validate_redirect($redirectTo, $defaultRedirectUrl) : $defaultRedirectUrl;

        if (empty($_POST[$confirmationField])) {
            wp_safe_redirect(add_query_arg('analysis-task-removal-confirmation-required', 'true', $redirectUrl));
            exit;
        }

        $state = $removeTasks($this->config);
        wp_safe_redirect(add_query_arg([
            $noticeParameter => 'true',
            'analysis-task-removal-total' => absint($state['total'] ?? 0),
        ], $redirectUrl));
        exit;
    }

    /**
     * @param callable(): int $removeTasks
     */
    protected function removeNetworkMonitoringTasks(string $nonceAction, string $confirmationField, callable $removeTasks, string $noticeParameter): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer($nonceAction);

        $redirectUrl = add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
                'monitoring_tab' => 'tools',
            ],
            admin_url('admin.php')
        );

        if (empty($_POST[$confirmationField])) {
            wp_safe_redirect(add_query_arg('monitoring-task-removal-confirmation-required', 'true', $redirectUrl));
            exit;
        }

        $removed = $removeTasks();
        wp_safe_redirect(add_query_arg($noticeParameter, $removed, $redirectUrl));
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

            if (empty($this->settingsFields[$sectionId]) || !is_array($this->settingsFields[$sectionId])) {
                continue;
            }

            $sectionTitle = (string)$section['title'];
            echo '<section class="rrze-msm-settings-group">';
            echo '<header class="rrze-msm-settings-group-header"><h2>' . esc_html($sectionTitle) . '</h2></header>';

            if (!empty($section['description'])) {
                echo '<p class="rrze-msm-settings-group-content">' . esc_html((string)$section['description']) . '</p>';
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
            echo '</section>';
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

    protected function getCycleFrequencyFromHours(int $hours): string {
        return $this->scheduleFrequency->fromHours($hours);
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
        $this->renderMonitoringManagementNotice();
    }

    protected function renderMonitoringTab(): void {
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl($this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        echo '<input type="hidden" name="settings_tab" value="monitoring">';
        $this->renderMonitoringFieldGroup(__('Network-wide', 'rrze-multisite-manager'), [
            'metrics_frequency',
            'monitoring_frequency',
            'batch_size',
        ]);
        $this->renderMonitoringFieldGroup(__('Website availability', 'rrze-multisite-manager'), [
            'provisioning_grace_hours',
            'dns_failure_threshold',
            'http_failure_threshold',
        ]);
        $this->renderMonitoringFieldGroup(__('Storage analyses', 'rrze-multisite-manager'), [
            'storage_analysis_frequency',
            'storage_analysis_batch_media_threshold',
            'storage_analysis_batch_runtime_threshold_seconds',
            'storage_analysis_timeout_minutes',
            'storage_analysis_orphan_files_limit',
            'storage_analysis_browser_max_megabytes',
        ]);
        $this->renderMonitoringFieldGroup(__('Shortcodes and Blocks', 'rrze-multisite-manager'), [
            'shortcode_block_analysis_frequency',
            'shortcode_block_analysis_timeout_minutes',
        ]);
        $this->renderMonitoringFieldGroup(__('Monitoring', 'rrze-multisite-manager'), [
            'run_log_entries',
            'recent_event_entries',
        ]);
        submit_button();
        echo '</form>';
        $this->renderMonitoringManagementNotice();
    }

    /** @param array<int, string> $fieldNames */
    protected function renderMonitoringFieldGroup(string $title, array $fieldNames): void {
        $availableFields = $this->settingsFields['monitoring'] ?? [];
        $fieldsByName = [];

        foreach ($availableFields as $field) {
            if (is_array($field) && !empty($field['name'])) {
                $fieldsByName[(string)$field['name']] = $field;
            }
        }

        $fields = [];

        foreach ($fieldNames as $fieldName) {
            if (isset($fieldsByName[$fieldName])) {
                $fields[] = $fieldsByName[$fieldName];
            }
        }

        if (empty($fields)) {
            return;
        }

        echo '<section class="rrze-msm-settings-group">';
        echo '<header class="rrze-msm-settings-group-header"><h2>' . esc_html($title) . '</h2></header>';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ($fields as $field) {
            $fieldName = 'monitoring_' . (string)$field['name'];
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
        echo '</section>';
    }

    protected function renderMonitoringManagementNotice(): void {
        $monitoringUrl = $this->getMonitoringPageUrl();
        $monitoringLink = '<a href="' . esc_url($monitoringUrl) . '">' . esc_html__('Monitoring', 'rrze-multisite-manager') . '</a>';
        $allowedHtml = [
            'a' => [
                'href' => true,
            ],
        ];

        echo '<p>' . wp_kses(
            sprintf(
                /* translators: 1, 2: link to the Monitoring page. */
                __('Metrics and website data are managed through %1$s. Visit %2$s to start or manage any tasks.', 'rrze-multisite-manager'),
                $monitoringLink,
                $monitoringLink
            ),
            $allowedHtml
        ) . '</p>';
    }

    public function renderMonitoringPage(): void {
        $currentTab = isset($_GET['monitoring_tab']) ? sanitize_key((string)wp_unslash($_GET['monitoring_tab'])) : 'network';
        $baseUrl = $this->getMonitoringPageUrl();

        // Keep existing bookmarks working after splitting the former Websites tab.
        if ($currentTab === 'websites') {
            $currentTab = 'storage';
        }

        if (!in_array($currentTab, ['network', 'storage', 'shortcode-block', 'tools'], true)) {
            $currentTab = 'network';
        }

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        echo '<div class="wrap rrze-multisite-manager-admin rrze-msm-monitoring-page rrze-msm-mode-' . esc_attr($this->getColorMode()) . '">';
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
        echo '<a class="nav-tab' . ($currentTab === 'storage' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['monitoring_tab' => 'storage'], $baseUrl)) . '">' . esc_html__('Storage analyses', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'shortcode-block' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['monitoring_tab' => 'shortcode-block'], $baseUrl)) . '">' . esc_html__('Shortcodes and Blocks', 'rrze-multisite-manager') . '</a>';
        echo '<a class="nav-tab' . ($currentTab === 'tools' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(['monitoring_tab' => 'tools'], $baseUrl)) . '">' . esc_html__('Tools', 'rrze-multisite-manager') . '</a>';
        echo '</nav>';

        if ($currentTab === 'storage') {
            $this->renderWebsiteStorageMonitoringTab();
        } elseif ($currentTab === 'shortcode-block') {
            $this->renderWebsiteShortcodeBlockMonitoringTable();
        } elseif ($currentTab === 'tools') {
            $this->renderMonitoringToolsTab();
        } else {
            $this->renderMonitoringOverviewSections();
        }
        echo '</div>';
        echo '</div>';
    }

    protected function renderCentralScheduleActionNotices(): void {
        $metricsAction = isset($_GET['metrics-schedule-action'])
            ? sanitize_key((string)wp_unslash($_GET['metrics-schedule-action']))
            : '';
        $monitoringAction = isset($_GET['monitoring-schedule-action'])
            ? sanitize_key((string)wp_unslash($_GET['monitoring-schedule-action']))
            : '';
        $messages = [
            'metrics-created' => __('The metrics task was created.', 'rrze-multisite-manager'),
            'metrics-rescheduled' => __('The metrics task was rescheduled to start now.', 'rrze-multisite-manager'),
            'monitoring-created' => __('The website availability task was created.', 'rrze-multisite-manager'),
            'monitoring-rescheduled' => __('The website availability task was rescheduled to start now.', 'rrze-multisite-manager'),
        ];
        $messageKey = $metricsAction !== '' ? 'metrics-' . $metricsAction : 'monitoring-' . $monitoringAction;

        if (!isset($messages[$messageKey])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$messageKey]) . '</p></div>';
    }

    protected function renderMonitoringNotices(): void {
        $this->renderCentralScheduleActionNotices();

        if (!empty($_GET['monitoring-ran'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Monitoring has been started and is now processing the websites in batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The metrics process has been reset.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['metrics-refreshed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The metrics refresh has been started and is now running in batches.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['full-data-cleanup-started'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('The complete MSM data cleanup has been started. Other MSM tasks remain paused until it is complete.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['full-data-cleanup-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the data cleanup before starting it.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (isset($_GET['storage-analysis-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled storage-analysis tasks. */
                __('Removed %d scheduled storage-analysis tasks.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['storage-analysis-tasks-removed']))
            )) . '</p></div>';
        }

        if (!empty($_GET['storage-analysis-task-removal-started']) || !empty($_GET['shortcode-block-analysis-task-removal-started'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of websites to process. */
                __('The scheduled-task cleanup has started and is processing %d websites in background Cron batches.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['analysis-task-removal-total'] ?? 0))
            )) . '</p></div>';
        }

        if (isset($_GET['shortcode-block-analysis-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled shortcode and block analysis tasks. */
                __('Removed %d scheduled shortcode and block analysis tasks.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['shortcode-block-analysis-tasks-removed']))
            )) . '</p></div>';
        }

        if (isset($_GET['dashboard-metrics-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled dashboard-metrics tasks. */
                __('Removed %d scheduled dashboard-metrics tasks. Automatic scheduling remains disabled until the process is started manually.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['dashboard-metrics-tasks-removed']))
            )) . '</p></div>';
        }

        if (isset($_GET['monitoring-tasks-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed scheduled website-availability tasks. */
                __('Removed %d scheduled website-availability tasks. Automatic scheduling remains disabled until the process is started manually.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['monitoring-tasks-removed']))
            )) . '</p></div>';
        }

        if (!empty($_GET['analysis-task-removal-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the removal of the scheduled tasks before continuing.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['site-schedule-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the scheduling of the website analysis tasks before continuing.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($_GET['storage-migration-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the schedule migration before continuing.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (isset($_GET['storage-migration-phase'])) {
            $phase = sanitize_key((string)wp_unslash($_GET['storage-migration-phase']));
            $processed = absint(wp_unslash($_GET['storage-migration-processed'] ?? 0));
            $total = absint(wp_unslash($_GET['storage-migration-total'] ?? 0));
            $scheduled = absint(wp_unslash($_GET['storage-migration-scheduled'] ?? 0));
            $complete = 'true' === (string)wp_unslash($_GET['storage-migration-complete'] ?? '');
            $message = $complete
                /* translators: %d: number of websites scheduled during migration. */
                ? sprintf(__('Storage-analysis schedule migration completed; %d websites were scheduled.', 'rrze-multisite-manager'), $scheduled)
                /* translators: 1: migration phase, 2: processed websites, 3: total websites. */
                : sprintf(__('Storage-analysis schedule migration (%1$s): %2$d of %3$d websites processed.', 'rrze-multisite-manager'), $phase, $processed, $total);
            echo '<div class="notice notice-' . ($complete ? 'success' : 'warning') . ' is-dismissible"><p>' . esc_html($message) . '</p>';
            if (!$complete) {
                echo '<p>' . esc_html__('Use the migration button again to continue. Storage analyses remain paused until it is complete.', 'rrze-multisite-manager') . '</p>';
            }
            echo '</div>';
        }

        if (isset($_GET['selected-storage-analysis-sites-scheduled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of scheduled storage analyses. */
                __('Storage-analysis tasks were scheduled for %d selected websites.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['selected-storage-analysis-sites-scheduled']))
            )) . '</p></div>';
        }

        if (isset($_GET['selected-shortcode-block-analysis-sites-scheduled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of scheduled shortcode and block analyses. */
                __('Shortcode and block analysis tasks were scheduled for %d selected websites.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['selected-shortcode-block-analysis-sites-scheduled']))
            )) . '</p></div>';
        }

        if (isset($_GET['selected-storage-analysis-sites-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed storage-analysis tasks. */
                __('Removed storage-analysis tasks for %d selected websites.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['selected-storage-analysis-sites-removed']))
            )) . '</p></div>';
        }

        if (isset($_GET['selected-shortcode-block-analysis-sites-removed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of removed shortcode and block analysis tasks. */
                __('Removed shortcode and block analysis tasks for %d selected websites.', 'rrze-multisite-manager'),
                absint(wp_unslash($_GET['selected-shortcode-block-analysis-sites-removed']))
            )) . '</p></div>';
        }

        if (!empty($_GET['monitoring-task-removal-confirmation-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please confirm the removal of the scheduled monitoring tasks before continuing.', 'rrze-multisite-manager') . '</p></div>';
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

        if (isset($_GET['site-storage-schedules-initialized'])) {
            $processed = isset($_GET['site-storage-schedules-processed']) ? absint(wp_unslash($_GET['site-storage-schedules-processed'])) : 0;
            $total = isset($_GET['site-storage-schedules-total']) ? absint(wp_unslash($_GET['site-storage-schedules-total'])) : 0;
            $initialized = absint(wp_unslash($_GET['site-storage-schedules-initialized']));
            $complete = !empty($_GET['site-storage-schedules-complete']);
            $message = $processed > 0 || $total > 0
                /* translators: 1: processed websites, 2: total websites, 3: created tasks. */
                ? sprintf(__('Storage-analysis setup: %1$d of %2$d websites checked; %3$d tasks created.', 'rrze-multisite-manager'), $processed, $total, $initialized)
                /* translators: %d: number of active websites with initialized schedules. */
                : sprintf(__('Storage analysis schedules were initialized for %d active websites.', 'rrze-multisite-manager'), $initialized);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p>';
            if (!$complete && $total > 0) {
                echo '<p>' . esc_html__('Use the same button to continue with the next batch.', 'rrze-multisite-manager') . '</p>';
            }
            echo '</div>';
        }

        if (!empty($_GET['shortcode-block-analysis-requested'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The shortcode and block analysis has been scheduled and will start shortly.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (isset($_GET['shortcode-block-schedules-initialized'])) {
            $processed = isset($_GET['shortcode-block-schedules-processed']) ? absint(wp_unslash($_GET['shortcode-block-schedules-processed'])) : 0;
            $total = isset($_GET['shortcode-block-schedules-total']) ? absint(wp_unslash($_GET['shortcode-block-schedules-total'])) : 0;
            $initialized = absint(wp_unslash($_GET['shortcode-block-schedules-initialized']));
            $complete = !empty($_GET['shortcode-block-schedules-complete']);
            $message = $processed > 0 || $total > 0
                /* translators: 1: processed websites, 2: total websites, 3: created tasks. */
                ? sprintf(__('Shortcode/block-analysis setup: %1$d of %2$d websites checked; %3$d tasks created.', 'rrze-multisite-manager'), $processed, $total, $initialized)
                /* translators: %d: number of active websites with initialized schedules. */
                : sprintf(__('Shortcode and block analysis schedules were initialized for %d active websites.', 'rrze-multisite-manager'), $initialized);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p>';
            if (!$complete && $total > 0) {
                echo '<p>' . esc_html__('Use the same button to continue with the next batch.', 'rrze-multisite-manager') . '</p>';
            }
            echo '</div>';
        }

    }

    protected function getMonitoringTablePage(string $parameter): int {
        if (!in_array($parameter, ['storage_monitoring_page', 'storage_unassigned_monitoring_page', 'shortcode_monitoring_page', 'shortcode_unassigned_monitoring_page'], true)) {
            return 1;
        }

        return isset($_GET[$parameter]) ? max(1, absint(wp_unslash($_GET[$parameter]))) : 1;
    }

    protected function getMonitoringWebsiteSearchTerm(string $parameter = 'monitoring_site_search'): string {
        $search = isset($_GET[$parameter])
            ? trim(sanitize_text_field(wp_unslash($_GET[$parameter])))
            : '';

        return strlen($search) >= 3 ? $search : '';
    }

    protected function renderMonitoringWebsiteSearchForm(string $search, string $fieldId, string $monitoringTab = 'storage', string $parameter = 'monitoring_site_search'): void {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="search-box rrze-msm-monitoring-site-search">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->getMonitoringSlug()) . '">';
        echo '<input type="hidden" name="monitoring_tab" value="' . esc_attr($monitoringTab) . '">';
        echo '<label class="screen-reader-text" for="' . esc_attr($fieldId) . '">' . esc_html__('Search websites:', 'rrze-multisite-manager') . '</label> ';
        echo '<input type="search" id="' . esc_attr($fieldId) . '" name="' . esc_attr($parameter) . '" value="' . esc_attr($search) . '" minlength="3" required> ';
        echo '<button type="submit" class="button">' . esc_html__('Search', 'rrze-multisite-manager') . '</button>';
        echo '</form>';
    }

    protected function renderSelectedSiteBulkActions(string $formId, string $position, string $actionValue = 'schedule', string $actionLabel = ''): void {
        $actionLabel = $actionLabel !== '' ? $actionLabel : __('Set up analyses', 'rrze-multisite-manager');

        echo '<div class="alignleft actions bulkactions">';
        echo '<label class="screen-reader-text" for="' . esc_attr($formId) . '-bulk-action-' . esc_attr($position) . '">' . esc_html__('Select bulk action', 'rrze-multisite-manager') . '</label>';
        echo '<select name="bulk_action_' . esc_attr($position) . '" id="' . esc_attr($formId) . '-bulk-action-' . esc_attr($position) . '" form="' . esc_attr($formId) . '">';
        echo '<option value="-1">' . esc_html__('Bulk actions', 'rrze-multisite-manager') . '</option>';
        echo '<option value="' . esc_attr($actionValue) . '">' . esc_html($actionLabel) . '</option>';
        echo '</select>';
        echo '<button type="submit" class="button action" form="' . esc_attr($formId) . '">' . esc_html__('Apply', 'rrze-multisite-manager') . '</button>';
        echo '</div>';
    }

    protected function renderMonitoringTablePagination(string $parameter, int $currentPage, int $totalItems, int $perPage, string $monitoringTab = 'storage'): void {
        $arguments = [
            'page' => $this->getMonitoringSlug(),
            'monitoring_tab' => $monitoringTab,
        ];

        foreach (['storage_monitoring_page', 'storage_unassigned_monitoring_page', 'shortcode_monitoring_page', 'shortcode_unassigned_monitoring_page'] as $pageParameter) {
            $page = $this->getMonitoringTablePage($pageParameter);

            if ($page > 1) {
                $arguments[$pageParameter] = $page;
            }
        }

        foreach (['monitoring_site_search', 'storage_unassigned_site_search', 'shortcode_unassigned_site_search'] as $searchParameter) {
            $search = $this->getMonitoringWebsiteSearchTerm($searchParameter);

            if ($search !== '') {
                $arguments[$searchParameter] = $search;
            }
        }

        $baseUrl = admin_url('admin.php');
        $totalItems = max(0, $totalItems);
        $totalPages = max(1, (int)ceil($totalItems / max(1, $perPage)));
        $currentPage = min(max(1, $currentPage), $totalPages);
        $getPageUrl = static function (int $page) use ($arguments, $parameter, $baseUrl): string {
            if ($page > 1) {
                $arguments[$parameter] = $page;
            } else {
                unset($arguments[$parameter]);
            }

            return add_query_arg($arguments, $baseUrl);
        };

        echo '<div class="tablenav bottom"><div class="tablenav-pages rrze-msm-site-table-pagination' . ($totalPages <= 1 ? ' one-page' : '') . '" aria-label="' . esc_attr__('Pagination', 'rrze-multisite-manager') . '">';
        /* translators: %s: number of websites. */
        echo '<span class="displaying-num">' . esc_html(sprintf(_n('%s website', '%s websites', $totalItems, 'rrze-multisite-manager'), number_format_i18n($totalItems))) . '</span>';
        echo '<span class="pagination-links">';

        if ($currentPage > 1) {
            echo '<a class="first-page button" href="' . esc_url($getPageUrl(1)) . '"><span class="screen-reader-text">' . esc_html__('First page', 'rrze-multisite-manager') . '</span><span aria-hidden="true">«</span></a>';
            echo '<a class="prev-page button" href="' . esc_url($getPageUrl($currentPage - 1)) . '"><span class="screen-reader-text">' . esc_html__('Previous page', 'rrze-multisite-manager') . '</span><span aria-hidden="true">‹</span></a>';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
        }

        /* translators: 1: current page number, 2: total page count. */
        echo '<span class="paging-input"><span class="tablenav-paging-text">' . esc_html(sprintf(__('Page %1$s of %2$s', 'rrze-multisite-manager'), $currentPage, $totalPages)) . '</span></span>';

        if ($currentPage < $totalPages) {
            echo '<a class="next-page button" href="' . esc_url($getPageUrl($currentPage + 1)) . '"><span class="screen-reader-text">' . esc_html__('Next page', 'rrze-multisite-manager') . '</span><span aria-hidden="true">›</span></a>';
            echo '<a class="last-page button" href="' . esc_url($getPageUrl($totalPages)) . '"><span class="screen-reader-text">' . esc_html__('Last page', 'rrze-multisite-manager') . '</span><span aria-hidden="true">»</span></a>';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
        }

        echo '</span></div></div>';
    }

    protected function renderWebsiteStorageMonitoringTab(): void {
        $scheduler = new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config);
        $process = [];
        $perPage = min(100, max(10, (int)$this->getOption('dashboard', 'activity_site_limit', 10)));
        $currentPage = $this->getMonitoringTablePage('storage_monitoring_page');
        $search = $this->getMonitoringWebsiteSearchTerm();
        $unassignedSearch = $this->getMonitoringWebsiteSearchTerm('storage_unassigned_site_search');
        $processPage = $scheduler->getSiteProcessesPage($currentPage, $perPage, $search);
        $processes = $processPage['processes'];
        $totalItems = (int)($processPage['total'] ?? 0);
        $unassignedPage = $scheduler->getUnscheduledActiveSiteProcessesPage(
            $this->getMonitoringTablePage('storage_unassigned_monitoring_page'),
            $perPage,
            $unassignedSearch
        );

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Website storage analyses', 'rrze-multisite-manager') . '</h2>';
        echo '</header>';

        if (empty($processes)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('There are currently no scheduled storage analysis tasks.', 'rrze-multisite-manager') . '</p></div>';
        }

        if (!empty($processes)) {
        $selectionFormId = wp_unique_id('rrze-msm-remove-storage-sites-');
        echo '<form id="' . esc_attr($selectionFormId) . '" method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_remove_selected_storage_analysis_sites')) . '">';
        wp_nonce_field('rrze_multisite_manager_remove_selected_storage_analysis_sites');
        echo '</form>';
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-server-paginated" data-table-id="monitoring-site-storage" data-sort-key="url" data-sort-direction="asc">';
        echo '<div class="tablenav top">';
        $this->renderSelectedSiteBulkActions($selectionFormId, 'top', 'remove', __('Remove selected tasks', 'rrze-multisite-manager'));
        echo '<div class="alignleft actions">';
        echo '</div><div class="alignright">';
        $this->renderMonitoringWebsiteSearchForm($search, 'rrze-msm-search-monitoring-site-storage');
        echo '</div><br class="clear"></div>';
        echo '<table class="widefat striped rrze-msm-table">';
        echo '<thead><tr>';
        echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all"></th>';
        echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="url" data-sort-direction="asc"><span>' . esc_html__('Site', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
        echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="status" data-sort-direction="asc"><span>' . esc_html__('Status', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
        echo '<th>' . esc_html__('Execution', 'rrze-multisite-manager') . '</th>';
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
            $statusKey = (string)($process['status_key'] ?? 'not_scheduled');
            $lastRun = (string)($process['last_run'] ?? '');
            $lastRunTimestamp = $lastRun !== '' ? (int)strtotime($lastRun . ' UTC') : 0;

            echo '<tr data-sort-name="' . esc_attr(strtolower((string)($process['name'] ?? ''))) . '" data-sort-url="' . esc_attr(strtolower((string)($process['url'] ?? ''))) . '" data-sort-status="' . esc_attr(strtolower((string)($process['status'] ?? ''))) . '" data-sort-last-run="' . esc_attr((string)$lastRunTimestamp) . '" data-site-status="' . esc_attr((string)($process['website_status_key'] ?? 'inactive')) . '">';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="site_ids[]" value="' . esc_attr((string)$siteId) . '" form="' . esc_attr($selectionFormId) . '"></th>';
            echo '<td class="rrze-msm-monitoring-site-url">';
            echo '<div class="rrze-msm-monitoring-site-identity"><strong>' . esc_html((string)($process['name'] ?? ($process['url'] ?? ''))) . '</strong><br><span>' . esc_html((string)($process['url'] ?? '')) . '</span>';
            $cronSiteId = ($process['schedule_mode'] ?? '') === 'batch'
                ? (int)get_main_site_id(get_current_network_id())
                : $siteId;
            $this->renderMonitoringSiteHoverLinks($siteId, $this->getSiteStorageAnalysisPageUrl($siteId), __('Storage Analysis', 'rrze-multisite-manager'), $cronSiteId);
            echo '</div>';
            echo '</td>';
            echo '<td>' . $this->renderScheduledJobStatusHtml($process) . '</td>';
            $assignment = ($process['schedule_mode'] ?? '') === 'batch' ? __('Shared batch', 'rrze-multisite-manager') : __('Individual schedule', 'rrze-multisite-manager');
            echo '<td>' . esc_html($assignment) . '</td>';
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
            } elseif (($process['schedule_mode'] ?? '') === 'batch') {
                echo '&mdash;';
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

        echo '</tbody><tfoot><tr>';
        echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all-bottom">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all-bottom"></th>';
        echo '<th>' . esc_html__('Site', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Execution', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th><th>' . esc_html__('File index', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Orphans', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Metadata', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Runtime', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Finished', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Next run', 'rrze-multisite-manager') . '</th><th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
        echo '</tr></tfoot></table>';
        echo '<div class="tablenav bottom">';
        $this->renderSelectedSiteBulkActions($selectionFormId, 'bottom', 'remove', __('Remove selected tasks', 'rrze-multisite-manager'));
        echo '<br class="clear"></div>';
        $this->renderMonitoringTablePagination('storage_monitoring_page', $currentPage, $totalItems, $perPage);
        echo '</div>';
        echo '<div class="rrze-msm-site-actions">';
        if ($scheduler->canStartBatchNow()) {
            $this->renderStartSharedBatchForm('storage');
        }
        $this->renderWebsiteAnalysisTaskRemovalForm('storage');
        echo '</div>';
        }
        echo '</section>';
        $this->renderUnscheduledStorageMonitoringTable($unassignedPage, $perPage, $unassignedSearch);
    }

    /** @param array{processes: array<int, array<string, mixed>>, total: int} $processPage */
    protected function renderUnscheduledStorageMonitoringTable(array $processPage, int $perPage, string $search): void {
        $processes = $processPage['processes'] ?? [];
        $totalItems = (int)($processPage['total'] ?? 0);
        $currentPage = $this->getMonitoringTablePage('storage_unassigned_monitoring_page');

        if (empty($processes) && $totalItems === 0 && $search === '') {
            return;
        }

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header"><h2>' . esc_html__('Websites without a scheduled storage analysis', 'rrze-multisite-manager') . '</h2></header>';

        if (empty($processes)) {
            echo '<div class="tablenav top"><div class="alignright">';
            $this->renderMonitoringWebsiteSearchForm($search, 'rrze-msm-search-unscheduled-storage-sites', 'storage', 'storage_unassigned_site_search');
            echo '</div><br class="clear"></div>';
            echo '<p>' . esc_html__('No active websites without a scheduled storage analysis were found.', 'rrze-multisite-manager') . '</p>';
        } else {
            $selectionFormId = wp_unique_id('rrze-msm-select-storage-sites-');
            echo '<form id="' . esc_attr($selectionFormId) . '" method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_schedule_selected_storage_analysis_sites')) . '">';
            wp_nonce_field('rrze_multisite_manager_schedule_selected_storage_analysis_sites');
            echo '</form>';
            echo '<div class="tablenav top"><div class="alignright">';
            $this->renderMonitoringWebsiteSearchForm($search, 'rrze-msm-search-unscheduled-storage-sites', 'storage', 'storage_unassigned_site_search');
            echo '</div>';
            $this->renderSelectedSiteBulkActions($selectionFormId, 'top');
            echo '</div>';
            echo '<table class="widefat striped rrze-msm-table"><thead><tr>';
            echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all"></th>';
            echo '<th>' . esc_html__('Site', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                $siteId = (int)($process['site_id'] ?? 0);
                echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="site_ids[]" value="' . esc_attr((string)$siteId) . '" form="' . esc_attr($selectionFormId) . '"></th><td class="rrze-msm-monitoring-site-url"><strong>' . esc_html((string)($process['name'] ?? ($process['url'] ?? ''))) . '</strong><br><span>' . esc_html((string)($process['url'] ?? '')) . '</span></td>';
                echo '<td><span class="rrze-msm-badge rrze-msm-badge-neutral">' . esc_html__('Not scheduled', 'rrze-multisite-manager') . '</span></td>';
                echo '<td class="rrze-msm-col-actions"><form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_start_site_storage_analysis')) . '">';
                echo '<input type="hidden" name="site_id" value="' . esc_attr((string)$siteId) . '">';
                wp_nonce_field('rrze_multisite_manager_start_site_storage_analysis_' . $siteId);
                echo '<button type="submit" class="button button-secondary">' . esc_html__('Set up analysis', 'rrze-multisite-manager') . '</button>';
                echo '</form></td></tr>';
            }

            echo '</tbody><tfoot><tr>';
            echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all-bottom">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all-bottom"></th>';
            echo '<th>' . esc_html__('Site', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th><th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></tfoot></table>';
            echo '<div class="tablenav bottom">';
            $this->renderSelectedSiteBulkActions($selectionFormId, 'bottom');
            echo '<br class="clear"></div>';
            $this->renderMonitoringTablePagination('storage_unassigned_monitoring_page', $currentPage, $totalItems, $perPage);
        }

        echo '<div class="rrze-msm-site-actions">';
        $this->renderAnalysisScheduleInitializationDialog(
            'storage',
                __('Reschedule storage analysis tasks for websites without a schedule', 'rrze-multisite-manager'),
                __('Storage-analysis tasks will be created for all active websites that do not yet have a scheduled task.', 'rrze-multisite-manager'),
            __('I understand that analysis tasks will be scheduled for websites without a schedule.', 'rrze-multisite-manager')
        );
        echo '</div>';
        echo '<p class="description">' . esc_html__('Only active websites can be selected for analysis tasks.', 'rrze-multisite-manager') . '</p>';
        echo '</section>';
    }

    protected function renderWebsiteShortcodeBlockMonitoringTable(): void {
        $scheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $perPage = min(100, max(10, (int)$this->getOption('dashboard', 'activity_site_limit', 10)));
        $currentPage = $this->getMonitoringTablePage('shortcode_monitoring_page');
        $search = $this->getMonitoringWebsiteSearchTerm();
        $unassignedSearch = $this->getMonitoringWebsiteSearchTerm('shortcode_unassigned_site_search');
        $processPage = $scheduler->getSiteProcessesPage($currentPage, $perPage, $search);
        $processes = $processPage['processes'];
        $totalItems = (int)($processPage['total'] ?? 0);
        $unassignedPage = $scheduler->getUnassignedActiveSiteProcessesPage(
            $this->getMonitoringTablePage('shortcode_unassigned_monitoring_page'),
            $perPage,
            $unassignedSearch
        );
        $monitoringUrl = add_query_arg(
            [
                'page' => $this->getMonitoringSlug(),
                'monitoring_tab' => 'shortcode-block',
            ],
            admin_url('admin.php')
        );

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header"><h2>' . esc_html__('Website shortcode and block analyses', 'rrze-multisite-manager') . '</h2></header>';

        if (empty($processes)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('There are currently no scheduled shortcode or block analysis tasks.', 'rrze-multisite-manager') . '</p></div>';
        } else {
            $selectionFormId = wp_unique_id('rrze-msm-remove-shortcode-block-sites-');
            echo '<form id="' . esc_attr($selectionFormId) . '" method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_remove_selected_shortcode_block_analysis_sites')) . '">';
            wp_nonce_field('rrze_multisite_manager_remove_selected_shortcode_block_analysis_sites');
            echo '</form>';
            echo '<div class="rrze-msm-site-table-wrap rrze-msm-server-paginated" data-table-id="monitoring-shortcode-block" data-sort-key="url" data-sort-direction="asc">';
            echo '<div class="tablenav top">';
            $this->renderSelectedSiteBulkActions($selectionFormId, 'top', 'remove', __('Remove selected tasks', 'rrze-multisite-manager'));
            echo '<div class="alignleft actions">';
            echo '</div><div class="alignright">';
            $this->renderMonitoringWebsiteSearchForm($search, 'rrze-msm-search-monitoring-shortcode-block', 'shortcode-block');
            echo '</div><br class="clear"></div>';
            echo '<table class="widefat striped rrze-msm-table"><thead><tr>';
            echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all"></th>';
            echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="url" data-sort-direction="asc"><span>' . esc_html__('Site', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
            echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="status" data-sort-direction="asc"><span>' . esc_html__('Status', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
            echo '<th>' . esc_html__('Execution', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Shortcodes', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Blocks', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Runtime', 'rrze-multisite-manager') . '</th>';
            echo '<th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="last-run" data-sort-direction="desc"><span>' . esc_html__('Finished', 'rrze-multisite-manager') . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>';
            echo '<th>' . esc_html__('Next run', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                $siteId = (int)($process['site_id'] ?? 0);
                $statusKey = (string)($process['status_key'] ?? 'not_scheduled');
                $lastFinishedAt = (string)($process['last_finished_at'] ?? '');
                $lastRunTimestamp = $lastFinishedAt !== '' ? (int)strtotime($lastFinishedAt . ' UTC') : 0;
                echo '<tr data-sort-name="' . esc_attr(strtolower((string)($process['name'] ?? ''))) . '" data-sort-url="' . esc_attr(strtolower((string)($process['url'] ?? ''))) . '" data-sort-status="' . esc_attr(strtolower((string)($process['status'] ?? ''))) . '" data-sort-last-run="' . esc_attr((string)$lastRunTimestamp) . '" data-site-status="' . esc_attr((string)($process['website_status_key'] ?? 'inactive')) . '">';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="site_ids[]" value="' . esc_attr((string)$siteId) . '" form="' . esc_attr($selectionFormId) . '"></th>';
                echo '<td class="rrze-msm-monitoring-site-url">';
                echo '<div class="rrze-msm-monitoring-site-identity"><strong>' . esc_html((string)($process['name'] ?? ($process['url'] ?? ''))) . '</strong><br><span>' . esc_html((string)($process['url'] ?? '')) . '</span>';
                $cronSiteId = ($process['assignment_mode'] ?? '') === 'batch'
                    ? (int)get_main_site_id(get_current_network_id())
                    : $siteId;
                $this->renderMonitoringSiteHoverLinks($siteId, $this->getSiteShortcodeBlockAnalysisPageUrl($siteId), __('Shortcodes and Blocks', 'rrze-multisite-manager'), $cronSiteId);
                echo '</div>';
                echo '</td>';
                echo '<td>' . $this->renderScheduledJobStatusHtml($process) . '</td>';
                $assignmentMode = (string)($process['assignment_mode'] ?? 'unassigned');
                $assignmentLabel = $assignmentMode === 'batch'
                    ? __('Shared batch', 'rrze-multisite-manager')
                    : ($assignmentMode === 'site'
                        ? __('Individual schedule', 'rrze-multisite-manager')
                        : __('Not assigned', 'rrze-multisite-manager'));
                echo '<td>' . esc_html($assignmentLabel) . '</td>';
                echo '<td>' . esc_html($this->formatMonitoringTimestamp((string)($process['last_started_at'] ?? ''))) . '</td>';
                echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'shortcodes', true);
                echo $this->renderAnalysisPhaseCell((array)($process['phases'] ?? []), 'blocks', true);
                $runtimeSeconds = $process['last_runtime_seconds'] ?? null;
                $runtimeLabel = $runtimeSeconds === null || $lastFinishedAt === ''
                    ? __('-', 'rrze-multisite-manager')
                    : ((float)$runtimeSeconds < 1 ? __('<1 sec.', 'rrze-multisite-manager') : $this->formatProcessDuration((int)$runtimeSeconds));
                echo '<td>' . esc_html($runtimeLabel) . '</td>';
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($process['last_finished_at'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($this->formatScheduledTimestamp((int)($process['next_run_timestamp'] ?? 0))) . '</td>';
                echo '<td class="rrze-msm-col-actions">';

                if (empty($process['is_active'])) {
                    echo '&mdash;';
                } elseif (!empty($process['is_running'])) {
                    echo esc_html__('Running', 'rrze-multisite-manager');
                } elseif ($assignmentMode === 'site') {
                    $hasSuccessfulRun = $statusKey === 'ok'
                        && (int)($process['next_run_timestamp'] ?? 0) > time();
                    echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_request_shortcode_block_analysis')) . '">';
                    echo '<input type="hidden" name="site_id" value="' . esc_attr((string)$siteId) . '">';
                    echo '<input type="hidden" name="redirect_to" value="' . esc_url($monitoringUrl) . '">';
                    wp_nonce_field('rrze_msm_request_shortcode_block_analysis_' . $siteId);
                    echo '<button type="submit" class="button button-secondary">' . esc_html($hasSuccessfulRun ? __('Start now', 'rrze-multisite-manager') : __('Start', 'rrze-multisite-manager')) . '</button>';
                    echo '</form>';
                } else {
                    echo '&mdash;';
                }

                echo '</td></tr>';
            }

            echo '</tbody><tfoot><tr>';
            echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all-bottom">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all-bottom"></th>';
            echo '<th>' . esc_html__('Site', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Status', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Execution', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Shortcodes', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Blocks', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Runtime', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Finished', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Next run', 'rrze-multisite-manager') . '</th><th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></tfoot></table>';
            echo '<div class="tablenav bottom">';
            $this->renderSelectedSiteBulkActions($selectionFormId, 'bottom', 'remove', __('Remove selected tasks', 'rrze-multisite-manager'));
            echo '<br class="clear"></div>';
            $this->renderMonitoringTablePagination('shortcode_monitoring_page', $currentPage, $totalItems, $perPage, 'shortcode-block');
            echo '</div>';
            echo '<div class="rrze-msm-site-actions">';
            if ($scheduler->canStartBatchNow()) {
                $this->renderStartSharedBatchForm('shortcode-block');
            }
            $this->renderWebsiteAnalysisTaskRemovalForm('shortcode-block');
            echo '</div>';
        }

        echo '</section>';
        $this->renderUnassignedShortcodeBlockMonitoringTable($unassignedPage, $perPage, $unassignedSearch, $monitoringUrl);
    }

    /** @param array{processes: array<int, array<string, mixed>>, total: int} $processPage */
    protected function renderUnassignedShortcodeBlockMonitoringTable(array $processPage, int $perPage, string $search, string $monitoringUrl): void {
        $processes = $processPage['processes'] ?? [];
        $totalItems = (int)($processPage['total'] ?? 0);
        $currentPage = $this->getMonitoringTablePage('shortcode_unassigned_monitoring_page');

        if (empty($processes) && $totalItems === 0 && $search === '') {
            return;
        }

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header"><h2>' . esc_html__('Websites without a scheduled shortcode or block analysis', 'rrze-multisite-manager') . '</h2></header>';
        echo '<p>' . esc_html__('These active websites have no scheduled shortcode or block analysis. Setting up an analysis assigns small websites to the shared batch and starts a separate task for larger websites.', 'rrze-multisite-manager') . '</p>';

        if (empty($processes)) {
            echo '<div class="tablenav top"><div class="alignright">';
            $this->renderMonitoringWebsiteSearchForm($search, 'rrze-msm-search-unscheduled-shortcode-block-sites', 'shortcode-block', 'shortcode_unassigned_site_search');
            echo '</div><br class="clear"></div>';
            echo '<p>' . esc_html__('No active websites without a scheduled shortcode or block analysis were found.', 'rrze-multisite-manager') . '</p>';
        } else {
            $selectionFormId = wp_unique_id('rrze-msm-select-shortcode-block-sites-');
            echo '<form id="' . esc_attr($selectionFormId) . '" method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_schedule_selected_shortcode_block_analysis_sites')) . '">';
            wp_nonce_field('rrze_multisite_manager_schedule_selected_shortcode_block_analysis_sites');
            echo '</form>';
            echo '<div class="tablenav top"><div class="alignright">';
            $this->renderMonitoringWebsiteSearchForm($search, 'rrze-msm-search-unscheduled-shortcode-block-sites', 'shortcode-block', 'shortcode_unassigned_site_search');
            echo '</div>';
            $this->renderSelectedSiteBulkActions($selectionFormId, 'top');
            echo '</div>';
            echo '<table class="widefat striped rrze-msm-table"><thead><tr>';
            echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all"></th>';
            echo '<th>' . esc_html__('Site', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Assignment', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                $siteId = (int)($process['site_id'] ?? 0);
                echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="site_ids[]" value="' . esc_attr((string)$siteId) . '" form="' . esc_attr($selectionFormId) . '"></th><td class="rrze-msm-monitoring-site-url"><strong>' . esc_html((string)($process['name'] ?? ($process['url'] ?? ''))) . '</strong><br><span>' . esc_html((string)($process['url'] ?? '')) . '</span></td>';
                echo '<td><span class="rrze-msm-badge rrze-msm-badge-neutral">' . esc_html__('Not scheduled', 'rrze-multisite-manager') . '</span></td>';
                echo '<td class="rrze-msm-col-actions"><form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_request_shortcode_block_analysis')) . '">';
                echo '<input type="hidden" name="site_id" value="' . esc_attr((string)$siteId) . '">';
                echo '<input type="hidden" name="redirect_to" value="' . esc_url($monitoringUrl) . '">';
                wp_nonce_field('rrze_msm_request_shortcode_block_analysis_' . $siteId);
                echo '<button type="submit" class="button button-secondary">' . esc_html__('Set up analysis', 'rrze-multisite-manager') . '</button>';
                echo '</form></td></tr>';
            }

            echo '</tbody><tfoot><tr>';
            echo '<th class="check-column"><label class="screen-reader-text" for="' . esc_attr($selectionFormId) . '-all-bottom">' . esc_html__('Select all visible websites', 'rrze-multisite-manager') . '</label><input type="checkbox" class="rrze-msm-select-visible-sites" id="' . esc_attr($selectionFormId) . '-all-bottom"></th>';
            echo '<th>' . esc_html__('Site', 'rrze-multisite-manager') . '</th><th>' . esc_html__('Assignment', 'rrze-multisite-manager') . '</th><th class="rrze-msm-col-actions">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr></tfoot></table>';
            echo '<div class="tablenav bottom">';
            $this->renderSelectedSiteBulkActions($selectionFormId, 'bottom');
            echo '<br class="clear"></div>';
            $this->renderMonitoringTablePagination('shortcode_unassigned_monitoring_page', $currentPage, $totalItems, $perPage, 'shortcode-block');
        }

        echo '<div class="rrze-msm-site-actions">';
        $this->renderAnalysisScheduleInitializationDialog(
            'shortcode-block',
                __('Reschedule shortcode and block analysis tasks for websites without a schedule', 'rrze-multisite-manager'),
                __('Shortcode and block analysis tasks will be created for all active websites that do not yet have a scheduled task.', 'rrze-multisite-manager'),
            __('I understand that analysis tasks will be scheduled for websites without a schedule.', 'rrze-multisite-manager')
        );
        echo '</div>';
        echo '<p class="description">' . esc_html__('Only active websites can be selected for analysis tasks.', 'rrze-multisite-manager') . '</p>';
        echo '</section>';
    }

    protected function renderMonitoringSiteHoverLinks(int $siteId, string $analysisUrl, string $analysisLabel, int $cronSiteId = 0): void {
        echo '<div class="row-actions">';
        echo '<span class="rrze-msm-row-action-dashboard"><a href="' . esc_url(get_admin_url($siteId)) . '">' . esc_html__('Dashboard', 'rrze-multisite-manager') . '</a></span>';
        echo ' | ';
        echo '<span class="rrze-msm-row-action-details"><a href="' . esc_url($this->getSiteDetailsPageUrl($siteId)) . '">' . esc_html__('Details', 'rrze-multisite-manager') . '</a></span>';
        echo ' | ';
        echo '<span class="rrze-msm-row-action-analysis"><a href="' . esc_url($analysisUrl) . '">' . esc_html($analysisLabel) . '</a></span>';
        $crontrolUrl = $this->getSiteCrontrolUrl($cronSiteId > 0 ? $cronSiteId : $siteId);

        if ($crontrolUrl !== '') {
            echo ' | ';
            echo '<span class="rrze-msm-row-action-crontrol"><a href="' . esc_url($crontrolUrl) . '">' . esc_html__('WP Crontrol Events', 'rrze-multisite-manager') . '</a></span>';
        }

        echo '</div>';
    }

    protected function getSiteCrontrolUrl(int $siteId): string {
        $pluginFile = 'wp-crontrol/wp-crontrol.php';
        $networkActive = function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($pluginFile);

        if (!$networkActive && !in_array($pluginFile, (array)get_blog_option($siteId, 'active_plugins', []), true)) {
            return '';
        }

        return add_query_arg(
            [
                'page' => 'wp-crontrol',
                's' => 'rrze_msm',
            ],
            get_admin_url($siteId, 'tools.php')
        );
    }

    protected function renderWebsiteAnalysisTaskRemovalForm(string $analysisType): void {
        $isStorage = $analysisType === 'storage';
        $label = $isStorage
            ? __('Remove all storage-analysis tasks', 'rrze-multisite-manager')
            : __('Remove all shortcode and block analysis tasks', 'rrze-multisite-manager');
        $warning = $isStorage
            ? __('All scheduled storage-analysis tasks for every website will be removed. Stored analysis results will not be deleted.', 'rrze-multisite-manager')
            : __('All scheduled shortcode and block analysis tasks for every website will be removed. Stored analysis results will not be deleted.', 'rrze-multisite-manager');
        $this->renderAnalysisTaskRemovalAjaxDialog($analysisType, $label, $warning);
    }

    protected function renderAnalysisTaskRemovalAjaxDialog(string $analysisType, string $label, string $warning): void {
        $modalId = wp_unique_id('rrze-msm-analysis-task-removal-');
        echo '<button type="button" class="button button-secondary rrze-msm-open-analysis-task-removal-dialog" data-dialog-id="' . esc_attr($modalId) . '">' . esc_html($label) . '</button>';
        echo '<dialog class="rrze-msm-task-removal-dialog rrze-msm-analysis-task-removal-dialog" id="' . esc_attr($modalId) . '" data-analysis-type="' . esc_attr($analysisType) . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('rrze_msm_analysis_task_removal')) . '" data-running-text="' . esc_attr__('Cleanup in progress.', 'rrze-multisite-manager') . '" data-completed-text="' . esc_attr__('Cleanup completed.', 'rrze-multisite-manager') . '" data-failed-text="' . esc_attr__('The cleanup request failed. You can reopen this dialog to continue.', 'rrze-multisite-manager') . '" aria-labelledby="' . esc_attr($modalId) . '-title">';
        echo '<h3 id="' . esc_attr($modalId) . '-title">' . esc_html($label) . '</h3><p class="rrze-msm-modal-text">' . esc_html($warning) . '</p>';
        echo '<p class="rrze-msm-modal-text" data-analysis-task-removal-status></p>';
        echo '<p data-analysis-task-removal-progress hidden><strong>' . esc_html__('Processed websites:', 'rrze-multisite-manager') . '</strong> <span data-analysis-task-removal-processed>0</span> / <span data-analysis-task-removal-total>0</span><br><strong>' . esc_html__('Removed tasks:', 'rrze-multisite-manager') . '</strong> <span data-analysis-task-removal-removed>0</span></p>';
        echo '<label class="rrze-msm-modal-checkbox" data-analysis-task-removal-confirmation><input type="checkbox" data-analysis-task-removal-confirm><span>' . esc_html__('I understand that the scheduled tasks will be removed.', 'rrze-multisite-manager') . '</span></label>';
        echo '<div class="rrze-msm-modal-actions"><button type="button" class="button button-secondary" data-analysis-task-removal-close onclick="' . esc_attr("document.getElementById('{$modalId}').close();") . '">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button><button type="button" class="button button-secondary rrze-msm-button-danger" data-analysis-task-removal-start disabled>' . esc_html($label) . '</button><button type="button" class="button button-secondary" data-analysis-task-removal-reload hidden>' . esc_html__('Reload page', 'rrze-multisite-manager') . '</button></div></dialog>';
    }

    protected function renderStartSharedBatchForm(string $analysisType): void {
        $modalId = wp_unique_id('rrze-msm-start-shared-batch-');
        $label = __('Start shared batches early', 'rrze-multisite-manager');
        $warning = __('Start the shared batch now for all websites assigned to it?', 'rrze-multisite-manager');
        echo '<button type="button" class="button button-secondary" onclick="' . esc_attr("document.getElementById('{$modalId}').showModal();") . '">' . esc_html($label) . '</button>';
        echo '<dialog class="rrze-msm-task-removal-dialog" id="' . esc_attr($modalId) . '"><h3>' . esc_html($label) . '</h3><p class="rrze-msm-modal-text">' . esc_html($warning) . '</p>';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_start_shared_analysis_batch')) . '"><input type="hidden" name="analysis_type" value="' . esc_attr($analysisType) . '">';
        wp_nonce_field('rrze_multisite_manager_start_shared_analysis_batch_' . $analysisType);
        echo '<label class="rrze-msm-modal-checkbox"><input type="checkbox" name="confirm_start_shared_batch" value="1" required><span>' . esc_html__('I understand that all websites in the shared batch will be started now.', 'rrze-multisite-manager') . '</span></label>';
        echo '<div class="rrze-msm-modal-actions"><button type="button" class="button button-secondary" onclick="' . esc_attr("document.getElementById('{$modalId}').close();") . '">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button><button type="submit" class="button button-secondary">' . esc_html($label) . '</button></div></form></dialog>';
    }

    protected function renderAnalysisScheduleInitializationDialog(string $analysisType, string $label, string $warning, string $confirmationText): void {
        $modalId = wp_unique_id('rrze-msm-schedule-initialization-');
        $openDialog = "document.getElementById('{$modalId}').showModal();";
        $closeDialog = "document.getElementById('{$modalId}').close();";

        echo '<button type="button" class="button button-secondary rrze-msm-open-schedule-initialization-dialog" data-dialog-id="' . esc_attr($modalId) . '">' . esc_html($label) . '</button>';
        echo '<dialog class="rrze-msm-task-removal-dialog rrze-msm-schedule-initialization-dialog" id="' . esc_attr($modalId) . '" data-analysis-type="' . esc_attr($analysisType) . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('rrze_msm_analysis_schedule_initialization')) . '" data-running-text="' . esc_attr__('Setup in progress.', 'rrze-multisite-manager') . '" data-completed-text="' . esc_attr__('Setup completed.', 'rrze-multisite-manager') . '" data-failed-text="' . esc_attr__('The setup request failed. You can reopen this dialog to continue.', 'rrze-multisite-manager') . '" aria-labelledby="' . esc_attr($modalId) . '-title">';
        echo '<h3 id="' . esc_attr($modalId) . '-title">' . esc_html($label) . '</h3>';
        echo '<p class="rrze-msm-modal-text">' . esc_html($warning) . '</p>';
        echo '<p class="rrze-msm-modal-text">' . esc_html__('The complete network is processed in groups of 25 websites. The setup batches are not run via Cron.', 'rrze-multisite-manager') . '</p>';
        echo '<div class="rrze-msm-schedule-initialization-progress" hidden aria-live="polite">';
        echo '<p data-schedule-initialization-status></p>';
        echo '<p><strong>' . esc_html__('Processed websites:', 'rrze-multisite-manager') . '</strong> <span data-schedule-initialization-processed>0</span> / <span data-schedule-initialization-total>0</span><br><strong>' . esc_html__('Created tasks:', 'rrze-multisite-manager') . '</strong> <span data-schedule-initialization-created>0</span></p>';
        echo '</div>';
        echo '<label class="rrze-msm-modal-checkbox" data-schedule-initialization-confirmation><input type="checkbox" data-schedule-initialization-confirm><span>' . esc_html($confirmationText) . '</span></label>';
        echo '<div class="rrze-msm-modal-actions">';
        echo '<button type="button" class="button button-secondary" data-schedule-initialization-close data-close-label="' . esc_attr__('Close', 'rrze-multisite-manager') . '" onclick="' . esc_attr($closeDialog) . '">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button>';
        echo '<button type="button" class="button button-secondary" data-schedule-initialization-start disabled>' . esc_html__('Start setup', 'rrze-multisite-manager') . '</button>';
        echo '<button type="button" class="button button-secondary" data-schedule-initialization-reload hidden>' . esc_html__('Reload page', 'rrze-multisite-manager') . '</button>';
        echo '</div></dialog>';
    }

    protected function renderTaskRemovalForm(string $action, string $confirmation, string $label, string $warning, string $redirectUrl = '', string $confirmationText = '', bool $isDestructive = true): void {
        $modalId = wp_unique_id('rrze-msm-task-removal-modal-');

        $openDialog = "document.getElementById('{$modalId}').showModal();";
        $closeDialog = "document.getElementById('{$modalId}').close();";

        echo '<button type="button" class="button button-secondary" onclick="' . esc_attr($openDialog) . '">' . esc_html($label) . '</button>';
        echo '<dialog class="rrze-msm-task-removal-dialog" id="' . esc_attr($modalId) . '" aria-labelledby="' . esc_attr($modalId) . '-title">';
        echo '<h3 id="' . esc_attr($modalId) . '-title">' . esc_html($label) . '</h3>';
        echo '<p class="rrze-msm-modal-text">' . esc_html($warning) . '</p>';
        echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl($action)) . '">';
        if ($redirectUrl !== '') {
            echo '<input type="hidden" name="redirect_to" value="' . esc_url($redirectUrl) . '">';
        }
        wp_nonce_field($action);
        echo '<label class="rrze-msm-modal-checkbox">';
        echo '<input type="checkbox" name="' . esc_attr($confirmation) . '" value="1" required>';
        echo '<span>' . esc_html($confirmationText !== '' ? $confirmationText : __('I understand that the scheduled tasks will be removed.', 'rrze-multisite-manager')) . '</span>';
        echo '</label>';
        echo '<div class="rrze-msm-modal-actions">';
        echo '<button type="button" class="button button-secondary" onclick="' . esc_attr($closeDialog) . '">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button>';
        echo '<button type="submit" class="button button-secondary' . ($isDestructive ? ' rrze-msm-button-danger' : '') . '">' . esc_html($label) . '</button>';
        echo '</div></form></dialog>';
    }

    protected function renderMonitoringToolsTab(): void {
        $this->renderFullDataCleanupSection(new MetricsService($this, $this->config));
        $this->renderLegacyCentralCronCleanupSection();
        $this->renderNetworkMonitoringTaskRemovalSection();
        $this->renderWebsiteAnalysisTaskRemovalSection();
    }

    protected function renderLegacyCentralCronCleanupSection(): void {
        $modalId = wp_unique_id('rrze-msm-legacy-central-cron-cleanup-');
        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12"><header class="rrze-msm-widget-header"><h2>' . esc_html__('Remove obsolete local central Cron events', 'rrze-multisite-manager') . '</h2></header>';
        echo '<p>' . esc_html__('Removes obsolete local copies of dashboard-metrics and availability-monitoring events from subsites. The central events on the main site remain unchanged.', 'rrze-multisite-manager') . '</p>';
        echo '<button type="button" class="button button-secondary rrze-msm-open-legacy-cron-cleanup-dialog" data-dialog-id="' . esc_attr($modalId) . '">' . esc_html__('Remove obsolete local Cron events', 'rrze-multisite-manager') . '</button>';
        echo '<dialog class="rrze-msm-task-removal-dialog rrze-msm-legacy-cron-cleanup-dialog" id="' . esc_attr($modalId) . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('rrze_msm_legacy_central_cron_cleanup')) . '" data-completed-text="' . esc_attr__('Cleanup completed.', 'rrze-multisite-manager') . '" data-failed-text="' . esc_attr__('The cleanup request failed. You can reopen this dialog to continue.', 'rrze-multisite-manager') . '"><h3>' . esc_html__('Remove obsolete local central Cron events', 'rrze-multisite-manager') . '</h3><p>' . esc_html__('The network is processed in groups of 100 websites without using WP-Cron.', 'rrze-multisite-manager') . '</p><p data-legacy-cron-cleanup-status></p><p data-legacy-cron-cleanup-progress hidden><span data-legacy-cron-cleanup-processed>0</span> / <span data-legacy-cron-cleanup-total>0</span></p><label class="rrze-msm-modal-checkbox" data-legacy-cron-cleanup-confirmation><input type="checkbox" data-legacy-cron-cleanup-confirm><span>' . esc_html__('I understand that obsolete local Cron events will be removed.', 'rrze-multisite-manager') . '</span></label><div class="rrze-msm-modal-actions" data-legacy-cron-cleanup-actions><button type="button" class="button button-secondary" data-legacy-cron-cleanup-cancel onclick="' . esc_attr("document.getElementById('{$modalId}').close();") . '">' . esc_html__('Cancel', 'rrze-multisite-manager') . '</button><button type="button" class="button button-secondary rrze-msm-button-danger" data-legacy-cron-cleanup-start disabled>' . esc_html__('Start cleanup', 'rrze-multisite-manager') . '</button><button type="button" class="button button-secondary" data-legacy-cron-cleanup-close hidden onclick="' . esc_attr("document.getElementById('{$modalId}').close();") . '">' . esc_html__('Close', 'rrze-multisite-manager') . '</button></div></dialog></section>';
    }

    protected function renderNetworkMonitoringTaskRemovalSection(): void {
        $actions = [
            [
                'title' => __('Stop dashboard metrics', 'rrze-multisite-manager'),
                'description' => __('Stops the current dashboard-metrics process, removes all of its Cron entries, and disables automatic scheduling. Start it manually under Network-wide to enable it again.', 'rrze-multisite-manager'),
                'action' => 'rrze_multisite_manager_remove_dashboard_metrics_tasks',
                'nonce' => 'rrze_multisite_manager_remove_dashboard_metrics_tasks',
                'confirmation' => 'confirm_dashboard_metrics_task_removal',
                'label' => __('Stop dashboard metrics and remove tasks', 'rrze-multisite-manager'),
            ],
            [
                'title' => __('Stop website availability checks', 'rrze-multisite-manager'),
                'description' => __('Stops the current website-availability process, removes all of its Cron entries, and disables automatic scheduling. Start it manually under Network-wide to enable it again.', 'rrze-multisite-manager'),
                'action' => 'rrze_multisite_manager_remove_monitoring_tasks',
                'nonce' => 'rrze_multisite_manager_remove_monitoring_tasks',
                'confirmation' => 'confirm_monitoring_task_removal',
                'label' => __('Stop availability checks and remove tasks', 'rrze-multisite-manager'),
            ],
        ];

        foreach ($actions as $action) {
            echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
            echo '<header class="rrze-msm-widget-header"><h2>' . esc_html((string)$action['title']) . '</h2></header>';
            echo '<p>' . esc_html((string)$action['description']) . '</p>';
            $this->renderTaskRemovalForm(
                (string)$action['action'],
                (string)$action['confirmation'],
                (string)$action['label'],
                __('This stops the process, removes its scheduled tasks, and keeps automatic scheduling disabled until it is started manually again.', 'rrze-multisite-manager')
            );
            echo '</section>';
        }
    }

    protected function renderWebsiteAnalysisTaskRemovalSection(): void {
        $storageScheduler = new StorageAnalysisSchedulerService(new StorageAnalysisService(new MetricsService($this, $this->config)), $this->config);
        $shortcodeScheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $cleanupStates = [
            __('Storage-analysis task cleanup', 'rrze-multisite-manager') => $storageScheduler->getScheduledTaskRemovalState(),
            __('Shortcode and block analysis task cleanup', 'rrze-multisite-manager') => $shortcodeScheduler->getScheduledTaskRemovalState(),
        ];

        foreach ($cleanupStates as $label => $state) {
            if (empty($state['running'])) {
                continue;
            }

            echo '<section class="rrze-msm-widget rrze-msm-widget-span-12"><header class="rrze-msm-widget-header"><h2>' . esc_html($label) . '</h2></header>';
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: processed websites, 2: total websites, 3: removed tasks, 4: failed websites. */
                __('Cleanup in progress: %1$d / %2$d websites processed, %3$d tasks removed, %4$d website errors.', 'rrze-multisite-manager'),
                absint($state['processed'] ?? 0),
                absint($state['total'] ?? 0),
                absint($state['removed'] ?? 0),
                absint($state['failed'] ?? 0)
            )) . '</p></section>';
        }

        $actions = [
            [
                'title' => __('Remove storage-analysis tasks', 'rrze-multisite-manager'),
                'description' => __('Removes every scheduled storage-analysis Cron entry for every website. Stored analysis results are not deleted.', 'rrze-multisite-manager'),
                'action' => 'rrze_multisite_manager_remove_storage_analysis_tasks',
                'nonce' => 'rrze_multisite_manager_remove_storage_analysis_tasks',
                'confirmation' => 'confirm_storage_task_removal',
                'label' => __('Remove all storage-analysis tasks', 'rrze-multisite-manager'),
            ],
            [
                'title' => __('Remove shortcode and block analysis tasks', 'rrze-multisite-manager'),
                'description' => __('Removes every scheduled shortcode and block analysis Cron entry for every website. Stored analysis results are not deleted.', 'rrze-multisite-manager'),
                'action' => 'rrze_multisite_manager_remove_shortcode_block_analysis_tasks',
                'nonce' => 'rrze_multisite_manager_remove_shortcode_block_analysis_tasks',
                'confirmation' => 'confirm_shortcode_block_task_removal',
                'label' => __('Remove all shortcode and block analysis tasks', 'rrze-multisite-manager'),
            ],
        ];

        foreach ($actions as $action) {
            echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
            echo '<header class="rrze-msm-widget-header"><h2>' . esc_html((string)$action['title']) . '</h2></header>';
            echo '<p>' . esc_html((string)$action['description']) . '</p>';
            $this->renderAnalysisTaskRemovalAjaxDialog(
                (string)($action['action'] === 'rrze_multisite_manager_remove_storage_analysis_tasks' ? 'storage' : 'shortcode-block'),
                (string)$action['label'],
                (string)$action['description']
            );
            echo '</section>';
        }
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
        $activeWebsiteCount = (int)get_sites([
            'count' => true,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ]);
        $batchSizes = array_values(array_filter(array_map(static fn(array $process): int => (int)($process['batch_size'] ?? 0), $processes)));
        $showProgressColumns = !empty($batchSizes) && $websiteCount > min($batchSizes);

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Monitoring processes', 'rrze-multisite-manager') . '</h2>';
        echo '</header>';

        if (!empty($processes)) {
            echo '<table class="widefat striped rrze-msm-table">';
            echo '<thead><tr>';
            echo '<th rowspan="2">' . esc_html__('Process', 'rrze-multisite-manager') . '</th>';
            echo '<th rowspan="2">' . esc_html__('Description', 'rrze-multisite-manager') . '</th>';
            echo '<th class="rrze-msm-col-numeric" rowspan="2">' . esc_html__('Sites', 'rrze-multisite-manager') . '</th>';
            echo '<th rowspan="2">' . esc_html__('Status', 'rrze-multisite-manager') . '</th>';
            if ($showProgressColumns) {
                echo '<th class="rrze-msm-col-numeric" rowspan="2">' . esc_html__('Progress', 'rrze-multisite-manager') . '</th>';
            }
            echo '<th colspan="3">' . esc_html__('Last active', 'rrze-multisite-manager') . '</th>';
            echo '<th rowspan="2">' . esc_html__('Next run', 'rrze-multisite-manager') . '</th>';
            echo '<th rowspan="2">' . esc_html__('Action', 'rrze-multisite-manager') . '</th>';
            echo '</tr><tr>';
            echo '<th>' . esc_html__('Start', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('End', 'rrze-multisite-manager') . '</th>';
            echo '<th>' . esc_html__('Duration', 'rrze-multisite-manager') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($processes as $process) {
                echo '<tr>';
                echo '<td>' . esc_html((string)($process['title'] ?? '')) . '</td>';
                echo '<td>' . $this->renderProcessDescriptionHtml($process) . '</td>';
                echo '<td class="rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($process['last_site_count'] ?? 0))) . '</td>';
                echo '<td>' . $this->renderProcessStatusHtml($process) . '</td>';
                if ($showProgressColumns) {
                    echo '<td>' . $this->renderProcessProgressHtml($process) . '</td>';
                }
                $finishedAt = (string)($process['finished_at'] ?? '');
                echo '<td>' . esc_html($this->formatProcessTimestamp((string)($process['started_at'] ?? ''))) . '</td>';
                echo '<td>' . esc_html($finishedAt === '' ? '-' : $this->formatProcessTimestamp($finishedAt)) . '</td>';
                echo '<td>' . esc_html($this->formatProcessDurationInMinutes((int)($process['last_duration_seconds'] ?? 0), $finishedAt !== '')) . '</td>';
                echo '<td>' . esc_html($this->formatScheduledTimestamp((int)($process['next_run_timestamp'] ?? 0))) . '</td>';
                echo '<td>' . $this->renderProcessActionsHtml($process, $this->getMonitoringPageUrl()) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('There are currently no monitoring processes registered.', 'rrze-multisite-manager') . '</p>';
        }

        $mainSiteId = (int)get_main_site_id(get_current_network_id());
        $crontrolUrl = $this->getSiteCrontrolUrl($mainSiteId);

        echo '<p>' . esc_html__('Total number of websites in the multisite:', 'rrze-multisite-manager') . ' <strong>';
        echo esc_html(sprintf(
            /* translators: 1: total website count, 2: active website count. */
            __('%1$s, of which %2$s are active', 'rrze-multisite-manager'),
            number_format_i18n($websiteCount),
            number_format_i18n($activeWebsiteCount)
        ));
        echo '</strong>.<br>';
        echo esc_html__('Website availability is checked only for active websites. Websites with the status "Archived", "Blocked", or "Marked for deletion" are excluded from the check.', 'rrze-multisite-manager');

        if ($crontrolUrl !== '') {
            $crontrolUrl = add_query_arg(
                [
                    'page' => 'wp-crontrol',
                    's' => 'msm',
                ],
                get_admin_url($mainSiteId, 'tools.php')
            );
            echo '<br><a href="' . esc_url($crontrolUrl) . '">' . esc_html__('Check Cron events.', 'rrze-multisite-manager') . '</a>';
        }

        echo '</p>';

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

        echo '<section class="rrze-msm-settings-group">';
        echo '<header class="rrze-msm-settings-group-header">';
        echo '<h2>' . esc_html__('Create new view', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('New views start with all widgets. You can then narrow the selection directly below.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';
        echo '<div class="rrze-msm-settings-group-content">';
        echo '<input type="text" class="regular-text" name="new_view_name" value="" placeholder="' . esc_attr__('Name of the new view', 'rrze-multisite-manager') . '">';
        echo '</div>';
        echo '</section>';

        foreach ($views as $view) {
            echo '<section class="rrze-msm-settings-group rrze-msm-view-editor">';
            echo '<header class="rrze-msm-settings-group-header">';
            echo '<h2>' . esc_html((string)$view['label']) . '</h2>';
            echo '<p><code>' . esc_html((string)$view['slug']) . '</code></p>';
            echo '</header>';
            echo '<div class="rrze-msm-settings-group-content">';

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

        return get_date_from_gmt($timestamp, 'd.m.Y H:i');
    }

    protected function formatScheduledTimestamp(int $timestamp): string {
        if ($timestamp <= 0) {
            return __('Not scheduled', 'rrze-multisite-manager');
        }

        return wp_date('d.m.Y H:i', $timestamp);
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
            /* translators: %d: number of hours. */
            $parts[] = sprintf(_n('%d hr.', '%d hr.', $hours, 'rrze-multisite-manager'), $hours);
        }

        if ($minutes > 0) {
            /* translators: %d: number of minutes. */
            $parts[] = sprintf(_n('%d min.', '%d min.', $minutes, 'rrze-multisite-manager'), $minutes);
        }

        if ($remainingSeconds > 0 || empty($parts)) {
            /* translators: %d: number of seconds. */
            $parts[] = sprintf(_n('%d sec.', '%d sec.', $remainingSeconds, 'rrze-multisite-manager'), $remainingSeconds);
        }

        return implode(' ', $parts);
    }

    protected function formatProcessDurationInMinutes(int $seconds, bool $hasFinished): string {
        if (!$hasFinished) {
            return '-';
        }

        $minutes = (int)floor($seconds / MINUTE_IN_SECONDS);

        if ($minutes <= 0) {
            return sprintf(
                /* translators: %d: number of seconds. */
                _n('%d sec.', '%d sec.', $seconds, 'rrze-multisite-manager'),
                $seconds
            );
        }

        return sprintf(
            /* translators: %d: number of minutes. */
            _n('%d min.', '%d min.', $minutes, 'rrze-multisite-manager'),
            $minutes
        );
    }

    protected function formatProcessProgress(array $process): string {
        $checkedSites = (int)($process['checked_sites'] ?? 0);
        $batchTotal = (int)($process['batch_total'] ?? 0);
        $progressPercent = (int)($process['progress_percent'] ?? 0);

        if ($batchTotal > 0) {
            return sprintf(
                /* translators: 1: checked websites, 2: total websites, 3: completion percentage. */
                __('%1$s / %2$s (%3$d%%)', 'rrze-multisite-manager'),
                number_format_i18n($checkedSites),
                number_format_i18n($batchTotal),
                $progressPercent
            );
        }

        if ((int)($process['last_site_count'] ?? 0) > 0) {
            return sprintf(
                /* translators: 1: checked websites, 2: total websites. */
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

        if ((int)($process['next_run_timestamp'] ?? 0) <= 0) {
            return __('Not scheduled', 'rrze-multisite-manager');
        }

        return $this->hasPreviousProcessResult($process)
            ? __('Ok', 'rrze-multisite-manager')
            : __('Scheduled', 'rrze-multisite-manager');
    }

    protected function renderScheduledJobStatusHtml(array $process): string {
        $label = $this->getProcessStatusLabel($process);
        $className = 'rrze-msm-badge rrze-msm-badge-neutral';

        if (!empty($process['is_running'])) {
            $className = 'rrze-msm-badge rrze-msm-badge-info';
        } elseif ((int)($process['next_run_timestamp'] ?? 0) > 0) {
            $className = $this->hasPreviousProcessResult($process)
                ? 'rrze-msm-badge rrze-msm-badge-positive'
                : 'rrze-msm-badge rrze-msm-badge-scheduled';
        }

        return '<span class="' . esc_attr($className) . '">' . esc_html($label) . '</span>';
    }

    protected function hasPreviousProcessResult(array $process): bool {
        foreach (['last_run', 'last_finished_at', 'finished_at'] as $field) {
            $timestamp = (string)($process[$field] ?? '');

            if ($timestamp !== '' && $timestamp !== '0000-00-00 00:00:00') {
                return true;
            }
        }

        return false;
    }

    protected function renderProcessDescriptionHtml(array $process): string {
        $description = (string)($process['description'] ?? '');
        $meta = [];

        if ((int)($process['batch_size'] ?? 0) > 0) {
            $meta[] = sprintf(
                /* translators: %s: batch size. */
                __('Batch size: %s', 'rrze-multisite-manager'),
                number_format_i18n((int)($process['batch_size'] ?? 0))
            );
        }

        if ((int)($process['checked_sites'] ?? 0) > 0) {
            $meta[] = sprintf(
                /* translators: %s: number of processed websites. */
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

        if ($warning !== '') {
            $className = 'rrze-msm-badge rrze-msm-badge-danger';
        } else {
            return '<div class="rrze-msm-process-status">' . $this->renderScheduledJobStatusHtml($process) . '</div>';
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
        $scheduleActionState = (string)($process['schedule_action_state'] ?? 'create');
        $html = '<div class="rrze-msm-process-actions">';

        if (!empty($process['is_running']) || $scheduleActionState === 'running') {
            $html .= '<span aria-hidden="true">—</span>';
            $html .= '</div>';

            return $html;
        }

        $actionLabel = $scheduleActionState === 'start'
            ? __('Start early now', 'rrze-multisite-manager')
            : __('Create task', 'rrze-multisite-manager');

        if ($processId === 'dashboard-metrics') {
            $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_refresh_metrics')) . '">';
            if ($redirectTo !== '') {
                $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
            }
            $html .= wp_nonce_field('rrze_multisite_manager_refresh_metrics', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-secondary">' . esc_html($actionLabel) . '</button>';
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
            $html .= '<button type="submit" class="button button-secondary">' . esc_html($actionLabel) . '</button>';
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

    protected function renderFullDataCleanupSection(MetricsService $metricsService): void {
        $status = $metricsService->getFullDataCleanupStatus();
        $isRunning = MetricsService::isFullDataCleanupInProgress();
        $showStatus = $isRunning || !empty($_GET['full-data-cleanup-started']);
        $siteOffset = max(0, (int)($status['site_offset'] ?? 0));
        $siteTotal = max(0, (int)($status['site_total'] ?? 0));
        $statusMessage = (string)($status['message'] ?? '');

        if (($status['status'] ?? '') === 'complete') {
            $statusMessage = __('Fertig: Die Bereinigung ist abgeschlossen. Speicheranalysen und Dashboard-Metriken können nun wieder manuell gestartet werden.', 'rrze-multisite-manager');
        }

        echo '<section class="rrze-msm-widget rrze-msm-widget-span-12" id="rrze-msm-full-data-cleanup" data-auto-run="' . esc_attr($isRunning ? '1' : '0') . '">';
        echo '<header class="rrze-msm-widget-header">';
        echo '<h2>' . esc_html__('Clean up legacy MSM data', 'rrze-multisite-manager') . '</h2>';
        echo '<p>' . esc_html__('Deletes stored storage-analysis results, their metadata, and all MSM transients. Dashboard metrics are also removed. This cannot be undone.', 'rrze-multisite-manager') . '</p>';
        echo '</header>';

        if ($showStatus && !empty($status)) {
            echo '<p><strong>' . esc_html__('Status:', 'rrze-multisite-manager') . '</strong> <span data-full-cleanup-status>' . esc_html($statusMessage) . '</span></p>';
            echo '<p data-full-cleanup-progress>' . esc_html(sprintf(
                /* translators: 1: processed websites, 2: total websites. */
                __('Websites processed: %1$s of %2$s', 'rrze-multisite-manager'),
                number_format_i18n(min($siteOffset, $siteTotal)),
                number_format_i18n($siteTotal)
            )) . '</p>';
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: transient row count, 2: data size. */
                __('Transient rows found at start: %1$s (%2$s)', 'rrze-multisite-manager'),
                number_format_i18n((int)($status['found_transient_rows'] ?? 0)),
                size_format(max(0, (int)($status['found_transient_bytes'] ?? 0)))
            )) . '</p>';
            echo '<p data-full-cleanup-deleted>' . esc_html(sprintf(
                /* translators: 1: transient value rows, 2: timeout rows, 3: website options. */
                __('Deleted so far: %1$s transient values, %2$s timeout rows, %3$s website options.', 'rrze-multisite-manager'),
                number_format_i18n((int)($status['deleted_transient_values'] ?? 0)),
                number_format_i18n((int)($status['deleted_transient_timeouts'] ?? 0)),
                number_format_i18n((int)($status['deleted_site_options'] ?? 0))
            )) . '</p>';
        }

        if (!$isRunning) {
            echo '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_start_full_data_cleanup')) . '">';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr(add_query_arg(['monitoring_tab' => 'tools'], $this->getMonitoringPageUrl())) . '">';
            wp_nonce_field('rrze_multisite_manager_start_full_data_cleanup');
            echo '<label><input type="checkbox" name="confirm_cleanup" value="1"> ' . esc_html__('I understand that saved storage analyses, metrics, and MSM transient caches will be permanently deleted.', 'rrze-multisite-manager') . '</label><br>';
            submit_button(__('Start complete data cleanup', 'rrze-multisite-manager'), 'delete', 'submit', false);
            echo '</form>';
        }

        echo '</section>';
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
                /* translators: %s: DNS status. */
                __('DNS: %s', 'rrze-multisite-manager'),
                $this->formatMonitoringStatusValue(
                    (string)$event['dns_status'],
                    (string)($event['dns_status_detail'] ?? '')
                )
            );
        }

        if (!empty($event['http_status'])) {
            $details[] = sprintf(
                /* translators: %s: HTTP status. */
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
                /* translators: 1: previous status, 2: current status. */
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
        return $this->statusFormatter->getMonitoringLabel($status, __('-', 'rrze-multisite-manager'));
    }

    protected function formatMonitoringStatusValue(string $status, string $detail = '', int $code = 0): string {
        return $this->statusFormatter->formatMonitoringValue($status, $detail, $code, __('-', 'rrze-multisite-manager'));
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
