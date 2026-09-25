<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class MonitoringService {
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_OPERATIONAL_STATUS_SOURCE = 'rrze_msm_operational_status_source';
    protected const META_PREVIOUS_OPERATIONAL_STATUS = 'rrze_msm_previous_operational_status';
    protected const META_OPERATIONAL_STATUS_CHANGED_AT = 'rrze_msm_operational_status_changed_at';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_DNS_STATUS_DETAIL = 'rrze_msm_dns_status_detail';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';
    protected const META_HTTP_STATUS_DETAIL = 'rrze_msm_http_status_detail';
    protected const META_HTTP_STATUS_CODE = 'rrze_msm_http_status_code';
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
    protected const OPTION_LAST_FINALIZED_RUN_ID = 'rrze_msm_monitoring_last_finalized_run_id';
    protected const SCHEDULING_ENABLED_OPTION = 'rrze_msm_monitoring_scheduling_enabled';
    protected const LOCK_KEY = 'rrze_msm_monitoring_lock';
    protected const LOCK_OPTION = 'rrze_msm_monitoring_lock_state';
    protected const LOCK_TTL = 900;
    protected const MAX_SITE_HISTORY_ENTRIES = 10;
    protected const MAX_RUN_EVENT_ENTRIES = 12;
    protected const BATCH_EVENT_ARGS = ['rrze_msm_monitoring_batch' => true];

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
                    /* translators: %d: interval in hours for the monitoring schedule. */
                    __('Every %d hours', 'rrze-multisite-manager'),
                    $this->getMonitoringIntervalHours()
                ),
            ];
        }

        return $schedules;
    }

    public function ensureScheduledEvent(): void {
        if (!$this->isMonitoringSchedulingEnabled()) {
            return;
        }

        $hook = $this->config->getMonitoringHook();
        $nextRecurringTimestamp = $this->getNextScheduledHookTimestamp($hook, true);
        $lastRunTimestamp = strtotime((string)get_site_option(self::OPTION_LAST_RUN, '') . ' GMT');
        $minimumNextRunTimestamp = $lastRunTimestamp > 0 ? $lastRunTimestamp + $this->getMonitoringInterval() : 0;

        if ($nextRecurringTimestamp <= 0) {
            $this->scheduleRecurringEvent($this->getMonitoringInterval());
        } elseif ($minimumNextRunTimestamp > time() && $nextRecurringTimestamp < $minimumNextRunTimestamp) {
            $this->rescheduleRecurringEvent($minimumNextRunTimestamp - time());
        }

        if (
            $this->isMonitoringRunInProgress()
            && !$this->isMonitoringLocked()
            && $this->getNextScheduledHookTimestamp($hook, false) <= 0
        ) {
            $this->scheduleNextBatch(5);
        }
    }

    /**
     * Replaces only the recurring monitoring event without interrupting a running batch.
     */
    public function rescheduleRecurringEvent(?int $delay = null): void {
        $hook = $this->config->getMonitoringHook();
        $cron = _get_cron_array();

        foreach ((array)$cron as $timestamp => $events) {
            foreach ((array)($events[$hook] ?? []) as $event) {
                if (empty($event['schedule'])) {
                    continue;
                }

                wp_unschedule_event((int)$timestamp, $hook, (array)($event['args'] ?? []));
            }
        }

        $this->scheduleRecurringEvent($delay ?? $this->getMonitoringInterval());
    }

    public function runScheduledChecks(...$args): void {
        $process = [];

        if (MetricsService::isFullDataCleanupInProgress() || !$this->isMonitoringSchedulingEnabled()) {
            return;
        }

        // A one-time event marked as a batch continuation must only continue
        // an active network pass. A stale event must not silently begin a new
        // availability run before the regular interval has elapsed.
        if ($this->isMonitoringBatchContinuation($args) && !$this->isMonitoringRunInProgress()) {
            return;
        }

        LoggingService::info(
            $this->config,
            'RRZE-MSM: Monitoring-Scheduler gestartet',
            []
        );

        try {
            $this->runMonitoringBatch();
            $processes = $this->getProcessesOverview();
            $process = is_array($processes[0] ?? null) ? $processes[0] : [];
            LoggingService::info(
                $this->config,
                'RRZE-MSM: Monitoring-Scheduler beendet',
                [
                    'success' => true,
                    'checked_sites' => (int)($process['checked_sites'] ?? 0),
                    'remaining_sites' => (int)($process['remaining_sites'] ?? 0),
                    'progress_percent' => (int)($process['progress_percent'] ?? 0),
                    'is_running' => !empty($process['is_running']),
                ]
            );
        } catch (\Throwable $exception) {
            do_action(
                'rrze.log.error',
                'RRZE-MSM: Fehler beim Monitoring',
                [
                    'message' => $exception->getMessage(),
                ]
            );
            LoggingService::info(
                $this->config,
                'RRZE-MSM: Monitoring-Scheduler beendet',
                [
                    'success' => false,
                    'message' => $exception->getMessage(),
                ]
            );
        }
    }

    public static function clearScheduledEvent(?Config $config = null): int {
        $config = $config ?? new Config();
        $hook = $config->getMonitoringHook();
        $cron = _get_cron_array();
        $removed = 0;

        foreach ((array)$cron as $timestamp => $events) {
            foreach ((array)($events[$hook] ?? []) as $event) {
                if (wp_unschedule_event((int)$timestamp, $hook, (array)($event['args'] ?? []))) {
                    $removed++;
                }
            }
        }

        delete_site_option(self::OPTION_BATCH_OFFSET);
        delete_site_option(self::OPTION_BATCH_TOTAL);
        delete_site_option(self::OPTION_RUN_STATE);
        delete_site_option(self::LOCK_OPTION);
        delete_site_transient(self::LOCK_KEY);

        return $removed;
    }

    /**
     * Stops availability monitoring and disables all automatic rescheduling.
     */
    public function disableMonitoringScheduling(): int {
        return self::disableScheduledChecks($this->config);
    }

    /**
     * Disables automatic availability monitoring without requiring a service instance.
     */
    public static function disableScheduledChecks(?Config $config = null): int {
        update_site_option(self::SCHEDULING_ENABLED_OPTION, 0);

        return self::clearScheduledEvent($config);
    }

    public function resetMonitoringRunState(bool $clearSchedule = false): void {
        if ($clearSchedule) {
            self::clearScheduledEvent($this->config);
            $this->ensureScheduledEvent();
            return;
        }

        $this->clearPendingBatchEvents();
        delete_site_option(self::OPTION_BATCH_OFFSET);
        delete_site_option(self::OPTION_BATCH_TOTAL);
        delete_site_option(self::OPTION_RUN_STATE);
        delete_site_option(self::LOCK_OPTION);
        delete_site_transient(self::LOCK_KEY);
    }

    public function getProcessesOverview(): array {
        $hook = $this->config->getMonitoringHook();
        $lastRun = (string)get_site_option(self::OPTION_LAST_RUN, '');
        $lastSiteCount = (int)get_site_option(self::OPTION_LAST_SITE_COUNT, 0);
        $batchOffset = (int)get_site_option(self::OPTION_BATCH_OFFSET, 0);
        $batchTotal = (int)get_site_option(self::OPTION_BATCH_TOTAL, 0);
        $isExecuting = $this->isMonitoringLocked();
        $runState = $this->getRunState();
        $runHistory = $this->getRunHistory();
        $lastRunEntry = !empty($runHistory[0]) && is_array($runHistory[0]) ? $runHistory[0] : [];
        $lastStartedAt = (string)($lastRunEntry['started_at'] ?? '');
        $lastFinishedAt = (string)($lastRunEntry['finished_at'] ?? $lastRun);

        if ($lastFinishedAt === '') {
            $lastFinishedAt = $lastRun;
        }
        $startedAtTimestamp = !empty($runState['started_at']) ? strtotime((string)$runState['started_at'] . ' GMT') : 0;
        $lastDurationSeconds = $this->calculateRunDurationSeconds(
            (string)($lastRunEntry['started_at'] ?? ''),
            (string)($lastRunEntry['finished_at'] ?? '')
        );
        $hasOpenBatch = $batchTotal > 0 && $batchOffset < $batchTotal;
        $isRunning = $isExecuting || ($hasOpenBatch && !empty($runState));
        $checkedSites = $hasOpenBatch
            ? max($batchOffset, (int)($runState['checked_sites'] ?? 0))
            : $lastSiteCount;
        $remainingSites = max(0, ($batchTotal > 0 ? $batchTotal : $lastSiteCount) - $checkedSites);
        $progressPercent = ($batchTotal > 0 && $checkedSites > 0)
            ? (int)round(($checkedSites / $batchTotal) * 100)
            : 0;
        $currentDurationSeconds = ($isRunning && $startedAtTimestamp > 0)
            ? max(0, time() - $startedAtTimestamp)
            : 0;
        $hasOpenBatch = $batchTotal > 0 && $checkedSites < $batchTotal;
        $nextRecurringRunTimestamp = $this->getNextScheduledHookTimestamp($hook, true);
        $nextBatchRunTimestamp = $this->getNextScheduledHookTimestamp($hook, false);
        $nextProgressRunTimestamp = $hasOpenBatch && $nextBatchRunTimestamp > 0
            ? $nextBatchRunTimestamp
            : $nextRecurringRunTimestamp;
        $isStale = $this->isMonitoringRunStale($isExecuting, $batchTotal, $checkedSites, $nextProgressRunTimestamp, $currentDurationSeconds);

        return [
            [
                'id' => 'site-availability',
                'title' => __('Website availability', 'rrze-multisite-manager'),
                'description' => __('Checks DNS and HTTP reachability of all active websites and updates the plugin\'s monitoring meta fields.', 'rrze-multisite-manager'),
                'interval_hours' => $this->getMonitoringIntervalHours(),
                'provisioning_grace_hours' => $this->getProvisioningGraceHours(),
                'dns_failure_threshold' => $this->getDnsFailureThreshold(),
                'http_failure_threshold' => $this->getHttpFailureThreshold(),
                'last_run' => $lastRun,
                'started_at' => $isRunning ? (string)($runState['started_at'] ?? '') : $lastStartedAt,
                'finished_at' => $isRunning ? '' : $lastFinishedAt,
                'last_site_count' => $lastSiteCount,
                // The table displays the next complete run, never a batch continuation.
                'next_run_timestamp' => $nextRecurringRunTimestamp ? (int)$nextRecurringRunTimestamp : 0,
                'next_recurring_run_timestamp' => $nextRecurringRunTimestamp,
                'next_batch_run_timestamp' => $nextBatchRunTimestamp,
                'batch_offset' => $batchOffset,
                'batch_total' => $batchTotal,
                'checked_sites' => $checkedSites,
                'remaining_sites' => $remainingSites,
                'progress_percent' => $progressPercent,
                'is_running' => $isRunning,
                'is_stale' => $isStale,
                'batch_size' => $this->getBatchSize(),
                'current_duration_seconds' => $currentDurationSeconds,
                'last_duration_seconds' => $isRunning ? 0 : $lastDurationSeconds,
                'run_state' => $runState,
            ],
        ];
    }

    public function getRunHistory(): array {
        $history = get_site_option(self::OPTION_RUN_LOG, []);

        if (!is_array($history)) {
            return [];
        }

        $deduplicatedHistory = $this->deduplicateRunHistory($history);

        if (count($deduplicatedHistory) !== count($history)) {
            update_site_option(self::OPTION_RUN_LOG, $deduplicatedHistory);
        }

        return $deduplicatedHistory;
    }

    public function getSiteHistory(int $siteId): array {
        $history = get_site_meta($siteId, self::META_MONITORING_HISTORY, true);

        return is_array($history) ? $history : [];
    }

    public function startMonitoringRun(bool $runImmediately = true): void {
        if (MetricsService::isFullDataCleanupInProgress()) {
            return;
        }

        $this->enableMonitoringScheduling();

        if ($this->isMonitoringLocked() || $this->isMonitoringRunInProgress()) {
            $this->scheduleNextBatch(5);
            return;
        }

        $this->clearPendingBatchEvents();
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
            $totalSites = (int)get_sites($this->getActiveSiteQueryArgs([
                'count' => true,
                'number' => 1,
            ]));
            update_site_option(self::OPTION_BATCH_TOTAL, $totalSites);
            $runState['total_sites'] = $totalSites;
            $this->saveRunState($runState);
            $lastRun = (string)get_site_option(self::OPTION_LAST_RUN, '');

            if ($lastRun !== '') {
                update_site_option(self::OPTION_PREVIOUS_RUN, $lastRun);
            }
        }

        $siteIds = get_sites($this->getActiveSiteQueryArgs([
            'fields' => 'ids',
            'number' => $batchSize,
            'offset' => max(0, $offset),
            'orderby' => 'id',
            'order' => 'ASC',
        ]));

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
            $this->rescheduleRecurringEvent($this->getMonitoringInterval());
            (new MetricsService(null, $this->config))->invalidateCaches();
            return;
        }

        update_site_option(self::OPTION_BATCH_OFFSET, $nextOffset);
        update_site_option(self::OPTION_BATCH_TOTAL, $totalSites);
        $this->releaseMonitoringLock();
        $this->scheduleNextBatch($manual ? 5 : 30);
    }

    protected function getBatchSize(): int {
        return $this->config->getMonitoringBatchSize();
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    protected function getActiveSiteQueryArgs(array $args = []): array {
        return array_merge(
            [
                'archived' => 0,
                'spam' => 0,
                'deleted' => 0,
            ],
            $args
        );
    }

    protected function scheduleNextBatch(int $delay = 30): void {
        if (!$this->isMonitoringSchedulingEnabled()) {
            return;
        }

        $hook = $this->config->getMonitoringHook();

        if ($this->getNextScheduledHookTimestamp($hook, false) > 0) {
            return;
        }

        wp_schedule_single_event(
            time() + max(5, $delay),
            $hook,
            self::BATCH_EVENT_ARGS
        );
    }

    /**
     * Determines whether the current cron invocation is an internal batch continuation.
     *
     * WordPress passes cron event arguments as individual action arguments, so the
     * associative marker stored in the event arrives here as its boolean value.
     *
     * @param array<int, mixed> $args Cron action arguments.
     */
    protected function isMonitoringBatchContinuation(array $args): bool {
        return in_array(true, $args, true);
    }

    protected function scheduleRecurringEvent(int $delay): void {
        if (!$this->isMonitoringSchedulingEnabled()) {
            return;
        }

        $hook = $this->config->getMonitoringHook();

        if ($this->getNextScheduledHookTimestamp($hook, true) > 0) {
            return;
        }

        wp_schedule_event(
            time() + max(MINUTE_IN_SECONDS, $delay),
            $this->config->getMonitoringScheduleSlug(),
            $hook
        );
    }

    protected function resetBatchState(): void {
        update_site_option(self::OPTION_BATCH_OFFSET, 0);
        update_site_option(self::OPTION_BATCH_TOTAL, 0);
    }

    protected function initializeRunState(string $trigger): void {
        $this->saveRunState([
            'run_id' => wp_generate_uuid4(),
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

        $runId = (string)($state['run_id'] ?? '');

        if ($runId === '') {
            $runId = md5(serialize([
                $state['started_at'] ?? '',
                $state['trigger'] ?? '',
                $state['total_sites'] ?? 0,
            ]));
        }

        if (hash_equals((string)get_site_option(self::OPTION_LAST_FINALIZED_RUN_ID, ''), $runId)) {
            delete_site_option(self::OPTION_RUN_STATE);
            return;
        }

        $state['finished_at'] = $finishedAt;
        $state['run_id'] = $runId;
        array_unshift($history, $state);
        $history = array_slice($this->deduplicateRunHistory($history), 0, $this->getRunLogEntryLimit());
        update_site_option(self::OPTION_RUN_LOG, $history);
        update_site_option(self::OPTION_LAST_FINALIZED_RUN_ID, $runId);
        delete_site_option(self::OPTION_RUN_STATE);
    }

    /**
     * @param array<int, mixed> $history
     * @return array<int, array<string, mixed>>
     */
    protected function deduplicateRunHistory(array $history): array {
        $deduplicated = [];
        $seen = [];

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $signature = md5(wp_json_encode($entry));

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $deduplicated[] = $entry;
        }

        return $deduplicated;
    }

    protected function acquireMonitoringLock(): bool {
        $timestamp = time();

        if (add_site_option(self::LOCK_OPTION, $timestamp)) {
            return true;
        }

        $existingLock = (int)get_site_option(self::LOCK_OPTION, 0);

        if ($existingLock > 0 && ($timestamp - $existingLock) < self::LOCK_TTL) {
            return false;
        }

        delete_site_option(self::LOCK_OPTION);

        return add_site_option(self::LOCK_OPTION, $timestamp);
    }

    protected function releaseMonitoringLock(): void {
        delete_site_option(self::LOCK_OPTION);
        delete_site_transient(self::LOCK_KEY);
    }

    protected function isMonitoringLocked(): bool {
        $timestamp = (int)get_site_option(self::LOCK_OPTION, 0);

        if ($timestamp <= 0) {
            return false;
        }

        if ((time() - $timestamp) >= self::LOCK_TTL) {
            delete_site_option(self::LOCK_OPTION);
            return false;
        }

        return true;
    }

    public function isMonitoringRunInProgress(): bool {
        $offset = (int)get_site_option(self::OPTION_BATCH_OFFSET, 0);
        $total = (int)get_site_option(self::OPTION_BATCH_TOTAL, 0);

        return $total > 0 && $offset < $total && !empty($this->getRunState());
    }

    protected function getNextScheduledHookTimestamp(string $hook, bool $recurring): int {
        $cron = _get_cron_array();
        $timestamp = 0;
        $events = [];
        $eventData = [];
        $signature = '';

        if (!is_array($cron) || empty($cron)) {
            return 0;
        }

        foreach ($cron as $timestamp => $events) {
            if (!is_numeric($timestamp) || empty($events[$hook]) || !is_array($events[$hook])) {
                continue;
            }

            foreach ($events[$hook] as $signature => $eventData) {
                $hasSchedule = !empty($eventData['schedule']);

                if ($recurring !== $hasSchedule) {
                    continue;
                }

                return (int)$timestamp;
            }
        }

        return 0;
    }

    protected function clearPendingBatchEvents(): void {
        $hook = $this->config->getMonitoringHook();
        $cron = _get_cron_array();
        $timestamp = 0;
        $events = [];
        $eventData = [];
        $signature = '';

        if (!is_array($cron) || empty($cron)) {
            return;
        }

        foreach ($cron as $timestamp => $events) {
            if (!is_numeric($timestamp) || empty($events[$hook]) || !is_array($events[$hook])) {
                continue;
            }

            foreach ($events[$hook] as $signature => $eventData) {
                if (!empty($eventData['schedule'])) {
                    continue;
                }

                wp_unschedule_event((int)$timestamp, $hook, is_array($eventData['args'] ?? null) ? $eventData['args'] : []);
            }
        }
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

    protected function getRunLogEntryLimit(): int {
        return max(5, min(200, $this->getMonitoringOption('monitoring_run_log_entries', 20)));
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

    protected function enableMonitoringScheduling(): void {
        update_site_option(self::SCHEDULING_ENABLED_OPTION, 1);
    }

    protected function isMonitoringSchedulingEnabled(): bool {
        return (bool)get_site_option(self::SCHEDULING_ENABLED_OPTION, false);
    }

    protected function checkSiteAvailability(int $siteId): array {
        $site = get_site($siteId);
        $siteUrl = '';
        $siteLabel = '';
        $host = '';
        $dnsData = [];
        $httpData = [];
        $dnsStatus = 'unknown';
        $httpStatus = 'unknown';
        $dnsStatusDetail = '';
        $httpStatusDetail = '';
        $httpStatusCode = 0;
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

        if (!$this->isActiveSite($site)) {
            (new StorageAnalysisSchedulerService(new MetricsService(null, $this->config), $this->config))
                ->deactivateIneligibleSite($siteId);
            (new ShortcodeBlockAnalysisSchedulerService($this->config))->deactivateSite($siteId);
            return [];
        }

        $siteUrl = get_home_url($siteId, '/');
        $siteLabel = $this->getSiteMonitoringLabel($site);
        $host = (string)wp_parse_url($siteUrl, PHP_URL_HOST);

        update_site_meta($siteId, self::META_LAST_AVAILABILITY_CHECK, $timestamp);

        if ($host === '') {
            $dnsStatus = 'error';
            $httpStatus = 'error';
            $dnsStatusDetail = __('No host could be determined from the site URL.', 'rrze-multisite-manager');
            $httpStatusDetail = __('HTTP check skipped because no host could be determined from the site URL.', 'rrze-multisite-manager');
        } else {
            $dnsData = $this->resolveDnsStatus($host);
            $dnsStatus = (string)($dnsData['status'] ?? 'unknown');
            $dnsStatusDetail = (string)($dnsData['detail'] ?? '');

            if ($dnsStatus === 'ok') {
                update_site_meta($siteId, self::META_LAST_DNS_OK_AT, $timestamp);
                $httpData = $this->resolveHttpStatus($siteUrl);
                $httpStatus = (string)($httpData['status'] ?? 'unknown');
                $httpStatusDetail = (string)($httpData['detail'] ?? '');
                $httpStatusCode = (int)($httpData['code'] ?? 0);

                if ($httpStatus === 'ok') {
                    update_site_meta($siteId, self::META_LAST_HTTP_OK_AT, $timestamp);
                }
            } else {
                $httpStatus = 'pending';
                $httpStatusDetail = __('HTTP check skipped because DNS resolution failed.', 'rrze-multisite-manager');
            }
        }

        update_site_meta($siteId, self::META_DNS_STATUS, $dnsStatus);
        update_site_meta($siteId, self::META_DNS_STATUS_DETAIL, $dnsStatusDetail);
        update_site_meta($siteId, self::META_HTTP_STATUS, $httpStatus);
        update_site_meta($siteId, self::META_HTTP_STATUS_DETAIL, $httpStatusDetail);
        update_site_meta($siteId, self::META_HTTP_STATUS_CODE, $httpStatusCode);

        if ($operationalStatusSource === 'manual' || $operationalStatus === 'retired' || $operationalStatus === 'provisioning') {
            $this->updateFailureTracking($siteId, $dnsStatus, $httpStatus, $timestamp, $dnsFailureCount, $httpFailureCount);
            $result = $this->buildCheckResult($siteId, $siteLabel, $siteUrl, $host, $timestamp, $dnsStatus, $httpStatus, $dnsStatusDetail, $httpStatusDetail, $httpStatusCode, $operationalStatus, $operationalStatus, false);
            $this->logMonitoringWarning($result);
            $this->appendSiteHistory($siteId, $result);
            $this->reconcileStorageAnalysisSchedule($siteId);
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
        $result = $this->buildCheckResult($siteId, $siteLabel, $siteUrl, $host, $timestamp, $dnsStatus, $httpStatus, $dnsStatusDetail, $httpStatusDetail, $httpStatusCode, $operationalStatus, $nextOperationalStatus, $statusChanged);
        $this->logMonitoringWarning($result);
        $this->appendSiteHistory($siteId, $result);
        $this->reconcileStorageAnalysisSchedule($siteId);

        return $result;
    }

    protected function reconcileStorageAnalysisSchedule(int $siteId): void {
        $storageScheduler = new StorageAnalysisSchedulerService(new MetricsService(null, $this->config), $this->config);
        $storageScheduler->reconcileSiteSchedule($siteId);

        $shortcodeBlockScheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $shortcodeBlockScheduler->reconcileSiteSchedule($siteId);
    }

    protected function getSiteMonitoringLabel(\WP_Site $site): string {
        $label = trim($site->domain . $site->path);

        if ($label !== '') {
            return $label;
        }

        return sprintf(
            /* translators: %d: site ID. */
            __('Site %d', 'rrze-multisite-manager'),
            (int)$site->blog_id
        );
    }

    protected function isActiveSite(\WP_Site $site): bool {
        return (int)$site->archived === 0
            && (int)$site->spam === 0
            && (int)$site->deleted === 0;
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

    protected function logMonitoringWarning(array $result): void {
        $dnsStatus = (string)($result['dns_status'] ?? 'unknown');
        $httpStatus = (string)($result['http_status'] ?? 'unknown');
        $hasDnsIssue = !in_array($dnsStatus, ['ok', 'unknown'], true);
        $hasHttpIssue = !in_array($httpStatus, ['ok', 'unknown', 'pending'], true);

        if (!$hasDnsIssue && !$hasHttpIssue) {
            return;
        }

        LoggingService::warning(
            'RRZE-MSM: Auffälligkeit beim Website-Monitoring',
            [
                'site_id' => (int)($result['site_id'] ?? 0),
                'site_label' => (string)($result['site_label'] ?? ''),
                'site_url' => (string)($result['site_url'] ?? ''),
                'host' => (string)($result['host'] ?? ''),
                'checked_at' => (string)($result['checked_at'] ?? ''),
                'dns_status' => $dnsStatus,
                'dns_detail' => (string)($result['dns_status_detail'] ?? ''),
                'http_status' => $httpStatus,
                'http_status_code' => (int)($result['http_status_code'] ?? 0),
                'http_detail' => (string)($result['http_status_detail'] ?? ''),
                'previous_operational_status' => (string)($result['previous_status'] ?? ''),
                'operational_status' => (string)($result['status'] ?? ''),
                'status_changed' => !empty($result['status_changed']),
            ]
        );
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

    protected function resolveDnsStatus(string $host): array {
        $records = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [
                'status' => 'ok',
                'detail' => sprintf(
                    /* translators: %s: IP address. */
                    __('Direct IP address: %s', 'rrze-multisite-manager'),
                    $host
                ),
            ];
        }

        if (function_exists('dns_get_record')) {
            try {
                $records = dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME);

                if (is_array($records) && !empty($records)) {
                    return [
                        'status' => 'ok',
                        'detail' => $this->formatDnsRecordDetails($records),
                    ];
                }
            } catch (\Throwable $exception) {
                return [
                    'status' => 'error',
                    'detail' => $this->normalizeStatusDetail($exception->getMessage(), __('DNS lookup failed.', 'rrze-multisite-manager')),
                ];
            }
        }

        if (function_exists('checkdnsrr')) {
            if (checkdnsrr($host, 'A') || checkdnsrr($host, 'AAAA') || checkdnsrr($host, 'CNAME')) {
                return [
                    'status' => 'ok',
                    'detail' => __('DNS record found.', 'rrze-multisite-manager'),
                ];
            }

            return [
                'status' => 'missing',
                'detail' => __('No A, AAAA, or CNAME record found.', 'rrze-multisite-manager'),
            ];
        }

        return [
            'status' => 'unknown',
            'detail' => __('DNS checks are not available on this server.', 'rrze-multisite-manager'),
        ];
    }

    protected function resolveHttpStatus(string $siteUrl): array {
        $response = wp_remote_head(
            $siteUrl,
            [
                'timeout' => 8,
                'redirection' => 5,
                'user-agent' => $this->config->getMonitoringUserAgent(),
            ]
        );
        $statusCode = 0;

        if (is_wp_error($response)) {
            if ($this->isTimeoutError($response)) {
                return [
                    'status' => 'timeout',
                    'code' => 0,
                    'detail' => $this->formatWpErrorDetail($response, 'HEAD'),
                ];
            }

            $response = wp_remote_get(
                $siteUrl,
                [
                    'timeout' => 8,
                    'redirection' => 5,
                    'limit_response_size' => 1024,
                    'user-agent' => $this->config->getMonitoringUserAgent(),
                ]
            );

            if (is_wp_error($response)) {
                if ($this->isTimeoutError($response)) {
                    return [
                        'status' => 'timeout',
                        'code' => 0,
                        'detail' => $this->formatWpErrorDetail($response, 'GET'),
                    ];
                }

                return [
                    'status' => 'error',
                    'code' => 0,
                    'detail' => $this->formatWpErrorDetail($response, 'GET'),
                ];
            }

            return $this->buildHttpResultFromResponse($response, 'GET');
        }

        $statusCode = (int)wp_remote_retrieve_response_code($response);

        if ($statusCode >= 400) {
            $response = wp_remote_get(
                $siteUrl,
                [
                    'timeout' => 8,
                    'redirection' => 5,
                    'limit_response_size' => 1024,
                    'user-agent' => $this->config->getMonitoringUserAgent(),
                ]
            );

            if (!is_wp_error($response)) {
                return $this->buildHttpResultFromResponse($response, 'GET');
            }

            if ($this->isTimeoutError($response)) {
                return [
                    'status' => 'timeout',
                    'code' => 0,
                    'detail' => $this->formatWpErrorDetail($response, 'GET'),
                ];
            }

            return [
                'status' => 'error',
                'code' => $statusCode,
                'detail' => $this->formatWpErrorDetail($response, 'GET'),
            ];
        }

        return $this->buildHttpResultFromResponse($response, 'HEAD');
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

    protected function buildCheckResult(int $siteId, string $siteLabel, string $siteUrl, string $host, string $checkedAt, string $dnsStatus, string $httpStatus, string $dnsStatusDetail, string $httpStatusDetail, int $httpStatusCode, string $previousStatus, string $nextStatus, bool $statusChanged): array {
        return [
            'site_id' => $siteId,
            'site_label' => $siteLabel,
            'site_url' => $siteUrl,
            'host' => $host,
            'checked_at' => $checkedAt,
            'dns_status' => $dnsStatus,
            'dns_status_detail' => $dnsStatusDetail,
            'http_status' => $httpStatus,
            'http_status_detail' => $httpStatusDetail,
            'http_status_code' => $httpStatusCode,
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
            'dns_status_detail' => (string)($result['dns_status_detail'] ?? ''),
            'http_status' => (string)($result['http_status'] ?? ''),
            'http_status_detail' => (string)($result['http_status_detail'] ?? ''),
            'http_status_code' => (int)($result['http_status_code'] ?? 0),
            'previous_status' => (string)($result['previous_status'] ?? ''),
            'status' => (string)($result['status'] ?? ''),
            'status_changed' => !empty($result['status_changed']),
        ];
    }

    protected function formatDnsRecordDetails(array $records): string {
        $targets = [];
        $record = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            if (!empty($record['ip'])) {
                $targets[] = 'A ' . (string)$record['ip'];
                continue;
            }

            if (!empty($record['ipv6'])) {
                $targets[] = 'AAAA ' . (string)$record['ipv6'];
                continue;
            }

            if (!empty($record['target'])) {
                $targets[] = 'CNAME ' . (string)$record['target'];
            }
        }

        $targets = array_values(array_unique(array_filter($targets)));

        if (empty($targets)) {
            return __('DNS record found.', 'rrze-multisite-manager');
        }

        return implode(', ', $targets);
    }

    protected function buildHttpResultFromResponse($response, string $method): array {
        $statusCode = (int)wp_remote_retrieve_response_code($response);
        $responseMessage = trim((string)wp_remote_retrieve_response_message($response));
        $detailParts = [];

        if ($method !== '') {
            $detailParts[] = $method;
        }

        if ($statusCode > 0) {
            $detailParts[] = (string)$statusCode;
        }

        if ($responseMessage !== '') {
            $detailParts[] = $responseMessage;
        }

        if ($statusCode >= 200 && $statusCode < 500) {
            return [
                'status' => 'ok',
                'code' => $statusCode,
                'detail' => implode(' ', $detailParts),
            ];
        }

        if ($statusCode === 0) {
            return [
                'status' => 'error',
                'code' => 0,
                'detail' => __('No HTTP status code received.', 'rrze-multisite-manager'),
            ];
        }

        return [
            'status' => 'error',
            'code' => $statusCode,
            'detail' => implode(' ', $detailParts),
        ];
    }

    protected function formatWpErrorDetail(\WP_Error $error, string $method): string {
        $code = (string)$error->get_error_code();
        $message = trim((string)$error->get_error_message());
        $parts = [];

        if ($method !== '') {
            $parts[] = $method;
        }

        if ($code !== '') {
            $parts[] = $code;
        }

        if ($message !== '') {
            $parts[] = $message;
        }

        return $this->normalizeStatusDetail(
            implode(': ', array_filter($parts)),
            __('HTTP request failed.', 'rrze-multisite-manager')
        );
    }

    protected function normalizeStatusDetail(string $value, string $fallback = ''): string {
        $value = sanitize_text_field($value);

        if ($value === '') {
            return $fallback;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 250);
        }

        return substr($value, 0, 250);
    }
}
