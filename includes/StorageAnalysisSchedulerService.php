<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Metrics\StorageAnalysisService;

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
    protected const SCHEDULE_INITIALIZATION_STATE_OPTION = 'rrze_msm_storage_analysis_schedule_initialization_state';
    protected const SCHEDULE_INITIALIZATION_LOCK_OPTION = 'rrze_msm_storage_analysis_schedule_initialization_lock';
    protected const BATCH_SITE_IDS_OPTION = 'rrze_msm_storage_analysis_batch_site_ids';
    protected const ASSIGNMENTS_OPTION = 'rrze_msm_storage_analysis_assignments';
    protected const BATCH_LAST_SITE_ID_OPTION = 'rrze_msm_storage_analysis_batch_last_site_id';
    protected const BATCH_LOCK_OPTION = 'rrze_msm_storage_analysis_batch_lock';
    protected const REMOVED_SITE_IDS_OPTION = 'rrze_msm_storage_analysis_removed_site_ids';
    protected const FREQUENCY_RESCHEDULE_HOOK = 'rrze_msm_reschedule_storage_analysis_frequency';
    protected const FREQUENCY_RESCHEDULE_STATE_OPTION = 'rrze_msm_storage_analysis_frequency_reschedule_state';
    protected const MIGRATION_STATE_OPTION = 'rrze_msm_storage_analysis_migration_state';
    protected const MIGRATION_LOCK_OPTION = 'rrze_msm_storage_analysis_migration_lock';
    protected const BATCH_CONTINUATION_ARGS = ['rrze_msm_storage_analysis_batch' => true];
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_storage_analysis_lock_';
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';

    protected StorageAnalysisService $storageAnalysis;
    protected Config $config;
    /** @var array<int, int>|null */
    protected ?array $currentRecurringScheduleTimestamps = null;
    /** @var array<int, true>|null */
    protected ?array $recurringScheduledSiteIds = null;

    public function __construct(MetricsService|StorageAnalysisService $storageAnalysis, ?Config $config = null) {
        $this->storageAnalysis = $storageAnalysis instanceof StorageAnalysisService
            ? $storageAnalysis
            : new StorageAnalysisService($storageAnalysis);
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action($this->config->getStorageAnalysisHook(), [$this, 'runScheduledAnalysis'], 10, 2);
        add_action($this->config->getStorageAnalysisBatchHook(), [$this, 'runBatchScheduledAnalysis'], 10, 2);
        add_action(self::FREQUENCY_RESCHEDULE_HOOK, [$this, 'runFrequencyRescheduleBatch']);
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureRecurringSchedules'], 20);
    }

    public function registerSchedules(array $schedules): array {
        foreach ($this->config->getStorageAnalysisScheduleKeys() as $frequency => $scheduleKey) {
            $schedules[$scheduleKey] = [
                'interval' => $this->config->getSchedulerFrequencyHours((string)$frequency) * HOUR_IN_SECONDS,
                'display' => $this->config->getSchedulerFrequencyLabel((string)$frequency),
            ];
        }

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

    /** Queues a restartable migration after a configured frequency changes. */
    public function queueFrequencyReschedule(): void {
        $state = get_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION, []);

        if (!is_array($state) || empty($state['pending'])) {
            update_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION, [
                'pending' => true,
                'offset' => 0,
                'total' => (int)get_sites(['count' => true, 'number' => 1]),
            ]);
        }

        $this->inBatchCronContext(function (): void {
            if (!wp_next_scheduled(self::FREQUENCY_RESCHEDULE_HOOK)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::FREQUENCY_RESCHEDULE_HOOK);
            }
        });
    }

    public function runFrequencyRescheduleBatch(): void {
        $state = get_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION, []);

        if (!is_array($state) || empty($state['pending']) || $this->isScheduleMigrationInProgress()) {
            return;
        }

        $offset = max(0, (int)($state['offset'] ?? 0));
        $total = max(0, (int)($state['total'] ?? 0));
        $siteIds = get_sites(['fields' => 'ids', 'number' => 10, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($siteId <= 0 || $this->getNextRecurringScheduledTimestamp($siteId) <= 0) {
                continue;
            }

            $this->unscheduleSite($siteId);
            $this->scheduleRecurringAnalysisAt($siteId, time() + MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS)));
        }

        $state['offset'] = $offset + count($siteIds);

        if (empty($siteIds) || (int)$state['offset'] >= $total) {
            delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
            $this->markScheduleConfigurationCurrent();
            return;
        }

        update_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION, $state);
        $this->inBatchCronContext(function (): void {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::FREQUENCY_RESCHEDULE_HOOK);
        });
    }

    public function isScheduleMigrationInProgress(): bool {
        return (bool)get_site_option(self::MIGRATION_LOCK_OPTION, false);
    }

    public function canStartBatchNow(): bool {
        $lock = get_site_option(self::BATCH_LOCK_OPTION, []);
        $startedAt = is_array($lock) ? (int)($lock['started_at'] ?? 0) : (int)$lock;

        return !$this->isScheduleMigrationInProgress()
            && $startedAt <= 0
            && !empty($this->getBatchSiteIds())
            && (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->config->getStorageAnalysisBatchHook())) > 0;
    }

    public function startBatchNow(): bool {
        if (!$this->canStartBatchNow()) {
            return false;
        }

        return (bool)$this->inBatchCronContext(function (): bool {
            $hook = $this->config->getStorageAnalysisBatchHook();
            wp_clear_scheduled_hook($hook);
            $scheduled = (bool)wp_schedule_event(time(), $this->getScheduleKey(), $hook);
            if ($scheduled) {
                LoggingService::info($this->config, 'RRZE-MSM: Shared storage-analysis batch started early.', ['cron_site_id' => $this->getBatchCronSiteId(), 'batch_site_count' => count($this->getBatchSiteIds())]);
            }
            return $scheduled;
        });
    }

    /**
     * Explicit, restartable migration. One call processes a small group only.
     * It deliberately preserves all per-site analysis options and timestamps.
     *
     * @return array{phase: string, processed: int, total: int, scheduled: int, complete: bool}
     */
    public function migrateSchedulesBatch(int $batchSize = 25): array {
        $batchSize = max(1, min(100, $batchSize));
        $state = get_site_option(self::MIGRATION_STATE_OPTION, []);
        $state = is_array($state) ? $state : [];

        if (empty($state)) {
            $state = ['phase' => 'remove', 'offset' => 0, 'total' => (int)get_sites(['count' => true]), 'scheduled' => 0];
            update_site_option(self::MIGRATION_LOCK_OPTION, 1);
            $this->inBatchCronContext(fn(): bool => wp_clear_scheduled_hook($this->config->getStorageAnalysisBatchHook()));
            delete_site_option(self::BATCH_SITE_IDS_OPTION);
            delete_site_option(self::ASSIGNMENTS_OPTION);
            delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
            LoggingService::info($this->config, 'RRZE-MSM: Storage-analysis schedule migration started.', ['phase' => 'remove', 'total_sites' => $state['total'], 'batch_size' => $batchSize]);
        }

        $offset = max(0, (int)($state['offset'] ?? 0));
        $total = max(0, (int)($state['total'] ?? 0));
        $siteIds = get_sites(['fields' => 'ids', 'number' => $batchSize, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (($state['phase'] ?? 'remove') === 'remove') {
                $this->unscheduleSiteEvents($siteId);
            } elseif ($this->isSiteEligible($siteId) && $this->scheduleMigratedSite($siteId)) {
                $state['scheduled'] = (int)($state['scheduled'] ?? 0) + 1;
            }
        }

        $state['offset'] = min($total, $offset + count($siteIds));

        if ($state['offset'] >= $total) {
            if (($state['phase'] ?? 'remove') === 'remove') {
                $state['phase'] = 'schedule';
                $state['offset'] = 0;
                LoggingService::info($this->config, 'RRZE-MSM: Storage-analysis schedule migration removed old Cron events.', ['phase' => 'schedule', 'total_sites' => $total]);
            } else {
                delete_site_option(self::MIGRATION_STATE_OPTION);
                delete_site_option(self::MIGRATION_LOCK_OPTION);
                $this->clearRecurringScheduleCache();
                LoggingService::info($this->config, 'RRZE-MSM: Storage-analysis schedule migration completed.', ['total_sites' => $total, 'scheduled_sites' => (int)($state['scheduled'] ?? 0)]);
                return ['phase' => 'complete', 'processed' => $total, 'total' => $total, 'scheduled' => (int)($state['scheduled'] ?? 0), 'complete' => true];
            }
        }

        update_site_option(self::MIGRATION_STATE_OPTION, $state);
        LoggingService::info($this->config, 'RRZE-MSM: Storage-analysis schedule migration batch processed.', ['phase' => (string)$state['phase'], 'processed_sites' => (int)$state['offset'], 'total_sites' => $total, 'scheduled_sites' => (int)($state['scheduled'] ?? 0)]);
        return ['phase' => (string)$state['phase'], 'processed' => (int)$state['offset'], 'total' => $total, 'scheduled' => (int)($state['scheduled'] ?? 0), 'complete' => false];
    }

    protected function scheduleMigratedSite(int $siteId): bool {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $hasCompletedRun = is_array($status)
            && !empty($status['last_completed_at'])
            && empty($status['last_error'])
            && empty($status['last_was_aborted'])
            && (array_key_exists('last_duration_precise_seconds', $status) || array_key_exists('last_duration_seconds', $status));
        $mode = $hasCompletedRun
            ? $this->getCompletedRunAssignmentMode((float)($status['last_duration_precise_seconds'] ?? $status['last_duration_seconds']))
            : $this->getInitialAssignmentMode($siteId);

        if ($mode === 'batch') {
            $siteIds = $this->getBatchSiteIds();
            $siteIds[] = $siteId;
            update_site_option(self::BATCH_SITE_IDS_OPTION, array_values(array_unique(array_map('absint', $siteIds))));
            if ($this->scheduleBatchRecurringAt(time() + MINUTE_IN_SECONDS)) {
                $this->setSiteAssignment($siteId, 'batch');
                $this->logSiteInfo('Speicherplatzanalyse-Migration: Sammelbatch zugeordnet', $siteId, 'batch', ['used_completed_runtime' => $hasCompletedRun]);
                return true;
            }
            $this->removeBatchSite($siteId);
            return false;
        }

        $scheduled = $this->scheduleIndividualAnalysisAt($siteId, time() + MINUTE_IN_SECONDS + ($siteId % MINUTE_IN_SECONDS));
        if ($scheduled) {
            $this->logSiteInfo('Speicherplatzanalyse-Migration: Einzelauftrag zugeordnet', $siteId, 'site', ['used_completed_runtime' => $hasCompletedRun]);
        }
        return $scheduled;
    }

    /**
     * Determines the initial task type without creating or changing a schedule.
     */
    public function getInitialAssignmentMode(int $siteId): string {
        if ($siteId <= 0 || !get_site($siteId)) {
            return 'site';
        }

        switch_to_blog($siteId);

        try {
            $counts = wp_count_posts('attachment');
            $mediaCount = is_object($counts) ? (int)($counts->inherit ?? 0) : 0;
        } finally {
            restore_current_blog();
        }

        return $mediaCount < $this->config->getStorageAnalysisBatchMediaThreshold() ? 'batch' : 'site';
    }

    /**
     * Call only after a successful complete run with its precise elapsed time.
     */
    public function getCompletedRunAssignmentMode(float $durationSeconds): string {
        return $durationSeconds < $this->config->getStorageAnalysisBatchRuntimeThresholdSeconds() ? 'batch' : 'site';
    }

    public function syncRecurringSchedules(): void {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'domain',
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
        if ($this->isScheduleMigrationInProgress()) {
            return 0;
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'domain',
            'order' => 'ASC',
        ]);
        $initialized = 0;

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteAwaitingSchedule($siteId)) {
                continue;
            }

            if ($this->scheduleRecurringAnalysis($siteId)) {
                $initialized++;
            }
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
        $state = $this->startScheduleInitialization();

        if (empty($state['run_id'])) {
            return ['initialized' => 0, 'processed' => 0, 'total' => 0, 'complete' => false];
        }

        return $this->runScheduleInitializationBatch((string)$state['run_id'], $batchSize);
    }

    /**
     * Starts, or returns, an explicit browser-driven initialization run.
     * The snapshot prevents pagination, searches, or concurrent site deletions
     * from changing which websites belong to this run.
     *
     * @return array{run_id: string, initialized: int, processed: int, total: int, complete: bool}
     */
    public function startScheduleInitialization(): array {
        if ($this->isScheduleMigrationInProgress()) {
            return ['run_id' => '', 'initialized' => 0, 'processed' => 0, 'total' => 0, 'complete' => false];
        }

        $state = get_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION, []);
        $state = is_array($state) ? $state : [];

        if (empty($state['run_id']) || !isset($state['site_ids']) || !is_array($state['site_ids'])) {
            $queryArgs = [
                'fields' => 'ids',
                'number' => 0,
                'orderby' => 'id',
                'order' => 'ASC',
                'archived' => 0,
                'spam' => 0,
                'deleted' => 0,
            ];
            $scheduledSiteIds = array_keys($this->getRecurringScheduledSiteIds());

            if (!empty($scheduledSiteIds)) {
                $queryArgs['site__not_in'] = $scheduledSiteIds;
            }

            $siteIds = get_sites($queryArgs);
            $state = [
                'run_id' => wp_generate_uuid4(),
                'site_ids' => array_values(array_map('absint', $siteIds)),
                'processed' => 0,
                'initialized' => 0,
            ];
            update_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION, $state);
            delete_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION);
        }

        return $this->getScheduleInitializationProgress($state, false);
    }

    /**
     * Processes exactly one bounded browser-requested group. It never creates
     * a Cron event; the dialog requests the following group after completion.
     *
     * @return array{run_id: string, initialized: int, processed: int, total: int, complete: bool, busy?: bool}
     */
    public function runScheduleInitializationBatch(string $runId, int $batchSize = 25): array {
        $state = get_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION, []);
        $state = is_array($state) ? $state : [];

        if ($runId === '' || !hash_equals((string)($state['run_id'] ?? ''), $runId) || !isset($state['site_ids']) || !is_array($state['site_ids'])) {
            return ['run_id' => '', 'initialized' => 0, 'processed' => 0, 'total' => 0, 'complete' => false];
        }

        if (!$this->acquireScheduleInitializationLock($runId)) {
            $progress = $this->getScheduleInitializationProgress($state, false);
            $progress['busy'] = true;
            return $progress;
        }

        try {
            $batchSize = max(1, min(100, $batchSize));
            $processed = max(0, (int)($state['processed'] ?? 0));
            $siteIds = array_slice($state['site_ids'], $processed, $batchSize);
            $initialized = 0;

            update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);

            foreach ($siteIds as $siteId) {
                $siteId = (int)$siteId;

                if ($this->isSiteAwaitingSchedule($siteId) && $this->scheduleRecurringAnalysis($siteId)) {
                    $initialized++;
                }
            }

            $state['processed'] = $processed + count($siteIds);
            $state['initialized'] = (int)($state['initialized'] ?? 0) + $initialized;
            $progress = $this->getScheduleInitializationProgress($state, false);

            if ($progress['complete']) {
                delete_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION);
                delete_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION);
                update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
            } else {
                update_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION, $state);
            }

            return $progress;
        } finally {
            $lock = get_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, []);
            if (is_array($lock) && (string)($lock['run_id'] ?? '') === $runId) {
                delete_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION);
            }
        }
    }

    protected function acquireScheduleInitializationLock(string $runId): bool {
        $lock = get_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, []);

        if (is_array($lock) && (int)($lock['expires_at'] ?? 0) < time()) {
            delete_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION);
        }

        return add_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, [
            'run_id' => $runId,
            'expires_at' => time() + (5 * MINUTE_IN_SECONDS),
        ]);
    }

    /** @return array{run_id: string, initialized: int, processed: int, total: int, complete: bool} */
    protected function getScheduleInitializationProgress(array $state, bool $complete): array {
        $total = count((array)($state['site_ids'] ?? []));
        $processed = min($total, max(0, (int)($state['processed'] ?? 0)));

        return [
            'run_id' => (string)($state['run_id'] ?? ''),
            'initialized' => max(0, (int)($state['initialized'] ?? 0)),
            'processed' => $processed,
            'total' => $total,
            'complete' => $complete || $processed >= $total,
        ];
    }

    /** @param array<int, int> $siteIds */
    public function scheduleSelectedActiveSites(array $siteIds): int {
        if ($this->isScheduleMigrationInProgress()) {
            return 0;
        }

        $initialized = 0;

        foreach (array_unique(array_map('absint', $siteIds)) as $siteId) {
            $this->allowSiteScheduling($siteId);

            if ($siteId <= 0 || !$this->isSiteEligible($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
                continue;
            }

            if ($this->scheduleRecurringAnalysis($siteId)) {
                $initialized++;
            }
        }

        return $initialized;
    }

    /** @param array<int, int> $siteIds */
    public function removeSelectedScheduledSites(array $siteIds): int {
        $removed = 0;
        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];

        foreach (array_unique(array_map('absint', $siteIds)) as $siteId) {
            if (
                $siteId <= 0
                || (
                    $this->getNextRecurringScheduledTimestamp($siteId) <= 0
                    && !in_array($siteId, $this->getBatchSiteIds(), true)
                    && !isset($assignments[$siteId])
                )
            ) {
                continue;
            }

            $this->unscheduleSite($siteId);
            $this->markSiteSchedulingRemoved($siteId);
            $removed++;
        }

        return $removed;
    }

    public function getUnscheduledEligibleSiteCount(): int {
        // Kept for backward compatibility. The monitoring page deliberately
        // does not determine this dynamically, because that opens options for
        // every website in a network.
        return 0;
    }

    protected function isSiteSchedulingRemoved(int $siteId): bool {
        $siteIds = get_site_option(self::REMOVED_SITE_IDS_OPTION, []);

        return $siteId > 0 && is_array($siteIds) && in_array($siteId, array_map('absint', $siteIds), true);
    }

    protected function markSiteSchedulingRemoved(int $siteId): void {
        if ($siteId <= 0) {
            return;
        }

        $siteIds = get_site_option(self::REMOVED_SITE_IDS_OPTION, []);
        $siteIds = is_array($siteIds) ? array_map('absint', $siteIds) : [];
        $siteIds[] = $siteId;
        update_site_option(self::REMOVED_SITE_IDS_OPTION, array_values(array_unique(array_filter($siteIds))));
    }

    protected function allowSiteScheduling(int $siteId): void {
        if ($siteId <= 0) {
            return;
        }

        $siteIds = get_site_option(self::REMOVED_SITE_IDS_OPTION, []);

        if (!is_array($siteIds) || !in_array($siteId, array_map('absint', $siteIds), true)) {
            return;
        }

        $siteIds = array_values(array_filter(array_map('absint', $siteIds), static fn(int $id): bool => $id > 0 && $id !== $siteId));

        if (empty($siteIds)) {
            delete_site_option(self::REMOVED_SITE_IDS_OPTION);
            return;
        }

        update_site_option(self::REMOVED_SITE_IDS_OPTION, $siteIds);
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
            $this->unscheduleSite($siteId);

            if (!$this->isSiteEligible($siteId)) {
                $this->storageAnalysis->clearProcessStates($siteId);
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

        $this->unscheduleSite($siteId);
        $this->storageAnalysis->clearProcessStates($siteId);

        return true;
    }

    public static function clearScheduledEvents(?Config $config = null, bool $acrossNetwork = true): int {
        $config = $config ?? new Config();
        $removed = 0;
        $currentSiteId = get_current_blog_id();
        $siteIds = $acrossNetwork
            ? get_sites([
                'fields' => 'ids',
                'number' => 0,
            ])
            : [$currentSiteId];

        if (empty($siteIds)) {
            $siteIds = [$currentSiteId];
        }

        foreach (array_unique(array_map('absint', $siteIds)) as $siteId) {
            $switched = $siteId > 0 && $siteId !== $currentSiteId;

            if ($switched) {
                switch_to_blog($siteId);
            }

            try {
                $cron = _get_cron_array();
                $removedOnSite = 0;

                if (!is_array($cron)) {
                    continue;
                }

                foreach ($cron as $timestamp => $events) {
                    if (!is_array($events)) {
                        continue;
                    }

                    foreach ([$config->getStorageAnalysisHook(), $config->getStorageAnalysisBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK] as $hook) {
                        if (empty($events[$hook])) {
                            continue;
                        }

                        $removedOnSite += count((array)$events[$hook]);
                        unset($cron[$timestamp][$hook]);
                    }

                    if (empty($cron[$timestamp])) {
                        unset($cron[$timestamp]);
                    }
                }

                if ($removedOnSite > 0 && !_set_cron_array($cron)) {
                    continue;
                }

                $removed += $removedOnSite;
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
        }

        delete_site_option(self::SCHEDULE_SIGNATURE_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION);
        delete_site_option(self::BATCH_SITE_IDS_OPTION);
        delete_site_option(self::ASSIGNMENTS_OPTION);
        delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::BATCH_LOCK_OPTION);
        delete_site_option(self::MIGRATION_STATE_OPTION);
        delete_site_option(self::MIGRATION_LOCK_OPTION);
        delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);

        return $removed;
    }

    public function startAnalysisNow(int $siteId): bool {
        if ($this->isScheduleMigrationInProgress()) {
            return false;
        }

        $this->recoverInterruptedRun($siteId);
        $status = $this->storageAnalysis->getProcessStatus($siteId);

        if (!$this->isSiteEligible($siteId) || $this->isSiteRunRunning($siteId, $status)) {
            $this->deactivateIneligibleSite($siteId);
            return false;
        }

        $mode = $this->getSiteAssignmentMode($siteId);

        if ($mode === 'batch') {
            return false;
        }

        $this->unscheduleSite($siteId);
        $scheduled = $mode === 'site'
            ? $this->scheduleIndividualAnalysisAt($siteId, time())
            : $this->scheduleRecurringAnalysisAt($siteId, time());

        if (!$scheduled) {
            return false;
        }

        $this->storageAnalysis->clearProcessStates($siteId);

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

    public function runScheduledAnalysis(int $siteId = 0, string $phase = self::BASE_PHASE, bool $fromBatch = false): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isScheduleMigrationInProgress()) {
            return;
        }

        $isBatchSite = $this->getSiteAssignmentMode($siteId) === 'batch';

        if ($fromBatch !== $isBatchSite) {
            if (!$fromBatch && $isBatchSite) {
                // A leftover individual event must not duplicate the shared run.
                $this->unscheduleSiteEvents($siteId);
            }
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

    /**
     * Processes at most one website per request. The recurring central event
     * starts each pass; single events continue it in short intervals.
     */
    public function runBatchScheduledAnalysis(...$args): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isScheduleMigrationInProgress()) {
            return;
        }

        if (get_current_blog_id() !== $this->getBatchCronSiteId()) {
            // A batch event must never run from a subsite's Cron table.
            wp_clear_scheduled_hook($this->config->getStorageAnalysisBatchHook());
            return;
        }

        $hook = $this->config->getStorageAnalysisBatchHook();

        if ((int)wp_next_scheduled($hook) <= 0) {
            // A removed recurring task must not be revived by a stale continuation.
            return;
        }

        $lockToken = $this->acquireBatchLock();

        if ($lockToken === '') {
            return;
        }

        $processedSiteId = 0;
        $isContinuation = false;
        $isTargetedSiteRun = false;
        LoggingService::info(
            $this->config,
            'RRZE-MSM: Shared storage-analysis batch started.',
            ['cron_site_id' => $this->getBatchCronSiteId()]
        );

        try {
            $siteIds = $this->getBatchSiteIds();

            if (empty($siteIds)) {
                delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
                wp_clear_scheduled_hook($hook);
                return;
            }

            $lastSiteId = max(0, (int)get_site_option(self::BATCH_LAST_SITE_ID_OPTION, 0));
            $isContinuation = in_array(true, $args, true);
            $requestedSiteId = 0;

            foreach ($args as $arg) {
                if (is_int($arg) && $arg > 0) {
                    $requestedSiteId = $arg;
                    break;
                }
            }

            $isTargetedSiteRun = $requestedSiteId > 0;

            if ($isTargetedSiteRun && !in_array($requestedSiteId, $siteIds, true)) {
                return;
            }

            if (!$isContinuation && (int)wp_next_scheduled($hook, self::BATCH_CONTINUATION_ARGS) > 0) {
                return;
            }

            $siteId = $isTargetedSiteRun ? $requestedSiteId : $this->getNextBatchSiteId($siteIds, $lastSiteId);

            if ($siteId <= 0) {
                delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);

                if ($isContinuation) {
                    return;
                }

                $siteId = (int)$siteIds[0];
            }

            $processedSiteId = $siteId;

            if ($this->isSiteEligible($siteId)) {
                $this->logSiteInfo('Speicherplatzanalyse-Sammelbatch verarbeitet Site', $siteId, 'batch', ['continuation' => $isContinuation, 'batch_site_count' => count($siteIds)]);
                try {
                    $this->runScheduledAnalysis($siteId, self::SCHEDULED_PHASE, true);
                } catch (\Throwable $exception) {
                    $this->logStorageAnalysisError($siteId, self::SCHEDULED_PHASE, $exception->getMessage());
                }
            } else {
                $this->deactivateIneligibleSite($siteId);
            }

            $siteIds = $this->getBatchSiteIds();

            if ($isTargetedSiteRun) {
                return;
            }

            if ($this->getNextBatchSiteId($siteIds, $siteId) <= 0) {
                delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
                return;
            }

            update_site_option(self::BATCH_LAST_SITE_ID_OPTION, $siteId);
            $this->scheduleBatchContinuation(time() + 5);
        } finally {
            $this->releaseBatchLock($lockToken);
            $context = [
                'cron_site_id' => $this->getBatchCronSiteId(),
                'continuation' => $isContinuation,
                'targeted_site_run' => $isTargetedSiteRun,
            ];

            if ($processedSiteId > 0) {
                $this->logSiteInfo('Speicherplatzanalyse-Sammelbatch beendet', $processedSiteId, 'batch', $context);
            } else {
                LoggingService::info($this->config, 'RRZE-MSM: Speicherplatzanalyse-Sammelbatch beendet.', $context);
            }
        }
    }

    /** @return array<int, int> */
    protected function getBatchSiteIds(): array {
        $storedSiteIds = get_site_option(self::BATCH_SITE_IDS_OPTION, []);
        $siteIds = is_array($storedSiteIds) ? array_filter(array_map('absint', $storedSiteIds)) : [];
        $siteIds = array_values(array_unique($siteIds));
        sort($siteIds, SORT_NUMERIC);

        return $siteIds;
    }

    /** @return 'batch'|'site'|'unassigned' */
    public function getSiteAssignmentMode(int $siteId): string {
        if ($siteId <= 0) {
            return 'unassigned';
        }

        if (in_array($siteId, $this->getBatchSiteIds(), true)) {
            $batchTimestamp = (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->config->getStorageAnalysisBatchHook()));

            if ($batchTimestamp > 0) {
                return 'batch';
            }
        }

        return $this->getNextRecurringScheduledTimestamp($siteId) > 0 ? 'site' : 'unassigned';
    }

    /**
     * Writes a site-specific information log entry in one consistent form.
     *
     * @param 'batch'|'site'|'unassigned' $assignmentMode
     * @param array<string, mixed>         $context
     */
    protected function logSiteInfo(string $label, int $siteId, string $assignmentMode = 'unassigned', array $context = []): void {
        if ($assignmentMode === 'unassigned') {
            $assignmentMode = $this->getSiteAssignmentMode($siteId);
        }

        $assignment = $assignmentMode === 'batch' ? 'Batch' : 'Single';
        $context = array_merge($context, [
            'site_id' => $siteId,
            'assignment' => $assignment,
        ]);

        LoggingService::info(
            $this->config,
            sprintf('RRZE-MSM: %s (Site Id: %d, Assignment: %s)', $label, $siteId, $assignment),
            $context
        );
    }

    protected function setSiteAssignment(int $siteId, string $mode): void {
        if ($siteId <= 0 || !in_array($mode, ['batch', 'site'], true)) {
            return;
        }

        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];
        $assignments[$siteId] = $mode;
        update_site_option(self::ASSIGNMENTS_OPTION, $assignments);
    }

    protected function removeSiteAssignment(int $siteId): void {
        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);

        if (!is_array($assignments) || !isset($assignments[$siteId])) {
            return;
        }

        unset($assignments[$siteId]);
        update_site_option(self::ASSIGNMENTS_OPTION, $assignments);
    }

    protected function removeBatchSite(int $siteId): void {
        $siteIds = $this->getBatchSiteIds();

        if (!in_array($siteId, $siteIds, true)) {
            return;
        }

        $siteIds = array_values(array_diff($siteIds, [$siteId]));
        update_site_option(self::BATCH_SITE_IDS_OPTION, $siteIds);

        if (empty($siteIds)) {
            $this->inBatchCronContext(function (): void {
                wp_clear_scheduled_hook($this->config->getStorageAnalysisBatchHook());
            });
            delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
        }
    }

    /** @param array<int, int> $siteIds */
    protected function getNextBatchSiteId(array $siteIds, int $lastSiteId): int {
        foreach ($siteIds as $siteId) {
            if ($siteId > $lastSiteId) {
                return $siteId;
            }
        }

        return 0;
    }

    protected function getBatchCronSiteId(): int {
        return (int)get_main_site_id(get_current_network_id());
    }

    protected function inBatchCronContext(callable $callback): mixed {
        $centralSiteId = $this->getBatchCronSiteId();

        if ($centralSiteId <= 0 || $centralSiteId === get_current_blog_id()) {
            return $callback();
        }

        switch_to_blog($centralSiteId);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }

    protected function scheduleBatchRecurringAt(int $timestamp): bool {
        if (empty($this->getBatchSiteIds())) {
            return false;
        }

        return (bool)$this->inBatchCronContext(function () use ($timestamp): bool {
            $hook = $this->config->getStorageAnalysisBatchHook();

            if ((int)wp_next_scheduled($hook) > 0) {
                return true;
            }

            $scheduled = (bool)wp_schedule_event(max(time(), $timestamp), $this->getScheduleKey(), $hook);

            if ($scheduled) {
                LoggingService::info(
                    $this->config,
                    'RRZE-MSM: Central shared storage-analysis batch scheduled.',
                    [
                        'cron_site_id' => $this->getBatchCronSiteId(),
                        'next_run_timestamp' => $timestamp,
                        'schedule' => $this->getScheduleKey(),
                        'batch_site_count' => count($this->getBatchSiteIds()),
                    ]
                );
            }

            return $scheduled;
        });
    }

    protected function scheduleBatchContinuation(int $timestamp): void {
        $this->inBatchCronContext(function () use ($timestamp): void {
            $hook = $this->config->getStorageAnalysisBatchHook();

            if ((int)wp_next_scheduled($hook) > 0 && (int)wp_next_scheduled($hook, self::BATCH_CONTINUATION_ARGS) <= 0) {
                wp_schedule_single_event($timestamp, $hook, self::BATCH_CONTINUATION_ARGS);
            }
        });
    }

    protected function acquireBatchLock(): string {
        $existing = get_site_option(self::BATCH_LOCK_OPTION, []);
        $startedAt = is_array($existing) ? (int)($existing['started_at'] ?? 0) : (int)$existing;

        if ($startedAt > 0 && (time() - $startedAt) > ($this->getTimeoutSeconds() + MINUTE_IN_SECONDS)) {
            delete_site_option(self::BATCH_LOCK_OPTION);
        }

        $token = wp_generate_uuid4();

        return add_site_option(self::BATCH_LOCK_OPTION, ['started_at' => time(), 'token' => $token]) ? $token : '';
    }

    protected function releaseBatchLock(string $token): void {
        $existing = get_site_option(self::BATCH_LOCK_OPTION, []);

        if (is_array($existing) && ($existing['token'] ?? '') === $token) {
            delete_site_option(self::BATCH_LOCK_OPTION);
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

            $this->logSiteInfo('Speicherplatzanalyse übersprungen', $siteId, 'unassigned', ['reason' => 'analysis_already_running']);
            return;
        }

        // Wall-clock timestamps remain part of the visible process status.
        // Use a monotonic clock exclusively for the classification decision so
        // an NTP adjustment cannot move a website across the threshold.
        $runStartedAt = hrtime(true);

        $this->storageAnalysis->clearProcessStates($siteId);
        $this->markRunStarted($siteId, true);
        $this->extendRuntimeLimit();
        $this->logSiteInfo('Speicherplatzanalyse gestartet', $siteId, 'unassigned', ['phase' => self::BASE_PHASE]);

        $deadline = time() + $this->getTimeoutSeconds();
        $phase = self::BASE_PHASE;

        try {
            foreach ([self::BASE_PHASE, self::ORPHAN_PHASE, self::METADATA_PHASE] as $phase) {
                $this->runAnalysisPhaseToCompletion($siteId, $phase, $deadline);
            }

            $durationSeconds = max(0.0, (hrtime(true) - $runStartedAt) / 1000000000);
            $this->markRunFinished($siteId, $durationSeconds);

            try {
                $this->updateCompletedRunAssignment($siteId, $durationSeconds);
            } catch (\Throwable $exception) {
                // The analysis result is valid even if creating its next Cron
                // event fails. Do not turn a completed run into a failed one.
                $this->logStorageAnalysisError($siteId, self::SCHEDULED_PHASE, $exception->getMessage());
            }
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
                $this->storageAnalysis->getProgressContext($siteId, $phase)
            );
        }
    }

    protected function runAnalysisPhaseToCompletion(int $siteId, string $phase, int $deadline): void {
        $restartMetadataAnalysis = true;

        $this->markPhaseStarted($siteId, $phase);

        while (time() < $deadline) {
            if ($phase === self::BASE_PHASE) {
                $result = $this->storageAnalysis->runBaseBatch($siteId);
                $isComplete = (($result['status']['base']['status'] ?? '') === 'complete');
            } elseif ($phase === self::ORPHAN_PHASE) {
                $result = $this->storageAnalysis->runOrphanBatch($siteId);
                $isComplete = (($result['status']['orphan']['status'] ?? '') === 'complete');
            } else {
                $result = $this->storageAnalysis->runMediaMetadataBatch($siteId, $restartMetadataAnalysis);
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
        $progressContext = $this->storageAnalysis->getProgressContext($siteId, $phase);
        $startedAt = (string)($status['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $duration = $startedTimestamp > 0 ? max(0, time() - $startedTimestamp) : 0;

        $this->storageAnalysis->clearProcessStates($siteId);
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
        return $this->getSiteProcessesPageBySchedule($page, $perPage, $urlSearch, true);
    }

    /**
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool, total: int}
     */
    public function getUnscheduledActiveSiteProcessesPage(int $page, int $perPage, string $urlSearch = ''): array {
        return $this->getSiteProcessesPageBySchedule($page, $perPage, $urlSearch, false);
    }

    /**
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool, total: int}
     */
    protected function getSiteProcessesPageBySchedule(int $page, int $perPage, string $urlSearch, bool $scheduled): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $scheduledSiteIds = array_keys($this->getRecurringScheduledSiteIds());

        if ($scheduled && empty($scheduledSiteIds)) {
            return ['processes' => [], 'has_more' => false, 'total' => 0];
        }

        $queryArgs = [
            'fields' => 'ids',
            'number' => $perPage + 1,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'domain',
            'order' => 'ASC',
        ];

        if ($scheduled) {
            $queryArgs['site__in'] = $scheduledSiteIds;
        } else {
            $queryArgs['archived'] = 0;
            $queryArgs['spam'] = 0;
            $queryArgs['deleted'] = 0;

            if (!empty($scheduledSiteIds)) {
                $queryArgs['site__not_in'] = $scheduledSiteIds;
            }
        }

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
        $analysisStatus = $this->storageAnalysis->getProcessStatus($siteId);
        $scheduleStatus = $this->getStatus($siteId);

        $this->recoverInterruptedRun($siteId, $analysisStatus, $scheduleStatus);
        $analysisStatus = $this->storageAnalysis->getProcessStatus($siteId);
        $scheduleStatus = $this->getStatus($siteId);
        $isEligible = $this->isSiteEligible($siteId);
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
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
            'schedule_mode' => $assignmentMode,
            'is_due' => $isDue,
            'cycle' => $isEligible ? $this->getScheduleLabel() : '',
            'last_started_at' => (string)($scheduleStatus['last_started_at'] ?? ''),
            'last_run' => (string)($scheduleStatus['last_completed_at'] ?? ''),
            // The monitoring table describes the configured recurrence, not internal follow-up batches.
            'next_run_timestamp' => $nextRunTimestamp,
            'last_duration_seconds' => (int)($scheduleStatus['last_duration_seconds'] ?? 0),
            'last_was_aborted' => !empty($scheduleStatus['last_was_aborted']),
            'phases' => is_array($scheduleStatus['phases'] ?? null) ? $scheduleStatus['phases'] : [],
            'can_start_now' => $isEligible && $assignmentMode !== 'batch' && !$isDue && !$this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
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

    protected function isSiteAwaitingSchedule(int $siteId): bool {
        if (!$this->isSiteEligible($siteId)) {
            return false;
        }

        $status = $this->getStatus($siteId);

        // The setup action applies to every eligible website without a current
        // recurring task. A completed historic analysis must not prevent a
        // website from being scheduled again after tasks were removed.
        return $this->getNextRecurringScheduledTimestamp($siteId) <= 0
            && empty($status['is_running']);
    }

    protected function scheduleRecurringAnalysis(int $siteId): bool {
        $delay = MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS));
        return $this->scheduleRecurringAnalysisAt($siteId, time() + $delay);
    }

    protected function scheduleRecurringAnalysisAt(int $siteId, int $timestamp): bool {
        if (!$this->isSiteEligible($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return false;
        }

        $this->unscheduleSiteEvents($siteId);
        $this->removeBatchSite($siteId);
        $this->removeSiteAssignment($siteId);

        if ($this->getInitialAssignmentMode($siteId) === 'batch') {
            $hadBatchSchedule = (bool)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->config->getStorageAnalysisBatchHook()));
            $siteIds = $this->getBatchSiteIds();
            $siteIds[] = $siteId;
            update_site_option(self::BATCH_SITE_IDS_OPTION, array_values(array_unique($siteIds)));

            if (!$this->scheduleBatchRecurringAt($timestamp)) {
                $this->removeBatchSite($siteId);
                return false;
            }

            $this->setSiteAssignment($siteId, 'batch');
            $this->clearRecurringScheduleCache();
            $this->logSiteInfo('Speicherplatzanalyse dem Sammelbatch zugeordnet', $siteId, 'batch', [
                'next_run_timestamp' => $timestamp,
                'media_threshold' => $this->config->getStorageAnalysisBatchMediaThreshold(),
            ]);
            if ($hadBatchSchedule) {
                $this->scheduleBatchContinuation(time() + MINUTE_IN_SECONDS);
            }
            return true;
        }

        return $this->scheduleIndividualAnalysisAt($siteId, $timestamp);
    }

    protected function scheduleIndividualAnalysisAt(int $siteId, int $timestamp): bool {
        $scheduled = (bool)$this->inSiteCronContext($siteId, function () use ($siteId, $timestamp): bool {
            return (bool)wp_schedule_event(
                max(time(), $timestamp),
                $this->getScheduleKey(),
                $this->config->getStorageAnalysisHook(),
                [$siteId, self::SCHEDULED_PHASE]
            );
        });

        if ($scheduled) {
            $this->setSiteAssignment($siteId, 'site');
            $this->clearRecurringScheduleCache();
            $this->logSiteInfo('Speicherplatzanalyse dem Einzelauftrag zugeordnet', $siteId, 'site', [
                'next_run_timestamp' => $timestamp,
                'media_threshold' => $this->config->getStorageAnalysisBatchMediaThreshold(),
            ]);
        }

        return $scheduled;
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
        foreach (array_unique([$siteId, $this->getBatchCronSiteId()]) as $cronSiteId) {
            $this->inSiteCronContext($cronSiteId, function () use ($siteId, $phase): void {
                wp_clear_scheduled_hook($this->config->getStorageAnalysisHook(), [$siteId, $phase]);
            });
        }
        $this->clearRecurringScheduleCache();
    }

    protected function unscheduleSiteEvents(int $siteId): void {
        foreach ([self::BASE_PHASE, self::ORPHAN_PHASE, self::METADATA_PHASE, self::SCHEDULED_PHASE, self::LEGACY_ACTIVE_PHASE] as $phase) {
            $this->unschedule($siteId, $phase);
        }
    }

    protected function unscheduleSite(int $siteId): void {
        $this->unscheduleSiteEvents($siteId);
        $this->removeBatchSite($siteId);
        $this->removeSiteAssignment($siteId);
    }

    protected function inSiteCronContext(int $siteId, callable $callback): mixed {
        if ($siteId <= 0 || $siteId === get_current_blog_id()) {
            return $callback();
        }

        switch_to_blog($siteId);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }

    protected function getNextRecurringScheduledTimestamp(int $siteId): int {
        if ($siteId <= 0) {
            return 0;
        }

        if (in_array($siteId, $this->getBatchSiteIds(), true)) {
            $batchTimestamp = (int)$this->inBatchCronContext(function (): int {
                return (int)wp_next_scheduled($this->config->getStorageAnalysisBatchHook());
            });

            if ($batchTimestamp > 0) {
                return $batchTimestamp;
            }
        }

        $this->loadRecurringScheduleCache();

        $centralTimestamp = (int)($this->currentRecurringScheduleTimestamps[$siteId] ?? 0);
        $siteTimestamp = (int)$this->inSiteCronContext($siteId, function () use ($siteId): int {
            return (int)wp_next_scheduled($this->config->getStorageAnalysisHook(), [$siteId, self::SCHEDULED_PHASE]);
        });

        if ($centralTimestamp <= 0) {
            return $siteTimestamp;
        }

        return $siteTimestamp > 0 ? min($siteTimestamp, $centralTimestamp) : $centralTimestamp;
    }

    protected function hasRecurringScheduledAnalysis(int $siteId): bool {
        return $this->getNextRecurringScheduledTimestamp($siteId) > 0;
    }

    /**
     * @return array<int, true>
     */
    protected function getRecurringScheduledSiteIds(): array {
        $this->loadRecurringScheduleCache();

        $siteIds = $this->recurringScheduledSiteIds ?? [];
        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);

        foreach ((array)$assignments as $siteId => $mode) {
            if (absint($siteId) > 0 && in_array($mode, ['batch', 'site'], true)) {
                $siteIds[absint($siteId)] = true;
            }
        }

        if ((int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->config->getStorageAnalysisBatchHook())) > 0) {
            foreach ($this->getBatchSiteIds() as $siteId) {
                $siteIds[$siteId] = true;
            }
        }

        return $siteIds;
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
        $cron = $this->inBatchCronContext(fn(): array => (array)_get_cron_array());
        foreach ((array)$cron as $timestamp => $events) {
            foreach ((array)($events[$this->config->getStorageAnalysisHook()] ?? []) as $event) {
                $args = (array)($event['args'] ?? []);
                $siteId = (int)($args[0] ?? 0);

                if (empty($event['schedule']) || ($args[1] ?? '') !== self::SCHEDULED_PHASE || $siteId <= 0) {
                    continue;
                }

                $siteIds[$siteId] = true;

                $eventTimestamp = (int)$timestamp;

                if ($eventTimestamp > 0 && (!isset($currentTimestamps[$siteId]) || $eventTimestamp < $currentTimestamps[$siteId])) {
                    $currentTimestamps[$siteId] = $eventTimestamp;
                }
            }
        }

        $this->currentRecurringScheduleTimestamps = $currentTimestamps;
        $this->recurringScheduledSiteIds = $siteIds;
    }

    protected function getScheduleKey(): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $frequency = is_array($options) ? (string)($options['monitoring_storage_analysis_frequency'] ?? 'twiceweekly') : 'twiceweekly';

        return $this->config->getStorageAnalysisScheduleKey($frequency);
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
        $options = get_site_option($this->config->getOptionName(), []);
        $frequency = is_array($options) ? (string)($options['monitoring_storage_analysis_frequency'] ?? 'twiceweekly') : 'twiceweekly';

        return $this->config->getSchedulerFrequencyLabel($frequency);
    }

    protected function getTimeoutSeconds(): int {
        return $this->config->getStorageAnalysisTimeoutSeconds();
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

        $this->storageAnalysis->clearProcessStates($siteId);
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
        $this->logSiteInfo(sprintf('Speicherplatzanalyse: Phase %s %s', $phase, $event), $siteId, 'unassigned', [
            'site_url' => get_home_url($siteId, '/'),
            'phase' => $phase,
            'event' => $event,
            'message' => $message,
        ]);
    }

    protected function markMediaMetadataStarted(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);

        if (!is_array($status)) {
            $status = [];
        }

        $status['metadata_started'] = true;
        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function markRunFinished(int $siteId, float $durationSeconds): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $startedAt = is_array($status) ? (string)($status['last_started_at'] ?? '') : '';
        $finishedAt = current_time('mysql', true);
        $durationSeconds = max(0.0, $durationSeconds);
        // Keep the established whole-second value for existing monitoring
        // displays, while retaining the measured value for assignment.
        $duration = (int)floor($durationSeconds);

        $status = is_array($status) ? $status : [];
        $status = array_merge(
            $status,
            [
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_completed_at' => $finishedAt,
                'last_duration_seconds' => $duration,
                'last_duration_precise_seconds' => $durationSeconds,
                'last_was_aborted' => false,
                'last_error' => '',
                'is_running' => false,
                'metadata_pending' => false,
                'metadata_started' => false,
            ]
        );
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logSiteInfo('Speicherplatzanalyse beendet', $siteId, 'unassigned', [
            'site_url' => get_home_url($siteId, '/'),
            'duration_seconds' => $durationSeconds,
            'runtime_threshold_seconds' => $this->config->getStorageAnalysisBatchRuntimeThresholdSeconds(),
        ]);
    }

    /**
     * Moves a website only after a complete successful run. Failed and aborted
     * processes never reach this method, and therefore retain their assignment.
     */
    protected function updateCompletedRunAssignment(int $siteId, float $durationSeconds): void {
        if ($this->isSiteSchedulingRemoved($siteId)) {
            return;
        }

        if (!$this->isSiteEligible($siteId)) {
            $this->deactivateIneligibleSite($siteId);
            return;
        }

        $currentMode = $this->getSiteAssignmentMode($siteId);
        $targetMode = $this->getCompletedRunAssignmentMode($durationSeconds);

        if ($currentMode === $targetMode) {
            $this->logSiteInfo('Speicherplatzanalyse-Zuordnung beibehalten', $siteId, $currentMode, [
                'duration_seconds' => $durationSeconds,
                'runtime_threshold_seconds' => $this->config->getStorageAnalysisBatchRuntimeThresholdSeconds(),
            ]);
            return;
        }

        // Schedule the replacement before removing the old placement. A failed
        // scheduling call must leave the existing valid assignment intact.
        $nextRunTimestamp = $this->getNextRecurringScheduledTimestamp($siteId);
        $nextRunTimestamp = max(time(), $nextRunTimestamp);

        if ($targetMode === 'batch') {
            if ($this->scheduleBatchRecurringAt($nextRunTimestamp)) {
                $this->unscheduleSiteEvents($siteId);
                $siteIds = $this->getBatchSiteIds();
                $siteIds[] = $siteId;
                $siteIds = array_values(array_unique(array_map('absint', $siteIds)));
                sort($siteIds, SORT_NUMERIC);
                update_site_option(self::BATCH_SITE_IDS_OPTION, $siteIds);
                $this->setSiteAssignment($siteId, 'batch');
                $this->clearRecurringScheduleCache();
                $this->logSiteInfo('Speicherplatzanalyse-Zuordnung zu Sammelbatch geändert', $siteId, 'batch', [
                    'previous_assignment' => $currentMode,
                    'duration_seconds' => $durationSeconds,
                    'runtime_threshold_seconds' => $this->config->getStorageAnalysisBatchRuntimeThresholdSeconds(),
                    'next_run_timestamp' => $nextRunTimestamp,
                ]);
                return;
            }
        } elseif ($this->scheduleIndividualAnalysisAt($siteId, $nextRunTimestamp)) {
            $this->removeBatchSite($siteId);
            $this->logSiteInfo('Speicherplatzanalyse-Zuordnung zu Einzelauftrag geändert', $siteId, 'site', [
                'previous_assignment' => $currentMode,
                'duration_seconds' => $durationSeconds,
                'runtime_threshold_seconds' => $this->config->getStorageAnalysisBatchRuntimeThresholdSeconds(),
                'next_run_timestamp' => $nextRunTimestamp,
            ]);
            return;
        }

        $this->logStorageAnalysisError(
            $siteId,
            self::SCHEDULED_PHASE,
            __('The storage analysis completed, but its next scheduled run could not be created.', 'rrze-multisite-manager')
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
