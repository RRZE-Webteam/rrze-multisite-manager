<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Metrics\StorageAnalysisService;
use RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException;
use RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService;
use RRZE\MultisiteManager\Support\NetworkCronCleanupQueue;

defined('ABSPATH') || exit;

class StorageAnalysisSchedulerService {
    protected const OPTION_STATUS = 'rrze_msm_storage_analysis_scheduler_status';
    protected const BASE_PHASE = 'base';
    protected const ORPHAN_PHASE = 'orphan';
    protected const METADATA_PHASE = 'metadata';
    protected const SCHEDULED_PHASE = 'scheduled';
    protected const CONTINUATION_HOOK = 'rrze_msm_continue_storage_analysis';
    protected const CONTINUATION_QUEUE_OPTION = 'rrze_msm_storage_analysis_continuation_queue';
    protected const REQUEST_BUDGET_SECONDS = 5;
    // The time and memory budgets normally stop a slice; this only bounds zero-cost loops.
    protected const MAX_BATCHES_PER_REQUEST = 1000;
    protected const REQUEST_LOCK_TTL = 120;
    protected const RESOURCE_WAIT_TIMEOUT = DAY_IN_SECONDS + (5 * MINUTE_IN_SECONDS);
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
    protected const TASK_REMOVAL_HOOK = 'rrze_msm_remove_storage_analysis_tasks';
    protected const TASK_REMOVAL_STATE_OPTION = 'rrze_msm_storage_analysis_task_removal_state';
    protected const FREQUENCY_RESCHEDULE_STATE_OPTION = 'rrze_msm_storage_analysis_frequency_reschedule_state';
    protected const MIGRATION_STATE_OPTION = 'rrze_msm_storage_analysis_migration_state';
    protected const MIGRATION_LOCK_OPTION = 'rrze_msm_storage_analysis_migration_lock';
    protected const BATCH_CONTINUATION_ARGS = ['rrze_msm_storage_analysis_batch' => true];
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_storage_analysis_lock_';
    protected const NETWORK_RUN_SLOTS_OPTION = 'rrze_msm_storage_analysis_run_slots';
    protected const NETWORK_RUN_SLOTS_LOCK_OPTION = 'rrze_msm_storage_analysis_run_slots_lock';
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';

    protected StorageAnalysisService $storageAnalysis;
    protected Config $config;
    protected string $networkRunSlotsLockToken = '';
    protected string $continuationSchedulingError = '';
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
        add_action(self::CONTINUATION_HOOK, [$this, 'runAnalysisContinuation']);
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
                $this->allowSiteScheduling($siteId);
                $this->resetRunStatusForScheduledRestart($siteId);
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
                    $this->allowSiteScheduling($siteId);
                    $this->resetRunStatusForScheduledRestart($siteId);
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
            if ($siteId <= 0 || !$this->isSiteEligible($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
                continue;
            }

            if ($this->scheduleRecurringAnalysis($siteId)) {
                $this->allowSiteScheduling($siteId);
                $this->resetRunStatusForScheduledRestart($siteId);
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

                    foreach ([$config->getStorageAnalysisHook(), $config->getStorageAnalysisBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK, self::TASK_REMOVAL_HOOK, self::CONTINUATION_HOOK, StorageAnalysisResourceService::MAINTENANCE_HOOK] as $hook) {
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
        delete_site_option(self::CONTINUATION_QUEUE_OPTION);
        delete_site_option(self::NETWORK_RUN_SLOTS_OPTION);
        delete_site_option(self::NETWORK_RUN_SLOTS_LOCK_OPTION);
        delete_site_option(self::MIGRATION_STATE_OPTION);
        delete_site_option(self::MIGRATION_LOCK_OPTION);
        delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
        delete_site_option(self::TASK_REMOVAL_STATE_OPTION);

        return $removed;
    }

    /** Starts the explicit network cleanup without scanning sites in the admin request. */
    public function queueScheduledTaskRemoval(): array {
        $this->prepareScheduledTaskRemoval();

        return (new NetworkCronCleanupQueue($this->config))->start(self::TASK_REMOVAL_STATE_OPTION, self::TASK_REMOVAL_HOOK);
    }

    public function startAjaxTaskRemoval(): array {
        $this->prepareScheduledTaskRemoval();

        return (new NetworkCronCleanupQueue($this->config))->startAjax(self::TASK_REMOVAL_STATE_OPTION);
    }

    public function runAjaxTaskRemoval(string $runId): array {
        return (new NetworkCronCleanupQueue($this->config))->runAjax(
            self::TASK_REMOVAL_STATE_OPTION,
            $runId,
            fn(): int => $this->clearScheduledEventsOnCurrentSite(),
            function (array $state): void {
                LoggingService::info($this->config, 'RRZE-MSM: Storage analysis task cleanup completed.', $state);
            }
        );
    }

    public function runScheduledTaskRemoval(): void {
        (new NetworkCronCleanupQueue($this->config))->run(
            self::TASK_REMOVAL_STATE_OPTION,
            self::TASK_REMOVAL_HOOK,
            fn(): int => $this->clearScheduledEventsOnCurrentSite(),
            function (array $state): void {
                LoggingService::info($this->config, 'RRZE-MSM: Storage analysis task cleanup completed.', $state);
            }
        );
    }

    public function getScheduledTaskRemovalState(): array {
        $state = get_site_option(self::TASK_REMOVAL_STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    protected function isTaskRemovalInProgress(): bool {
        return (new NetworkCronCleanupQueue($this->config))->isRunning(self::TASK_REMOVAL_STATE_OPTION, self::TASK_REMOVAL_HOOK);
    }

    protected function prepareScheduledTaskRemoval(): void {
        delete_site_option(self::SCHEDULE_SIGNATURE_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_OFFSET_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_STATE_OPTION);
        delete_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION);
        delete_site_option(self::BATCH_SITE_IDS_OPTION);
        delete_site_option(self::ASSIGNMENTS_OPTION);
        delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::BATCH_LOCK_OPTION);
        delete_site_option(self::CONTINUATION_QUEUE_OPTION);
        delete_site_option(self::MIGRATION_STATE_OPTION);
        delete_site_option(self::MIGRATION_LOCK_OPTION);
        delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
    }

    protected function clearScheduledEventsOnCurrentSite(): int {
        $cron = _get_cron_array();
        $removed = 0;

        foreach ((array)$cron as $timestamp => $events) {
            foreach ([$this->config->getStorageAnalysisHook(), $this->config->getStorageAnalysisBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK, self::CONTINUATION_HOOK, StorageAnalysisResourceService::MAINTENANCE_HOOK] as $hook) {
                if (empty($events[$hook])) {
                    continue;
                }
                $removed += count((array)$events[$hook]);
                unset($cron[$timestamp][$hook]);
            }
            if (empty($cron[$timestamp])) {
                unset($cron[$timestamp]);
            }
        }

        return $removed > 0 && _set_cron_array($cron) ? $removed : 0;
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
            // A failed website can be retried without restarting the shared pass.
            $scheduleStatus = $this->getStatus($siteId);
            if (empty($scheduleStatus['last_was_aborted']) && empty($scheduleStatus['last_error'])) {
                return false;
            }
            $scheduled = $this->scheduleTargetedBatchSite($siteId, time() + 5);
        } else {
            $this->unscheduleSite($siteId);
            $scheduled = $mode === 'site'
                ? $this->scheduleIndividualAnalysisAt($siteId, time())
                : $this->scheduleRecurringAnalysisAt($siteId, time());
        }

        if (!$scheduled) {
            return false;
        }

        $this->allowSiteScheduling($siteId);
        $this->storageAnalysis->clearProcessStates($siteId);
        $this->resetRunStatusForScheduledRestart($siteId);

        return true;
    }

    protected function scheduleTargetedBatchSite(int $siteId, int $timestamp): bool {
        return (bool)$this->inBatchCronContext(function () use ($siteId, $timestamp): bool {
            $hook = $this->config->getStorageAnalysisBatchHook();
            $args = [$siteId, true];
            $existingTimestamp = (int)wp_next_scheduled($hook, $args);

            if ($existingTimestamp > 0 && $existingTimestamp <= $timestamp) {
                return true;
            }
            if ($existingTimestamp > 0) {
                wp_clear_scheduled_hook($hook, $args);
            }

            $scheduled = wp_schedule_single_event($timestamp, $hook, $args, true);
            if ((is_wp_error($scheduled) || !$scheduled) && $existingTimestamp > 0) {
                wp_schedule_single_event($existingTimestamp, $hook, $args);
            }

            return !is_wp_error($scheduled) && (bool)$scheduled;
        });
    }

    protected function resetRunStatusForScheduledRestart(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $status = is_array($status) ? $status : [];
        $status['is_running'] = false;
        $status['last_was_aborted'] = false;
        $status['last_error'] = '';
        $status['last_attempt_at'] = '';
        $status['last_started_at'] = '';
        $status['last_finished_at'] = '';
        $status['last_duration_seconds'] = 0;
        $status['active_duration_seconds'] = 0.0;
        $status['resource_wait_started_at'] = 0;
        $status['metadata_pending'] = false;
        $status['metadata_started'] = false;
        $status['run_id'] = '';
        $status['run_phase'] = self::BASE_PHASE;
        $status['phases'] = $this->getScheduledPhaseStatuses();
        update_blog_option($siteId, self::OPTION_STATUS, $status);
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
            'last_attempt_at' => is_array($storedStatus) ? (string)($storedStatus['last_attempt_at'] ?? '') : '',
            'is_running' => is_array($storedStatus) && !empty($storedStatus['is_running']),
            'metadata_pending' => is_array($storedStatus) && !empty($storedStatus['metadata_pending']),
            'metadata_started' => is_array($storedStatus) && !empty($storedStatus['metadata_started']),
            'phases' => $phases,
        ];
    }

    public function runScheduledAnalysis(int $siteId = 0, string $phase = self::BASE_PHASE, bool $fromBatch = false): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isScheduleMigrationInProgress() || $this->isTaskRemovalInProgress()) {
            $this->recordRunWait($siteId, __('Storage analysis is paused while maintenance is running.', 'rrze-multisite-manager'));
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
            $this->recordRunWait($siteId, __('Another storage analysis request is already running for this website.', 'rrze-multisite-manager'));
            if ($fromBatch) {
                $this->scheduleTargetedBatchSite($siteId, time() + MINUTE_IN_SECONDS);
            } else {
                $this->scheduleIndividualRetry($siteId);
            }
            return;
        }

        if (!$this->acquireNetworkRunSlot($siteId)) {
            $this->releaseSiteLock($siteId);
            $this->recordRunWait($siteId, __('All storage analysis execution slots are occupied.', 'rrze-multisite-manager'));

            if ($fromBatch) {
                $this->scheduleTargetedBatchSite($siteId, time() + MINUTE_IN_SECONDS);
            } else {
                $this->scheduleIndividualRetry($siteId);
            }

            return;
        }

        try {
            if ($phase !== self::SCHEDULED_PHASE) {
                // A continuation event from an earlier release must not start a second process.
                $this->unschedule($siteId, $phase);
                return;
            }

            $this->runSingleProcessAnalysis($siteId);
        } catch (StorageAnalysisBusyException $exception) {
            $this->recordRunWait($siteId, $exception->getMessage());
            if ($fromBatch) {
                $this->scheduleTargetedBatchSite($siteId, time() + MINUTE_IN_SECONDS);
            } else {
                $this->scheduleIndividualRetry($siteId);
            }
        } finally {
            $this->releaseNetworkRunSlot($siteId);
            $this->releaseSiteLock($siteId);
        }
    }

    /** One central event resumes one website, not every queued website in the same wp-cron process. */
    public function runAnalysisContinuation(): void {
        $centralSiteId = $this->getBatchCronSiteId();
        if ($centralSiteId <= 0) {
            return;
        }
        if (get_current_blog_id() !== $centralSiteId) {
            $this->inBatchCronContext(function (): void {
                $this->runAnalysisContinuation();
            });
            return;
        }
        if ($this->isTaskRemovalInProgress()) {
            return;
        }
        if (MetricsService::isFullDataCleanupInProgress() || $this->isScheduleMigrationInProgress()) {
            $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
            $siteId = is_array($queue) ? (int)array_key_first($queue) : 0;
            $this->recordRunWait($siteId, __('Storage analysis is paused while maintenance is running.', 'rrze-multisite-manager'));
            $this->scheduleContinuationQueueEvent(MINUTE_IN_SECONDS);
            return;
        }
        if (!$this->acquireNetworkRunSlotsLock()) {
            $this->scheduleContinuationQueueEvent();
            return;
        }
        try {
            $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
            $queue = is_array($queue) ? $queue : [];
            $siteId = (int)array_key_first($queue);
            $runId = (string)($queue[$siteId] ?? '');
            unset($queue[$siteId]);
            if ($siteId > 0 && $runId !== '') {
                // Keep the item at the tail until completion, even if PHP dies in this slice.
                $queue[$siteId] = $runId;
            }
            update_site_option(self::CONTINUATION_QUEUE_OPTION, $queue);
        } finally {
            $this->releaseNetworkRunSlotsLock();
        }
        $keepQueued = false;
        $this->scheduleContinuationQueueEvent();
        try {
            if ($siteId <= 0 || $runId === '') {
                return;
            }
            $status = get_blog_option($siteId, self::OPTION_STATUS, []);
            if ($runId === '' || !is_array($status) || empty($status['is_running'])
                || !hash_equals((string)($status['run_id'] ?? ''), $runId) || $this->isSiteSchedulingRemoved($siteId)
            ) {
                $this->logContinuationDiagnostic('skipped', $siteId, $runId, [
                    'reason' => 'stale_or_removed_run',
                    'status_running' => is_array($status) && !empty($status['is_running']),
                    'status_run_matches' => is_array($status) && hash_equals((string)($status['run_id'] ?? ''), $runId),
                ]);
                return;
            }
            $keepQueued = true;
            $this->logContinuationDiagnostic('dispatched', $siteId, $runId, [
                'phase' => (string)($status['run_phase'] ?? self::BASE_PHASE),
                'queue_size' => count($queue),
            ]);
            $this->runScheduledAnalysis(
                $siteId,
                self::SCHEDULED_PHASE,
                $this->getSiteAssignmentMode($siteId) === 'batch'
            );
            $status = get_blog_option($siteId, self::OPTION_STATUS, []);
            $keepQueued = !empty($status['is_running']) && (string)($status['run_id'] ?? '') === $runId;
        } finally {
            if (!$keepQueued) {
                $this->removeAnalysisContinuation($siteId, $runId);
            }
            $this->scheduleContinuationQueueEvent();
            if ((int)get_site_option(self::BATCH_LAST_SITE_ID_OPTION, 0) > 0
                && empty(get_site_option(self::CONTINUATION_QUEUE_OPTION, []))) {
                $this->scheduleBatchContinuation(time() + 1);
            }
        }
    }

    protected function removeAnalysisContinuation(int $siteId, string $runId): void {
        if (!$this->acquireNetworkRunSlotsLock()) {
            return;
        }
        try {
            $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
            if (is_array($queue) && (string)($queue[$siteId] ?? '') === $runId) {
                unset($queue[$siteId]);
                update_site_option(self::CONTINUATION_QUEUE_OPTION, $queue);
            }
        } finally {
            $this->releaseNetworkRunSlotsLock();
        }
    }

    protected function scheduleAnalysisContinuation(int $siteId, string $runId): bool {
        $this->continuationSchedulingError = '';

        if ($runId === '') {
            $this->continuationSchedulingError = __('The analysis run has no run ID.', 'rrze-multisite-manager');
            return false;
        }

        if ($this->isTaskRemovalInProgress()) {
            $this->continuationSchedulingError = __('Storage-analysis tasks are currently being removed.', 'rrze-multisite-manager');
            return false;
        }

        if ($this->isSiteSchedulingRemoved($siteId)) {
            $this->continuationSchedulingError = __('Scheduling for this website has been removed.', 'rrze-multisite-manager');
            return false;
        }
        if (!$this->acquireNetworkRunSlotsLock()) {
            // A brief queue-lock collision must not discard a website's saved progress.
            if ($this->getSiteAssignmentMode($siteId) === 'batch') {
                $scheduled = (bool)$this->inBatchCronContext(function () use ($siteId): bool {
                    $args = [$siteId, true];
                    $hook = $this->config->getStorageAnalysisBatchHook();
                    return (bool)wp_next_scheduled($hook, $args) || (bool)wp_schedule_single_event(time() + MINUTE_IN_SECONDS, $hook, $args);
                });

                if (!$scheduled) {
                    $this->continuationSchedulingError = __('The batch retry event could not be scheduled after a queue-lock collision.', 'rrze-multisite-manager');
                }

                return $scheduled;
            }
            $scheduled = $this->scheduleIndividualRetry($siteId);

            if (!$scheduled) {
                $this->continuationSchedulingError = __('The individual retry event could not be scheduled after a queue-lock collision.', 'rrze-multisite-manager');
            }

            return $scheduled;
        }
        try {
            $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
            $queue = is_array($queue) ? $queue : [];
            $queue[$siteId] = $runId;
            update_site_option(self::CONTINUATION_QUEUE_OPTION, $queue);
        } finally {
            $this->releaseNetworkRunSlotsLock();
        }
        return $this->scheduleContinuationQueueEvent();
    }

    protected function scheduleContinuationQueueEvent(int $delaySeconds = 1): bool {
        return (bool)$this->inBatchCronContext(function () use ($delaySeconds): bool {
            if (empty(get_site_option(self::CONTINUATION_QUEUE_OPTION, []))) {
                return true;
            }
            $nextTimestamp = time() + max(1, $delaySeconds);
            $existingTimestamp = (int)wp_next_scheduled(self::CONTINUATION_HOOK);
            if ($existingTimestamp > 0 && $existingTimestamp <= $nextTimestamp) {
                return true;
            }
            if ($existingTimestamp > 0) {
                wp_clear_scheduled_hook(self::CONTINUATION_HOOK);
            }
            $scheduled = wp_schedule_single_event($nextTimestamp, self::CONTINUATION_HOOK, [], true);

            if ((is_wp_error($scheduled) || !$scheduled) && $existingTimestamp > 0) {
                // Keep the older reservation if WordPress rejects the earlier replacement.
                wp_schedule_single_event($existingTimestamp, self::CONTINUATION_HOOK);
            }

            if (is_wp_error($scheduled)) {
                $this->continuationSchedulingError = sprintf(
                    /* translators: %s: WordPress Cron scheduling error. */
                    __('WordPress could not create the continuation event: %s', 'rrze-multisite-manager'),
                    $scheduled->get_error_message()
                );
                return false;
            }

            if (!$scheduled) {
                $this->continuationSchedulingError = __('WordPress could not create the continuation event for an unknown reason.', 'rrze-multisite-manager');
            }

            return (bool)$scheduled;
        });
    }

    protected function getContinuationSchedulingError(): string {
        if ($this->continuationSchedulingError !== '') {
            return $this->continuationSchedulingError;
        }

        return __('The storage analysis continuation could not be scheduled.', 'rrze-multisite-manager');
    }

    /**
     * Processes at most one website per request. The recurring central event
     * starts each pass; single events continue it in short intervals.
     */
    public function runBatchScheduledAnalysis(...$args): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isScheduleMigrationInProgress() || $this->isTaskRemovalInProgress()) {
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
            foreach ($args as $arg) {
                if (is_int($arg) && $arg > 0) {
                    $this->scheduleTargetedBatchSite($arg, time() + MINUTE_IN_SECONDS);
                    break;
                }
            }
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

            // The temporary index reservation belongs to the whole analysis,
            // not just one Cron slice. Keep explicit retry events eligible:
            // they may be the only continuation for a queue-lock collision.
            if (!$isTargetedSiteRun && !empty(get_site_option(self::CONTINUATION_QUEUE_OPTION, []))) {
                $this->scheduleContinuationQueueEvent();
                if ($lastSiteId === 0) {
                    $this->scheduleBatchContinuation(time() + MINUTE_IN_SECONDS);
                }
                return;
            }

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

            update_site_option(self::BATCH_LAST_SITE_ID_OPTION, $siteId);

            if (!empty(get_site_option(self::CONTINUATION_QUEUE_OPTION, []))) {
                return;
            }

            if ($this->getNextBatchSiteId($siteIds, $siteId) <= 0) {
                delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
                return;
            }

            $this->scheduleBatchContinuation(time() + 1);
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

    /** Keep continuation diagnostics available even when general info logging is disabled. */
    protected function logContinuationDiagnostic(string $event, int $siteId, string $runId, array $context = []): void {
        do_action('rrze.log.info', __('Storage analysis continuation diagnostic.', 'rrze-multisite-manager'), array_merge([
            'event' => $event,
            'site_id' => $siteId,
            'run_id' => $runId,
        ], $context));
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
        if ($this->isTaskRemovalInProgress()) {
            return;
        }

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

        if ($startedAt > 0 && (time() - $startedAt) > self::REQUEST_LOCK_TTL) {
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

    /** Runs a bounded slice; the following Cron request resumes the same run. */
    protected function runSingleProcessAnalysis(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $status = is_array($status) ? $status : [];
        $memoryBaseline = memory_get_usage(true);
        $started = hrtime(true);
        $deadline = $this->getAnalysisRequestDeadline();
        $phase = (string)($status['run_phase'] ?? self::BASE_PHASE);

        if (empty($status['is_running']) || empty($status['run_id'])) {
            $this->storageAnalysis->clearProcessStates($siteId);
            $this->markRunStarted($siteId, true);
            $status = get_blog_option($siteId, self::OPTION_STATUS, []);
            $status['run_id'] = wp_generate_uuid4();
            $status['run_phase'] = self::BASE_PHASE;
            $status['active_duration_seconds'] = 0.0;
            $phase = self::BASE_PHASE;
            update_blog_option($siteId, self::OPTION_STATUS, $status);
        }

        $runId = (string)$status['run_id'];
        $status['last_attempt_at'] = current_time('mysql', true);
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logContinuationDiagnostic('slice_started', $siteId, $runId, [
            'phase' => $phase,
        ]);
        $startedAt = (int)strtotime((string)$status['last_started_at'] . ' UTC');
        if ((float)($status['active_duration_seconds'] ?? 0) >= $this->getTimeoutSeconds() || ($startedAt > 0 && time() - $startedAt >= 7 * DAY_IN_SECONDS)) {
            $this->abortSingleProcessAnalysis($siteId, $phase, __('The storage analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager'));
            return;
        }

        try {
            // Reserve the next slice before doing work so a fatal PHP error cannot lose it.
            if (!$this->scheduleAnalysisContinuation($siteId, $runId)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when rendered.
                throw new \RuntimeException($this->getContinuationSchedulingError());
            }
            $this->logContinuationDiagnostic('next_slice_reserved', $siteId, $runId, [
                'phase' => $phase,
                'elapsed_seconds' => round((hrtime(true) - $started) / 1000000000, 3),
            ]);
            if ($this->isRequestMemoryBudgetReached($memoryBaseline)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when displayed.
                throw new \RuntimeException(__('Not enough PHP memory is available to start another analysis step.', 'rrze-multisite-manager'));
            }
            $batchesProcessed = 0;
            for ($batch = 0; $batch < self::MAX_BATCHES_PER_REQUEST && ($batch === 0 || microtime(true) < $deadline); $batch++) {
                if ((float)($status['active_duration_seconds'] ?? 0) + (hrtime(true) - $started) / 1000000000 >= $this->getTimeoutSeconds()) {
                    $this->abortSingleProcessAnalysis($siteId, $phase, __('The storage analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager'));
                    return;
                }
                if ($this->isTaskRemovalInProgress() || $this->isSiteSchedulingRemoved($siteId)) {
                    $this->abortSingleProcessAnalysis($siteId, $phase, __('The storage analysis was stopped because its scheduled tasks are being removed.', 'rrze-multisite-manager'));
                    return;
                }
                $logBatch = $batch === 0 || ($batch + 1) % 16 === 0;
                if ($logBatch) {
                    $this->logContinuationDiagnostic('batch_started', $siteId, $runId, [
                        'phase' => $phase,
                        'batch_number' => $batch + 1,
                        'elapsed_seconds' => round((hrtime(true) - $started) / 1000000000, 3),
                    ]);
                }
                if ($phase === self::BASE_PHASE) {
                    $result = $this->storageAnalysis->runBaseBatch($siteId);
                    $complete = ($result['status']['base']['status'] ?? '') === 'complete';
                } elseif ($phase === self::ORPHAN_PHASE) {
                    $result = $this->storageAnalysis->runOrphanBatch($siteId);
                    $complete = ($result['status']['orphan']['status'] ?? '') === 'complete';
                } elseif ($phase === self::METADATA_PHASE) {
                    $current = get_blog_option($siteId, self::OPTION_STATUS, []);
                    $result = $this->storageAnalysis->runMediaMetadataBatch($siteId, empty($current['metadata_started']));
                    $this->markMediaMetadataStarted($siteId);
                    $complete = ($result['analysis']['status'] ?? '') === 'complete';
                } else {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when rendered.
                    throw new \RuntimeException(__('Invalid storage analysis phase.', 'rrze-multisite-manager'));
                }
                if (empty($result['success'])) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when rendered.
                    throw new \RuntimeException((string)($result['message'] ?? __('The storage analysis could not be completed.', 'rrze-multisite-manager')));
                }
                $batchesProcessed++;
                if ($logBatch || $complete) {
                    $this->logContinuationDiagnostic('batch_completed', $siteId, $runId, [
                        'phase' => $phase,
                        'batch_number' => $batch + 1,
                        'phase_complete' => $complete,
                        'elapsed_seconds' => round((hrtime(true) - $started) / 1000000000, 3),
                        'progress' => $this->storageAnalysis->getProgressContext($siteId, $phase),
                    ]);
                }
                $this->markPhaseStarted($siteId, $phase);
                $this->clearResourceWait($siteId);
                if ($complete) {
                    $this->markPhaseFinished($siteId, $phase);
                    if ($phase === self::METADATA_PHASE) {
                        $duration = (float)($status['active_duration_seconds'] ?? 0) + (hrtime(true) - $started) / 1000000000;
                        $this->markRunFinished($siteId, $duration);
                        $this->removeAnalysisContinuation($siteId, $runId);
                        try {
                            $this->updateCompletedRunAssignment($siteId, $duration);
                        } catch (\Throwable $exception) {
                            $this->logStorageAnalysisError($siteId, self::SCHEDULED_PHASE, $exception->getMessage());
                        }
                        return;
                    }
                    $phase = $phase === self::BASE_PHASE ? self::ORPHAN_PHASE : self::METADATA_PHASE;
                }
                $current = get_blog_option($siteId, self::OPTION_STATUS, []);
                if (($current['run_phase'] ?? '') !== $phase) {
                    $current['run_phase'] = $phase;
                    update_blog_option($siteId, self::OPTION_STATUS, $current);
                }
                if ($this->isRequestMemoryBudgetReached($memoryBaseline)) {
                    break;
                }
            }
            $current = get_blog_option($siteId, self::OPTION_STATUS, []);
            $current['active_duration_seconds'] = (float)($status['active_duration_seconds'] ?? 0) + (hrtime(true) - $started) / 1000000000;
            update_blog_option($siteId, self::OPTION_STATUS, $current);
            if (!$this->scheduleAnalysisContinuation($siteId, $runId)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when rendered.
                throw new \RuntimeException($this->getContinuationSchedulingError());
            }
            $this->logContinuationDiagnostic('slice_yielded', $siteId, $runId, [
                'phase' => $phase,
                'batches_processed' => $batchesProcessed,
                'elapsed_seconds' => round((hrtime(true) - $started) / 1000000000, 3),
                'progress' => $this->storageAnalysis->getProgressContext($siteId, $phase),
            ]);
        } catch (StorageAnalysisBusyException $exception) {
            $current = get_blog_option($siteId, self::OPTION_STATUS, []);
            $current['active_duration_seconds'] = (float)($status['active_duration_seconds'] ?? 0) + (hrtime(true) - $started) / 1000000000;
            $waitingSince = max(0, (int)($current['resource_wait_started_at'] ?? 0));
            $current['resource_wait_started_at'] = $waitingSince > 0 ? $waitingSince : time();
            $current['last_error'] = $exception->getMessage();
            update_blog_option($siteId, self::OPTION_STATUS, $current);
            if (time() - $current['resource_wait_started_at'] >= self::RESOURCE_WAIT_TIMEOUT) {
                $message = __('Storage analysis remained blocked for more than 24 hours. Check the network-wide reservations before restarting it.', 'rrze-multisite-manager');
                $this->markRunFailed($siteId, $message);
                $this->markPhaseFailed($siteId, $phase, $message);
                $this->removeAnalysisContinuation($siteId, $runId);
                $this->logStorageAnalysisError($siteId, $phase, $message);
                return;
            }
            // A single assignment retries on its own site, without occupying the central queue.
            $scheduled = $this->getSiteAssignmentMode($siteId) === 'site'
                ? $this->scheduleIndividualRetry($siteId)
                : $this->scheduleAnalysisContinuation($siteId, $runId);
            if ($scheduled && $this->getSiteAssignmentMode($siteId) === 'site') {
                $this->removeAnalysisContinuation($siteId, $runId);
            }
            if ($scheduled && $waitingSince === 0) {
                $this->logContinuationDiagnostic('capacity_wait', $siteId, $runId, [
                    'phase' => $phase,
                    'reason' => $exception->getMessage(),
                    'individual_retry_timestamp' => $this->getNextIndividualRetryTimestamp($siteId),
                ]);
            }
            if (!$scheduled) {
                $message = $this->getContinuationSchedulingError();
                $this->markRunFailed($siteId, $message);
                $this->markPhaseFailed($siteId, $phase, $message);
                $this->removeAnalysisContinuation($siteId, $runId);
                $this->logStorageAnalysisError($siteId, $phase, $message);
            }
        } catch (\Throwable $exception) {
            $progress = $this->storageAnalysis->getProgressContext($siteId, $phase);
            $this->storageAnalysis->clearProcessStates($siteId);
            $this->markRunFailed($siteId, $exception->getMessage());
            $this->markPhaseFailed($siteId, $phase, $exception->getMessage());
            $this->removeAnalysisContinuation($siteId, $runId);
            $this->logStorageAnalysisError($siteId, $phase, $exception->getMessage(), $progress);
        }
    }

    protected function getAnalysisRequestDeadline(): float {
        return microtime(true) + self::REQUEST_BUDGET_SECONDS;
    }

    /** Stop before another step when PHP allocations approach the configured limit. */
    protected function isRequestMemoryBudgetReached(int $baseline): bool {
        $value = trim((string)ini_get('memory_limit'));
        $limit = $value === '' || $value === '-1' ? PHP_INT_MAX : wp_convert_hr_to_bytes($value);
        $budget = min($baseline + 32 * MB_IN_BYTES, (int)($limit * 0.7));
        return memory_get_usage(true) + 16 * MB_IN_BYTES > $budget;
    }

    protected function abortSingleProcessAnalysis(int $siteId, string $phase, string $message): void {
        $status = $this->getStatus($siteId);
        $progressContext = $this->storageAnalysis->getProgressContext($siteId, $phase);
        $startedAt = (string)($status['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $duration = $startedTimestamp > 0 ? max(0, time() - $startedTimestamp) : 0;

        $stored = get_blog_option($siteId, self::OPTION_STATUS, []);
        $this->removeAnalysisContinuation($siteId, (string)($stored['run_id'] ?? ''));
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
        if ($isEligible && $assignmentMode === 'batch' && empty($scheduleStatus['is_running'])) {
            $targetedTimestamp = (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->config->getStorageAnalysisBatchHook(), [$siteId, true]));
            if ($targetedTimestamp > 0) {
                $nextRunTimestamp = $nextRunTimestamp > 0 ? min($nextRunTimestamp, $targetedTimestamp) : $targetedTimestamp;
            }
        }
        if ($isEligible && $assignmentMode === 'site' && empty($scheduleStatus['is_running'])) {
            $retryTimestamp = $this->getNextIndividualRetryTimestamp($siteId);
            if ($retryTimestamp > 0) {
                $nextRunTimestamp = $nextRunTimestamp > 0 ? min($nextRunTimestamp, $retryTimestamp) : $retryTimestamp;
            }
        }
        if ($isEligible && !empty($scheduleStatus['is_running'])) {
            $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
            $runId = (string)(get_blog_option($siteId, self::OPTION_STATUS, [])['run_id'] ?? '');
            $nextRunTimestamp = is_array($queue) && $runId !== '' && (string)($queue[$siteId] ?? '') === $runId
                ? (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled(self::CONTINUATION_HOOK))
                : 0;
            if ($assignmentMode === 'site') {
                $retryTimestamp = $this->getNextIndividualRetryTimestamp($siteId);
                if ($retryTimestamp > 0) {
                    $nextRunTimestamp = $nextRunTimestamp > 0 ? min($nextRunTimestamp, $retryTimestamp) : $retryTimestamp;
                }
            }
        }
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
            // Show the actual pending continuation while a run is active.
            'next_run_timestamp' => $nextRunTimestamp,
            'last_duration_seconds' => (int)($scheduleStatus['last_duration_seconds'] ?? 0),
            'last_was_aborted' => !empty($scheduleStatus['last_was_aborted']),
            'last_error' => (string)($scheduleStatus['last_error'] ?? ''),
            'last_attempt_at' => (string)($scheduleStatus['last_attempt_at'] ?? ''),
            'phases' => is_array($scheduleStatus['phases'] ?? null) ? $scheduleStatus['phases'] : [],
            'base_progress' => is_array($analysisStatus['base'] ?? null) ? $analysisStatus['base'] : [],
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
        if ($this->acquireNetworkRunSlotsLock()) {
            try {
                $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
                if (is_array($queue) && isset($queue[$siteId])) {
                    unset($queue[$siteId]);
                    update_site_option(self::CONTINUATION_QUEUE_OPTION, $queue);
                }
            } finally {
                $this->releaseNetworkRunSlotsLock();
            }
        }
        foreach ([self::BASE_PHASE, self::ORPHAN_PHASE, self::METADATA_PHASE, self::SCHEDULED_PHASE, self::LEGACY_ACTIVE_PHASE] as $phase) {
            $this->unschedule($siteId, $phase);
        }
        $this->clearIndividualRetry($siteId);
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
        return $this->getScheduleKey() . ':resumable-v4';
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

        if (!empty($scheduleStatus['last_error'])) {
            if (!empty($scheduleStatus['last_was_aborted'])) {
                return 'aborted';
            }

            return 'error';
        }

        if ($isDue) {
            return 'waiting_for_cron';
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

        if ($existing > 0 && (time() - $existing) > self::REQUEST_LOCK_TTL) {
            delete_site_option($optionName);
        }

        return add_site_option($optionName, time());
    }

    protected function releaseSiteLock(int $siteId): void {
        delete_site_option(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function acquireNetworkRunSlot(int $siteId): bool {
        if ($siteId <= 0 || !$this->acquireNetworkRunSlotsLock()) {
            return false;
        }

        try {
            $slots = get_site_option(self::NETWORK_RUN_SLOTS_OPTION, []);
            $slots = is_array($slots) ? $slots : [];
            $expiresBefore = time() - self::REQUEST_LOCK_TTL;

            foreach ($slots as $lockedSiteId => $startedAt) {
                if ((int)$startedAt <= $expiresBefore) {
                    unset($slots[$lockedSiteId]);
                }
            }

            if (isset($slots[$siteId])) {
                return true;
            }

            if (count($slots) >= $this->config->getStorageAnalysisMaxConcurrentRuns()) {
                update_site_option(self::NETWORK_RUN_SLOTS_OPTION, $slots);
                return false;
            }

            $slots[$siteId] = time();
            update_site_option(self::NETWORK_RUN_SLOTS_OPTION, $slots);

            return true;
        } finally {
            $this->releaseNetworkRunSlotsLock();
        }
    }

    protected function releaseNetworkRunSlot(int $siteId): void {
        if ($siteId <= 0 || !$this->acquireNetworkRunSlotsLock()) {
            return;
        }

        try {
            $slots = get_site_option(self::NETWORK_RUN_SLOTS_OPTION, []);
            $slots = is_array($slots) ? $slots : [];
            unset($slots[$siteId]);

            if (empty($slots)) {
                delete_site_option(self::NETWORK_RUN_SLOTS_OPTION);
                return;
            }

            update_site_option(self::NETWORK_RUN_SLOTS_OPTION, $slots);
        } finally {
            $this->releaseNetworkRunSlotsLock();
        }
    }

    protected function acquireNetworkRunSlotsLock(): bool {
        // wp_options has a unique option_name index; network metadata does not.
        $token = wp_generate_uuid4();
        $acquired = (bool)$this->inBatchCronContext(function () use ($token): bool {
            $lock = get_option(self::NETWORK_RUN_SLOTS_LOCK_OPTION, []);
            if (is_array($lock) && (int)($lock['expires_at'] ?? 0) < time()) {
                delete_option(self::NETWORK_RUN_SLOTS_LOCK_OPTION);
            }
            return add_option(self::NETWORK_RUN_SLOTS_LOCK_OPTION, ['token' => $token, 'expires_at' => time() + MINUTE_IN_SECONDS], '', false);
        });
        if ($acquired) {
            $this->networkRunSlotsLockToken = $token;
            // Refresh data potentially read before another request held the lock.
            $network = get_current_network_id();
            wp_cache_delete($network . ':' . self::NETWORK_RUN_SLOTS_OPTION, 'site-options');
            wp_cache_delete($network . ':' . self::CONTINUATION_QUEUE_OPTION, 'site-options');
        }
        return $acquired;
    }

    protected function releaseNetworkRunSlotsLock(): void {
        $token = $this->networkRunSlotsLockToken;
        $this->inBatchCronContext(function () use ($token): void {
            $lock = get_option(self::NETWORK_RUN_SLOTS_LOCK_OPTION, []);
            if ($token !== '' && is_array($lock) && (string)($lock['token'] ?? '') === $token) {
                delete_option(self::NETWORK_RUN_SLOTS_LOCK_OPTION);
            }
        });
        $this->networkRunSlotsLockToken = '';
    }

    protected function scheduleIndividualRetry(int $siteId): bool {
        return (bool)$this->inSiteCronContext($siteId, function () use ($siteId): bool {
            $args = [$siteId, self::SCHEDULED_PHASE, true];
            $hook = $this->config->getStorageAnalysisHook();
            $timestamp = time() + MINUTE_IN_SECONDS + wp_rand(0, 30);
            $existingTimestamp = (int)wp_next_scheduled($hook, $args);
            if ($existingTimestamp > 0 && $existingTimestamp <= $timestamp) {
                return true;
            }
            if ($existingTimestamp > 0) {
                wp_clear_scheduled_hook($hook, $args);
            }
            $scheduled = wp_schedule_single_event($timestamp, $hook, $args, true);
            if ((is_wp_error($scheduled) || !$scheduled) && $existingTimestamp > 0) {
                wp_schedule_single_event($existingTimestamp, $hook, $args);
            }
            if (is_wp_error($scheduled)) {
                $this->continuationSchedulingError = sprintf(
                    /* translators: %s: WordPress Cron scheduling error. */
                    __('WordPress could not create the individual retry event: %s', 'rrze-multisite-manager'),
                    $scheduled->get_error_message()
                );
            } elseif (!$scheduled) {
                $this->continuationSchedulingError = __('WordPress could not create the individual retry event.', 'rrze-multisite-manager');
            }

            return !is_wp_error($scheduled) && (bool)$scheduled;
        });
    }

    protected function getNextIndividualRetryTimestamp(int $siteId): int {
        return (int)$this->inSiteCronContext($siteId, fn(): int => (int)wp_next_scheduled(
            $this->config->getStorageAnalysisHook(),
            [$siteId, self::SCHEDULED_PHASE, true]
        ));
    }

    protected function clearIndividualRetry(int $siteId): void {
        $this->inSiteCronContext($siteId, function () use ($siteId): void {
            wp_clear_scheduled_hook($this->config->getStorageAnalysisHook(), [$siteId, self::SCHEDULED_PHASE, true]);
        });
    }

    protected function hasActiveSiteLock(int $siteId): bool {
        $startedAt = (int)get_site_option(self::LOCK_OPTION_PREFIX . $siteId, 0);

        return $startedAt > 0 && (time() - $startedAt) <= self::REQUEST_LOCK_TTL;
    }

    protected function recoverInterruptedRun(int $siteId, array $analysisStatus = [], array $scheduleStatus = []): bool {
        if ($siteId <= 0) {
            return false;
        }

        if (empty($analysisStatus)) {
            $analysisStatus = $this->storageAnalysis->getProcessStatus($siteId);
        }

        if (empty($scheduleStatus)) {
            $scheduleStatus = $this->getStatus($siteId);
        }

        if (empty($scheduleStatus['is_running']) || $this->hasActiveSiteLock($siteId)) {
            return false;
        }

        $stored = get_blog_option($siteId, self::OPTION_STATUS, []);
        $runId = is_array($stored) ? (string)($stored['run_id'] ?? '') : '';
        $startedTimestamp = !empty($scheduleStatus['last_started_at']) ? (int)strtotime((string)$scheduleStatus['last_started_at'] . ' UTC') : 0;

        if ($this->isCompletedAnalysisFromCurrentRun($analysisStatus, $startedTimestamp)) {
            $completedTimestamp = (int)strtotime((string)$analysisStatus['cached_generated_at'] . ' UTC');
            $duration = $startedTimestamp > 0 && $completedTimestamp > 0
                ? max(0.0, (float)($completedTimestamp - $startedTimestamp))
                : (float)($stored['active_duration_seconds'] ?? 0);

            $this->markRunFinished($siteId, $duration);
            $this->removeAnalysisContinuation($siteId, $runId);
            $this->logSiteInfo('Speicherplatzanalyse nach fehlender Abschlussmarkierung wiederhergestellt', $siteId, 'unassigned', [
                'cached_generated_at' => (string)$analysisStatus['cached_generated_at'],
            ]);

            return true;
        }

        if ($runId !== '' && $startedTimestamp > 0 && (float)($stored['active_duration_seconds'] ?? 0) < $this->getTimeoutSeconds()
            && time() - $startedTimestamp < 7 * DAY_IN_SECONDS
            && !$this->isTaskRemovalInProgress() && !$this->isSiteSchedulingRemoved($siteId)
            && $this->getSiteAssignmentMode($siteId) !== 'unassigned'
        ) {
            if ($this->getSiteAssignmentMode($siteId) !== 'site' || $this->getNextIndividualRetryTimestamp($siteId) <= 0) {
                $this->scheduleAnalysisContinuation($siteId, $runId);
            }
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

    /**
     * Determines whether a missing scheduler completion marker belongs to an
     * analysis that already persisted its complete result.
     *
     * @param array<string, mixed> $analysisStatus
     */
    protected function isCompletedAnalysisFromCurrentRun(array $analysisStatus, int $startedTimestamp): bool {
        if ($startedTimestamp <= 0 || empty($analysisStatus['has_cached_analysis'])) {
            return false;
        }

        if (($analysisStatus['base']['status'] ?? '') !== 'complete' || ($analysisStatus['orphan']['status'] ?? '') !== 'complete') {
            return false;
        }

        $completedTimestamp = !empty($analysisStatus['cached_generated_at'])
            ? (int)strtotime((string)$analysisStatus['cached_generated_at'] . ' UTC')
            : 0;

        return $completedTimestamp >= $startedTimestamp;
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
            $status['last_was_aborted'] = false;
            $status['last_duration_seconds'] = 0;
            $status['resource_wait_started_at'] = 0;
            $status['last_attempt_at'] = '';
            $status['metadata_pending'] = true;
            $status['metadata_started'] = false;
            $status['phases'] = $this->getScheduledPhaseStatuses();
        }

        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function clearResourceWait(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        if (!is_array($status) || (empty($status['resource_wait_started_at']) && empty($status['last_error']))) {
            return;
        }
        $status['resource_wait_started_at'] = 0;
        $status['last_error'] = '';
        update_blog_option($siteId, self::OPTION_STATUS, $status);
    }

    protected function recordRunWait(int $siteId, string $message): void {
        if ($siteId <= 0) {
            return;
        }
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        if (!is_array($status) || empty($status['is_running'])) {
            return;
        }
        $status['last_error'] = $message;
        $status['last_attempt_at'] = current_time('mysql', true);
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
        $this->clearIndividualRetry($siteId);
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
        $this->clearIndividualRetry($siteId);
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
        $this->clearIndividualRetry($siteId);
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
