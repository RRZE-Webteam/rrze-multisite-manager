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
    protected const META_MONITORING_HISTORY = 'rrze_msm_monitoring_history';
    protected const OPTION_LAST_RUN = 'rrze_msm_monitoring_last_run';
    protected const OPTION_PREVIOUS_RUN = 'rrze_msm_monitoring_previous_run';
    protected const OPTION_LAST_SITE_COUNT = 'rrze_msm_monitoring_last_site_count';
    protected const OPTION_BATCH_OFFSET = 'rrze_msm_monitoring_batch_offset';
    protected const OPTION_BATCH_TOTAL = 'rrze_msm_monitoring_batch_total';
    protected const OPTION_RUN_STATE = 'rrze_msm_monitoring_run_state';
    protected const OPTION_RUN_LOG = 'rrze_msm_monitoring_run_log';
    protected const LOCK_KEY = 'rrze_msm_monitoring_lock';
    protected const LOCK_TTL = 900;
    protected const BATCH_SIZE = 20;
    protected const MAX_RUN_LOG_ENTRIES = 20;
    protected const MAX_SITE_HISTORY_ENTRIES = 10;
    protected const MAX_RUN_EVENT_ENTRIES = 12;

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
        $this->runMonitoringBatch();
    }

    public static function clearScheduledEvent(?Config $config = null): void {
        $config = $config ?? new Config();
        $hook = $config->getMonitoringHook();
        $timestamp = wp_next_scheduled($hook);

        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }

        delete_site_option(self::OPTION_BATCH_OFFSET);
        delete_site_option(self::OPTION_BATCH_TOTAL);
        delete_site_option(self::OPTION_RUN_STATE);
        delete_site_transient(self::LOCK_KEY);
    }

    public function resetMonitoringRunState(bool $clearSchedule = false): void {
        if ($clearSchedule) {
            self::clearScheduledEvent($this->config);
            $this->ensureScheduledEvent();
            return;
        }

        delete_site_option(self::OPTION_BATCH_OFFSET);
        delete_site_option(self::OPTION_BATCH_TOTAL);
        delete_site_option(self::OPTION_RUN_STATE);
        delete_site_transient(self::LOCK_KEY);
    }

    public function getProcessesOverview(): array {
        $hook = $this->config->getMonitoringHook();
        $lastRun = (string)get_site_option(self::OPTION_LAST_RUN, '');
        $lastSiteCount = (int)get_site_option(self::OPTION_LAST_SITE_COUNT, 0);
        $nextRunTimestamp = wp_next_scheduled($hook);
        $batchOffset = (int)get_site_option(self::OPTION_BATCH_OFFSET, 0);
        $batchTotal = (int)get_site_option(self::OPTION_BATCH_TOTAL, 0);
        $isRunning = $this->isMonitoringLocked();
        $runState = $this->getRunState();
        $runHistory = $this->getRunHistory();
        $lastRunEntry = !empty($runHistory[0]) && is_array($runHistory[0]) ? $runHistory[0] : [];
        $startedAtTimestamp = !empty($runState['started_at']) ? strtotime((string)$runState['started_at'] . ' GMT') : 0;
        $lastDurationSeconds = $this->calculateRunDurationSeconds(
            (string)($lastRunEntry['started_at'] ?? ''),
            (string)($lastRunEntry['finished_at'] ?? '')
        );
        $checkedSites = $isRunning ? (int)($runState['checked_sites'] ?? 0) : $lastSiteCount;
        $remainingSites = max(0, ($batchTotal > 0 ? $batchTotal : $lastSiteCount) - $checkedSites);
        $progressPercent = ($batchTotal > 0 && $checkedSites > 0)
            ? (int)round(($checkedSites / $batchTotal) * 100)
            : 0;
        $currentDurationSeconds = ($isRunning && $startedAtTimestamp > 0)
            ? max(0, time() - $startedAtTimestamp)
            : 0;
        $isStale = $this->isMonitoringRunStale($isRunning, $batchTotal, $checkedSites, $nextRunTimestamp, $currentDurationSeconds);

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
                'batch_offset' => $batchOffset,
                'batch_total' => $batchTotal,
                'checked_sites' => $checkedSites,
                'remaining_sites' => $remainingSites,
                'progress_percent' => $progressPercent,
                'is_running' => $isRunning,
                'is_stale' => $isStale,
                'batch_size' => $this->getBatchSize(),
                'current_duration_seconds' => $currentDurationSeconds,
                'last_duration_seconds' => $lastDurationSeconds,
                'run_state' => $runState,
            ],
        ];
    }

    public function getRunHistory(): array {
        $history = get_site_option(self::OPTION_RUN_LOG, []);

        return is_array($history) ? $history : [];
    }

    public function getSiteHistory(int $siteId): array {
        $history = get_site_meta($siteId, self::META_MONITORING_HISTORY, true);

        return is_array($history) ? $history : [];
    }

    public function startMonitoringRun(bool $runImmediately = true): void {
        $this->resetBatchState();
        $this->initializeRunState($runImmediately ? 'manual' : 'scheduled');

        if ($runImmediately) {
            $this->runMonitoringBatch(true);
            return;
        }

        $this->scheduleNextBatch(5);
    }

    protected function runMonitoringBatch(bool $manual = false): void {
        $siteIds = [];
        $offset = (int)get_site_option(self::OPTION_BATCH_OFFSET, 0);
        $totalSites = (int)get_site_option(self::OPTION_BATCH_TOTAL, 0);
        $siteId = 0;
        $timestamp = current_time('mysql', true);
        $lastRun = '';
        $nextOffset = 0;
        $batchSize = $this->getBatchSize();
        $runState = $this->getRunState();
        $result = [];

        if (!$this->acquireMonitoringLock()) {
            return;
        }

        if (empty($runState)) {
            $this->initializeRunState($manual ? 'manual' : 'scheduled');
            $runState = $this->getRunState();
        }

        if ($offset <= 0 || $totalSites <= 0) {
            $totalSites = (int)get_sites([
                'count' => true,
                'number' => 1,
            ]);
            update_site_option(self::OPTION_BATCH_TOTAL, $totalSites);
            $runState['total_sites'] = $totalSites;
            $this->saveRunState($runState);
            $lastRun = (string)get_site_option(self::OPTION_LAST_RUN, '');

            if ($lastRun !== '') {
                update_site_option(self::OPTION_PREVIOUS_RUN, $lastRun);
            }
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => $batchSize,
            'offset' => max(0, $offset),
            'orderby' => 'id',
            'order' => 'ASC',
        ]);

        foreach ($siteIds as $siteId) {
            $result = $this->checkSiteAvailability((int)$siteId);
            $runState = $this->applyCheckResultToRunState($runState, $result);
        }

        $this->saveRunState($runState);
        $nextOffset = $offset + count($siteIds);

        if (empty($siteIds) || $nextOffset >= $totalSites) {
            update_site_option(self::OPTION_LAST_RUN, $timestamp);
            update_site_option(self::OPTION_LAST_SITE_COUNT, $totalSites);
            $this->finalizeRunState($timestamp);
            $this->resetBatchState();
            $this->releaseMonitoringLock();
            (new MetricsService(null, $this->config))->invalidateCaches();
            return;
        }

        update_site_option(self::OPTION_BATCH_OFFSET, $nextOffset);
        update_site_option(self::OPTION_BATCH_TOTAL, $totalSites);
        $this->releaseMonitoringLock();
        $this->scheduleNextBatch($manual ? 5 : 30);
    }

    protected function getBatchSize(): int {
        return self::BATCH_SIZE;
    }

    protected function scheduleNextBatch(int $delay = 30): void {
        wp_schedule_single_event(time() + max(5, $delay), $this->config->getMonitoringHook());
    }

    protected function resetBatchState(): void {
        update_site_option(self::OPTION_BATCH_OFFSET, 0);
        update_site_option(self::OPTION_BATCH_TOTAL, 0);
    }

    protected function initializeRunState(string $trigger): void {
        $this->saveRunState([
            'started_at' => current_time('mysql', true),
            'finished_at' => '',
            'trigger' => $trigger,
            'total_sites' => 0,
            'checked_sites' => 0,
            'status_changes' => 0,
            'dns_issues' => 0,
            'http_issues' => 0,
            'healthy_sites' => 0,
            'provisioning_sites' => 0,
            'dns_missing_sites' => 0,
            'unreachable_sites' => 0,
            'changed_sites' => [],
            'issue_sites' => [],
        ]);
    }

    protected function getRunState(): array {
        $state = get_site_option(self::OPTION_RUN_STATE, []);

        return is_array($state) ? $state : [];
    }

    protected function saveRunState(array $state): void {
        update_site_option(self::OPTION_RUN_STATE, $state);
    }

    protected function finalizeRunState(string $finishedAt): void {
        $state = $this->getRunState();
        $history = $this->getRunHistory();

        if (empty($state)) {
            return;
        }

        $state['finished_at'] = $finishedAt;
        array_unshift($history, $state);
        $history = array_slice($history, 0, self::MAX_RUN_LOG_ENTRIES);
        update_site_option(self::OPTION_RUN_LOG, $history);
        delete_site_option(self::OPTION_RUN_STATE);
    }

    protected function acquireMonitoringLock(): bool {
        if ($this->isMonitoringLocked()) {
            return false;
        }

        return (bool)set_site_transient(self::LOCK_KEY, time(), self::LOCK_TTL);
    }

    protected function releaseMonitoringLock(): void {
        delete_site_transient(self::LOCK_KEY);
    }

    protected function isMonitoringLocked(): bool {
        return (int)get_site_transient(self::LOCK_KEY) > 0;
    }

    protected function isMonitoringRunStale(bool $isRunning, int $batchTotal, int $checkedSites, int $nextRunTimestamp, int $currentDurationSeconds): bool {
        if ($isRunning && $currentDurationSeconds > (self::LOCK_TTL + 120)) {
            return true;
        }

        if (!$isRunning && $batchTotal > 0 && $checkedSites < $batchTotal && $nextRunTimestamp > 0 && $nextRunTimestamp < (time() - 300)) {
            return true;
        }

        return false;
    }

    protected function calculateRunDurationSeconds(string $startedAt, string $finishedAt): int {
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

    protected function checkSiteAvailability(int $siteId): array {
        $site = get_site($siteId);
        $siteUrl = '';
        $siteLabel = '';
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
        $statusChanged = false;
        $result = [];

        if (!$site instanceof \WP_Site) {
            return [];
        }

        $siteUrl = get_home_url($siteId, '/');
        $siteLabel = $this->getSiteMonitoringLabel($site);
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
            $result = $this->buildCheckResult($siteId, $siteLabel, $siteUrl, $host, $timestamp, $dnsStatus, $httpStatus, $operationalStatus, $operationalStatus, false);
            $this->appendSiteHistory($siteId, $result);
            return $result;
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

        $statusChanged = $this->updateOperationalStatus($siteId, $operationalStatus, $nextOperationalStatus, $timestamp, 'auto');
        $result = $this->buildCheckResult($siteId, $siteLabel, $siteUrl, $host, $timestamp, $dnsStatus, $httpStatus, $operationalStatus, $nextOperationalStatus, $statusChanged);
        $this->appendSiteHistory($siteId, $result);

        return $result;
    }

    protected function getSiteMonitoringLabel(\WP_Site $site): string {
        $label = trim($site->domain . $site->path);

        if ($label !== '') {
            return $label;
        }

        return sprintf(__('Site %d', 'rrze-multisite-manager'), (int)$site->blog_id);
    }

    protected function updateOperationalStatus(int $siteId, string $currentStatus, string $nextStatus, string $timestamp, string $source): bool {
        if ($currentStatus === $nextStatus) {
            update_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE, $source);
            return false;
        }

        update_site_meta($siteId, self::META_PREVIOUS_OPERATIONAL_STATUS, $currentStatus);
        update_site_meta($siteId, self::META_OPERATIONAL_STATUS, $nextStatus);
        update_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE, $source);
        update_site_meta($siteId, self::META_OPERATIONAL_STATUS_CHANGED_AT, $timestamp);

        return true;
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

    protected function buildCheckResult(int $siteId, string $siteLabel, string $siteUrl, string $host, string $checkedAt, string $dnsStatus, string $httpStatus, string $previousStatus, string $nextStatus, bool $statusChanged): array {
        return [
            'site_id' => $siteId,
            'site_label' => $siteLabel,
            'site_url' => $siteUrl,
            'host' => $host,
            'checked_at' => $checkedAt,
            'dns_status' => $dnsStatus,
            'http_status' => $httpStatus,
            'previous_status' => $previousStatus,
            'status' => $nextStatus,
            'status_changed' => $statusChanged,
        ];
    }

    protected function appendSiteHistory(int $siteId, array $entry): void {
        $history = $this->getSiteHistory($siteId);

        if (empty($entry)) {
            return;
        }

        array_unshift($history, $entry);
        $history = array_slice($history, 0, self::MAX_SITE_HISTORY_ENTRIES);
        update_site_meta($siteId, self::META_MONITORING_HISTORY, $history);
    }

    protected function applyCheckResultToRunState(array $runState, array $result): array {
        $status = (string)($result['status'] ?? '');
        $dnsStatus = (string)($result['dns_status'] ?? '');
        $httpStatus = (string)($result['http_status'] ?? '');
        $issueKind = '';

        if (empty($runState)) {
            return $runState;
        }

        $runState['checked_sites'] = (int)($runState['checked_sites'] ?? 0) + 1;

        if (!empty($result['status_changed'])) {
            $runState['status_changes'] = (int)($runState['status_changes'] ?? 0) + 1;
            $runState = $this->appendRunStateEvent(
                $runState,
                'changed_sites',
                $this->buildRunEventEntry($result, 'status_change')
            );
        }

        if ($dnsStatus !== 'ok' && $dnsStatus !== 'unknown') {
            $runState['dns_issues'] = (int)($runState['dns_issues'] ?? 0) + 1;
            $issueKind = 'dns_issue';
        }

        if (!in_array($httpStatus, ['ok', 'unknown', 'pending'], true)) {
            $runState['http_issues'] = (int)($runState['http_issues'] ?? 0) + 1;

            if ($issueKind === '') {
                $issueKind = 'http_issue';
            }
        }

        if ($status === 'healthy') {
            $runState['healthy_sites'] = (int)($runState['healthy_sites'] ?? 0) + 1;
        } elseif ($status === 'provisioning') {
            $runState['provisioning_sites'] = (int)($runState['provisioning_sites'] ?? 0) + 1;
        } elseif ($status === 'dns_missing') {
            $runState['dns_missing_sites'] = (int)($runState['dns_missing_sites'] ?? 0) + 1;
        } elseif ($status === 'unreachable') {
            $runState['unreachable_sites'] = (int)($runState['unreachable_sites'] ?? 0) + 1;
        }

        if ($issueKind !== '') {
            $runState = $this->appendRunStateEvent(
                $runState,
                'issue_sites',
                $this->buildRunEventEntry($result, $issueKind)
            );
        }

        return $runState;
    }

    protected function appendRunStateEvent(array $runState, string $key, array $entry): array {
        $events = [];

        if (empty($entry)) {
            return $runState;
        }

        $events = isset($runState[$key]) && is_array($runState[$key]) ? $runState[$key] : [];
        array_unshift($events, $entry);
        $events = array_slice($events, 0, self::MAX_RUN_EVENT_ENTRIES);
        $runState[$key] = $events;

        return $runState;
    }

    protected function buildRunEventEntry(array $result, string $type): array {
        return [
            'type' => $type,
            'site_id' => (int)($result['site_id'] ?? 0),
            'site_label' => (string)($result['site_label'] ?? ''),
            'site_url' => (string)($result['site_url'] ?? ''),
            'host' => (string)($result['host'] ?? ''),
            'checked_at' => (string)($result['checked_at'] ?? ''),
            'dns_status' => (string)($result['dns_status'] ?? ''),
            'http_status' => (string)($result['http_status'] ?? ''),
            'previous_status' => (string)($result['previous_status'] ?? ''),
            'status' => (string)($result['status'] ?? ''),
            'status_changed' => !empty($result['status_changed']),
        ];
    }
}
