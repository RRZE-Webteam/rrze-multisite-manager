<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Config {
    private array $config = [];

    public function __construct() {
        $this->config = [
            'option_name' => 'rrze-multisite-manager',
            'constants' => [
                'plugin_name' => __('RRZE Multisite Manager', 'rrze-multisite-manager'),
                'textdomain' => 'rrze-multisite-manager',
                'metrics_cache_ttl' => HOUR_IN_SECONDS,
                'monitoring_schedule_slug' => 'rrze_msm_every_six_hours',
                'monitoring_interval' => 6 * HOUR_IN_SECONDS,
                'monitoring_hook' => 'rrze_msm_check_site_availability',
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
                        'name' => 'metrics_interval_minutes',
                        'label' => __('Metrics interval in minutes', 'rrze-multisite-manager'),
                        'desc' => __('Minimum interval between automatically scheduled metrics runs. Manually started runs are not affected.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 60,
                        'min' => 60,
                        'max' => 10080,
                    ],
                    [
                        'name' => 'monitoring_interval_hours',
                        'label' => __('Check interval in hours', 'rrze-multisite-manager'),
                        'desc' => __('How often the availability check should run for all sites.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 6,
                        'min' => 1,
                        'max' => 168,
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
            ],
        ];
    }

    public function getOptionName(): string {
        return $this->config['option_name'];
    }

    public function getConstants(): array {
        return $this->config['constants'];
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

    public function getSections(): array {
        return $this->config['settings_sections'];
    }

    public function getFields(): array {
        return $this->config['settings_fields'];
    }
}
