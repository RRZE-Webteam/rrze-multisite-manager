<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Config {
    private array $config = [];

    public function __construct() {
        $schedulerFrequencies = [
            'weekly' => ['hours' => 168, 'label' => __('Once weekly', 'rrze-multisite-manager')],
            'twiceweekly' => ['hours' => 84, 'label' => __('Twice weekly', 'rrze-multisite-manager')],
            'daily' => ['hours' => 24, 'label' => __('Once daily', 'rrze-multisite-manager')],
            'twicedaily' => ['hours' => 12, 'label' => __('Twice daily', 'rrze-multisite-manager')],
            'fourtimesdaily' => ['hours' => 6, 'label' => __('Four times daily', 'rrze-multisite-manager')],
        ];
        $schedulerFrequencyChoices = array_map(
            static fn(array $frequency): string => (string)$frequency['label'],
            $schedulerFrequencies
        );

        $this->config = [
            'option_name' => 'rrze-multisite-manager',
            'constants' => [
                'plugin_name' => __('RRZE Multisite Manager', 'rrze-multisite-manager'),
                'textdomain' => 'rrze-multisite-manager',
                'wp_version' => '6.9.4',
                'php_version' => '8.3',
                'metrics_cache_ttl' => HOUR_IN_SECONDS,
                'monitoring_schedule_slug' => 'rrze_msm_every_six_hours',
                'monitoring_interval' => 6 * HOUR_IN_SECONDS,
                'availability_monitoring_batch_size' => 5,
                'availability_monitoring_request_budget_seconds' => 45,
                'availability_monitoring_http_timeout_seconds' => 4,
                'availability_monitoring_http_fallback_timeout_seconds' => 2,
                'shortcode_block_result_entry_limit' => 1000,
                'storage_analysis_content_usage_matches_limit' => 10,
                'monitoring_hook' => 'rrze_msm_check_site_availability',
                'storage_analysis_hook' => 'rrze_msm_run_site_storage_analysis',
                'storage_analysis_batch_hook' => 'rrze_msm_run_site_storage_analysis_batch',
                'shortcode_block_analysis_hook' => 'rrze_msm_run_shortcode_block_analysis_site',
                'shortcode_block_analysis_batch_hook' => 'rrze_msm_run_shortcode_block_analysis_batch',
                'shortcode_block_analysis_timeout_minutes' => 60,
                'storage_analysis_timeout_minutes' => 60,
                'monitoring_user_agent' => 'FAU-RRZE-MSM/1.2 (+https://www.wp.rrze.fau.de; mailto:webmaster@fau.de)',
                'scheduler_frequencies' => $schedulerFrequencies,
                'storage_analysis_schedule_keys' => [
                    'weekly' => 'rrze_msm_storage_weekly',
                    'twiceweekly' => 'rrze_msm_storage_twice_weekly',
                    'daily' => 'rrze_msm_storage_daily',
                    'twicedaily' => 'rrze_msm_storage_twice_daily',
                    'fourtimesdaily' => 'rrze_msm_storage_four_times_daily',
                ],
                'shortcode_block_analysis_schedule_keys' => [
                    'weekly' => 'rrze_msm_shortcode_block_weekly',
                    'twiceweekly' => 'rrze_msm_shortcode_block_twice_weekly',
                    'daily' => 'rrze_msm_shortcode_block_daily',
                    'twicedaily' => 'rrze_msm_shortcode_block_twice_daily',
                    'fourtimesdaily' => 'rrze_msm_shortcode_block_four_times_daily',
                ],
            ],
            'menu_settings' => [
                'page_title' => __('RRZE Multisite Manager', 'rrze-multisite-manager'),
                'menu_title' => __('Multisite Manager', 'rrze-multisite-manager'),
                'capability' => 'rrze_multisite_manager_access',
                'parent_slug' => 'rrze-multisite-manager-dashboard',
                'dashboard_slug' => 'rrze-multisite-manager-dashboard',
                'environment_overview_slug' => 'rrze-multisite-manager-environment-overview',
                'site_overview_slug' => 'rrze-multisite-manager-site-overview',
                'plugin_overview_slug' => 'rrze-multisite-manager-plugin-overview',
                'plugin_details_slug' => 'rrze-multisite-manager-plugin-details',
                'theme_overview_slug' => 'rrze-multisite-manager-theme-overview',
                'theme_details_slug' => 'rrze-multisite-manager-theme-details',
                'site_details_slug' => 'rrze-multisite-manager-site-details',
                'site_storage_analysis_slug' => 'rrze-multisite-manager-site-storage-analysis',
                'site_storage_analysis_media_slug' => 'rrze-multisite-manager-media-storage-analysis',
                'shortcode_block_analysis_slug' => 'rrze-multisite-manager-shortcodes-blocks',
                'shortcode_block_analysis_tools_slug' => 'rrze-multisite-manager-shortcodes-blocks-tools',
                'site_status_slug' => 'rrze-multisite-manager-site-status',
                'monitoring_slug' => 'rrze-multisite-manager-monitoring',
                'views_slug' => 'rrze-multisite-manager-views',
                'settings_slug' => 'rrze-multisite-manager-settings',
            ],
            'visibility' => [
                'superadmin_only_site_options' => [
                    'rrze_settings',
                    'fau_api',
                    'fau_api_key',
                    'rrze_faudir_options',
                    'rrze_search_settings',
                    'rrze-jobs',
                    'rrze-lectures',
                ],
            ],
            'settings_sections' => [
                [
                    'id' => 'dashboard',
                    'title' => __('Dashboard', 'rrze-multisite-manager'),
                    'description' => __('Settings for dashboard widgets and activity metrics.', 'rrze-multisite-manager'),
                ],
                [
                    'id' => 'monitoring',
                    'title' => __('Monitoring', 'rrze-multisite-manager'),
                    'description' => __('Settings for technical reachability and availability checks.', 'rrze-multisite-manager'),
                ],
                [
                    'id' => 'debugging',
                    'title' => __('Debugging', 'rrze-multisite-manager'),
                    'description' => __('Optional logging for background processes.', 'rrze-multisite-manager'),
                ],
            ],
            'settings_fields' => [
                'dashboard' => [
                    [
                        'name' => 'activity_site_limit',
                        'label' => __('Default number of sites in table widgets', 'rrze-multisite-manager'),
                        'desc' => __('Default value N for site tables in the dashboard. This value also appears in the selector above the table.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 10,
                        'min' => 1,
                    ],
                    [
                        'name' => 'inactive_highlight_months',
                        'label' => __('Months until inactivity highlight', 'rrze-multisite-manager'),
                        'desc' => __('After how many months without new posts, pages, or media a site is highlighted in the long inactivity widget.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 6,
                        'min' => 1,
                    ],
                ],
                'monitoring' => [
                    [
                        'name' => 'metrics_frequency',
                        'label' => __('Metrics cycle', 'rrze-multisite-manager'),
                        'desc' => __('How often the centrally scheduled metrics collection starts. Internal batch continuations are not affected.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'fourtimesdaily',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'batch_size',
                        'label' => __('Batch size for network processes', 'rrze-multisite-manager'),
                        'desc' => __('Number of websites processed in each batch for dashboard metrics and website availability checks. Larger values complete a network pass faster but increase load per Cron request.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 25,
                        'min' => 5,
                        'max' => 100,
                    ],
                    [
                        'name' => 'storage_analysis_browser_max_megabytes',
                        'label' => __('Maximum storage size for browser analysis in MB', 'rrze-multisite-manager'),
                        'desc' => __('Up to this WordPress-reported storage usage, the storage analysis can run in browser batches. Larger websites are processed exclusively by scheduled background tasks.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 100,
                        'min' => 1,
                        'max' => 1048576,
                    ],
                    [
                        'name' => 'storage_analysis_frequency',
                        'label' => __('Storage analysis cycle per website', 'rrze-multisite-manager'),
                        'desc' => __('How often the scheduled storage analysis is run for each website.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'twiceweekly',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'storage_analysis_batch_media_threshold',
                        'label' => __('Media threshold for shared storage analysis', 'rrze-multisite-manager'),
                        'desc' => __('Websites with fewer media attachments than this value are initially assigned to the shared batch. Websites at or above it receive an individual task.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 100,
                        'min' => 1,
                        'max' => 1000000,
                    ],
                    [
                        'name' => 'storage_analysis_batch_runtime_threshold_seconds',
                        'label' => __('Runtime threshold for shared storage analysis in seconds', 'rrze-multisite-manager'),
                        'desc' => __('After a successful complete run, websites below this runtime are assigned to the shared batch; websites at or above it receive an individual task.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 5,
                        'min' => 1,
                        'max' => 3600,
                    ],
                    [
                        'name' => 'shortcode_block_analysis_frequency',
                        'label' => __('Shortcode and block analysis cycle per website', 'rrze-multisite-manager'),
                        'desc' => __('How often the scheduled shortcode and block analysis is run for each website.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'twiceweekly',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'storage_analysis_timeout_minutes',
                        'label' => __('Maximum storage analysis runtime in minutes', 'rrze-multisite-manager'),
                        'desc' => __('A storage analysis runs in one scheduled process and is aborted when this runtime limit is reached.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 60,
                        'min' => 1,
                        'max' => 1440,
                    ],
                    [
                        'name' => 'storage_analysis_orphan_files_limit',
                        'label' => __('Maximum number of files for the orphan check', 'rrze-multisite-manager'),
                        'desc' => __('Only the largest potentially orphaned files up to this limit are checked for references in post and page content. The total number of detected files remains visible.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 250,
                        'min' => 10,
                        'max' => 5000,
                    ],
                    [
                        'name' => 'shortcode_block_analysis_timeout_minutes',
                        'label' => __('Maximum shortcode and block analysis runtime in minutes', 'rrze-multisite-manager'),
                        'desc' => __('A shortcode and block analysis runs in one scheduled process and is aborted when this runtime limit is reached.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 60,
                        'min' => 1,
                        'max' => 1440,
                    ],
                    [
                        'name' => 'monitoring_frequency',
                        'label' => __('Availability check cycle', 'rrze-multisite-manager'),
                        'desc' => __('How often the centrally scheduled availability check starts. Internal batch continuations are not affected.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'fourtimesdaily',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'provisioning_grace_hours',
                        'label' => __('Provisioning grace period in hours', 'rrze-multisite-manager'),
                        'desc' => __('As long as a new site is younger than this grace period, it remains in status "Provisioning in progress" during technical problems instead of immediately counting as an issue.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 48,
                        'min' => 0,
                        'max' => 720,
                    ],
                    [
                        'name' => 'dns_failure_threshold',
                        'label' => __('Threshold for DNS failure runs', 'rrze-multisite-manager'),
                        'desc' => __('Only after this number of consecutive DNS failures is the operational status set to "DNS missing".', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 2,
                        'min' => 1,
                        'max' => 20,
                    ],
                    [
                        'name' => 'http_failure_threshold',
                        'label' => __('Threshold for HTTP failure runs', 'rrze-multisite-manager'),
                        'desc' => __('Only after this number of consecutive HTTP failures is the operational status set to "Technically unreachable".', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 2,
                        'min' => 1,
                        'max' => 20,
                    ],
                    [
                        'name' => 'run_log_entries',
                        'label' => __('Retained monitoring runs', 'rrze-multisite-manager'),
                        'desc' => __('How many completed monitoring runs remain stored in the log.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 20,
                        'min' => 5,
                        'max' => 200,
                    ],
                    [
                        'name' => 'recent_event_entries',
                        'label' => __('Visible issues', 'rrze-multisite-manager'),
                        'desc' => __('How many entries are shown at most in the "Recently detected anomalies" table.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 30,
                        'min' => 10,
                        'max' => 500,
                    ],
                ],
                'debugging' => [
                    [
                        'name' => 'logging',
                        'label' => __('Logging', 'rrze-multisite-manager'),
                        'desc' => __('Send informational messages to the info channel.', 'rrze-multisite-manager'),
                        'type' => 'checkbox',
                        'default' => false,
                    ],
                ],
            ],
        ];
    }

    public function getOptionName(): string {
        return $this->config['option_name'];
    }

    public function getConstants(): array {
        return $this->config['constants'];
    }

    public function getRequiredWpVersion(): string {
        return (string)($this->config['constants']['wp_version'] ?? '');
    }

    public function getRequiredPhpVersion(): string {
        return (string)($this->config['constants']['php_version'] ?? '');
    }

    public function getMenuSettings(): array {
        return $this->config['menu_settings'];
    }

    public function getVisibilitySettings(): array {
        return $this->config['visibility'] ?? [];
    }

    public function getMetricsCacheTtl(): int {
        return (int)($this->config['constants']['metrics_cache_ttl'] ?? HOUR_IN_SECONDS);
    }

    public function getMonitoringScheduleSlug(): string {
        return (string)($this->config['constants']['monitoring_schedule_slug'] ?? 'rrze_msm_every_six_hours');
    }

    public function getMonitoringInterval(): int {
        return (int)($this->config['constants']['monitoring_interval'] ?? (6 * HOUR_IN_SECONDS));
    }

    public function getMonitoringHook(): string {
        return (string)($this->config['constants']['monitoring_hook'] ?? 'rrze_msm_check_site_availability');
    }

    public function getStorageAnalysisHook(): string {
        return (string)($this->config['constants']['storage_analysis_hook'] ?? 'rrze_msm_run_site_storage_analysis');
    }

    public function getStorageAnalysisBatchHook(): string {
        return (string)($this->config['constants']['storage_analysis_batch_hook'] ?? 'rrze_msm_run_site_storage_analysis_batch');
    }

    public function getStorageAnalysisOrphanFilesLimit(): int {
        $options = get_site_option($this->getOptionName(), []);
        $limit = is_array($options) ? (int)($options['monitoring_storage_analysis_orphan_files_limit'] ?? 250) : 250;

        return max(10, min(5000, $limit));
    }

    public function getStorageAnalysisBatchMediaThreshold(): int {
        $options = get_site_option($this->getOptionName(), []);
        $threshold = is_array($options) ? (int)($options['monitoring_storage_analysis_batch_media_threshold'] ?? 100) : 100;

        return max(1, min(1000000, $threshold));
    }

    public function getStorageAnalysisBatchRuntimeThresholdSeconds(): int {
        $options = get_site_option($this->getOptionName(), []);
        $threshold = is_array($options) ? (int)($options['monitoring_storage_analysis_batch_runtime_threshold_seconds'] ?? 5) : 5;

        return max(1, min(3600, $threshold));
    }

    public function getMonitoringBatchSize(): int {
        $options = get_site_option($this->getOptionName(), []);
        $size = is_array($options) ? (int)($options['monitoring_batch_size'] ?? 25) : 25;

        return max(5, min(100, $size));
    }

    public function getAvailabilityMonitoringBatchSize(): int {
        return max(1, min(25, (int)($this->config['constants']['availability_monitoring_batch_size'] ?? 5)));
    }

    public function getAvailabilityMonitoringRequestBudgetSeconds(): int {
        return max(10, min(240, (int)($this->config['constants']['availability_monitoring_request_budget_seconds'] ?? 45)));
    }

    public function getAvailabilityMonitoringHttpTimeoutSeconds(): int {
        return max(1, min(30, (int)($this->config['constants']['availability_monitoring_http_timeout_seconds'] ?? 4)));
    }

    public function getAvailabilityMonitoringHttpFallbackTimeoutSeconds(): int {
        return max(1, min(30, (int)($this->config['constants']['availability_monitoring_http_fallback_timeout_seconds'] ?? 2)));
    }

    public function getShortcodeBlockResultEntryLimit(): int {
        return max(100, min(5000, (int)($this->config['constants']['shortcode_block_result_entry_limit'] ?? 1000)));
    }

    public function getStorageAnalysisContentUsageMatchesLimit(): int {
        return max(1, min(50, (int)($this->config['constants']['storage_analysis_content_usage_matches_limit'] ?? 10)));
    }

    public function getStorageAnalysisTimeoutSeconds(): int {
        $options = get_site_option($this->getOptionName(), []);
        $default = (int)($this->config['constants']['storage_analysis_timeout_minutes'] ?? 60);

        if (!is_array($options)) {
            return max(MINUTE_IN_SECONDS, $default * MINUTE_IN_SECONDS);
        }

        return max(
            MINUTE_IN_SECONDS,
            min(1440 * MINUTE_IN_SECONDS, (int)($options['monitoring_storage_analysis_timeout_minutes'] ?? $default) * MINUTE_IN_SECONDS)
        );
    }

    public function getShortcodeBlockAnalysisHook(): string {
        return (string)($this->config['constants']['shortcode_block_analysis_hook'] ?? 'rrze_msm_run_shortcode_block_analysis_site');
    }

    public function getShortcodeBlockAnalysisBatchHook(): string {
        return (string)($this->config['constants']['shortcode_block_analysis_batch_hook'] ?? 'rrze_msm_run_shortcode_block_analysis_batch');
    }

    public function getLegacyShortcodeBlockAnalysisHook(): string {
        return 'rrze_msm_run_shortcode_block_analysis';
    }

    public function getShortcodeBlockAnalysisTimeoutSeconds(): int {
        $options = get_site_option($this->getOptionName(), []);
        $default = (int)($this->config['constants']['shortcode_block_analysis_timeout_minutes'] ?? 60);

        if (!is_array($options)) {
            return max(MINUTE_IN_SECONDS, $default * MINUTE_IN_SECONDS);
        }

        return max(
            MINUTE_IN_SECONDS,
            min(1440 * MINUTE_IN_SECONDS, (int)($options['monitoring_shortcode_block_analysis_timeout_minutes'] ?? $default) * MINUTE_IN_SECONDS)
        );
    }

    public function getMonitoringUserAgent(): string {
        return (string)($this->config['constants']['monitoring_user_agent'] ?? 'FAU-RRZE-MSM/1.2 (+https://www.wp.rrze.fau.de; mailto:webmaster@fau.de)');
    }

    /** @return array<string, array{hours: int, label: string}> */
    public function getSchedulerFrequencies(): array {
        return (array)($this->config['constants']['scheduler_frequencies'] ?? []);
    }

    /** @return array<string, string> */
    public function getSchedulerFrequencyChoices(): array {
        return array_map(
            static fn(array $frequency): string => (string)($frequency['label'] ?? ''),
            $this->getSchedulerFrequencies()
        );
    }

    public function getSchedulerFrequencyHours(string $frequency): int {
        $frequencies = $this->getSchedulerFrequencies();

        return max(1, (int)($frequencies[$frequency]['hours'] ?? $frequencies['fourtimesdaily']['hours'] ?? 6));
    }

    public function hasSchedulerFrequency(string $frequency): bool {
        return array_key_exists($frequency, $this->getSchedulerFrequencies());
    }

    public function getSchedulerFrequencyLabel(string $frequency): string {
        $frequencies = $this->getSchedulerFrequencies();

        return (string)($frequencies[$frequency]['label'] ?? $frequencies['fourtimesdaily']['label'] ?? '');
    }

    public function getSchedulerFrequencyFromHours(int $hours): string {
        foreach (array_reverse($this->getSchedulerFrequencies(), true) as $key => $frequency) {
            if ($hours <= (int)($frequency['hours'] ?? 0)) {
                return (string)$key;
            }
        }

        return 'weekly';
    }

    public function getStorageAnalysisScheduleKey(string $frequency): string {
        $keys = (array)($this->config['constants']['storage_analysis_schedule_keys'] ?? []);

        return (string)($keys[$frequency] ?? $keys['twiceweekly'] ?? '');
    }

    /** @return array<string, string> */
    public function getStorageAnalysisScheduleKeys(): array {
        return (array)($this->config['constants']['storage_analysis_schedule_keys'] ?? []);
    }

    public function getShortcodeBlockAnalysisScheduleKey(string $frequency): string {
        $keys = (array)($this->config['constants']['shortcode_block_analysis_schedule_keys'] ?? []);

        return (string)($keys[$frequency] ?? $keys['twiceweekly'] ?? '');
    }

    /** @return array<string, string> */
    public function getShortcodeBlockAnalysisScheduleKeys(): array {
        return (array)($this->config['constants']['shortcode_block_analysis_schedule_keys'] ?? []);
    }

    public function getSections(): array {
        return $this->config['settings_sections'];
    }

    public function getFields(): array {
        return $this->config['settings_fields'];
    }
}
