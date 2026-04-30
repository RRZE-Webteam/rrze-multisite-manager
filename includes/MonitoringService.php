<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class MonitoringService {
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_OPERATIONAL_STATUS_SOURCE = 'rrze_msm_operational_status_source';
    protected const META_PREVIOUS_OPERATIONAL_STATUS = 'rrze_msm_previous_operational_status';
    protected const META_OPERATIONAL_STATUS_CHANGED_AT = 'rrze_msm_operational_status_changed_at';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';
    protected const META_LAST_AVAILABILITY_CHECK = 'rrze_msm_last_availability_check';
    protected const META_LAST_DNS_OK_AT = 'rrze_msm_last_dns_ok_at';
    protected const META_LAST_HTTP_OK_AT = 'rrze_msm_last_http_ok_at';
    protected const META_LAST_DNS_ERROR_AT = 'rrze_msm_last_dns_error_at';
    protected const META_LAST_HTTP_ERROR_AT = 'rrze_msm_last_http_error_at';
    protected const META_DNS_FAILURE_COUNT = 'rrze_msm_dns_failure_count';
    protected const META_HTTP_FAILURE_COUNT = 'rrze_msm_http_failure_count';
    protected const META_MONITORING_NOTE = 'rrze_msm_monitoring_note';
    protected const OPTION_LAST_RUN = 'rrze_msm_monitoring_last_run';
    protected const OPTION_PREVIOUS_RUN = 'rrze_msm_monitoring_previous_run';
    protected const OPTION_LAST_SITE_COUNT = 'rrze_msm_monitoring_last_site_count';

    protected Plugin $plugin;
    protected Config $config;

    public function __construct(Plugin $plugin, ?Config $config = null) {
        $this->plugin = $plugin;
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureScheduledEvent']);
        add_action($this->config->getMonitoringHook(), [$this, 'runScheduledChecks']);
    }

    public function registerSchedules(array $schedules): array {
        $slug = $this->config->getMonitoringScheduleSlug();

        if (empty($schedules[$slug])) {
            $schedules[$slug] = [
                'interval' => $this->getMonitoringInterval(),
                'display' => sprintf(
                    __('Alle %d Stunden', 'rrze-multisite-manager'),
                    $this->getMonitoringIntervalHours()
                ),
            ];
        }

        return $schedules;
    }

    public function ensureScheduledEvent(): void {
        $hook = $this->config->getMonitoringHook();
        $schedule = $this->config->getMonitoringScheduleSlug();

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, $hook);
        }
    }

    public function runScheduledChecks(): void {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $siteId = 0;
        $timestamp = current_time('mysql', true);
        $lastRun = (string)get_site_option(self::OPTION_LAST_RUN, '');

        if ($lastRun !== '') {
            update_site_option(self::OPTION_PREVIOUS_RUN, $lastRun);
        }

        foreach ($siteIds as $siteId) {
            $this->checkSiteAvailability((int)$siteId);
        }

        update_site_option(self::OPTION_LAST_RUN, $timestamp);
        update_site_option(self::OPTION_LAST_SITE_COUNT, count($siteIds));
    }

    public static function clearScheduledEvent(?Config $config = null): void {
        $config = $config ?? new Config();
        $hook = $config->getMonitoringHook();
        $timestamp = wp_next_scheduled($hook);

        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }

    public function getProcessesOverview(): array {
        $hook = $this->config->getMonitoringHook();
        $lastRun = (string)get_site_option(self::OPTION_LAST_RUN, '');
        $lastSiteCount = (int)get_site_option(self::OPTION_LAST_SITE_COUNT, 0);
        $nextRunTimestamp = wp_next_scheduled($hook);

        return [
            [
                'id' => 'site-availability',
                'title' => __('Erreichbarkeit von Websites', 'rrze-multisite-manager'),
                'description' => __('Prüft DNS und HTTP-Erreichbarkeit aller registrierten Websites und aktualisiert die eigenen Monitoring-Metafelder.', 'rrze-multisite-manager'),
                'interval_hours' => $this->getMonitoringIntervalHours(),
                'provisioning_grace_hours' => $this->getProvisioningGraceHours(),
                'dns_failure_threshold' => $this->getDnsFailureThreshold(),
                'http_failure_threshold' => $this->getHttpFailureThreshold(),
                'last_run' => $lastRun,
                'last_site_count' => $lastSiteCount,
                'next_run_timestamp' => $nextRunTimestamp ? (int)$nextRunTimestamp : 0,
            ],
        ];
    }

    protected function getMonitoringInterval(): int {
        return max(HOUR_IN_SECONDS, $this->getMonitoringIntervalHours() * HOUR_IN_SECONDS);
    }

    protected function getMonitoringIntervalHours(): int {
        return max(1, min(168, $this->getMonitoringOption('monitoring_monitoring_interval_hours', 6)));
    }

    protected function getProvisioningGraceHours(): int {
        return max(0, min(720, $this->getMonitoringOption('monitoring_provisioning_grace_hours', 48)));
    }

    protected function getDnsFailureThreshold(): int {
        return max(1, min(20, $this->getMonitoringOption('monitoring_dns_failure_threshold', 2)));
    }

    protected function getHttpFailureThreshold(): int {
        return max(1, min(20, $this->getMonitoringOption('monitoring_http_failure_threshold', 2)));
    }

    protected function getMonitoringOption(string $key, int $default): int {
        $options = get_site_option($this->config->getOptionName(), []);

        if (is_array($options) && isset($options[$key]) && is_numeric($options[$key])) {
            return (int)$options[$key];
        }

        return $default;
    }

    protected function checkSiteAvailability(int $siteId): void {
        $site = get_site($siteId);
        $siteUrl = '';
        $host = '';
        $dnsStatus = 'unknown';
        $httpStatus = 'unknown';
        $timestamp = current_time('mysql', true);
        $operationalStatus = (string)get_site_meta($siteId, self::META_OPERATIONAL_STATUS, true);
        $operationalStatusSource = (string)get_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE, true);
        $nextOperationalStatus = $operationalStatus;
        $dnsFailureCount = (int)get_site_meta($siteId, self::META_DNS_FAILURE_COUNT, true);
        $httpFailureCount = (int)get_site_meta($siteId, self::META_HTTP_FAILURE_COUNT, true);
        $isProvisioningGrace = false;

        if (!$site instanceof \WP_Site) {
            return;
        }

        $siteUrl = get_home_url($siteId, '/');
        $host = (string)wp_parse_url($siteUrl, PHP_URL_HOST);

        update_site_meta($siteId, self::META_LAST_AVAILABILITY_CHECK, $timestamp);

        if ($host === '') {
            $dnsStatus = 'error';
            $httpStatus = 'error';
        } else {
            $dnsStatus = $this->resolveDnsStatus($host);

            if ($dnsStatus === 'ok') {
                update_site_meta($siteId, self::META_LAST_DNS_OK_AT, $timestamp);
                $httpStatus = $this->resolveHttpStatus($siteUrl);

                if ($httpStatus === 'ok') {
                    update_site_meta($siteId, self::META_LAST_HTTP_OK_AT, $timestamp);
                }
            } else {
                $httpStatus = 'pending';
            }
        }

        update_site_meta($siteId, self::META_DNS_STATUS, $dnsStatus);
        update_site_meta($siteId, self::META_HTTP_STATUS, $httpStatus);

        if ($operationalStatusSource === 'manual' || $operationalStatus === 'retired' || $operationalStatus === 'provisioning') {
            $this->updateFailureTracking($siteId, $dnsStatus, $httpStatus, $timestamp, $dnsFailureCount, $httpFailureCount);
            return;
        }

        $isProvisioningGrace = $this->isWithinProvisioningGracePeriod($site);
        $this->updateFailureTracking($siteId, $dnsStatus, $httpStatus, $timestamp, $dnsFailureCount, $httpFailureCount);
        $dnsFailureCount = (int)get_site_meta($siteId, self::META_DNS_FAILURE_COUNT, true);
        $httpFailureCount = (int)get_site_meta($siteId, self::META_HTTP_FAILURE_COUNT, true);

        if ($dnsStatus === 'ok' && $httpStatus === 'ok') {
            $nextOperationalStatus = 'healthy';
        } elseif ($isProvisioningGrace) {
            $nextOperationalStatus = 'provisioning';
        } elseif ($dnsStatus !== 'ok' && $dnsFailureCount >= $this->getDnsFailureThreshold()) {
            $nextOperationalStatus = 'dns_missing';
        } elseif ($dnsStatus === 'ok' && $httpStatus !== 'ok' && $httpFailureCount >= $this->getHttpFailureThreshold()) {
            $nextOperationalStatus = 'unreachable';
        }

        $this->updateOperationalStatus($siteId, $operationalStatus, $nextOperationalStatus, $timestamp, 'auto');
    }

    protected function updateOperationalStatus(int $siteId, string $currentStatus, string $nextStatus, string $timestamp, string $source): void {
        if ($currentStatus === $nextStatus) {
            update_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE, $source);
            return;
        }

        update_site_meta($siteId, self::META_PREVIOUS_OPERATIONAL_STATUS, $currentStatus);
        update_site_meta($siteId, self::META_OPERATIONAL_STATUS, $nextStatus);
        update_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE, $source);
        update_site_meta($siteId, self::META_OPERATIONAL_STATUS_CHANGED_AT, $timestamp);
    }

    protected function updateFailureTracking(int $siteId, string $dnsStatus, string $httpStatus, string $timestamp, int $dnsFailureCount, int $httpFailureCount): void {
        if ($dnsStatus === 'ok') {
            update_site_meta($siteId, self::META_DNS_FAILURE_COUNT, 0);
        } elseif ($dnsStatus !== 'unknown') {
            update_site_meta($siteId, self::META_DNS_FAILURE_COUNT, $dnsFailureCount + 1);
            update_site_meta($siteId, self::META_LAST_DNS_ERROR_AT, $timestamp);
        }

        if ($httpStatus === 'ok') {
            update_site_meta($siteId, self::META_HTTP_FAILURE_COUNT, 0);
        } elseif (!in_array($httpStatus, ['unknown', 'pending'], true)) {
            update_site_meta($siteId, self::META_HTTP_FAILURE_COUNT, $httpFailureCount + 1);
            update_site_meta($siteId, self::META_LAST_HTTP_ERROR_AT, $timestamp);
        }
    }

    protected function isWithinProvisioningGracePeriod(\WP_Site $site): bool {
        $registered = (string)($site->registered ?? '');
        $registeredTimestamp = 0;
        $ageSeconds = 0;
        $graceHours = $this->getProvisioningGraceHours();

        if ($graceHours <= 0 || $registered === '' || $registered === '0000-00-00 00:00:00') {
            return false;
        }

        $registeredTimestamp = strtotime($registered . ' GMT');

        if ($registeredTimestamp <= 0) {
            return false;
        }

        $ageSeconds = time() - $registeredTimestamp;

        return $ageSeconds < ($graceHours * HOUR_IN_SECONDS);
    }

    protected function resolveDnsStatus(string $host): string {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return 'ok';
        }

        if (function_exists('dns_get_record')) {
            try {
                $records = dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME);

                if (is_array($records) && !empty($records)) {
                    return 'ok';
                }
            } catch (\Throwable $exception) {
                return 'error';
            }
        }

        if (function_exists('checkdnsrr')) {
            if (checkdnsrr($host, 'A') || checkdnsrr($host, 'AAAA') || checkdnsrr($host, 'CNAME')) {
                return 'ok';
            }

            return 'missing';
        }

        return 'unknown';
    }

    protected function resolveHttpStatus(string $siteUrl): string {
        $response = wp_remote_head(
            $siteUrl,
            [
                'timeout' => 8,
                'redirection' => 5,
                'user-agent' => 'RRZE Multisite Manager Availability Monitor',
            ]
        );
        $statusCode = 0;

        if (is_wp_error($response)) {
            if ($this->isTimeoutError($response)) {
                return 'timeout';
            }

            $response = wp_remote_get(
                $siteUrl,
                [
                    'timeout' => 8,
                    'redirection' => 5,
                    'limit_response_size' => 1024,
                    'user-agent' => 'RRZE Multisite Manager Availability Monitor',
                ]
            );

            if (is_wp_error($response)) {
                if ($this->isTimeoutError($response)) {
                    return 'timeout';
                }

                return 'error';
            }
        }

        $statusCode = (int)wp_remote_retrieve_response_code($response);

        if ($statusCode >= 200 && $statusCode < 400) {
            return 'ok';
        }

        if ($statusCode === 0) {
            return 'error';
        }

        return 'error';
    }

    protected function isTimeoutError(\WP_Error $error): bool {
        $codes = (array)$error->get_error_codes();
        $code = '';
        $message = strtolower($error->get_error_message());

        foreach ($codes as $code) {
            if (in_array((string)$code, ['http_request_failed', 'connect_timeout', 'timeout'], true) && str_contains($message, 'timeout')) {
                return true;
            }
        }

        return false;
    }
}
