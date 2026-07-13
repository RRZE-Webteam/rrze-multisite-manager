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
                'version' => '0.1.0',
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
                'site_status_slug' => 'rrze-multisite-manager-site-status',
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
                    'description' => __('Einstellungen für Dashboard-Widgets und Aktivitätsauswertungen.', 'rrze-multisite-manager'),
                ],
                [
                    'id' => 'monitoring',
                    'title' => __('Monitoring', 'rrze-multisite-manager'),
                    'description' => __('Einstellungen für technische Erreichbarkeits- und Verfügbarkeitsprüfungen.', 'rrze-multisite-manager'),
                ],
            ],
            'settings_fields' => [
                'dashboard' => [
                    [
                        'name' => 'activity_site_limit',
                        'label' => __('Standardanzahl Sites in Tabellen-Widgets', 'rrze-multisite-manager'),
                        'desc' => __('Standardwert N für Site-Tabellen im Dashboard. Dieser Wert erscheint auch in der Auswahl über der Tabelle.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 10,
                        'min' => 1,
                    ],
                    [
                        'name' => 'inactive_highlight_months',
                        'label' => __('Monate bis Inaktiv-Hervorhebung', 'rrze-multisite-manager'),
                        'desc' => __('Ab wie vielen Monaten ohne neue Posts, Pages oder Medien eine Site im Widget für lange Nichtnutzung hervorgehoben wird.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 6,
                        'min' => 1,
                    ],
                ],
                'monitoring' => [
                    [
                        'name' => 'metrics_interval_minutes',
                        'label' => __('Metrics-Intervall in Minuten', 'rrze-multisite-manager'),
                        'desc' => __('Mindestabstand zwischen automatisch eingeplanten Metrics-Läufen. Manuell gestartete Läufe sind davon nicht betroffen.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 60,
                        'min' => 60,
                        'max' => 10080,
                    ],
                    [
                        'name' => 'monitoring_interval_hours',
                        'label' => __('Prüfintervall in Stunden', 'rrze-multisite-manager'),
                        'desc' => __('Wie oft der Verfügbarkeitscheck für alle Sites laufen soll.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 6,
                        'min' => 1,
                        'max' => 168,
                    ],
                    [
                        'name' => 'provisioning_grace_hours',
                        'label' => __('Karenzzeit für Einrichtung in Stunden', 'rrze-multisite-manager'),
                        'desc' => __('Solange eine neue Site jünger ist als diese Karenzzeit, bleibt sie bei technischen Problemen im Status „Einrichtung läuft“ statt sofort als Problemfall zu gelten.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 48,
                        'min' => 0,
                        'max' => 720,
                    ],
                    [
                        'name' => 'dns_failure_threshold',
                        'label' => __('Schwelle für DNS-Fehlerläufe', 'rrze-multisite-manager'),
                        'desc' => __('Erst ab dieser Zahl aufeinanderfolgender DNS-Fehlläufe wird der Betriebsstatus auf „DNS fehlt“ gesetzt.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 2,
                        'min' => 1,
                        'max' => 20,
                    ],
                    [
                        'name' => 'http_failure_threshold',
                        'label' => __('Schwelle für HTTP-Fehlerläufe', 'rrze-multisite-manager'),
                        'desc' => __('Erst ab dieser Zahl aufeinanderfolgender HTTP-Fehlläufe wird der Betriebsstatus auf „Technisch nicht erreichbar“ gesetzt.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 2,
                        'min' => 1,
                        'max' => 20,
                    ],
                    [
                        'name' => 'run_log_entries',
                        'label' => __('Behaltene Monitoring-Läufe', 'rrze-multisite-manager'),
                        'desc' => __('Wie viele abgeschlossene Monitoring-Läufe im Protokoll gespeichert bleiben.', 'rrze-multisite-manager'),
                        'type' => 'number',
                        'default' => 20,
                        'min' => 5,
                        'max' => 200,
                    ],
                    [
                        'name' => 'recent_event_entries',
                        'label' => __('Sichtbare Auffälligkeiten', 'rrze-multisite-manager'),
                        'desc' => __('Wie viele Einträge in der Tabelle „Zuletzt erkannte Auffälligkeiten“ maximal angezeigt werden.', 'rrze-multisite-manager'),
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
