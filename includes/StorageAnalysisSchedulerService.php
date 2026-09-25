<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class StorageAnalysisSchedulerService {
    protected const OPTION_STATUS = 'rrze_msm_storage_analysis_scheduler_status';
    protected const BASE_PHASE = 'base';
    protected const ORPHAN_PHASE = 'orphan';
    protected const METADATA_PHASE = 'metadata';
    protected const SCHEDULED_PHASE = 'scheduled';
    // Only retained to remove obsolete continuation events from older releases.
    protected const LEGACY_ACTIVE_PHASE = 'active';
    protected const SCHEDULE_SIGNATURE_OPTION = 'rrze_msm_storage_analysis_schedule_signature';
    protected const GLOBAL_INITIALIZATION_OPTION = 'rrze_msm_storage_analysis_global_initialization';
    protected const SCHEDULE_INITIALIZATION_OFFSET_OPTION = 'rrze_msm_storage_analysis_schedule_initialization_offset';
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_storage_analysis_lock_';
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';

    protected MetricsService $metrics;
    protected Config $config;
    /** @var array<int, int>|null */
    protected ?array $currentRecurringScheduleTimestamps = null;
    /** @var array<int, true>|null */
    protected ?array $recurringScheduledSiteIds = null;

    public function __construct(MetricsService $metrics, ?Config $config = null) {
        $this->metrics = $metrics;
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action($this->config->getStorageAnalysisHook(), [$this, 'runScheduledAnalysis'], 10, 2);
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureRecurringSchedules'], 20);
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
        return $schedules;
    }

    public function ensureRecurringSchedules(): void {
        $signature = $this->getScheduleSignature();
        $storedSignature = get_site_option(self::SCHEDULE_SIGNATURE_OPTION, null);

        if ($storedSignature === null || $storedSignature === false) {
            $this->markScheduleConfigurationCurrent();
            return;
        }

        if ((string)$storedSignature === $signature) {
            return;
        }

        // Never reschedule every website while an arbitrary admin request is
        // being rendered. Explicit setup runs are processed in small batches.
        $this->markScheduleConfigurationCurrent();
    }

    /**
     * Marks the current scheduler configuration without creating site-specific tasks.
     */
    public function markScheduleConfigurationCurrent(): void {
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
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

            // Remove continuation events created by earlier plugin versions.
            $this->unschedule($siteId, self::BASE_PHASE);
            $this->unschedule($siteId, self::ORPHAN_PHASE);
            $this->unschedule($siteId, self::LEGACY_ACTIVE_PHASE);

            if (!$this->isSiteEligible($siteId)) {
                $this->deactivateIneligibleSite($siteId);
                continue;
            }

            if (!$this->hasRecurringScheduledAnalysis($siteId)) {
                continue;
            }

            $this->unschedule($siteId, self::SCHEDULED_PHASE);
            $this->scheduleRecurringAnalysisAt($siteId, time() + MINUTE_IN_SECONDS + wp_rand(0, MINUTE_IN_SECONDS));
        }

        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
    }

    /**
     * Schedules currently eligible, unscheduled sites after an explicit user request.
     */
    public function initializeActiveSiteSchedules(): int {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
        $initialized = 0;

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteAwaitingInitialSchedule($siteId)) {
                continue;
            }

            $this->scheduleRecurringAnalysis($siteId);
            $initialized++;
        }

        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return $initialized;
    }

    /**
     * Schedules one bounded group of websites after an explicit administrator
     * request. Option reads therefore never happen for all sites on page load.
     *
     * @return array{initialized: int, processed: int, total: int, complete: bool}
     */
    public function initializeActiveSiteSchedulesBatch(int $batchSize = 25): array {
        $batchSize = max(1, $batchSize);
        $offset = max(0, (int)get_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION, 0));
        $total = (int)get_sites(['count' => true]);
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => $batchSize,
            'offset' => $offset,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
        $initialized = 0;

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteAwaitingInitialSchedule($siteId)) {
                continue;
            }

            $this->scheduleRecurringAnalysis($siteId);
            $initialized++;
        }

        $processed = min($total, $offset + count($siteIds));
        $complete = $processed >= $total;

        if ($complete) {
            delete_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION);
            update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
        } else {
            update_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION, $processed);
        }

        return [
            'initialized' => $initialized,
            'processed' => $processed,
            'total' => $total,
            'complete' => $complete,
        ];
    }

    public function getUnscheduledEligibleSiteCount(): int {
        // Kept for backward compatibility. The monitoring page deliberately
        // does not determine this dynamically, because that opens options for
        // every website in a network.
        return 0;
    }

    public function reconcileSiteSchedule(int $siteId): void {
        if (!$this->isSiteEligible($siteId)) {
            $this->deactivateIneligibleSite($siteId);
        }
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
            $this->unschedule($siteId, self::BASE_PHASE);
            $this->unschedule($siteId, self::ORPHAN_PHASE);
            $this->unschedule($siteId, self::SCHEDULED_PHASE);
            $this->unschedule($siteId, self::LEGACY_ACTIVE_PHASE);

            if (!$this->isSiteEligible($siteId)) {
                $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
            }
        }

        $eligibleSiteIds = $this->getEligibleSiteIds($siteIds);
        $this->scheduleRecurringAnalyses($eligibleSiteIds);
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return count($eligibleSiteIds);
    }

    public function scheduleNewSiteRecurringAnalysis(int $siteId): void {
        // Scheduling is deliberately only initiated by an explicit user action.
        // A newly eligible site must not recreate a removed recurring event.
    }

    public function isSiteEligible(int $siteId): bool {
        $site = $siteId > 0 ? get_site($siteId) : null;
        $isActive = false;
        $operationalStatus = '';
        $dnsStatus = '';
        $httpStatus = '';

        if (!$site instanceof \WP_Site) {
            return false;
        }

        $isActive = (int)$site->archived === 0 && (int)$site->spam === 0 && (int)$site->deleted === 0;

        if (!$isActive) {
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
        $this->unschedule($siteId, self::LEGACY_ACTIVE_PHASE);
        $this->unschedule($siteId, self::SCHEDULED_PHASE);
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);

        return true;
    }

    public static function clearScheduledEvents(?Config $config = null): int {
        $config = $config ?? new Config();
        $cron = _get_cron_array();
        $removed = 0;

        if (!is_array($cron)) {
            return 0;
        }

        foreach ($cron as $timestamp => $events) {
            if (!is_array($events) || empty($events[$config->getStorageAnalysisHook()])) {
                continue;
            }

            $removed += count((array)$events[$config->getStorageAnalysisHook()]);
            unset($cron[$timestamp][$config->getStorageAnalysisHook()]);

            if (empty($cron[$timestamp])) {
                unset($cron[$timestamp]);
            }
        }

        if ($removed > 0) {
            // Write the Cron array once. Removing hundreds of events one by
            // one can time out and leave a partially removed schedule behind.
            if (!_set_cron_array($cron)) {
                return 0;
            }
        }

        delete_site_option(self::SCHEDULE_SIGNATURE_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION);

        return $removed;
    }

    public function startAnalysisNow(int $siteId): bool {
        $this->recoverInterruptedRun($siteId);
        $status = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);

        if (!$this->isSiteEligible($siteId) || $this->isSiteRunRunning($siteId, $status)) {
            $this->deactivateIneligibleSite($siteId);
            return false;
        }

        $this->unschedule($siteId, self::BASE_PHASE);
        $this->unschedule($siteId, self::ORPHAN_PHASE);
        $this->unschedule($siteId, self::LEGACY_ACTIVE_PHASE);
        $this->unschedule($siteId, self::SCHEDULED_PHASE);
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->scheduleRecurringAnalysisAt($siteId, time());

        return true;
    }

    public function getStatus(int $siteId): array {
        $recurringTimestamp = $this->getNextRecurringScheduledTimestamp($siteId);
        $storedStatus = $siteId > 0 ? get_blog_option($siteId, self::OPTION_STATUS, []) : [];
        $lastCompletedAt = is_array($storedStatus) ? (string)($storedStatus['last_completed_at'] ?? '') : '';
        $phases = is_array($storedStatus['phases'] ?? null)
            ? $storedStatus['phases']
            : ($recurringTimestamp > 0 ? $this->getScheduledPhaseStatuses() : $this->getDefaultPhaseStatuses());

        // Preserve a successful timestamp created by releases before last_completed_at existed.
        if ($lastCompletedAt === '' && is_array($storedStatus) && empty($storedStatus['last_error']) && empty($storedStatus['last_was_aborted'])) {
            $lastCompletedAt = (string)($storedStatus['last_finished_at'] ?? '');
        }

        return [
            'next_run_timestamp' => $recurringTimestamp,
            'next_phase' => self::SCHEDULED_PHASE,
            'next_recurring_run_timestamp' => $recurringTimestamp,
            'last_started_at' => is_array($storedStatus) ? (string)($storedStatus['last_started_at'] ?? '') : '',
            'last_finished_at' => is_array($storedStatus) ? (string)($storedStatus['last_finished_at'] ?? '') : '',
            'last_completed_at' => $lastCompletedAt,
            'last_duration_seconds' => is_array($storedStatus) ? max(0, (int)($storedStatus['last_duration_seconds'] ?? 0)) : 0,
            'last_was_aborted' => is_array($storedStatus) && !empty($storedStatus['last_was_aborted']),
            'last_error' => is_array($storedStatus) ? (string)($storedStatus['last_error'] ?? '') : '',
            'is_running' => is_array($storedStatus) && !empty($storedStatus['is_running']),
            'metadata_pending' => is_array($storedStatus) && !empty($storedStatus['metadata_pending']),
            'metadata_started' => is_array($storedStatus) && !empty($storedStatus['metadata_started']),
            'phases' => $phases,
        ];
    }

    public function runScheduledAnalysis(int $siteId = 0, string $phase = self::BASE_PHASE): void {
        if (MetricsService::isFullDataCleanupInProgress()) {
            return;
        }

        if (!$this->isSiteEligible($siteId)) {
            $this->deactivateIneligibleSite($siteId);
            return;
        }

        if (!$this->acquireSiteLock($siteId)) {
            return;
        }

        try {
            if ($phase !== self::SCHEDULED_PHASE) {
                // A continuation event from an earlier release must not start a second process.
                $this->unschedule($siteId, $phase);
                return;
            }

            $this->runSingleProcessAnalysis($siteId);
        } finally {
            $this->releaseSiteLock($siteId);
        }
    }

    protected function runSingleProcessAnalysis(int $siteId): void {
        $status = $this->getStatus($siteId);

        if (!empty($status['is_running'])) {
            $startedTimestamp = !empty($status['last_started_at'])
                ? (int)strtotime((string)$status['last_started_at'] . ' UTC')
                : 0;

            if ($startedTimestamp > 0 && (time() - $startedTimestamp) >= $this->getTimeoutSeconds()) {
                $this->abortSingleProcessAnalysis(
                    $siteId,
                    $this->getRunningPhase($status),
                    __('The storage analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager')
                );
                return;
            }

            LoggingService::info(
                $this->config,
                'RRZE-MSM: Storage analysis scheduler skipped',
                ['site_id' => $siteId, 'reason' => 'analysis_already_running']
            );
            return;
        }

        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->markRunStarted($siteId, true);
        $this->extendRuntimeLimit();
        LoggingService::info(
            $this->config,
            /* translators: %d: site ID. */
            sprintf(__('RRZE-MSM: Storage analysis (site %d) started', 'rrze-multisite-manager'), $siteId),
            ['site_id' => $siteId, 'phase' => self::BASE_PHASE]
        );

        $deadline = time() + $this->getTimeoutSeconds();
        $phase = self::BASE_PHASE;

        try {
            foreach ([self::BASE_PHASE, self::ORPHAN_PHASE, self::METADATA_PHASE] as $phase) {
                $this->runAnalysisPhaseToCompletion($siteId, $phase, $deadline);
            }

            $this->markRunFinished($siteId);
        } catch (\Throwable $exception) {
            if (time() >= $deadline) {
                $this->abortSingleProcessAnalysis($siteId, $phase, $exception->getMessage());
                return;
            }

            $this->markRunFailed($siteId, $exception->getMessage());
            $this->markPhaseFailed($siteId, $phase, $exception->getMessage());
            $this->logStorageAnalysisError(
                $siteId,
                $phase,
                $exception->getMessage(),
                $this->metrics->getSiteStorageAnalysisProgressContext($siteId, $phase)
            );
        }
    }

    protected function runAnalysisPhaseToCompletion(int $siteId, string $phase, int $deadline): void {
        $restartMetadataAnalysis = true;

        $this->markPhaseStarted($siteId, $phase);

        while (time() < $deadline) {
            if ($phase === self::BASE_PHASE) {
                $result = $this->metrics->runSiteStorageAnalysisBatch($siteId);
                $isComplete = (($result['status']['base']['status'] ?? '') === 'complete');
            } elseif ($phase === self::ORPHAN_PHASE) {
                $result = $this->metrics->runSiteStorageOrphanAnalysisBatch($siteId);
                $isComplete = (($result['status']['orphan']['status'] ?? '') === 'complete');
            } else {
                $result = $this->metrics->runSiteMediaMetadataAnalysisBatch($siteId, $restartMetadataAnalysis);
                $restartMetadataAnalysis = false;
                $isComplete = (($result['analysis']['status'] ?? '') === 'complete');
                $this->markMediaMetadataStarted($siteId);
            }

            if (empty($result['success'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception message is not HTML output and is escaped when rendered.
                throw new \RuntimeException((string)($result['message'] ?? __('The storage analysis could not be completed.', 'rrze-multisite-manager')));
            }

            if ($isComplete) {
                $this->markPhaseFinished($siteId, $phase);
                return;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception message is not HTML output and is escaped when rendered.
        throw new \RuntimeException(__('The storage analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager'));
    }

    protected function abortSingleProcessAnalysis(int $siteId, string $phase, string $message): void {
        $status = $this->getStatus($siteId);
        $progressContext = $this->metrics->getSiteStorageAnalysisProgressContext($siteId, $phase);
        $startedAt = (string)($status['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $duration = $startedTimestamp > 0 ? max(0, time() - $startedTimestamp) : 0;

        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->markRunAborted($siteId, $startedAt, $duration, $message);
        $this->markPhaseAborted($siteId, $phase, $message);
        do_action(
            'rrze.log.error',
            'RRZE-MSM: Storage analysis aborted because of its runtime limit',
            [
                'site_id' => $siteId,
                'phase' => $phase,
                'duration_seconds' => $duration,
                'timeout_seconds' => $this->getTimeoutSeconds(),
                'progress' => $progressContext,
            ]
        );
    }

    protected function extendRuntimeLimit(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit($this->getTimeoutSeconds() + MINUTE_IN_SECONDS);
        }
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
            $process = $this->getSiteProcess((int)$siteId);

            if ($process !== null) {
                $processes[] = $process;
            }
        }

        return $processes;
    }

    /**
     * Returns one bounded page for the monitoring table.  Rendering a page
     * must not load scheduler data for every site in a large network.
     *
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool, total: int}
     */
    public function getSiteProcessesPage(int $page, int $perPage, string $urlSearch = ''): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $queryArgs = [
            'fields' => 'ids',
            'number' => $perPage + 1,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'id',
            'order' => 'ASC',
        ];

        if ($urlSearch !== '') {
            $queryArgs['search'] = $urlSearch;
        }

        $siteIds = get_sites($queryArgs);
        $hasMore = count($siteIds) > $perPage;
        $queryArgs['count'] = true;
        unset($queryArgs['fields'], $queryArgs['number'], $queryArgs['offset'], $queryArgs['orderby'], $queryArgs['order']);
        $total = (int)get_sites($queryArgs);
        $processes = [];

        foreach (array_slice($siteIds, 0, $perPage) as $siteId) {
            $process = $this->getSiteProcess((int)$siteId);

            if ($process !== null) {
                $processes[] = $process;
            }
        }

        return [
            'processes' => $processes,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    protected function getSiteProcess(int $siteId): ?array {
        $site = get_site($siteId);
        $analysisStatus = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);
        $scheduleStatus = $this->getStatus($siteId);

        $this->recoverInterruptedRun($siteId, $analysisStatus, $scheduleStatus);
        $analysisStatus = $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);
        $scheduleStatus = $this->getStatus($siteId);
        $isEligible = $this->isSiteEligible($siteId);
        $nextRunTimestamp = $isEligible ? (int)($scheduleStatus['next_recurring_run_timestamp'] ?? 0) : 0;
        $isDue = $nextRunTimestamp > 0 && $nextRunTimestamp <= time();

        if (!$site) {
            return null;
        }

        if (!$isEligible) {
            $this->deactivateIneligibleSite($siteId);
        }

        return [
            'site_id' => $siteId,
            'name' => (string)get_blog_option($siteId, 'blogname', ''),
            'url' => get_home_url($siteId, '/'),
            'website_status_key' => $this->getWebsiteStatusFilterKey($site),
            'status' => $this->getSiteProcessStatus($isEligible, $isDue, $analysisStatus, $scheduleStatus),
            'status_key' => $this->getSiteProcessStatusKey($isEligible, $isDue, $analysisStatus, $scheduleStatus),
            'is_running' => $isEligible && $this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
            'is_eligible' => $isEligible,
            'is_due' => $isDue,
            'cycle' => $isEligible ? $this->getScheduleLabel() : '',
            'last_started_at' => (string)($scheduleStatus['last_started_at'] ?? ''),
            'last_run' => (string)($scheduleStatus['last_completed_at'] ?? ''),
            // The monitoring table describes the configured recurrence, not internal follow-up batches.
            'next_run_timestamp' => $nextRunTimestamp,
            'last_duration_seconds' => (int)($scheduleStatus['last_duration_seconds'] ?? 0),
            'last_was_aborted' => !empty($scheduleStatus['last_was_aborted']),
            'phases' => is_array($scheduleStatus['phases'] ?? null) ? $scheduleStatus['phases'] : [],
            'can_start_now' => $isEligible && !$isDue && !$this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
        ];
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

    protected function isSiteAwaitingInitialSchedule(int $siteId): bool {
        if (!$this->isSiteEligible($siteId)) {
            return false;
        }

        $status = $this->getStatus($siteId);

        return (int)($status['next_recurring_run_timestamp'] ?? 0) <= 0
            && empty($status['last_started_at'])
            && empty($status['last_finished_at'])
            && empty($status['last_completed_at']);
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
        $this->clearRecurringScheduleCache();
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

    protected function unschedule(int $siteId, string $phase): void {
        $timestamp = $this->getNextScheduledTimestamp($siteId, $phase);

        while ($timestamp > 0) {
            wp_unschedule_event($timestamp, $this->config->getStorageAnalysisHook(), [$siteId, $phase]);
            $timestamp = $this->getNextScheduledTimestamp($siteId, $phase);
        }

        $this->clearRecurringScheduleCache();
    }

    protected function getNextScheduledTimestamp(int $siteId, string $phase): int {
        return (int)wp_next_scheduled($this->config->getStorageAnalysisHook(), [$siteId, $phase]);
    }

    protected function getNextRecurringScheduledTimestamp(int $siteId): int {
        $this->loadRecurringScheduleCache();

        return (int)($this->currentRecurringScheduleTimestamps[$siteId] ?? 0);
    }

    protected function hasRecurringScheduledAnalysis(int $siteId): bool {
        $this->loadRecurringScheduleCache();

        return isset($this->recurringScheduledSiteIds[$siteId]);
    }

    /**
     * @return array<int, true>
     */
    protected function getRecurringScheduledSiteIds(): array {
        $this->loadRecurringScheduleCache();

        return $this->recurringScheduledSiteIds ?? [];
    }

    protected function clearRecurringScheduleCache(): void {
        $this->currentRecurringScheduleTimestamps = null;
        $this->recurringScheduledSiteIds = null;
    }

    protected function loadRecurringScheduleCache(): void {
        if ($this->currentRecurringScheduleTimestamps !== null && $this->recurringScheduledSiteIds !== null) {
            return;
        }

        $currentTimestamps = [];
        $siteIds = [];
        $cron = _get_cron_array();
        $expectedSchedule = $this->getScheduleKey();

        foreach ((array)$cron as $timestamp => $events) {
            foreach ((array)($events[$this->config->getStorageAnalysisHook()] ?? []) as $event) {
                $args = (array)($event['args'] ?? []);
                $siteId = (int)($args[0] ?? 0);

                if (empty($event['schedule']) || ($args[1] ?? '') !== self::SCHEDULED_PHASE || $siteId <= 0) {
                    continue;
                }

                $siteIds[$siteId] = true;

                if ((string)($event['schedule'] ?? '') === $expectedSchedule) {
                    $eventTimestamp = (int)$timestamp;

                    if ($eventTimestamp > 0 && (!isset($currentTimestamps[$siteId]) || $eventTimestamp < $currentTimestamps[$siteId])) {
                        $currentTimestamps[$siteId] = $eventTimestamp;
                    }
                }
            }
        }

        $this->currentRecurringScheduleTimestamps = $currentTimestamps;
        $this->recurringScheduledSiteIds = $siteIds;
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
        return $this->getScheduleKey() . ':single-process-v3';
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

    protected function getSiteProcessStatus(bool $isEligible, bool $isDue, array $analysisStatus, array $scheduleStatus): string {
        $labels = [
            'running' => __('Running', 'rrze-multisite-manager'),
            'inactive' => __('Inactive', 'rrze-multisite-manager'),
            'waiting_for_cron' => __('Waiting for cron', 'rrze-multisite-manager'),
            'aborted' => __('Aborted', 'rrze-multisite-manager'),
            'error' => __('Error', 'rrze-multisite-manager'),
            'ok' => __('Ok', 'rrze-multisite-manager'),
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

        $nextRunTimestamp = (int)($scheduleStatus['next_recurring_run_timestamp'] ?? 0);

        if (
            $nextRunTimestamp > time()
            && !empty($scheduleStatus['last_completed_at'])
        ) {
            return 'ok';
        }

        return $nextRunTimestamp > 0 ? 'scheduled' : 'not_scheduled';
    }

    protected function acquireSiteLock(int $siteId): bool {
        $optionName = self::LOCK_OPTION_PREFIX . $siteId;
        $existing = (int)get_site_option($optionName, 0);

        if ($existing > 0 && (time() - $existing) > ($this->getTimeoutSeconds() + MINUTE_IN_SECONDS)) {
            delete_site_option($optionName);
        }

        return add_site_option($optionName, time());
    }

    protected function releaseSiteLock(int $siteId): void {
        delete_site_option(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function hasActiveSiteLock(int $siteId): bool {
        $startedAt = (int)get_site_option(self::LOCK_OPTION_PREFIX . $siteId, 0);

        return $startedAt > 0 && (time() - $startedAt) <= ($this->getTimeoutSeconds() + MINUTE_IN_SECONDS);
    }

    protected function recoverInterruptedRun(int $siteId, array $analysisStatus = [], array $scheduleStatus = []): bool {
        if ($siteId <= 0) {
            return false;
        }

        if (empty($scheduleStatus)) {
            $scheduleStatus = $this->getStatus($siteId);
        }

        if (empty($scheduleStatus['is_running']) || $this->hasActiveSiteLock($siteId)) {
            return false;
        }

        $startedAt = (string)($scheduleStatus['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $duration = $startedTimestamp > 0 ? max(0, time() - $startedTimestamp) : 0;
        $message = __('The storage analysis was interrupted before it could finish.', 'rrze-multisite-manager');

        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
        $this->markRunAborted($siteId, $startedAt, $duration, $message);
        $this->markPhaseAborted($siteId, $this->getRunningPhase($scheduleStatus), $message);
        do_action(
            'rrze.log.error',
            'RRZE-MSM: Storage analysis interrupted unexpectedly',
            [
                'site_id' => $siteId,
                'duration_seconds' => $duration,
                'status' => $analysisStatus,
            ]
        );

        return true;
    }

    protected function markRunStarted(int $siteId, bool $isNewRun): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        if ($isNewRun || empty($status['last_started_at'])) {
            $status['last_started_at'] = current_time('mysql', true);
        }

        $status['last_error'] = '';
        $status['is_running'] = true;

        if ($isNewRun) {
            $status['metadata_pending'] = true;
            $status['metadata_started'] = false;
            $status['phases'] = $this->getScheduledPhaseStatuses();
        }

        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function markPhaseStarted(int $siteId, string $phase): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $phaseStatus = is_array($status['phases'][$phase] ?? null) ? $status['phases'][$phase] : [];

        if (($phaseStatus['status'] ?? '') === 'running' && !empty($phaseStatus['started_at'])) {
            return;
        }

        if (!is_array($status)) {
            $status = [];
        }

        $status['phases'] = is_array($status['phases'] ?? null)
            ? $status['phases']
            : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = [
            'status' => 'running',
            'started_at' => current_time('mysql', true),
            'finished_at' => '',
            'message' => '',
        ];
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logPhaseEvent($siteId, $phase, 'started');
    }

    protected function markPhaseFinished(int $siteId, string $phase): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        $status['phases'] = is_array($status['phases'] ?? null)
            ? $status['phases']
            : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'complete',
                'finished_at' => current_time('mysql', true),
                'message' => '',
            ]
        );
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logPhaseEvent($siteId, $phase, 'finished');
    }

    protected function markPhaseFailed(int $siteId, string $phase, string $message): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        $status['phases'] = is_array($status['phases'] ?? null)
            ? $status['phases']
            : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'error',
                'finished_at' => current_time('mysql', true),
                'message' => $message,
            ]
        );
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logPhaseEvent($siteId, $phase, 'failed', $message);
    }

    protected function markPhaseAborted(int $siteId, string $phase, string $message): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        $status['phases'] = is_array($status['phases'] ?? null)
            ? $status['phases']
            : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'aborted',
                'finished_at' => current_time('mysql', true),
                'message' => $message,
            ]
        );
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logPhaseEvent($siteId, $phase, 'aborted', $message);
    }

    protected function getDefaultPhaseStatuses(): array {
        return [
            self::BASE_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'message' => ''],
            self::ORPHAN_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'message' => ''],
            self::METADATA_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'message' => ''],
        ];
    }

    protected function getRunningPhase(array $status): string {
        $phases = is_array($status['phases'] ?? null) ? $status['phases'] : [];

        foreach ([self::METADATA_PHASE, self::ORPHAN_PHASE, self::BASE_PHASE] as $phase) {
            if (($phases[$phase]['status'] ?? '') === 'running') {
                return $phase;
            }
        }

        return self::BASE_PHASE;
    }

    protected function getScheduledPhaseStatuses(): array {
        $phases = $this->getDefaultPhaseStatuses();

        foreach (array_keys($phases) as $phase) {
            $phases[$phase]['status'] = 'scheduled';
        }

        return $phases;
    }

    protected function logPhaseEvent(int $siteId, string $phase, string $event, string $message = ''): void {
        LoggingService::info(
            $this->config,
            sprintf(
                /* translators: 1: analysis phase, 2: site ID, 3: event name. */
                __('RRZE-MSM: Storage analysis phase %1$s for site %2$d %3$s', 'rrze-multisite-manager'),
                $phase,
                $siteId,
                $event
            ),
            [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'phase' => $phase,
                'event' => $event,
                'message' => $message,
            ]
        );
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

        $status = is_array($status) ? $status : [];
        $status = array_merge(
            $status,
            [
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_completed_at' => $finishedAt,
                'last_duration_seconds' => $duration,
                'last_was_aborted' => false,
                'last_error' => '',
                'is_running' => false,
                'metadata_pending' => false,
                'metadata_started' => false,
            ]
        );
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        LoggingService::info(
            $this->config,
            /* translators: %d: site ID. */
            sprintf(__('RRZE-MSM: Storage analysis (site %d) finished', 'rrze-multisite-manager'), $siteId),
            [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'duration_seconds' => $duration,
            ]
        );
    }

    protected function markRunAborted(int $siteId, string $startedAt, int $duration, string $message): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $status = is_array($status) ? $status : [];
        $status = array_merge(
            $status,
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
        update_blog_option($siteId, self::OPTION_STATUS, $status);
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

}
