<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class StorageAnalysisSchedulerService {
    protected const OPTION_STATUS = 'rrze_msm_storage_analysis_scheduler_status';
    protected const BASE_PHASE = 'base';
    protected const ORPHAN_PHASE = 'orphan';
    protected const METADATA_PHASE = 'metadata';
    protected const SCHEDULED_PHASE = 'scheduled';
    protected const ACTIVE_PHASE = 'active';
    protected const SCHEDULE_SIGNATURE_OPTION = 'rrze_msm_storage_analysis_schedule_signature';
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_storage_analysis_lock_';
    protected const CANCEL_TRANSIENT_PREFIX = 'rrze_msm_storage_analysis_cancel_';
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';
    protected const LOCK_TTL = 30 * MINUTE_IN_SECONDS;

    protected MetricsService $metrics;
    protected Config $config;

    public function __construct(MetricsService $metrics, ?Config $config = null) {
        $this->metrics = $metrics;
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action($this->config->getStorageAnalysisHook(), [$this, 'runScheduledAnalysis'], 10, 2);
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureRecurringSchedules'], 20);
        add_action('wpmu_new_blog', [$this, 'scheduleNewSiteRecurringAnalysis'], 20, 1);
    }

    public function registerSchedules(array $schedules): array {
        $schedules['rrze_msm_storage_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Once weekly', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_storage_twice_weekly'] = [
            'interval' => (int)(WEEK_IN_SECONDS / 2),
            'display' => __('Twice weekly', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_storage_daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display' => __('Once daily', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_storage_twice_daily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Twice daily', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_storage_four_times_daily'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Four times daily', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_storage_active_batch'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Storage analysis batch', 'rrze-multisite-manager'),
        ];

        return $schedules;
    }

    public function ensureRecurringSchedules(): void {
        $signature = $this->getScheduleSignature();

        if ((string)get_site_option(self::SCHEDULE_SIGNATURE_OPTION, '') === $signature) {
            return;
        }

        $this->syncRecurringSchedules();
    }

    public function syncRecurringSchedules(): void {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteEligible($siteId)) {
                $this->deactivateIneligibleSite($siteId);
                continue;
            }

            $this->unschedule($siteId, self::SCHEDULED_PHASE);
            // Remove continuation events created by releases before recurring batch tasks.
            $this->unschedule($siteId, self::BASE_PHASE);
            $this->unschedule($siteId, self::ORPHAN_PHASE);

            if ($this->isSiteRunRunning((int)$siteId, $this->metrics->getSiteStorageAnalysisProcessStatus((int)$siteId))) {
                $this->ensureActiveBatchSchedule((int)$siteId);
            }
        }

        $this->scheduleRecurringAnalyses($this->getEligibleSiteIds($siteIds));

        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
    }

    public function resetAllSiteSchedules(): int {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            $this->requestCancellation($siteId);
            $this->unschedule($siteId, self::BASE_PHASE);
            $this->unschedule($siteId, self::ORPHAN_PHASE);
            $this->unschedule($siteId, self::SCHEDULED_PHASE);
            $this->unschedule($siteId, self::ACTIVE_PHASE);

            if (!$this->isSiteEligible($siteId)) {
                $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
            }
        }

        $eligibleSiteIds = $this->getEligibleSiteIds($siteIds);
        $this->scheduleRecurringAnalyses($eligibleSiteIds);
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return count($eligibleSiteIds);
    }

    public function requestCancellation(int $siteId): bool {
        $status = [];

        if ($siteId <= 0 || !get_site($siteId)) {
            return false;
        }

        $status = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if (!$this->isSiteRunRunning($siteId, $status)) {
            return false;
        }

        set_site_transient(
            $this->getCancellationTransientKey($siteId),
            [
                'requested_at' => current_time('mysql', true),
            ],
            self::LOCK_TTL
        );

        return true;
    }

    public function scheduleNewSiteRecurringAnalysis(int $siteId): void {
        if (!$this->isSiteEligible($siteId)) {
            return;
        }

        $this->scheduleRecurringAnalysis($siteId);
    }

    public function isSiteEligible(int $siteId): bool {
        $site = $siteId > 0 ? get_site($siteId) : null;
        $isActive = false;
        $isArchived = false;
        $operationalStatus = '';
        $dnsStatus = '';
        $httpStatus = '';

        if (!$site instanceof \WP_Site) {
            return false;
        }

        $isActive = (int)$site->archived === 0 && (int)$site->spam === 0 && (int)$site->deleted === 0;
        $isArchived = (int)$site->archived === 1 && (int)$site->spam === 0 && (int)$site->deleted === 0;

        if (!$isActive && !$isArchived) {
            return false;
        }

        $operationalStatus = (string)get_site_meta($siteId, self::META_OPERATIONAL_STATUS, true);
        $dnsStatus = (string)get_site_meta($siteId, self::META_DNS_STATUS, true);
        $httpStatus = (string)get_site_meta($siteId, self::META_HTTP_STATUS, true);

        if (in_array($operationalStatus, ['provisioning', 'retired', 'dns_missing', 'unreachable'], true)) {
            return false;
        }

        if (!in_array($dnsStatus, ['', 'ok', 'unknown'], true)) {
            return false;
        }

        return in_array($httpStatus, ['', 'ok', 'unknown', 'pending'], true);
    }

    public function deactivateIneligibleSite(int $siteId): bool {
        if ($siteId <= 0 || $this->isSiteEligible($siteId)) {
            return false;
        }

        $this->unschedule($siteId, self::BASE_PHASE);
        $this->unschedule($siteId, self::ORPHAN_PHASE);
        $this->unschedule($siteId, self::ACTIVE_PHASE);
        $this->unschedule($siteId, self::SCHEDULED_PHASE);
        delete_site_transient($this->getCancellationTransientKey($siteId));
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);

        return true;
    }

    public static function clearScheduledEvents(?Config $config = null): void {
        $config = $config ?? new Config();
        $cron = _get_cron_array();
        $timestamp = 0;
        $events = [];
        $event = [];

        if (!is_array($cron)) {
            return;
        }

        foreach ($cron as $timestamp => $events) {
            if (!is_array($events) || empty($events[$config->getStorageAnalysisHook()])) {
                continue;
            }

            foreach ($events[$config->getStorageAnalysisHook()] as $event) {
                wp_unschedule_event((int)$timestamp, $config->getStorageAnalysisHook(), (array)($event['args'] ?? []));
            }
        }

        delete_site_option(self::SCHEDULE_SIGNATURE_OPTION);
    }

    public function startAnalysisNow(int $siteId): bool {
        $status = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if (!$this->isSiteEligible($siteId) || $this->isSiteRunRunning($siteId, $status)) {
            $this->deactivateIneligibleSite($siteId);
            return false;
        }

        $this->unschedule($siteId, self::BASE_PHASE);
        $this->unschedule($siteId, self::ORPHAN_PHASE);
        $this->unschedule($siteId, self::ACTIVE_PHASE);
        $this->unschedule($siteId, self::SCHEDULED_PHASE);
        delete_site_transient($this->getCancellationTransientKey($siteId));
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->scheduleRecurringAnalysisAt($siteId, time());

        return true;
    }

    public function getStatus(int $siteId): array {
        $activeTimestamp = $this->getNextScheduledTimestamp($siteId, self::ACTIVE_PHASE);
        $recurringTimestamp = $this->getNextRecurringScheduledTimestamp($siteId);
        $nextTimestamp = 0;
        $nextPhase = '';
        $storedStatus = $siteId > 0 ? get_blog_option($siteId, self::OPTION_STATUS, []) : [];

        if ($activeTimestamp > 0) {
            $nextTimestamp = $activeTimestamp;
            $nextPhase = self::ACTIVE_PHASE;
        }

        if ($recurringTimestamp > 0 && ($nextTimestamp <= 0 || $recurringTimestamp < $nextTimestamp)) {
            $nextTimestamp = $recurringTimestamp;
            $nextPhase = self::SCHEDULED_PHASE;
        }

        return [
            'next_run_timestamp' => $nextTimestamp,
            'next_phase' => $nextPhase,
            'next_recurring_run_timestamp' => $recurringTimestamp,
            'last_started_at' => is_array($storedStatus) ? (string)($storedStatus['last_started_at'] ?? '') : '',
            'last_finished_at' => is_array($storedStatus) ? (string)($storedStatus['last_finished_at'] ?? '') : '',
            'last_duration_seconds' => is_array($storedStatus) ? max(0, (int)($storedStatus['last_duration_seconds'] ?? 0)) : 0,
            'last_was_aborted' => is_array($storedStatus) && !empty($storedStatus['last_was_aborted']),
            'last_error' => is_array($storedStatus) ? (string)($storedStatus['last_error'] ?? '') : '',
            'is_running' => is_array($storedStatus) && !empty($storedStatus['is_running']),
            'metadata_pending' => is_array($storedStatus) && !empty($storedStatus['metadata_pending']),
            'metadata_started' => is_array($storedStatus) && !empty($storedStatus['metadata_started']),
        ];
    }

    public function runScheduledAnalysis(int $siteId = 0, string $phase = self::BASE_PHASE): void {
        if (!$this->isSiteEligible($siteId)) {
            $this->deactivateIneligibleSite($siteId);
            return;
        }

        if (!$this->acquireSiteLock($siteId)) {
            return;
        }

        try {
            $this->runScheduledAnalysisLocked($siteId, $phase);
        } finally {
            $this->releaseSiteLock($siteId);
        }
    }

    protected function runScheduledAnalysisLocked(int $siteId, string $phase, bool $forceNewRun = false): void {
        $result = [];
        $status = [];
        $previousStatus = [];
        $isNewRun = false;

        $previousStatus = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if ($this->isCancellationRequested($siteId)) {
            $this->abortCancelledAnalysis($siteId, $previousStatus);
            return;
        }

        if ($this->isAnalysisTimedOut($siteId, $previousStatus)) {
            $this->abortTimedOutAnalysis($siteId, $previousStatus);
            return;
        }

        if ($phase === self::SCHEDULED_PHASE) {
            $this->runRecurringAnalysis($siteId);
            return;
        }

        if ($phase === self::ACTIVE_PHASE) {
            $this->runActiveAnalysis($siteId);
            return;
        }

        $phase = in_array($phase, [self::BASE_PHASE, self::ORPHAN_PHASE, self::METADATA_PHASE], true)
            ? $phase
            : self::BASE_PHASE;
        $isNewRun = $forceNewRun || ($phase === self::BASE_PHASE && ($previousStatus['base']['status'] ?? 'idle') === 'idle');

        if ($isNewRun) {
            LoggingService::info(
                $this->config,
                sprintf(
                    __('RRZE-MSM: Storage analysis (site %d) started', 'rrze-multisite-manager'),
                    $siteId
                ),
                [
                    'site_id' => $siteId,
                    'phase' => $phase,
                ]
            );
        }

        try {
            $this->markRunStarted($siteId, $isNewRun);
            if ($phase === self::ORPHAN_PHASE) {
                $result = $this->metrics->runSiteStorageOrphanAnalysisBatch($siteId);
            } elseif ($phase === self::METADATA_PHASE) {
                $result = $this->metrics->runSiteMediaMetadataAnalysisBatch(
                    $siteId,
                    empty($this->getStatus($siteId)['metadata_started'])
                );
                $this->markMediaMetadataStarted($siteId);
            } else {
                $result = $this->metrics->runSiteStorageAnalysisBatch($siteId);
            }
        } catch (\Throwable $exception) {
            $this->markRunFailed($siteId, $exception->getMessage());
            $this->unschedule($siteId, self::ACTIVE_PHASE);
            $this->logStorageAnalysisError($siteId, $phase, $exception->getMessage());
            $this->logTaskFinished($siteId, $phase, [], false);
            return;
        }

        if (empty($result['success'])) {
            $message = (string)($result['message'] ?? __('The storage analysis could not be completed.', 'rrze-multisite-manager'));
            $this->markRunFailed($siteId, $message);
            $this->unschedule($siteId, self::ACTIVE_PHASE);
            $this->logStorageAnalysisError($siteId, $phase, $message, is_array($result['status'] ?? null) ? $result['status'] : []);
            $this->logTaskFinished($siteId, $phase, is_array($result['status'] ?? null) ? $result['status'] : [], false);
            return;
        }

        $status = is_array($result['status'] ?? null) ? $result['status'] : $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if (!$this->isSiteEligible($siteId)) {
            $this->deactivateIneligibleSite($siteId);
            return;
        }

        if ($this->isCancellationRequested($siteId)) {
            $this->abortCancelledAnalysis($siteId, $status);
            return;
        }

        if ($phase === self::BASE_PHASE) {
            if (($status['base']['status'] ?? '') === 'running') {
                $this->ensureActiveBatchSchedule($siteId);
                $this->logTaskFinished($siteId, $phase, $status, true);
                return;
            }

            if (($status['base']['status'] ?? '') === 'complete') {
                $this->ensureActiveBatchSchedule($siteId);
                $this->logTaskFinished($siteId, $phase, $status, true);
                return;
            }
        }

        if ($phase === self::ORPHAN_PHASE && ($status['orphan']['status'] ?? '') === 'running') {
            $this->ensureActiveBatchSchedule($siteId);
            $this->logTaskFinished($siteId, $phase, $status, true);
            return;
        }

        if ($phase === self::ORPHAN_PHASE && ($status['orphan']['status'] ?? '') === 'complete') {
            $this->ensureActiveBatchSchedule($siteId);
            $this->logTaskFinished($siteId, $phase, $status, true);
            return;
        }

        if ($phase === self::METADATA_PHASE) {
            $metadataState = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];

            if (($metadataState['status'] ?? '') === 'running') {
                $this->ensureActiveBatchSchedule($siteId);
                $this->logTaskFinished($siteId, $phase, $status, true);
                return;
            }

            if (($metadataState['status'] ?? '') === 'complete') {
                $this->markRunFinished($siteId);
                $this->unschedule($siteId, self::ACTIVE_PHASE);
            }
        }

        $this->logTaskFinished($siteId, $phase, $status, true);
    }

    protected function runRecurringAnalysis(int $siteId): void {
        $status = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if ($this->isSiteRunRunning($siteId, $status)) {
            LoggingService::info(
                $this->config,
                'RRZE-MSM: Speicheranalyse-Scheduler übersprungen',
                [
                    'site_id' => $siteId,
                    'reason' => 'analysis_already_running',
                ]
            );
            $this->ensureActiveBatchSchedule($siteId);
            return;
        }

        $this->unschedule($siteId, self::BASE_PHASE);
        $this->unschedule($siteId, self::ORPHAN_PHASE);
        $this->unschedule($siteId, self::ACTIVE_PHASE);
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->runScheduledAnalysisLocked($siteId, self::BASE_PHASE, true);
    }

    protected function runActiveAnalysis(int $siteId): void {
        $status = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if (($status['base']['status'] ?? '') === 'running') {
            $this->runScheduledAnalysisLocked($siteId, self::BASE_PHASE);
            return;
        }

        if (($status['base']['status'] ?? '') === 'complete' && ($status['orphan']['status'] ?? '') !== 'complete') {
            $this->runScheduledAnalysisLocked($siteId, self::ORPHAN_PHASE);
            return;
        }

        if (($status['base']['status'] ?? '') === 'complete'
            && ($status['orphan']['status'] ?? '') === 'complete'
            && !empty($this->getStatus($siteId)['metadata_pending'])) {
            $this->runScheduledAnalysisLocked($siteId, self::METADATA_PHASE);
            return;
        }

        $this->unschedule($siteId, self::ACTIVE_PHASE);
    }

    public function getSiteProcesses(): array {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
        $processes = [];

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            $site = get_site($siteId);
            $analysisStatus = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);
            $scheduleStatus = $this->getStatus($siteId);
            $isEligible = $this->isSiteEligible($siteId);
            $nextRunTimestamp = $isEligible ? (int)($scheduleStatus['next_recurring_run_timestamp'] ?? 0) : 0;
            $isDue = $nextRunTimestamp > 0 && $nextRunTimestamp <= time();

            if (!$site) {
                continue;
            }

            if (!$isEligible) {
                $this->deactivateIneligibleSite($siteId);
            }

            $processes[] = [
                'site_id' => $siteId,
                'url' => get_home_url($siteId, '/'),
                'website_status_key' => $this->getWebsiteStatusFilterKey($site),
                'status' => $this->getSiteProcessStatus($isEligible, $isDue, $analysisStatus, $scheduleStatus),
                'status_key' => $this->getSiteProcessStatusKey($isEligible, $isDue, $analysisStatus, $scheduleStatus),
                'is_running' => $isEligible && $this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
                'is_eligible' => $isEligible,
                'is_due' => $isDue,
                'cycle' => $isEligible ? $this->getScheduleLabel() : '',
                'last_run' => (string)($scheduleStatus['last_finished_at'] ?? ''),
                // The monitoring table describes the configured recurrence, not internal follow-up batches.
                'next_run_timestamp' => $nextRunTimestamp,
                'last_duration_seconds' => (int)($scheduleStatus['last_duration_seconds'] ?? 0),
                'last_was_aborted' => !empty($scheduleStatus['last_was_aborted']),
                'can_start_now' => $isEligible && !$isDue && !$this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
            ];
        }

        return $processes;
    }

    /**
     * Returns the WordPress Core site status for the monitoring table filter.
     * The scheduler eligibility also considers monitoring metadata, but that
     * must not change how an otherwise active website is classified here.
     */
    protected function getWebsiteStatusFilterKey(\WP_Site $site): string {
        if ((int)$site->archived === 0 && (int)$site->spam === 0 && (int)$site->deleted === 0) {
            return 'active';
        }

        return 'inactive';
    }

    protected function scheduleRecurringAnalysis(int $siteId): void {
        $delay = MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS));
        $this->scheduleRecurringAnalysisAt($siteId, time() + $delay);
    }

    protected function scheduleRecurringAnalysisAt(int $siteId, int $timestamp): void {
        if (!$this->isSiteEligible($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return;
        }

        wp_schedule_event(max(time(), $timestamp), $this->getScheduleKey(), $this->config->getStorageAnalysisHook(), [$siteId, self::SCHEDULED_PHASE]);
    }

    protected function scheduleRecurringAnalyses(array $siteIds): void {
        $nextTimestamp = time();
        $siteId = 0;

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteEligible($siteId)) {
                continue;
            }

            // Spread first executions to prevent a bulk reset from creating a cron spike.
            $nextTimestamp += MINUTE_IN_SECONDS + wp_rand(0, MINUTE_IN_SECONDS);
            $this->scheduleRecurringAnalysisAt($siteId, $nextTimestamp);
        }
    }

    protected function ensureActiveBatchSchedule(int $siteId): void {
        if (!$this->isSiteEligible($siteId) || $this->getNextScheduledTimestamp($siteId, self::ACTIVE_PHASE) > 0) {
            return;
        }

        wp_schedule_event(
            time() + MINUTE_IN_SECONDS,
            'rrze_msm_storage_active_batch',
            $this->config->getStorageAnalysisHook(),
            [$siteId, self::ACTIVE_PHASE]
        );
    }

    protected function unschedule(int $siteId, string $phase): void {
        $timestamp = $this->getNextScheduledTimestamp($siteId, $phase);

        while ($timestamp > 0) {
            wp_unschedule_event($timestamp, $this->config->getStorageAnalysisHook(), [$siteId, $phase]);
            $timestamp = $this->getNextScheduledTimestamp($siteId, $phase);
        }
    }

    protected function getNextScheduledTimestamp(int $siteId, string $phase): int {
        return (int)wp_next_scheduled($this->config->getStorageAnalysisHook(), [$siteId, $phase]);
    }

    protected function getNextRecurringScheduledTimestamp(int $siteId): int {
        $cron = _get_cron_array();
        $timestamp = 0;
        $events = [];
        $event = [];
        $expectedArgs = [$siteId, self::SCHEDULED_PHASE];
        $expectedSchedule = $this->getScheduleKey();

        if (!is_array($cron)) {
            return 0;
        }

        foreach ($cron as $timestamp => $events) {
            if (!is_array($events) || empty($events[$this->config->getStorageAnalysisHook()])) {
                continue;
            }

            foreach ($events[$this->config->getStorageAnalysisHook()] as $event) {
                if (
                    (array)($event['args'] ?? []) === $expectedArgs
                    && (string)($event['schedule'] ?? '') === $expectedSchedule
                ) {
                    return (int)$timestamp;
                }
            }
        }

        return 0;
    }

    protected function getScheduleKey(): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $frequency = is_array($options) ? (string)($options['monitoring_storage_analysis_frequency'] ?? 'twiceweekly') : 'twiceweekly';
        $keys = [
            'weekly' => 'rrze_msm_storage_weekly',
            'twiceweekly' => 'rrze_msm_storage_twice_weekly',
            'daily' => 'rrze_msm_storage_daily',
            'twicedaily' => 'rrze_msm_storage_twice_daily',
            'fourtimesdaily' => 'rrze_msm_storage_four_times_daily',
        ];

        return $keys[$frequency] ?? $keys['twiceweekly'];
    }

    protected function getEligibleSiteIds(array $siteIds): array {
        $eligibleSiteIds = [];

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($this->isSiteEligible($siteId)) {
                $eligibleSiteIds[] = $siteId;
            }
        }

        return $eligibleSiteIds;
    }

    protected function getScheduleSignature(): string {
        return $this->getScheduleKey() . ':recurring-batches-v2';
    }

    protected function getScheduleLabel(): string {
        $labels = [
            'rrze_msm_storage_weekly' => __('Once weekly', 'rrze-multisite-manager'),
            'rrze_msm_storage_twice_weekly' => __('Twice weekly', 'rrze-multisite-manager'),
            'rrze_msm_storage_daily' => __('Once daily', 'rrze-multisite-manager'),
            'rrze_msm_storage_twice_daily' => __('Twice daily', 'rrze-multisite-manager'),
            'rrze_msm_storage_four_times_daily' => __('Four times daily', 'rrze-multisite-manager'),
        ];

        return $labels[$this->getScheduleKey()] ?? $labels['rrze_msm_storage_twice_daily'];
    }

    protected function getTimeoutSeconds(): int {
        $options = get_site_option($this->config->getOptionName(), []);
        $minutes = is_array($options) ? (int)($options['monitoring_storage_analysis_timeout_minutes'] ?? 60) : 60;

        return max(MINUTE_IN_SECONDS, min(1440 * MINUTE_IN_SECONDS, $minutes * MINUTE_IN_SECONDS));
    }

    protected function isAnalysisTimedOut(int $siteId, array $analysisStatus): bool {
        $scheduleStatus = $this->getStatus($siteId);
        $startedAt = (string)($scheduleStatus['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;

        return $this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus)
            && $startedTimestamp > 0
            && (time() - $startedTimestamp) >= $this->getTimeoutSeconds();
    }

    protected function abortTimedOutAnalysis(int $siteId, array $analysisStatus): void {
        $scheduleStatus = $this->getStatus($siteId);
        $startedAt = (string)($scheduleStatus['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $duration = $startedTimestamp > 0 ? max(0, time() - $startedTimestamp) : 0;
        $phase = ($analysisStatus['orphan']['status'] ?? '') === 'running' ? self::ORPHAN_PHASE : self::BASE_PHASE;
        $message = __('The storage analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager');

        $this->unschedule($siteId, self::BASE_PHASE);
        $this->unschedule($siteId, self::ORPHAN_PHASE);
        $this->unschedule($siteId, self::ACTIVE_PHASE);
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->markRunAborted($siteId, $startedAt, $duration, $message);

        do_action(
            'rrze.log.error',
            'RRZE-MSM: Speicheranalyse wegen Zeitüberschreitung abgebrochen',
            [
                'site_id' => $siteId,
                'phase' => $phase,
                'started_at' => $startedAt,
                'duration_seconds' => $duration,
                'timeout_seconds' => $this->getTimeoutSeconds(),
                'status' => $analysisStatus,
            ]
        );
    }

    protected function abortCancelledAnalysis(int $siteId, array $analysisStatus): void {
        $scheduleStatus = $this->getStatus($siteId);
        $startedAt = (string)($scheduleStatus['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $duration = $startedTimestamp > 0 ? max(0, time() - $startedTimestamp) : 0;
        $message = __('The storage analysis was cancelled.', 'rrze-multisite-manager');

        $this->unschedule($siteId, self::BASE_PHASE);
        $this->unschedule($siteId, self::ORPHAN_PHASE);
        $this->unschedule($siteId, self::ACTIVE_PHASE);
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->markRunAborted($siteId, $startedAt, $duration, $message);
        delete_site_transient($this->getCancellationTransientKey($siteId));

        LoggingService::info(
            $this->config,
            sprintf(
                __('RRZE-MSM: Storage analysis (site %d) cancelled', 'rrze-multisite-manager'),
                $siteId
            ),
            [
                'site_id' => $siteId,
                'duration_seconds' => $duration,
            ]
        );
    }

    protected function isAnalysisRunning(array $status): bool {
        return ($status['base']['status'] ?? '') === 'running' || ($status['orphan']['status'] ?? '') === 'running';
    }

    protected function isSiteRunRunning(int $siteId, array $analysisStatus, array $scheduleStatus = []): bool {
        if ($this->isAnalysisRunning($analysisStatus)) {
            return true;
        }

        if (empty($scheduleStatus)) {
            $scheduleStatus = $this->getStatus($siteId);
        }

        return !empty($scheduleStatus['is_running']);
    }

    protected function getCancellationTransientKey(int $siteId): string {
        return self::CANCEL_TRANSIENT_PREFIX . $siteId;
    }

    protected function isCancellationRequested(int $siteId): bool {
        return get_site_transient($this->getCancellationTransientKey($siteId)) !== false;
    }

    protected function getSiteProcessStatus(bool $isEligible, bool $isDue, array $analysisStatus, array $scheduleStatus): string {
        $labels = [
            'running' => __('Running', 'rrze-multisite-manager'),
            'inactive' => __('Inactive', 'rrze-multisite-manager'),
            'waiting_for_cron' => __('Waiting for cron', 'rrze-multisite-manager'),
            'aborted' => __('Aborted', 'rrze-multisite-manager'),
            'error' => __('Error', 'rrze-multisite-manager'),
            'scheduled' => __('Scheduled', 'rrze-multisite-manager'),
            'not_scheduled' => __('Not scheduled', 'rrze-multisite-manager'),
        ];
        $statusKey = $this->getSiteProcessStatusKey($isEligible, $isDue, $analysisStatus, $scheduleStatus);

        return $labels[$statusKey];
    }

    protected function getSiteProcessStatusKey(bool $isEligible, bool $isDue, array $analysisStatus, array $scheduleStatus): string {
        if (!$isEligible) {
            return 'inactive';
        }

        if ($this->isSiteRunRunning(0, $analysisStatus, $scheduleStatus)) {
            return 'running';
        }

        if ($isDue) {
            return 'waiting_for_cron';
        }

        if (!empty($scheduleStatus['last_error'])) {
            if (!empty($scheduleStatus['last_was_aborted'])) {
                return 'aborted';
            }

            return 'error';
        }

        return (int)($scheduleStatus['next_run_timestamp'] ?? 0) > 0 ? 'scheduled' : 'not_scheduled';
    }

    protected function acquireSiteLock(int $siteId): bool {
        $optionName = self::LOCK_OPTION_PREFIX . $siteId;
        $existing = (int)get_site_option($optionName, 0);

        if ($existing > 0 && (time() - $existing) > self::LOCK_TTL) {
            delete_site_option($optionName);
        }

        return add_site_option($optionName, time());
    }

    protected function releaseSiteLock(int $siteId): void {
        delete_site_option(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function markRunStarted(int $siteId, bool $isNewRun): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status) || $isNewRun || empty($status['last_started_at'])) {
            $status = [];
            $status['last_started_at'] = current_time('mysql', true);
        }

        $status['last_error'] = '';
        $status['is_running'] = true;

        if ($isNewRun) {
            $status['metadata_pending'] = true;
            $status['metadata_started'] = false;
        }

        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function markMediaMetadataStarted(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        $status['metadata_started'] = true;
        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function markRunFinished(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $startedAt = is_array($status) ? (string)($status['last_started_at'] ?? '') : '';
        $finishedAt = current_time('mysql', true);
        $duration = 0;

        if ($startedAt !== '') {
            $startedTimestamp = strtotime($startedAt . ' GMT');
            $finishedTimestamp = strtotime($finishedAt . ' GMT');
            $duration = $startedTimestamp && $finishedTimestamp ? max(0, $finishedTimestamp - $startedTimestamp) : 0;
        }

        update_blog_option(
            $siteId,
            self::OPTION_STATUS,
            [
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_duration_seconds' => $duration,
                'last_was_aborted' => false,
                'last_error' => '',
                'is_running' => false,
                'metadata_pending' => false,
                'metadata_started' => false,
            ]
        );
    }

    protected function markRunAborted(int $siteId, string $startedAt, int $duration, string $message): void {
        update_blog_option(
            $siteId,
            self::OPTION_STATUS,
            [
                'last_started_at' => $startedAt,
                'last_finished_at' => current_time('mysql', true),
                'last_duration_seconds' => $duration,
                'last_was_aborted' => true,
                'last_error' => $message,
                'is_running' => false,
                'metadata_pending' => false,
                'metadata_started' => false,
            ]
        );
    }

    protected function markRunFailed(int $siteId, string $message): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        $status['last_error'] = $message;
        $status['is_running'] = false;
        $status['metadata_pending'] = false;
        $status['metadata_started'] = false;
        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function logStorageAnalysisError(int $siteId, string $phase, string $message, array $status = []): void {
        LoggingService::storageAnalysisError(
            [
                'site_id' => $siteId,
                'phase' => $phase,
                'trigger' => 'scheduler',
                'message' => $message,
                'status' => $status,
            ]
        );
    }

    protected function logTaskFinished(int $siteId, string $phase, array $status, bool $success): void {
        $phaseStatus = is_array($status[$phase] ?? null) ? $status[$phase] : [];
        $isComplete = $phase === self::ORPHAN_PHASE && ($phaseStatus['status'] ?? '') === 'complete';

        if ($success && !$isComplete) {
            return;
        }

        LoggingService::info(
            $this->config,
            sprintf(
                __('RRZE-MSM: Storage analysis (site %d) finished', 'rrze-multisite-manager'),
                $siteId
            ),
            [
                'site_id' => $siteId,
                'phase' => $phase,
                'success' => $success,
                'status' => (string)($phaseStatus['status'] ?? ''),
                'message' => (string)($phaseStatus['message'] ?? ''),
                'processed_files' => (int)($phaseStatus['processed_files'] ?? 0),
                'processed_directories' => (int)($phaseStatus['processed_directories'] ?? 0),
                'processed' => (int)($phaseStatus['processed'] ?? 0),
                'total' => (int)($phaseStatus['total'] ?? 0),
            ]
        );
    }
}
