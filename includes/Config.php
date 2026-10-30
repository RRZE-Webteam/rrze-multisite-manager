<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Config {
    public const STORAGE_ANALYSIS_MAX_CONCURRENT_RUNS = 8;

    private array $config = [];

    public function __construct() {
        $network = function_exists('get_network') ? get_network() : null;
        $serverDomain = $network instanceof \WP_Network ? $network->domain : '';
        $websiteStatusReportSubject = 'MSM Report: Website-Status ' . $serverDomain;
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
                'availability_monitoring_batch_size' => 10,
                'availability_monitoring_request_budget_seconds' => 45,
                'availability_monitoring_http_timeout_seconds' => 4,
                'availability_monitoring_http_fallback_timeout_seconds' => 2,
                'network_cron_cleanup_batch_size' => 100,
                'shortcode_block_result_entry_limit' => 1000,
                'storage_analysis_content_usage_matches_limit' => 10,
                'monitoring_hook' => 'rrze_msm_check_site_availability',
                'storage_analysis_hook' => 'rrze_msm_run_site_storage_analysis',
                'storage_analysis_batch_hook' => 'rrze_msm_run_site_storage_analysis_batch',
                'storage_analysis_medium_batch_hook' => 'rrze_msm_run_site_storage_analysis_medium_batch',
                'shortcode_block_analysis_hook' => 'rrze_msm_run_shortcode_block_analysis_site',
                'shortcode_block_analysis_batch_hook' => 'rrze_msm_run_shortcode_block_analysis_batch',
                'shortcode_block_analysis_timeout_minutes' => 60,
                'storage_analysis_timeout_minutes' => 60,
                'monitoring_user_agent' => 'FAU-RRZE-MSM/1.2 (+https://www.wp.rrze.fau.de; mailto:webmaster@fau.de)',
                'site_status_colors' => [
                    'active' => '#43A047',
                    'public' => '#1565C0',
                    'not_public' => '#039BE5',
                    'active_public' => '#43A047',
                    'active_private' => '#039BE5',
                    'archived' => '#A15C00',
                    'blocked' => '#9F1239',
                    'deleted' => '#7F1D1D',
                    'automatic' => '#64748B',
                    'technically_reachable' => '#26A69A',
                    'provisioning' => '#6D28D9',
                    'dns_missing' => '#B45309',
                    'technically_unreachable' => '#C2410C',
                    'out_of_service' => '#7F1D1D',
                ],
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
                    'id' => 'reporting',
                    'title' => __('Website Status Report', 'rrze-multisite-manager'),
                    'description' => __('Settings for the central website status report.', 'rrze-multisite-manager'),
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
                'reporting' => [
                    [
                        'name' => 'website_status_report_enabled',
                        'label' => __('Enable reporting', 'rrze-multisite-manager'),
                        'desc' => __('Creates a centrally scheduled email report for website statuses.', 'rrze-multisite-manager'),
                        'type' => 'checkbox',
                        'default' => false,
                    ],
                    [
                        'name' => 'website_status_report_frequency',
                        'label' => __('Cycle', 'rrze-multisite-manager'),
                        'desc' => __('For monthly reports, the report is sent on the first selected weekday of each month.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'weekly',
                        'choices' => [
                            'weekly' => __('Weekly', 'rrze-multisite-manager'),
                            'biweekly' => __('Every two weeks', 'rrze-multisite-manager'),
                            'monthly' => __('Monthly', 'rrze-multisite-manager'),
                        ],
                    ],
                    [
                        'name' => 'website_status_report_weekday',
                        'label' => __('Weekday', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => '1',
                        'choices' => [
                            '0' => __('Sunday', 'rrze-multisite-manager'),
                            '1' => __('Monday', 'rrze-multisite-manager'),
                            '2' => __('Tuesday', 'rrze-multisite-manager'),
                            '3' => __('Wednesday', 'rrze-multisite-manager'),
                            '4' => __('Thursday', 'rrze-multisite-manager'),
                            '5' => __('Friday', 'rrze-multisite-manager'),
                            '6' => __('Saturday', 'rrze-multisite-manager'),
                        ],
                    ],
                    [
                        'name' => 'website_status_report_time',
                        'label' => __('Earliest sending time', 'rrze-multisite-manager'),
                        'desc' => __('The report is sent by the next WordPress Cron run at or after this local time.', 'rrze-multisite-manager'),
                        'type' => 'time',
                        'default' => '09:00',
                    ],
                    [
                        'name' => 'website_status_report_recipient',
                        'label' => __('Recipient email address', 'rrze-multisite-manager'),
                        'desc' => __('Defaults to the network administrator email address.', 'rrze-multisite-manager'),
                        'type' => 'email',
                        'default' => '',
                    ],
                    [
                        'name' => 'website_status_report_subject',
                        'label' => __('Mail subject', 'rrze-multisite-manager'),
                        'desc' => __('Defaults to the network domain. You can adjust the subject as needed.', 'rrze-multisite-manager'),
                        'type' => 'text',
                        'default' => $websiteStatusReportSubject,
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
                        'name' => 'dashboard_metrics_history_frequency',
                        'label' => __('Dashboard metrics history cycle', 'rrze-multisite-manager'),
                        'desc' => __('How often current dashboard metric snapshots are retained as historical statistics. Current snapshots are always kept separately.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'weekly',
                        'choices' => [
                            'disabled' => __('Disabled (keep existing statistics)', 'rrze-multisite-manager'),
                            'daily' => __('Daily', 'rrze-multisite-manager'),
                            'weekly' => __('Weekly', 'rrze-multisite-manager'),
                            'monthly' => __('Monthly', 'rrze-multisite-manager'),
                        ],
                    ],
                    [
                        'name' => 'batch_size',
                        'label' => __('Dashboard metrics batch size', 'rrze-multisite-manager'),
                        'desc' => __('Number of websites processed in each dashboard-metrics batch. Larger values complete a network pass faster but increase load per Cron request.', 'rrze-multisite-manager'),
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
                        'name' => 'storage_analysis_small_batch_frequency',
                        'label' => __('Small storage-analysis batch cycle', 'rrze-multisite-manager'),
                        'desc' => __('How often the central small storage-analysis batch is run.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'twiceweekly',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'storage_analysis_medium_batch_frequency',
                        'label' => __('Medium storage-analysis batch cycle', 'rrze-multisite-manager'),
                        'desc' => __('How often the central medium storage-analysis batch is run.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'twiceweekly',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'storage_analysis_individual_frequency',
                        'label' => __('Individual storage-analysis cycle', 'rrze-multisite-manager'),
                        'desc' => __('How often individually scheduled storage analyses are run.', 'rrze-multisite-manager'),
                        'type' => 'select',
                        'default' => 'twiceweekly',
                        'choices' => $schedulerFrequencyChoices,
                    ],
                    [
                        'name' => 'storage_analysis_small_batch_media_threshold',
                        'label' => __('Media threshold for small storage-analysis batches', 'rrze-multisite-manager'),
                        'desc' => __('Websites at or below this number of media attachments are assigned to the small shared batch, unless their last successful active runtime exceeds its threshold.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 250,
                        'min' => 1,
                        'max' => 1000000,
                    ],
                    [
                        'name' => 'storage_analysis_medium_batch_media_threshold',
                        'label' => __('Media threshold for medium storage-analysis batches', 'rrze-multisite-manager'),
                        'desc' => __('Websites above the small-batch threshold and at or below this value are assigned to the medium shared batch, unless their last successful active runtime exceeds its threshold.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 1500,
                        'min' => 1,
                        'max' => 1000000,
                    ],
                    [
                        'name' => 'storage_analysis_small_batch_runtime_threshold_seconds',
                        'label' => __('Active runtime threshold for small storage-analysis batches in seconds', 'rrze-multisite-manager'),
                        'desc' => __('A website above this active runtime is moved from the small to the medium shared batch after a successful complete run.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 5,
                        'min' => 1,
                        'max' => 3600,
                    ],
                    [
                        'name' => 'storage_analysis_medium_batch_runtime_threshold_seconds',
                        'label' => __('Active runtime threshold for medium storage-analysis batches in seconds', 'rrze-multisite-manager'),
                        'desc' => __('A website above this active runtime is moved from the medium shared batch to an individual task after a successful complete run.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 120,
                        'min' => 1,
                        'max' => 3600,
                    ],
                    [
                        'name' => 'storage_analysis_medium_attachment_index_batch_size',
                        'label' => __('Media attachments per index step in medium storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allowed values: 10 to 50. Use the default of 35 for medium shared batches; larger values increase memory and database work per step.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 35,
                        'min' => 10,
                        'max' => 50,
                    ],
                    [
                        'name' => 'storage_analysis_medium_index_write_batch_size',
                        'label' => __('Index segments written per step in medium storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allowed values: 8 to 24. Use the default of 20 for medium shared batches; larger values increase database work per step.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 20,
                        'min' => 8,
                        'max' => 24,
                    ],
                    [
                        'name' => 'storage_analysis_shared_attachment_index_batch_size',
                        'label' => __('Media attachments per index step in shared storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allowed values: 5 to 25. Use 25 unless a shared batch causes noticeable load; 25 is the safe upper limit so one website cannot delay the remaining batch.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 25,
                        'min' => 5,
                        'max' => 25,
                    ],
                    [
                        'name' => 'storage_analysis_shared_index_write_batch_size',
                        'label' => __('Index segments written per step in shared storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allowed values: 4 to 16. Use 16 unless database writes from shared batches cause noticeable load; 16 is the safe upper limit.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 16,
                        'min' => 4,
                        'max' => 16,
                    ],
                    [
                        'name' => 'storage_analysis_individual_attachment_index_batch_size',
                        'label' => __('Media attachments per index step in individual storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allowed values: 25 to 100. The default of 50 is intended for websites with more than 1,000 media items. Only use 100 if the server has sufficient database and PHP capacity.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 50,
                        'min' => 25,
                        'max' => 100,
                    ],
                    [
                        'name' => 'storage_analysis_individual_index_write_batch_size',
                        'label' => __('Index segments written per step in individual storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allowed values: 8 to 32. Use the default of 24; use 32 only if database writes remain fast. Larger values reduce continuation requests but increase database work per step.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 24,
                        'min' => 8,
                        'max' => 32,
                    ],
                    [
                        'name' => 'storage_analysis_max_concurrent_runs',
                        'label' => __('Maximum concurrent storage analyses', 'rrze-multisite-manager'),
                        'desc' => __('Allows four storage analyses and temporary indexes across the network by default, with up to eight. Additional analyses wait for capacity.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 4,
                        'min' => 4,
                        'max' => self::STORAGE_ANALYSIS_MAX_CONCURRENT_RUNS,
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
                        'name' => 'show_local_analysis_request_notice',
                        'label' => __('Show analysis request notice to local administrators', 'rrze-multisite-manager'),
                        'desc' => __('When a website has neither a saved result nor a planned storage or shortcode-and-block analysis, show local administrators a request notice under Tools. When disabled, the relevant Tools page is hidden instead.', 'rrze-multisite-manager'),
                        'type' => 'checkbox',
                        'default' => false,
                    ],
                    [
                        'name' => 'storage_analysis_small_batch_timeout_minutes',
                        'label' => __('Maximum active runtime for small storage-analysis batches in minutes', 'rrze-multisite-manager'),
                        'desc' => __('Limits active processing time across short scheduled steps for each individual website in the small batch, not for the entire batch. Waiting for Cron or analysis capacity does not count.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 60,
                        'min' => 1,
                        'max' => 240,
                    ],
                    [
                        'name' => 'storage_analysis_medium_batch_timeout_minutes',
                        'label' => __('Maximum active runtime for medium storage-analysis batches in minutes', 'rrze-multisite-manager'),
                        'desc' => __('Limits active processing time across short scheduled steps for each individual website in the medium batch, not for the entire batch. Waiting for Cron or analysis capacity does not count.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 180,
                        'min' => 1,
                        'max' => 480,
                    ],
                    [
                        'name' => 'storage_analysis_individual_timeout_minutes',
                        'label' => __('Maximum active runtime for individual storage analyses in minutes', 'rrze-multisite-manager'),
                        'desc' => __('Limits active processing time across short scheduled steps for individual websites. Waiting for Cron or analysis capacity does not count. Values up to twelve hours are allowed.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 360,
                        'min' => 1,
                        'max' => 720,
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
                        'desc' => __('This limit applies to active PHP processing across resumable shortcode and block analysis steps. Waiting for the next Cron request does not count.', 'rrze-multisite-manager'),
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
                        'name' => 'availability_batch_size',
                        'label' => __('Websites per availability-check batch', 'rrze-multisite-manager'),
                        'desc' => __('Number of websites checked in one availability-monitoring Cron request. The default of 10 keeps failed DNS or HTTP requests within the request budget; larger values can speed up healthy networks but may require more continuations when websites time out.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 10,
                        'min' => 1,
                        'max' => 25,
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

    public function shouldShowLocalAnalysisRequestNotice(): bool {
        $options = get_site_option($this->getOptionName(), []);

        return is_array($options) && !empty($options['monitoring_show_local_analysis_request_notice']);
    }

    public function getConstants(): array {
        return $this->config['constants'];
    }

    public function getSiteStatusColors(): array {
        return (array)($this->config['constants']['site_status_colors'] ?? []);
    }

    public function getSiteStatusColor(string $status, string $fallback = '#343a40'): string {
        $colors = $this->getSiteStatusColors();

        return isset($colors[$status]) && is_string($colors[$status])
            ? $colors[$status]
            : $fallback;
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

    public function getStorageAnalysisMediumBatchHook(): string {
        return (string)($this->config['constants']['storage_analysis_medium_batch_hook'] ?? 'rrze_msm_run_site_storage_analysis_medium_batch');
    }

    public function getStorageAnalysisOrphanFilesLimit(): int {
        $options = get_site_option($this->getOptionName(), []);
        $limit = is_array($options) ? (int)($options['monitoring_storage_analysis_orphan_files_limit'] ?? 250) : 250;

        return max(10, min(5000, $limit));
    }

    public function getStorageAnalysisSmallBatchMediaThreshold(): int {
        $options = get_site_option($this->getOptionName(), []);
        $threshold = is_array($options) ? (int)($options['monitoring_storage_analysis_small_batch_media_threshold'] ?? 250) : 250;

        return max(1, min(1000000, $threshold));
    }

    public function getStorageAnalysisMediumBatchMediaThreshold(): int {
        $options = get_site_option($this->getOptionName(), []);
        $smallThreshold = $this->getStorageAnalysisSmallBatchMediaThreshold();
        $threshold = is_array($options) ? (int)($options['monitoring_storage_analysis_medium_batch_media_threshold'] ?? 1500) : 1500;

        return max($smallThreshold, min(1000000, $threshold));
    }

    public function getStorageAnalysisSmallBatchRuntimeThresholdSeconds(): int {
        $options = get_site_option($this->getOptionName(), []);
        $threshold = is_array($options) ? (int)($options['monitoring_storage_analysis_small_batch_runtime_threshold_seconds'] ?? 5) : 5;

        return max(1, min(3600, $threshold));
    }

    public function getStorageAnalysisMediumBatchRuntimeThresholdSeconds(): int {
        $options = get_site_option($this->getOptionName(), []);
        $smallThreshold = $this->getStorageAnalysisSmallBatchRuntimeThresholdSeconds();
        $threshold = is_array($options) ? (int)($options['monitoring_storage_analysis_medium_batch_runtime_threshold_seconds'] ?? 120) : 120;

        return max($smallThreshold, min(3600, $threshold));
    }

    /** @deprecated Use getStorageAnalysisSmallBatchMediaThreshold(). */
    public function getStorageAnalysisBatchMediaThreshold(): int {
        return $this->getStorageAnalysisSmallBatchMediaThreshold();
    }

    /** @deprecated Use getStorageAnalysisSmallBatchRuntimeThresholdSeconds(). */
    public function getStorageAnalysisBatchRuntimeThresholdSeconds(): int {
        return $this->getStorageAnalysisSmallBatchRuntimeThresholdSeconds();
    }

    public function getStorageAnalysisAttachmentIndexBatchSize(string $assignmentMode): int {
        $options = get_site_option($this->getOptionName(), []);
        $isIndividual = $assignmentMode === 'site';
        $isMedium = $assignmentMode === 'medium-batch';
        $option = $isIndividual
            ? 'monitoring_storage_analysis_individual_attachment_index_batch_size'
            : ($isMedium ? 'monitoring_storage_analysis_medium_attachment_index_batch_size' : 'monitoring_storage_analysis_shared_attachment_index_batch_size');
        $default = $isIndividual ? 50 : ($isMedium ? 35 : 25);
        $size = is_array($options) ? (int)($options[$option] ?? $default) : $default;

        return $isIndividual ? max(25, min(100, $size)) : ($isMedium ? max(10, min(50, $size)) : max(5, min(25, $size)));
    }

    public function getStorageAnalysisIndexWriteBatchSize(string $assignmentMode): int {
        $options = get_site_option($this->getOptionName(), []);
        $isIndividual = $assignmentMode === 'site';
        $isMedium = $assignmentMode === 'medium-batch';
        $option = $isIndividual
            ? 'monitoring_storage_analysis_individual_index_write_batch_size'
            : ($isMedium ? 'monitoring_storage_analysis_medium_index_write_batch_size' : 'monitoring_storage_analysis_shared_index_write_batch_size');
        $default = $isIndividual ? 24 : ($isMedium ? 20 : 16);
        $size = is_array($options) ? (int)($options[$option] ?? $default) : $default;

        return $isIndividual ? max(8, min(32, $size)) : ($isMedium ? max(8, min(24, $size)) : max(4, min(16, $size)));
    }

    public function getStorageAnalysisMaxConcurrentRuns(): int {
        $options = get_site_option($this->getOptionName(), []);
        $limit = is_array($options) ? (int)($options['monitoring_storage_analysis_max_concurrent_runs'] ?? 4) : 4;

        return max(4, min(self::STORAGE_ANALYSIS_MAX_CONCURRENT_RUNS, $limit));
    }

    public function getMonitoringBatchSize(): int {
        $options = get_site_option($this->getOptionName(), []);
        $size = is_array($options) ? (int)($options['monitoring_batch_size'] ?? 25) : 25;

        return max(5, min(100, $size));
    }

    public function getAvailabilityMonitoringBatchSize(): int {
        $options = get_site_option($this->getOptionName(), []);
        $default = (int)($this->config['constants']['availability_monitoring_batch_size'] ?? 10);
        $size = is_array($options) ? (int)($options['monitoring_availability_batch_size'] ?? $default) : $default;

        return max(1, min(25, $size));
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

    public function getNetworkCronCleanupBatchSize(): int {
        return max(25, min(250, (int)($this->config['constants']['network_cron_cleanup_batch_size'] ?? 100)));
    }

    public function getShortcodeBlockResultEntryLimit(): int {
        return max(100, min(5000, (int)($this->config['constants']['shortcode_block_result_entry_limit'] ?? 1000)));
    }

    public function getStorageAnalysisContentUsageMatchesLimit(): int {
        return max(1, min(50, (int)($this->config['constants']['storage_analysis_content_usage_matches_limit'] ?? 10)));
    }

    public function getStorageAnalysisTimeoutSeconds(string $assignmentMode = 'site'): int {
        $options = get_site_option($this->getOptionName(), []);
        $isSmall = $assignmentMode === 'small-batch';
        $isMedium = $assignmentMode === 'medium-batch';
        $option = $isSmall
            ? 'monitoring_storage_analysis_small_batch_timeout_minutes'
            : ($isMedium ? 'monitoring_storage_analysis_medium_batch_timeout_minutes' : 'monitoring_storage_analysis_individual_timeout_minutes');
        $default = $isSmall ? 60 : ($isMedium ? 180 : 360);
        $maximum = $isSmall ? 240 : ($isMedium ? 480 : 720);

        if (!is_array($options)) {
            return max(MINUTE_IN_SECONDS, $default * MINUTE_IN_SECONDS);
        }

        return max(
            MINUTE_IN_SECONDS,
            min($maximum * MINUTE_IN_SECONDS, (int)($options[$option] ?? ($options['monitoring_storage_analysis_timeout_minutes'] ?? $default)) * MINUTE_IN_SECONDS)
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
