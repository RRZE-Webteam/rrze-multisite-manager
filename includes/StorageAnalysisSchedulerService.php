<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Metrics\StorageAnalysisService;
use RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException;
use RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService;
use RRZE\MultisiteManager\Support\NetworkCronCleanupQueue;

defined('ABSPATH') || exit;

class StorageAnalysisSchedulerService {
    use \RRZE\MultisiteManager\Support\ProcessLocks;
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
    protected const MEDIUM_BATCH_SITE_IDS_OPTION = 'rrze_msm_storage_analysis_medium_batch_site_ids';
    protected const LARGE_BATCH_SITE_IDS_OPTION = 'rrze_msm_storage_analysis_large_batch_site_ids';
    protected const ASSIGNMENTS_OPTION = 'rrze_msm_storage_analysis_assignments';
    // Compact per-site record of the last completed run: [site_id => ['t' => timestamp, 'd' => seconds]].
    protected const PERFORMANCE_RUNS_OPTION = 'rrze_msm_storage_analysis_performance_runs';
    protected const PERFORMANCE_RUNS_LOCK_KEY = 'storage_analysis_performance_runs';
    protected const BATCH_LAST_SITE_ID_OPTION = 'rrze_msm_storage_analysis_batch_last_site_id';
    protected const MEDIUM_BATCH_LAST_SITE_ID_OPTION = 'rrze_msm_storage_analysis_medium_batch_last_site_id';
    protected const LARGE_BATCH_LAST_SITE_ID_OPTION = 'rrze_msm_storage_analysis_large_batch_last_site_id';
    protected const BATCH_LOCK_OPTION = 'rrze_msm_storage_analysis_batch_lock';
    protected const MEDIUM_BATCH_LOCK_OPTION = 'rrze_msm_storage_analysis_medium_batch_lock';
    protected const LARGE_BATCH_LOCK_OPTION = 'rrze_msm_storage_analysis_large_batch_lock';
    protected const SMALL_BATCH_QUEUE_OPTION = 'rrze_msm_storage_analysis_small_batch_queue';
    protected const MEDIUM_BATCH_QUEUE_OPTION = 'rrze_msm_storage_analysis_medium_batch_queue';
    protected const LARGE_BATCH_QUEUE_OPTION = 'rrze_msm_storage_analysis_large_batch_queue';
    protected const STOPPED_BATCHES_OPTION = 'rrze_msm_storage_analysis_stopped_batches';
    protected const REMOVED_SITE_IDS_OPTION = 'rrze_msm_storage_analysis_removed_site_ids';
    protected const FREQUENCY_RESCHEDULE_HOOK = 'rrze_msm_reschedule_storage_analysis_frequency';
    protected const TASK_REMOVAL_HOOK = 'rrze_msm_remove_storage_analysis_tasks';
    protected const TASK_REMOVAL_STATE_OPTION = 'rrze_msm_storage_analysis_task_removal_state';
    protected const FREQUENCY_RESCHEDULE_STATE_OPTION = 'rrze_msm_storage_analysis_frequency_reschedule_state';
    protected const MIGRATION_STATE_OPTION = 'rrze_msm_storage_analysis_migration_state';
    protected const MIGRATION_LOCK_OPTION = 'rrze_msm_storage_analysis_migration_lock';
    protected const BATCH_CONTINUATION_ARGS = ['rrze_msm_storage_analysis_batch' => true];
    protected const MEDIUM_BATCH_CONTINUATION_ARGS = ['rrze_msm_storage_analysis_medium_batch' => true];
    protected const LARGE_BATCH_CONTINUATION_ARGS = ['rrze_msm_storage_analysis_large_batch' => true];
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_storage_analysis_lock_';
    protected const NETWORK_RUN_SLOTS_OPTION = 'rrze_msm_storage_analysis_run_slots';
    protected const NETWORK_RUN_SLOTS_LOCK_OPTION = 'rrze_msm_storage_analysis_run_slots_lock';

    protected StorageAnalysisService $storageAnalysis;
    protected Config $config;
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
        add_action($this->config->getStorageAnalysisHook(), [$this, 'runScheduledAnalysis'], 10, 3);
        add_action($this->config->getStorageAnalysisBatchHook(), [$this, 'runBatchScheduledAnalysis'], 10, 2);
        add_action($this->config->getStorageAnalysisMediumBatchHook(), [$this, 'runMediumBatchScheduledAnalysis'], 10, 2);
        add_action($this->config->getStorageAnalysisLargeBatchHook(), [$this, 'runLargeBatchScheduledAnalysis'], 10, 2);
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
        $this->migrateLegacyContinuationQueue();

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

    public function canStartBatchNow(string $mode = 'small-batch'): bool {
        if (!in_array($mode, ['small-batch', 'medium-batch', 'large-batch'], true)) {
            return false;
        }

        return !$this->isScheduleMigrationInProgress()
            && !\RRZE\MultisiteManager\Support\AtomicLock::isLocked($this->getBatchLockOptionName($mode))
            && !$this->isBatchRunning($mode)
            && !empty($this->getBatchSiteIds($mode));
    }

    public function startBatchNow(string $mode = 'small-batch'): bool {
        if (!$this->canStartBatchNow($mode)) {
            return false;
        }

        return (bool)$this->inBatchCronContext(function () use ($mode): bool {
            $hook = $this->getBatchHook($mode);
            wp_clear_scheduled_hook($hook);
            $scheduled = (bool)wp_schedule_event(time(), $this->getScheduleKey($mode), $hook);
            if ($scheduled) {
                $this->markBatchStopped($mode, false);
                LoggingService::info($this->config, 'RRZE-MSM: Shared storage-analysis batch started early.', ['cron_site_id' => $this->getBatchCronSiteId(), 'batch_mode' => $mode, 'batch_site_count' => count($this->getBatchSiteIds($mode))]);
            }
            return $scheduled;
        });
    }

    public function isBatchRunning(string $mode): bool {
        if (!in_array($mode, ['small-batch', 'medium-batch', 'large-batch'], true)) {
            return false;
        }

        $queue = get_site_option($this->getBatchQueueOptionName($mode), []);
        $queue = is_array($queue) ? $queue : [];
        $siteIds = array_values(array_filter(array_map('absint', (array)($queue['site_ids'] ?? []))));
        $position = max(0, (int)($queue['position'] ?? 0));

        return !empty($siteIds) && $position < count($siteIds) && absint($queue['current_site_id'] ?? 0) > 0;
    }

    /** Stops the complete shared batch while retaining its site assignment. */
    public function stopBatch(string $mode): bool {
        if (!in_array($mode, ['small-batch', 'medium-batch', 'large-batch'], true)) {
            return false;
        }

        $queue = get_site_option($this->getBatchQueueOptionName($mode), []);
        $queue = is_array($queue) ? $queue : [];
        $currentSiteId = absint($queue['current_site_id'] ?? 0);

        if ($currentSiteId > 0 && !empty($this->getStatus($currentSiteId)['is_running'])) {
            $this->abortBatchSiteAnalysis($currentSiteId, __('The shared storage-analysis batch was stopped.', 'rrze-multisite-manager'));
        }

        $siteIds = array_unique(array_merge($this->getBatchSiteIds($mode), array_map('absint', (array)($queue['site_ids'] ?? []))));
        foreach ($siteIds as $siteId) {
            $this->inSiteCronContext((int)$siteId, function () use ($siteId): void {
                wp_clear_scheduled_hook($this->config->getStorageAnalysisHook(), [(int)$siteId, self::SCHEDULED_PHASE, true]);
            });
        }

        $this->inBatchCronContext(function () use ($mode): void {
            wp_clear_scheduled_hook($this->getBatchHook($mode));
        });
        delete_site_option($this->getBatchQueueOptionName($mode));
        delete_site_option($this->getBatchLastSiteOptionName($mode));
        $this->markBatchStopped($mode, true);
        $this->clearRecurringScheduleCache();

        return true;
    }

    protected function markBatchStopped(string $mode, bool $stopped): void {
        $stoppedModes = get_site_option(self::STOPPED_BATCHES_OPTION, []);
        $stoppedModes = is_array($stoppedModes) ? $stoppedModes : [];

        if ($stopped) {
            $stoppedModes[$mode] = true;
        } else {
            unset($stoppedModes[$mode]);
        }

        if (empty($stoppedModes)) {
            delete_site_option(self::STOPPED_BATCHES_OPTION);
            return;
        }

        update_site_option(self::STOPPED_BATCHES_OPTION, $stoppedModes);
    }

    protected function isBatchStopped(string $mode): bool {
        $stoppedModes = get_site_option(self::STOPPED_BATCHES_OPTION, []);

        return is_array($stoppedModes) && !empty($stoppedModes[$mode]);
    }

    /** Aborts only the currently running website and advances its shared queue. */
    public function abortBatchSiteAnalysis(int $siteId, string $message = ''): bool {
        $mode = $this->getSiteAssignmentMode($siteId);
        if (!$this->isBatchAssignmentMode($mode) || !$this->isBatchRunning($mode)) {
            return false;
        }

        $queue = get_site_option($this->getBatchQueueOptionName($mode), []);
        if (!is_array($queue) || absint($queue['current_site_id'] ?? 0) !== $siteId) {
            return false;
        }

        $status = $this->getStatus($siteId);
        if (empty($status['is_running'])) {
            return false;
        }

        $this->abortSingleProcessAnalysis(
            $siteId,
            (string)($status['next_phase'] ?? self::BASE_PHASE),
            $message !== '' ? $message : __('The storage analysis was stopped manually.', 'rrze-multisite-manager')
        );

        return true;
    }

    /** Aborts one running individual storage-analysis task only. */
    public function abortIndividualSiteAnalysis(int $siteId, string $message = ''): bool {
        if ($this->getSiteAssignmentMode($siteId) !== 'site') {
            return false;
        }

        $status = $this->getStatus($siteId);
        if (empty($status['is_running'])) {
            return false;
        }

        $this->abortSingleProcessAnalysis(
            $siteId,
            (string)($status['next_phase'] ?? self::BASE_PHASE),
            $message !== '' ? $message : __('The individual storage analysis was stopped manually.', 'rrze-multisite-manager')
        );

        return true;
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
            $this->inBatchCronContext(function (): void {
                wp_clear_scheduled_hook($this->config->getStorageAnalysisBatchHook());
                wp_clear_scheduled_hook($this->config->getStorageAnalysisMediumBatchHook());
                wp_clear_scheduled_hook($this->config->getStorageAnalysisLargeBatchHook());
            });
            delete_site_option(self::BATCH_SITE_IDS_OPTION);
            delete_site_option(self::MEDIUM_BATCH_SITE_IDS_OPTION);
            delete_site_option(self::LARGE_BATCH_SITE_IDS_OPTION);
            delete_site_option(self::ASSIGNMENTS_OPTION);
            delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
            delete_site_option(self::MEDIUM_BATCH_LAST_SITE_ID_OPTION);
            delete_site_option(self::LARGE_BATCH_LAST_SITE_ID_OPTION);
            delete_site_option(self::SMALL_BATCH_QUEUE_OPTION);
            delete_site_option(self::MEDIUM_BATCH_QUEUE_OPTION);
            delete_site_option(self::LARGE_BATCH_QUEUE_OPTION);
            delete_site_option(self::STOPPED_BATCHES_OPTION);
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
            ? $this->getCompletedRunAssignmentMode((float)($status['last_duration_precise_seconds'] ?? $status['last_duration_seconds']), $this->getSiteAttachmentCount($siteId))
            : $this->getInitialAssignmentMode($siteId);

        if ($this->isBatchAssignmentMode($mode)) {
            $siteIds = $this->getBatchSiteIds($mode);
            $siteIds[] = $siteId;
            $this->updateBatchSiteIds($mode, $siteIds);
            if ($this->scheduleBatchRecurringAt(time() + MINUTE_IN_SECONDS, $mode)) {
                $this->setSiteAssignment($siteId, $mode);
                $this->logSiteInfo('Speicherplatzanalyse-Migration: Sammelbatch zugeordnet', $siteId, $mode, ['used_completed_runtime' => $hasCompletedRun]);
                return true;
            }
            $this->removeBatchSite($siteId, $mode);
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

        return $this->getAssignmentModeForValues($this->getSiteAttachmentCount($siteId), null);
    }

    protected function getSiteAttachmentCount(int $siteId): int {
        switch_to_blog($siteId);

        try {
            $counts = wp_count_posts('attachment');
            return is_object($counts) ? (int)($counts->inherit ?? 0) : 0;
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Call only after a successful complete run with its precise elapsed time.
     */
    public function getCompletedRunAssignmentMode(float $durationSeconds, ?int $mediaCount = null): string {
        return $this->getAssignmentModeForValues($mediaCount ?? 0, $durationSeconds);
    }

    /** @return 'small-batch'|'medium-batch'|'large-batch'|'site' */
    protected function getAssignmentModeForValues(int $mediaCount, ?float $durationSeconds): string {
        $smallMedia = $this->config->getStorageAnalysisSmallBatchMediaThreshold();
        $mediumMedia = $this->config->getStorageAnalysisMediumBatchMediaThreshold();
        $largeMedia = $this->config->getStorageAnalysisLargeBatchMediaThreshold();
        $smallRuntime = $this->config->getStorageAnalysisSmallBatchRuntimeThresholdSeconds();
        $mediumRuntime = $this->config->getStorageAnalysisMediumBatchRuntimeThresholdSeconds();
        $largeRuntime = $this->config->getStorageAnalysisLargeBatchRuntimeThresholdSeconds();

        if ($mediaCount > $largeMedia || ($durationSeconds !== null && $durationSeconds > $largeRuntime)) {
            return 'site';
        }

        if ($mediaCount > $mediumMedia || ($durationSeconds !== null && $durationSeconds > $mediumRuntime)) {
            return 'large-batch';
        }

        if ($mediaCount > $smallMedia || ($durationSeconds !== null && $durationSeconds > $smallRuntime)) {
            return 'medium-batch';
        }

        return 'small-batch';
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

        $lockToken = $this->acquireScheduleInitializationLock($runId);

        if ($lockToken === '') {
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
                if (!$this->refreshScheduleInitializationLock($runId, $lockToken)) {
                    $progress = $this->getScheduleInitializationProgress($state, false);
                    $progress['busy'] = true;
                    return $progress;
                }

                $siteId = (int)$siteId;

                if ($this->isSiteAwaitingSchedule($siteId) && $this->scheduleRecurringAnalysis($siteId)) {
                    $this->allowSiteScheduling($siteId);
                    $this->resetRunStatusForScheduledRestart($siteId);
                    $initialized++;
                }
            }

            $state['processed'] = $processed + count($siteIds);
            $state['initialized'] = (int)($state['initialized'] ?? 0) + $initialized;

            if (!$this->refreshScheduleInitializationLock($runId, $lockToken)) {
                $progress = $this->getScheduleInitializationProgress($state, false);
                $progress['busy'] = true;
                return $progress;
            }

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
            $this->releaseScheduleInitializationLock($runId, $lockToken);
        }
    }

    /** Returns an exclusive lock token, or an empty string while setup is busy. */
    protected function acquireScheduleInitializationLock(string $runId): string {
        return $this->acquireProcessLock(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, [
            'run_id' => $runId,
            'expires_at' => time() + (5 * MINUTE_IN_SECONDS),
        ]);
    }

    /** Verifies ownership before checkpointing schedule initialization. */
    protected function refreshScheduleInitializationLock(string $runId, string $token): bool {
        $lock = get_site_option(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, []);
        return is_array($lock) && (string)($lock['run_id'] ?? '') === $runId
            && \RRZE\MultisiteManager\Support\AtomicLock::owns(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, $token);
    }

    /** Releases only a lock that belongs to this exact request. */
    protected function releaseScheduleInitializationLock(string $runId, string $token): void {
        $this->releaseProcessLock(self::SCHEDULE_INITIALIZATION_LOCK_OPTION, $token);
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

            if ($this->scheduleRecurringAnalysis($siteId, true)) {
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
                    && !in_array($siteId, $this->getBatchSiteIds('small-batch'), true)
                    && !in_array($siteId, $this->getBatchSiteIds('medium-batch'), true)
                    && !in_array($siteId, $this->getBatchSiteIds('large-batch'), true)
                    && !isset($assignments[$siteId])
                )
            ) {
                continue;
            }

            $this->unscheduleSite($siteId);
            (new \RRZE\MultisiteManager\Analysis\AnalysisStore())->removeSiteAnalysis($siteId, 'storage');
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

        if (!$site instanceof \WP_Site) {
            return false;
        }

        // Storage analysis reads the local database and uploads directory. It
        // must therefore follow the WordPress site state, not a potentially
        // stale DNS or HTTP monitoring result.
        return (int)$site->archived === 0
            && (int)$site->spam === 0
            && (int)$site->deleted === 0;
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

                    foreach ([$config->getStorageAnalysisHook(), $config->getStorageAnalysisBatchHook(), $config->getStorageAnalysisMediumBatchHook(), $config->getStorageAnalysisLargeBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK, self::TASK_REMOVAL_HOOK, self::CONTINUATION_HOOK, StorageAnalysisResourceService::MAINTENANCE_HOOK] as $hook) {
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
        delete_site_option(self::MEDIUM_BATCH_SITE_IDS_OPTION);
        delete_site_option(self::LARGE_BATCH_SITE_IDS_OPTION);
        delete_site_option(self::ASSIGNMENTS_OPTION);
        delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::MEDIUM_BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::LARGE_BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::BATCH_LOCK_OPTION);
        delete_site_option(self::MEDIUM_BATCH_LOCK_OPTION);
        delete_site_option(self::LARGE_BATCH_LOCK_OPTION);
        delete_site_option(self::SMALL_BATCH_QUEUE_OPTION);
        delete_site_option(self::MEDIUM_BATCH_QUEUE_OPTION);
        delete_site_option(self::LARGE_BATCH_QUEUE_OPTION);
        delete_site_option(self::STOPPED_BATCHES_OPTION);
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
        delete_site_option(self::MEDIUM_BATCH_SITE_IDS_OPTION);
        delete_site_option(self::LARGE_BATCH_SITE_IDS_OPTION);
        delete_site_option(self::ASSIGNMENTS_OPTION);
        delete_site_option(self::BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::MEDIUM_BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::LARGE_BATCH_LAST_SITE_ID_OPTION);
        delete_site_option(self::BATCH_LOCK_OPTION);
        delete_site_option(self::MEDIUM_BATCH_LOCK_OPTION);
        delete_site_option(self::LARGE_BATCH_LOCK_OPTION);
        delete_site_option(self::SMALL_BATCH_QUEUE_OPTION);
        delete_site_option(self::MEDIUM_BATCH_QUEUE_OPTION);
        delete_site_option(self::LARGE_BATCH_QUEUE_OPTION);
        delete_site_option(self::STOPPED_BATCHES_OPTION);
        delete_site_option(self::CONTINUATION_QUEUE_OPTION);
        delete_site_option(self::MIGRATION_STATE_OPTION);
        delete_site_option(self::MIGRATION_LOCK_OPTION);
        delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
    }

    protected function clearScheduledEventsOnCurrentSite(): int {
        $cron = _get_cron_array();
        $removed = 0;

        foreach ((array)$cron as $timestamp => $events) {
            foreach ([$this->config->getStorageAnalysisHook(), $this->config->getStorageAnalysisBatchHook(), $this->config->getStorageAnalysisMediumBatchHook(), $this->config->getStorageAnalysisLargeBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK, self::CONTINUATION_HOOK, StorageAnalysisResourceService::MAINTENANCE_HOOK] as $hook) {
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

        $saved = $removed > 0 && _set_cron_array($cron);
        (new \RRZE\MultisiteManager\Analysis\AnalysisStore())->removeSiteAnalysis(get_current_blog_id(), 'storage');

        return $saved ? $removed : 0;
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

        if ($this->isBatchAssignmentMode($mode)) {
            // A manually restarted batch website becomes an independent task.
            // The active shared queue remains untouched.
            $scheduleStatus = $this->getStatus($siteId);
            if (empty($scheduleStatus['last_was_aborted']) && empty($scheduleStatus['last_error'])) {
                return false;
            }
            $scheduled = $this->scheduleIndividualAnalysisAt($siteId, time());

            if ($scheduled) {
                $this->inBatchCronContext(function () use ($siteId, $mode): void {
                    wp_clear_scheduled_hook($this->getBatchHook($mode), [$siteId, true]);
                });
                $this->removeBatchSite($siteId, $mode);
                $this->clearRecurringScheduleCache();
                $this->clearPerformanceSummaryCache();
            }
        } else {
            $this->unscheduleSite($siteId);
            $scheduled = $mode === 'site'
                ? $this->scheduleIndividualAnalysisAt($siteId, time())
                : $this->scheduleRecurringAnalysisAt($siteId, time(), true);
        }

        if (!$scheduled) {
            return false;
        }

        $this->allowSiteScheduling($siteId);
        $this->storageAnalysis->clearProcessStates($siteId);
        $this->resetRunStatusForScheduledRestart($siteId);

        return true;
    }

    protected function scheduleTargetedBatchSite(int $siteId, int $timestamp, string $mode = 'small-batch'): bool {
        return (bool)$this->inBatchCronContext(function () use ($siteId, $timestamp, $mode): bool {
            $hook = $this->getBatchHook($mode);
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

        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        $isBatchSite = $this->isBatchAssignmentMode($assignmentMode);

        if ($fromBatch !== $isBatchSite) {
            if (!$fromBatch && $isBatchSite) {
                // A leftover individual event must not duplicate the shared run.
                $this->unscheduleSiteEvents($siteId);
                return;
            }

            // Older releases marked every site-local continuation as a batch
            // continuation. Accept that legacy event for an individual task;
            // new events below always carry the correct flag.
            if ($fromBatch && !$isBatchSite && $phase === self::SCHEDULED_PHASE) {
                $fromBatch = false;
            } else {
                return;
            }
        }

        if (!$this->isSiteEligible($siteId)) {
            $this->deactivateIneligibleSite($siteId);
            return;
        }

        $budget = new \RRZE\MultisiteManager\Support\WorkerBudget();
        if (!$budget->acquire($siteId)) {
            $this->scheduleSiteRetry($siteId);
            return;
        }
        try {
            $this->runAdmittedScheduledAnalysis($siteId, $phase, $fromBatch, $assignmentMode);
        } finally {
            $budget->release();
        }
    }

    protected function runAdmittedScheduledAnalysis(int $siteId, string $phase, bool $fromBatch, string $assignmentMode): void {
        $isBatchSite = $this->isBatchAssignmentMode($assignmentMode);
        if (!$this->acquireSiteLock($siteId)) {
            $this->recordRunWait($siteId, __('Another storage analysis request is already running for this website.', 'rrze-multisite-manager'));
            $this->scheduleSiteRetry($siteId);
            return;
        }

        // Shared batches already have one queue head each. They share the
        // global worker budget, but need no additional per-type execution slot.
        $usesNetworkRunSlot = !$isBatchSite;
        if ($usesNetworkRunSlot && !$this->acquireNetworkRunSlot($siteId)) {
            $this->releaseSiteLock($siteId);
            $this->recordRunWait($siteId, __('All storage analysis execution slots are occupied.', 'rrze-multisite-manager'));
            $this->scheduleSiteRetry($siteId);

            return;
        }

        try {
            if ($phase !== self::SCHEDULED_PHASE) {
                // A continuation event from an earlier release must not start a second process.
                $this->unschedule($siteId, $phase);
                return;
            }

            \RRZE\MultisiteManager\Analysis\AnalysisLifecycle::prepareSite($siteId);
            $this->runSingleProcessAnalysis($siteId);
        } catch (StorageAnalysisBusyException $exception) {
            $this->recordRunWait($siteId, $exception->getMessage());
            $this->scheduleSiteRetry($siteId);
        } catch (\Throwable $exception) {
            $this->markRunFailed($siteId, $exception->getMessage());
            $this->logStorageAnalysisError($siteId, $phase, $exception->getMessage());
        } finally {
            if ($usesNetworkRunSlot) {
                $this->releaseNetworkRunSlot($siteId);
            }
            $this->releaseSiteLock($siteId);

            // A shared batch advances only after this website is fully
            // complete (or has ended with an error), never after one slice.
            if ($fromBatch && $this->isBatchAssignmentMode($assignmentMode)) {
                $status = $this->getStatus($siteId);
                if (empty($status['is_running'])) {
                    $this->advanceBatchQueue($assignmentMode, $siteId);
                }
            }
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
                $this->isBatchAssignmentMode($this->getSiteAssignmentMode($siteId))
            );
            $status = get_blog_option($siteId, self::OPTION_STATUS, []);
            $keepQueued = !empty($status['is_running']) && (string)($status['run_id'] ?? '') === $runId;
        } finally {
            if (!$keepQueued) {
                $this->removeAnalysisContinuation($siteId, $runId);
            }
            $this->scheduleContinuationQueueEvent();
            if (empty(get_site_option(self::CONTINUATION_QUEUE_OPTION, []))) {
                    foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
                    if ((int)get_site_option($this->getBatchLastSiteOptionName($mode), 0) > 0) {
                        $this->scheduleBatchContinuation(time() + 1, $mode);
                    }
                }
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
        /*
         * A continuation belongs in the Cron table of the affected website.
         * The multisite Cron runner can then start several different sites in
         * parallel. A central queue made every continuation appear on the main
         * site and therefore limited the whole network to one runner process.
         */
        return $this->scheduleSiteContinuation($siteId);
    }

    /**
     * Schedule a single short continuation in the target website's Cron table.
     *
     * The third argument identifies whether the continuation belongs to a
     * shared batch. It must match the current assignment mode.
     */
    protected function scheduleSiteContinuation(int $siteId, int $delaySeconds = 1): bool {
        return (bool)$this->inSiteCronContext($siteId, function () use ($siteId, $delaySeconds): bool {
            $args = $this->getScheduledAnalysisEventArgs($siteId);
            $hook = $this->config->getStorageAnalysisHook();
            $timestamp = time() + max(1, $delaySeconds);
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
                    __('WordPress could not create the continuation event: %s', 'rrze-multisite-manager'),
                    $scheduled->get_error_message()
                );
            } elseif (!$scheduled) {
                $this->continuationSchedulingError = __('WordPress could not create the continuation event.', 'rrze-multisite-manager');
            }

            return !is_wp_error($scheduled) && (bool)$scheduled;
        });
    }

    /** Move continuations queued by older releases into their target Cron tables. */
    protected function migrateLegacyContinuationQueue(): void {
        $queue = get_site_option(self::CONTINUATION_QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        $remaining = [];
        foreach ($queue as $siteId => $runId) {
            $siteId = (int)$siteId;
            if ($siteId <= 0 || !is_string($runId) || $runId === '') {
                continue;
            }
            if (!$this->scheduleSiteContinuation($siteId)) {
                $remaining[$siteId] = $runId;
            }
        }

        if (empty($remaining)) {
            delete_site_option(self::CONTINUATION_QUEUE_OPTION);
            $this->inBatchCronContext(function (): void {
                wp_clear_scheduled_hook(self::CONTINUATION_HOOK);
            });
            return;
        }

        update_site_option(self::CONTINUATION_QUEUE_OPTION, $remaining);
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
        $this->runScheduledBatchAnalysisForMode('small-batch', $args);
    }

    public function runMediumBatchScheduledAnalysis(...$args): void {
        $this->runScheduledBatchAnalysisForMode('medium-batch', $args);
    }

    public function runLargeBatchScheduledAnalysis(...$args): void {
        $this->runScheduledBatchAnalysisForMode('large-batch', $args);
    }

    /** @param array<int, mixed> $args */
    protected function runScheduledBatchAnalysisForMode(string $batchMode, array $args): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isScheduleMigrationInProgress() || $this->isTaskRemovalInProgress()) {
            return;
        }

        if (get_current_blog_id() !== $this->getBatchCronSiteId()) {
            // A batch event must never run from a subsite's Cron table.
            wp_clear_scheduled_hook($this->getBatchHook($batchMode));
            return;
        }

        $hook = $this->getBatchHook($batchMode);

        if ((int)wp_next_scheduled($hook) <= 0) {
            // A removed recurring task must not be revived by a stale continuation.
            return;
        }

        $lockToken = $this->acquireBatchLock($batchMode);

        if ($lockToken === '') {
            foreach ($args as $arg) {
                if (is_int($arg) && $arg > 0) {
                    $this->scheduleTargetedBatchSite($arg, time() + MINUTE_IN_SECONDS, $batchMode);
                    break;
                }
            }
            return;
        }

        $processedSiteId = 0;
        LoggingService::debug(
            $this->config,
            'RRZE-MSM: Shared storage-analysis batch started.',
            ['cron_site_id' => $this->getBatchCronSiteId()]
        );

        try {
            $queue = $this->getOrCreateBatchQueue($batchMode);
            $siteId = (int)($queue['current_site_id'] ?? 0);

            if ($siteId <= 0) {
                return;
            }

            $processedSiteId = $siteId;
            $assignedMode = $this->getSiteAssignmentMode($siteId);

            // A completed website can be assigned to a different batch for
            // its next cycle. An obsolete queue created by an earlier release
            // must not process that website again or block the queue behind it.
            if ($assignedMode !== 'batch' && $assignedMode !== $batchMode) {
                $this->logSiteInfo('Speicherplatzanalyse-Sammelbatch überspringt veraltete Queue-Site', $siteId, $batchMode, [
                    'current_assignment' => $assignedMode,
                ], true);
                $this->advanceBatchQueue($batchMode, $siteId);
                return;
            }

            // A previous continuation may have been lost, for example while
            // WordPress Cron was unavailable. Recover it before deciding that
            // the queue head is still active, otherwise the whole batch would
            // remain blocked behind this website indefinitely.
            $this->recoverInterruptedRun($siteId);
            $status = $this->getStatus($siteId);

            // A central recurring event must never jump ahead of the current
            // website. Its local continuation completes all three phases.
            if (!empty($status['is_running'])) {
                return;
            }

            $queueStartedAt = absint($queue['started_at'] ?? 0);
            $lastCompletedAt = (string)($status['last_completed_at'] ?? '');
            $lastCompletedTimestamp = $lastCompletedAt !== ''
                ? (int)strtotime($lastCompletedAt . ' UTC')
                : 0;

            // Recover a queue that was left at an already completed head.
            // This can occur when an earlier release completed the local
            // analysis but lost the central queue advancement. Do not repeat
            // that analysis: progress to the next queue entry instead.
            if ($queueStartedAt > 0 && $lastCompletedTimestamp >= $queueStartedAt) {
                $this->logSiteInfo('Speicherplatzanalyse-Sammelbatch setzt abgeschlossene Queue-Site fort', $siteId, $batchMode, [
                    'queue_started_at' => $queueStartedAt,
                    'last_completed_at' => $lastCompletedAt,
                ], true);
                $this->advanceBatchQueue($batchMode, $siteId);
                return;
            }

            if ($this->isSiteEligible($siteId)) {
                $this->logSiteInfo('Speicherplatzanalyse-Sammelbatch verarbeitet Site', $siteId, $batchMode, [
                    'queue_position' => (int)($queue['position'] ?? 0) + 1,
                    'batch_site_count' => count((array)($queue['site_ids'] ?? [])),
                ], true);
                $this->runScheduledAnalysis($siteId, self::SCHEDULED_PHASE, true);
            } else {
                $this->deactivateIneligibleSite($siteId);
                $this->advanceBatchQueue($batchMode, $siteId);
            }
        } finally {
            $this->releaseBatchLock($lockToken, $batchMode);
            $context = [
                'cron_site_id' => $this->getBatchCronSiteId(),
                'queue_mode' => $batchMode,
            ];

            if ($processedSiteId > 0) {
                $this->logSiteInfo('Speicherplatzanalyse-Sammelbatch beendet', $processedSiteId, $batchMode, $context, true);
            } else {
                LoggingService::debug($this->config, 'RRZE-MSM: Speicherplatzanalyse-Sammelbatch beendet.', $context);
            }
        }
    }

    protected function isBatchAssignmentMode(string $mode): bool {
        return in_array($mode, ['batch', 'small-batch', 'medium-batch', 'large-batch'], true);
    }

    protected function getBatchOptionName(string $mode): string {
        return $mode === 'large-batch'
            ? self::LARGE_BATCH_SITE_IDS_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_SITE_IDS_OPTION : self::BATCH_SITE_IDS_OPTION);
    }

    protected function getBatchLastSiteOptionName(string $mode): string {
        return $mode === 'large-batch'
            ? self::LARGE_BATCH_LAST_SITE_ID_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_LAST_SITE_ID_OPTION : self::BATCH_LAST_SITE_ID_OPTION);
    }

    protected function getBatchLockOptionName(string $mode): string {
        return $mode === 'large-batch'
            ? self::LARGE_BATCH_LOCK_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_LOCK_OPTION : self::BATCH_LOCK_OPTION);
    }

    protected function getBatchHook(string $mode): string {
        return $mode === 'large-batch'
            ? $this->config->getStorageAnalysisLargeBatchHook()
            : ($mode === 'medium-batch' ? $this->config->getStorageAnalysisMediumBatchHook() : $this->config->getStorageAnalysisBatchHook());
    }

    /** @return array<string, bool> */
    protected function getBatchContinuationArgs(string $mode): array {
        return $mode === 'large-batch'
            ? self::LARGE_BATCH_CONTINUATION_ARGS
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_CONTINUATION_ARGS : self::BATCH_CONTINUATION_ARGS);
    }

    protected function getBatchQueueOptionName(string $mode): string {
        return $mode === 'large-batch'
            ? self::LARGE_BATCH_QUEUE_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_QUEUE_OPTION : self::SMALL_BATCH_QUEUE_OPTION);
    }

    /** @return array{site_ids: array<int, int>, position: int, current_site_id: int, started_at: int} */
    protected function getOrCreateBatchQueue(string $mode): array {
        $queue = get_site_option($this->getBatchQueueOptionName($mode), []);
        $queue = is_array($queue) ? $queue : [];
        $siteIds = array_values(array_filter(array_map('absint', (array)($queue['site_ids'] ?? []))));
        $position = max(0, (int)($queue['position'] ?? 0));

        if (!empty($siteIds) && $position < count($siteIds)) {
            $queue['site_ids'] = $siteIds;
            $queue['position'] = $position;
            $queue['current_site_id'] = (int)$siteIds[$position];
            return $queue;
        }

        $siteIds = $this->getBatchSiteIds($mode);
        if (count($siteIds) > 1) {
            shuffle($siteIds);
        }
        $queue = [
            'site_ids' => $siteIds,
            'position' => 0,
            'current_site_id' => (int)($siteIds[0] ?? 0),
            'started_at' => time(),
        ];
        update_site_option($this->getBatchQueueOptionName($mode), $queue);

        return $queue;
    }

    protected function advanceBatchQueue(string $mode, int $siteId): void {
        $queue = get_site_option($this->getBatchQueueOptionName($mode), []);
        $queue = is_array($queue) ? $queue : [];
        $siteIds = array_values(array_filter(array_map('absint', (array)($queue['site_ids'] ?? []))));
        $position = max(0, (int)($queue['position'] ?? 0));

        if ($siteId <= 0 || (int)($siteIds[$position] ?? 0) !== $siteId) {
            return;
        }

        $position++;
        if ($position >= count($siteIds)) {
            delete_site_option($this->getBatchQueueOptionName($mode));
            delete_site_option($this->getBatchLastSiteOptionName($mode));
            return;
        }

        $queue['site_ids'] = $siteIds;
        $queue['position'] = $position;
        $queue['current_site_id'] = (int)$siteIds[$position];
        update_site_option($this->getBatchQueueOptionName($mode), $queue);
        update_site_option($this->getBatchLastSiteOptionName($mode), $siteId);
        $this->scheduleBatchContinuation(time() + 1, $mode);
    }

    /** @return array<int, int> */
    protected function getBatchSiteIds(string $mode = 'small-batch'): array {
        $storedSiteIds = get_site_option($this->getBatchOptionName($mode), []);
        $siteIds = is_array($storedSiteIds) ? array_filter(array_map('absint', $storedSiteIds)) : [];
        $siteIds = array_values(array_unique($siteIds));
        sort($siteIds, SORT_NUMERIC);

        return $siteIds;
    }

    /** @param array<int, int> $siteIds */
    protected function updateBatchSiteIds(string $mode, array $siteIds): void {
        update_site_option($this->getBatchOptionName($mode), array_values(array_unique(array_filter(array_map('absint', $siteIds)))));
    }

    /** @return 'small-batch'|'medium-batch'|'large-batch'|'site'|'unassigned' */
    public function getSiteAssignmentMode(int $siteId): string {
        if ($siteId <= 0) {
            return 'unassigned';
        }

        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];
        $assignedMode = (string)($assignments[$siteId] ?? '');

        if (in_array($assignedMode, ['small-batch', 'medium-batch', 'large-batch'], true)
            && in_array($siteId, $this->getBatchSiteIds($assignedMode), true)
            && (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($assignedMode))) > 0) {
            return $assignedMode;
        }

        if ($assignedMode === 'site' && $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return 'site';
        }

        // Kept as a fallback for schedules created before assignments were
        // persisted separately from the batch membership lists.
        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            if (!in_array($siteId, $this->getBatchSiteIds($mode), true)) {
                continue;
            }

            $batchTimestamp = (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($mode)));

            if ($batchTimestamp > 0) {
                return $mode;
            }
        }

        return $this->getNextRecurringScheduledTimestamp($siteId) > 0 ? 'site' : 'unassigned';
    }

    /** @return 'small-batch'|'medium-batch'|'large-batch'|'' */
    protected function getBatchMembershipMode(int $siteId): string {
        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            if (in_array($siteId, $this->getBatchSiteIds($mode), true)) {
                return $mode;
            }
        }

        return '';
    }

    /**
     * Returns the planning distribution for active websites without loading
     * per-site analysis state or inspecting attachments.
     *
     * @return array{small-batch: int, medium-batch: int, large-batch: int, site: int, unassigned: int}
     */
    public function getActiveSiteAssignmentCounts(): array {
        $counts = [
            'small-batch' => 0,
            'medium-batch' => 0,
            'large-batch' => 0,
            'site' => 0,
            'unassigned' => 0,
        ];
        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];
        $assignmentBySiteId = [];

        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            foreach ($this->getBatchSiteIds($mode) as $siteId) {
                $assignmentBySiteId[$siteId] = $mode;
            }
        }

        foreach ($assignments as $siteId => $mode) {
            $siteId = absint($siteId);

            if ($siteId <= 0 || isset($assignmentBySiteId[$siteId]) || !in_array($mode, ['small-batch', 'medium-batch', 'large-batch', 'site'], true)) {
                continue;
            }

            $assignmentBySiteId[$siteId] = $mode;
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ]);

        foreach ($siteIds as $siteId) {
            $mode = $assignmentBySiteId[absint($siteId)] ?? 'unassigned';
            $counts[$mode]++;
        }

        return $counts;
    }

    /**
     * Returns completed per-website runtimes grouped by their current
     * storage-analysis assignment. Queue wait times are intentionally not
     * included: this is a performance measure of the actual analysis work.
     *
     * @return array<string, array{site_count: int, latest_durations: array<int, int>, average_duration_seconds: ?float, fastest_duration_seconds: ?int, slowest_duration_seconds: ?int, last_execution_timestamp: int}>
     */
    public function getPerformanceSummary(): array {
        $cacheKey = 'storage_analysis_performance_summary';
        $cachedSummary = wp_cache_get($cacheKey, 'rrze-msm');
        if (is_array($cachedSummary)) {
            return $cachedSummary;
        }

        $modes = ['small-batch', 'medium-batch', 'large-batch', 'site'];
        $summary = [];
        $assignmentBySiteId = [];
        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];
        $performanceRuns = $this->getPerformanceRuns();
        $run = [];

        foreach ($modes as $mode) {
            $summary[$mode] = [
                'site_count' => 0,
                'latest_durations' => [],
                'average_duration_seconds' => null,
                'fastest_duration_seconds' => null,
                'slowest_duration_seconds' => null,
                'last_execution_timestamp' => 0,
            ];
        }

        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            foreach ($this->getBatchSiteIds($mode) as $siteId) {
                $assignmentBySiteId[$siteId] = $mode;
            }
        }

        foreach ($assignments as $siteId => $mode) {
            $siteId = absint($siteId);
            if ($siteId > 0 && !isset($assignmentBySiteId[$siteId]) && in_array($mode, $modes, true)) {
                $assignmentBySiteId[$siteId] = $mode;
            }
        }

        $activeSiteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ]);
        $runsByMode = array_fill_keys($modes, []);

        // The per-site completion records live in one network option. The
        // summary therefore never reads the status option of every website.
        foreach ($activeSiteIds as $siteId) {
            $siteId = absint($siteId);
            $mode = $assignmentBySiteId[$siteId] ?? '';
            if (!isset($summary[$mode])) {
                continue;
            }

            $summary[$mode]['site_count']++;
            $run = is_array($performanceRuns[$siteId] ?? null) ? $performanceRuns[$siteId] : [];
            $completedTimestamp = (int)($run['t'] ?? 0);
            if ($completedTimestamp <= 0) {
                continue;
            }

            $runsByMode[$mode][] = [
                'timestamp' => $completedTimestamp,
                'duration' => max(0, (int)($run['d'] ?? 0)),
            ];
            $summary[$mode]['last_execution_timestamp'] = max($summary[$mode]['last_execution_timestamp'], $completedTimestamp);
        }

        foreach ($runsByMode as $mode => $runs) {
            if (empty($runs)) {
                continue;
            }

            usort($runs, [self::class, 'comparePerformanceRunsByTimestamp']);
            $durations = array_map('intval', array_column($runs, 'duration'));
            $summary[$mode]['latest_durations'] = array_slice($durations, 0, 5);
            $summary[$mode]['average_duration_seconds'] = array_sum($durations) / count($durations);
            $summary[$mode]['fastest_duration_seconds'] = min($durations);
            $summary[$mode]['slowest_duration_seconds'] = max($durations);
        }

        wp_cache_set($cacheKey, $summary, 'rrze-msm', 5 * MINUTE_IN_SECONDS);

        return $summary;
    }

    protected static function comparePerformanceRunsByTimestamp(array $left, array $right): int {
        return $right['timestamp'] <=> $left['timestamp'];
    }

    /**
     * Returns the compact completion records. Installations upgraded from an
     * older release build them once from the per-site status options; every
     * later read uses the single network option.
     *
     * @return array<int, array{t: int, d: int}>
     */
    protected function getPerformanceRuns(): array {
        $runs = get_site_option(self::PERFORMANCE_RUNS_OPTION, null);

        if (is_array($runs)) {
            return $runs;
        }

        $runs = [];
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ]);

        foreach ($siteIds as $siteId) {
            $siteId = absint($siteId);
            $status = get_blog_option($siteId, self::OPTION_STATUS, []);
            $status = is_array($status) ? $status : [];
            $completedAt = (string)($status['last_completed_at'] ?? '');
            if ($completedAt === '' && empty($status['last_error']) && empty($status['last_was_aborted'])) {
                $completedAt = (string)($status['last_finished_at'] ?? '');
            }

            $completedTimestamp = $completedAt !== '' ? (int)strtotime($completedAt . ' UTC') : 0;
            if ($completedTimestamp > 0) {
                $runs[$siteId] = ['t' => $completedTimestamp, 'd' => max(0, (int)($status['last_duration_seconds'] ?? 0))];
            }
        }

        update_site_option(self::PERFORMANCE_RUNS_OPTION, $runs);

        return $runs;
    }

    /**
     * Updates one website's completion record. Concurrent completions are
     * serialized by a short non-blocking lock with a few retries; a sample
     * that still cannot be written is dropped and replaced by the next run.
     */
    protected function recordPerformanceRun(int $siteId, int $completedTimestamp, int $durationSeconds): void {
        $token = '';
        $attempt = 0;
        $runs = [];

        if ($siteId <= 0) {
            return;
        }

        for ($attempt = 0; $attempt < 5 && $token === ''; $attempt++) {
            $token = \RRZE\MultisiteManager\Support\AtomicLock::acquire(self::PERFORMANCE_RUNS_LOCK_KEY);
            if ($token === '') {
                usleep(100000);
            }
        }

        if ($token === '') {
            return;
        }

        try {
            wp_cache_delete(get_current_network_id() . ':' . self::PERFORMANCE_RUNS_OPTION, 'site-options');
            $runs = get_site_option(self::PERFORMANCE_RUNS_OPTION, null);

            // Without existing records the first summary request builds them
            // from all status options, including this completed run.
            if (!is_array($runs)) {
                return;
            }

            $runs[$siteId] = ['t' => $completedTimestamp, 'd' => max(0, $durationSeconds)];
            update_site_option(self::PERFORMANCE_RUNS_OPTION, $runs);
        } finally {
            \RRZE\MultisiteManager\Support\AtomicLock::release(self::PERFORMANCE_RUNS_LOCK_KEY, $token);
        }
    }

    protected function clearPerformanceSummaryCache(): void {
        wp_cache_delete('storage_analysis_performance_summary', 'rrze-msm');
    }

    /**
     * Writes a site-specific information log entry in one consistent form.
     *
     * @param 'small-batch'|'medium-batch'|'site'|'unassigned' $assignmentMode
     * @param array<string, mixed>         $context
     */
    protected function logSiteInfo(string $label, int $siteId, string $assignmentMode = 'unassigned', array $context = [], bool $fineGrained = false): void {
        if ($assignmentMode === 'unassigned') {
            $assignmentMode = $this->getSiteAssignmentMode($siteId);
        }

        $assignment = $assignmentMode === 'small-batch' ? 'Small batch' : ($assignmentMode === 'medium-batch' ? 'Medium batch' : ($assignmentMode === 'large-batch' ? 'Large batch' : 'Single'));
        $context = array_merge($context, [
            'site_id' => $siteId,
            'assignment' => $assignment,
        ]);

        $message = sprintf('RRZE-MSM: %s (Site Id: %d, Assignment: %s)', $label, $siteId, $assignment);

        if ($fineGrained) {
            LoggingService::debug($this->config, $message, $context);
            return;
        }

        LoggingService::info($this->config, $message, $context);
    }

    /** Per-slice continuation details belong to fine-grained debugging. */
    protected function logContinuationDiagnostic(string $event, int $siteId, string $runId, array $context = []): void {
        LoggingService::debug($this->config, __('Storage analysis continuation diagnostic.', 'rrze-multisite-manager'), array_merge([
            'event' => $event,
            'site_id' => $siteId,
            'run_id' => $runId,
        ], $context));
    }

    protected function setSiteAssignment(int $siteId, string $mode): void {
        if ($siteId <= 0 || !in_array($mode, ['small-batch', 'medium-batch', 'large-batch', 'site'], true)) {
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

    protected function removeBatchSite(int $siteId, ?string $onlyMode = null): void {
        foreach ($onlyMode !== null ? [$onlyMode] : ['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            $this->removeBatchSiteFromMode($siteId, $mode);
        }
    }

    /** Removes obsolete future assignments while retaining the new batch mode. */
    protected function removeSiteFromOtherBatchModes(int $siteId, string $keepMode): void {
        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            if ($mode === $keepMode) {
                continue;
            }

            $this->removeBatchSiteFromMode($siteId, $mode);
        }
    }

    protected function removeBatchSiteFromMode(int $siteId, string $mode): void {
        $siteIds = $this->getBatchSiteIds($mode);

        if (!in_array($siteId, $siteIds, true)) {
            return;
        }

        $siteIds = array_values(array_diff($siteIds, [$siteId]));
        $this->updateBatchSiteIds($mode, $siteIds);

        if (empty($siteIds)) {
            $this->inBatchCronContext(function () use ($mode): void {
                wp_clear_scheduled_hook($this->getBatchHook($mode));
            });
            delete_site_option($this->getBatchLastSiteOptionName($mode));
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

    protected function scheduleBatchRecurringAt(int $timestamp, string $mode = 'small-batch'): bool {
        if (empty($this->getBatchSiteIds($mode))) {
            return false;
        }

        return (bool)$this->inBatchCronContext(function () use ($timestamp, $mode): bool {
            $hook = $this->getBatchHook($mode);

            if ((int)wp_next_scheduled($hook) > 0) {
                return true;
            }

            $scheduled = (bool)wp_schedule_event(max(time(), $timestamp), $this->getScheduleKey($mode), $hook);

            if ($scheduled) {
                LoggingService::debug(
                    $this->config,
                    'RRZE-MSM: Central shared storage-analysis batch scheduled.',
                    [
                        'cron_site_id' => $this->getBatchCronSiteId(),
                        'next_run_timestamp' => $timestamp,
                        'schedule' => $this->getScheduleKey($mode),
                        'batch_mode' => $mode,
                        'batch_site_count' => count($this->getBatchSiteIds($mode)),
                    ]
                );
            }

            return $scheduled;
        });
    }

    protected function scheduleBatchContinuation(int $timestamp, string $mode = 'small-batch'): void {
        if ($this->isTaskRemovalInProgress()) {
            return;
        }

        $this->inBatchCronContext(function () use ($timestamp, $mode): void {
            $hook = $this->getBatchHook($mode);
            $args = $this->getBatchContinuationArgs($mode);

            if ((int)wp_next_scheduled($hook) > 0 && (int)wp_next_scheduled($hook, $args) <= 0) {
                wp_schedule_single_event($timestamp, $hook, $args);
            }
        });
    }

    protected function acquireBatchLock(string $mode = 'small-batch'): string {
        return $this->acquireProcessLock($this->getBatchLockOptionName($mode));
    }

    protected function releaseBatchLock(string $token, string $mode = 'small-batch'): void {
        $this->releaseProcessLock($this->getBatchLockOptionName($mode), $token);
    }

    /** Runs a bounded slice; the following Cron request resumes the same run. */
    protected function runSingleProcessAnalysis(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $status = is_array($status) ? $status : [];
        $memoryBaseline = memory_get_usage(true);
        $started = hrtime(true);
        $deadline = $this->getAnalysisRequestDeadline();
        $phase = (string)($status['run_phase'] ?? self::BASE_PHASE);
        $assignmentMode = $this->getSiteAssignmentMode($siteId);

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

        // A previous slice may have waited briefly for the reservation mutex.
        // Once this retry has actually entered the analysis runner, its old
        // message is no longer the current state. Retain the wait timestamp,
        // however, so repeatedly blocked work can still time out safely.
        $this->clearResourceWaitMessage($siteId);
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        $status = is_array($status) ? $status : [];

        $runId = (string)$status['run_id'];
        $status['last_attempt_at'] = current_time('mysql', true);
        update_blog_option($siteId, self::OPTION_STATUS, $status);
        $this->logContinuationDiagnostic('slice_started', $siteId, $runId, [
            'phase' => $phase,
        ]);
        $startedAt = (int)strtotime((string)$status['last_started_at'] . ' UTC');
        if ((float)($status['active_duration_seconds'] ?? 0) >= $this->getTimeoutSeconds($assignmentMode) || ($startedAt > 0 && time() - $startedAt >= 7 * DAY_IN_SECONDS)) {
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
                throw new \RuntimeException(esc_html__('Not enough PHP memory is available to start another analysis step.', 'rrze-multisite-manager'));
            }
            $batchesProcessed = 0;
            for ($batch = 0; $batch < self::MAX_BATCHES_PER_REQUEST && ($batch === 0 || microtime(true) < $deadline); $batch++) {
                if ((float)($status['active_duration_seconds'] ?? 0) + (hrtime(true) - $started) / 1000000000 >= $this->getTimeoutSeconds($assignmentMode)) {
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
                    $result = $this->storageAnalysis->runBaseBatch($siteId, $assignmentMode);
                    $complete = ($result['status']['base']['status'] ?? '') === 'complete';
                } elseif ($phase === self::ORPHAN_PHASE) {
                    $result = $this->storageAnalysis->runOrphanBatch($siteId, $assignmentMode);
                    $complete = ($result['status']['orphan']['status'] ?? '') === 'complete';
                } elseif ($phase === self::METADATA_PHASE) {
                    $current = get_blog_option($siteId, self::OPTION_STATUS, []);
                    $result = $this->storageAnalysis->runMediaMetadataBatch($siteId, empty($current['metadata_started']), $assignmentMode);
                    $this->markMediaMetadataStarted($siteId);
                    $complete = ($result['analysis']['status'] ?? '') === 'complete';
                } else {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped when rendered.
                    throw new \RuntimeException(esc_html__('Invalid storage analysis phase.', 'rrze-multisite-manager'));
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
                        if ($this->isBatchAssignmentMode($assignmentMode)) {
                            $this->advanceBatchQueue($assignmentMode, $siteId);
                        }
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
            // A resource wait must back off on the affected website, regardless
            // of whether its recurring assignment is individual or shared.
            $scheduled = $this->scheduleSiteRetry($siteId);
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
                'timeout_seconds' => $this->getTimeoutSeconds($this->getSiteAssignmentMode($siteId)),
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

        if ($scheduled) {
            $queryArgs = [
                'fields' => 'ids',
                'number' => 0,
                'site__in' => $scheduledSiteIds,
            ];

            if ($urlSearch !== '') {
                $queryArgs['search'] = $urlSearch;
            }

            $siteIds = $this->sortStorageMonitoringSiteIds(array_map('absint', (array)get_sites($queryArgs)));
            $total = count($siteIds);
            $offset = ($page - 1) * $perPage;
            $pageSiteIds = array_slice($siteIds, $offset, $perPage);
            $processes = [];

            foreach ($pageSiteIds as $siteId) {
                $process = $this->getSiteProcess($siteId);

                if ($process !== null) {
                    $processes[] = $process;
                }
            }

            return [
                'processes' => $processes,
                'has_more' => $offset + $perPage < $total,
                'total' => $total,
            ];
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

    /**
     * Orders the storage monitoring table by the configured analysis method.
     * A running shared batch has a persisted randomized queue; its stored
     * order is authoritative until that batch has completed.
     *
     * @param array<int, int> $siteIds
     * @return array<int, int>
     */
    protected function sortStorageMonitoringSiteIds(array $siteIds): array {
        $modePriority = [
            'small-batch' => 0,
            'medium-batch' => 1,
            'large-batch' => 2,
            'site' => 3,
        ];
        $siteOrder = [];
        $batchSites = [];
        $queuePositions = [];
        $mode = '';
        $queue = [];
        $position = 0;
        $siteId = 0;

        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            foreach ($this->getBatchSiteIds($mode) as $position => $siteId) {
                $batchSites[(int)$siteId] = $mode;
                $queuePositions[$mode][(int)$siteId] = (int)$position;
            }

            $queue = get_site_option($this->getBatchQueueOptionName($mode), []);
            $queue = is_array($queue) ? $queue : [];
            foreach ((array)($queue['site_ids'] ?? []) as $position => $siteId) {
                $siteId = absint($siteId);
                if ($siteId > 0) {
                    $queuePositions[$mode][$siteId] = (int)$position;
                }
            }
        }

        foreach ($siteIds as $siteId) {
            $siteId = absint($siteId);
            $mode = (string)($batchSites[$siteId] ?? 'site');
            $priority = (int)($modePriority[$mode] ?? 4);
            $nextRunTimestamp = $this->getNextRecurringScheduledTimestamp($siteId);
            $order = $mode === 'site'
                ? ($nextRunTimestamp > 0 ? $nextRunTimestamp : PHP_INT_MAX)
                : (int)($queuePositions[$mode][$siteId] ?? PHP_INT_MAX);
            $siteOrder[$siteId] = [$priority, $order, $siteId];
        }

        usort($siteIds, static function (int $left, int $right) use ($siteOrder): int {
            return $siteOrder[$left] <=> $siteOrder[$right];
        });

        return array_values($siteIds);
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
        $batchMembershipMode = $this->getBatchMembershipMode($siteId);
        if ($assignmentMode === 'unassigned' && $batchMembershipMode !== '') {
            $assignmentMode = $batchMembershipMode;
        }
        $nextRunTimestamp = $isEligible ? (int)($scheduleStatus['next_recurring_run_timestamp'] ?? 0) : 0;
        $isWaitingForCurrentBatch = false;
        $isCurrentBatchSite = false;
        $waitingForSiteName = '';
        $batchRunState = '';
        $queuePosition = false;

        if ($batchMembershipMode !== '') {
            if ($this->isBatchRunning($batchMembershipMode)) {
                $batchRunState = 'running';
            } elseif ($this->isBatchStopped($batchMembershipMode)) {
                $batchRunState = 'stopped';
            } elseif ((int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($batchMembershipMode))) <= 0) {
                $batchRunState = 'not_planned';
            }
        }

        if ($isEligible && $this->isBatchAssignmentMode($assignmentMode)) {
            $queue = get_site_option($this->getBatchQueueOptionName($assignmentMode), []);
            $queue = is_array($queue) ? $queue : [];
            $currentSiteId = absint($queue['current_site_id'] ?? 0);
            $currentQueuePosition = max(0, (int)($queue['position'] ?? 0));
            $queuePosition = array_search($siteId, (array)($queue['site_ids'] ?? []), true);
            $isCurrentBatchSite = $currentSiteId > 0 && $siteId === $currentSiteId;
            if (empty($scheduleStatus['is_running']) && $queuePosition !== false && $queuePosition > $currentQueuePosition) {
                $isWaitingForCurrentBatch = true;
                $previousSiteId = absint($queue['site_ids'][$queuePosition - 1] ?? 0);
                $waitingForSiteName = (string)wp_parse_url(get_home_url($previousSiteId, '/'), PHP_URL_HOST);
                if ($waitingForSiteName === '') {
                    $waitingForSiteName = (string)get_blog_option($previousSiteId, 'blogname', '');
                }
            }
        }

        if ($isEligible && $this->isBatchAssignmentMode($assignmentMode) && empty($scheduleStatus['is_running'])) {
            $targetedTimestamp = (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($assignmentMode), [$siteId, true]));
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

        $canRestartBatchSite = $isEligible
            && $this->isBatchAssignmentMode($assignmentMode)
            && empty($scheduleStatus['is_running'])
            && (!empty($scheduleStatus['last_was_aborted']) || !empty($scheduleStatus['last_error']))
            && !$this->isBatchStopped($assignmentMode)
            && $batchRunState !== 'not_planned';

        return [
            'site_id' => $siteId,
            'name' => (string)get_blog_option($siteId, 'blogname', ''),
            'url' => get_home_url($siteId, '/'),
            'website_status_key' => $this->getWebsiteStatusFilterKey($site),
            'status' => $isWaitingForCurrentBatch
                ? __('Scheduled', 'rrze-multisite-manager')
                : $this->getSiteProcessStatus($isEligible, $isDue, $analysisStatus, $scheduleStatus),
            'status_key' => $isWaitingForCurrentBatch
                ? 'scheduled'
                : $this->getSiteProcessStatusKey($isEligible, $isDue, $analysisStatus, $scheduleStatus),
            'is_running' => $isEligible && $this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
            'is_eligible' => $isEligible,
            'schedule_mode' => $assignmentMode,
            'method_sort' => $this->getStorageMonitoringMethodSortValue($assignmentMode, $queuePosition, $nextRunTimestamp),
            'is_due' => $isDue,
            'is_waiting_for_current_batch' => $isWaitingForCurrentBatch,
            'is_current_batch_site' => $isCurrentBatchSite,
            'batch_run_state' => $batchRunState,
            'waiting_for_site_name' => $waitingForSiteName,
            'cycle' => $isEligible ? $this->getScheduleLabel($assignmentMode) : '',
            'last_started_at' => (string)($scheduleStatus['last_started_at'] ?? ''),
            'last_run' => (string)($scheduleStatus['last_completed_at'] ?? ''),
            // Show the actual pending continuation while a run is active.
            'next_run_timestamp' => $nextRunTimestamp,
            'last_duration_seconds' => (int)($scheduleStatus['last_duration_seconds'] ?? 0),
            'last_was_aborted' => !empty($scheduleStatus['last_was_aborted']),
            'can_restart_batch_site' => $canRestartBatchSite,
            'last_error' => $isWaitingForCurrentBatch
                ? sprintf(
                    /* translators: %s: domain of the currently processed website. */
                    __('Waiting for completion of website %s.', 'rrze-multisite-manager'),
                    $waitingForSiteName
                )
                : (string)($scheduleStatus['last_error'] ?? ''),
            'last_attempt_at' => (string)($scheduleStatus['last_attempt_at'] ?? ''),
            'phases' => is_array($scheduleStatus['phases'] ?? null) ? $scheduleStatus['phases'] : [],
            'base_progress' => is_array($analysisStatus['base'] ?? null) ? $analysisStatus['base'] : [],
            'can_start_now' => $isEligible && !$this->isBatchAssignmentMode($assignmentMode) && !$isDue && !$this->isSiteRunRunning($siteId, $analysisStatus, $scheduleStatus),
        ];
    }

    protected function getStorageMonitoringMethodSortValue(string $mode, int|false $queuePosition, int $nextRunTimestamp): string {
        $priority = match ($mode) {
            'small-batch' => 0,
            'medium-batch' => 1,
            'large-batch' => 2,
            default => 3,
        };
        $order = $mode === 'site'
            ? ($nextRunTimestamp > 0 ? $nextRunTimestamp : PHP_INT_MAX)
            : ($queuePosition === false ? PHP_INT_MAX : $queuePosition);

        return sprintf('%d-%012d', $priority, $order);
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

        // An interrupted analysis may have left its status as running even
        // though the recurring task was removed. Recover it before deciding
        // whether this explicit setup operation may schedule the website.
        $this->recoverInterruptedRun($siteId);
        $status = $this->getStatus($siteId);

        // The setup action applies to every eligible website without a current
        // recurring task. A completed historic analysis must not prevent a
        // website from being scheduled again after tasks were removed.
        return $this->getNextRecurringScheduledTimestamp($siteId) <= 0
            && empty($status['is_running']);
    }

    protected function scheduleRecurringAnalysis(int $siteId, bool $prioritizeFirstRun = false): bool {
        $delay = MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS));
        return $this->scheduleRecurringAnalysisAt($siteId, time() + $delay, $prioritizeFirstRun);
    }

    protected function scheduleRecurringAnalysisAt(int $siteId, int $timestamp, bool $prioritizeFirstRun = false): bool {
        if (!$this->isSiteEligible($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return false;
        }

        $this->unscheduleSiteEvents($siteId);
        $this->removeBatchSite($siteId);
        $this->removeSiteAssignment($siteId);

        $mode = $this->getInitialAssignmentMode($siteId);
        if ($this->isBatchAssignmentMode($mode)) {
            $hadBatchSchedule = (bool)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($mode)));
            $siteIds = $this->getBatchSiteIds($mode);
            $siteIds[] = $siteId;
            $this->updateBatchSiteIds($mode, $siteIds);

            if (!$this->scheduleBatchRecurringAt($timestamp, $mode)) {
                $this->removeBatchSite($siteId, $mode);
                return false;
            }

            $this->setSiteAssignment($siteId, $mode);
            $this->clearRecurringScheduleCache();
            $this->logSiteInfo('Speicherplatzanalyse dem Sammelbatch zugeordnet', $siteId, $mode, [
                'next_run_timestamp' => $timestamp,
                'small_media_threshold' => $this->config->getStorageAnalysisSmallBatchMediaThreshold(),
                'medium_media_threshold' => $this->config->getStorageAnalysisMediumBatchMediaThreshold(),
            ], true);
            if ($prioritizeFirstRun) {
                if (!$this->scheduleTargetedBatchSite($siteId, time() + MINUTE_IN_SECONDS, $mode)) {
                    // Keep the site in the recurring batch even if the
                    // additional targeted event could not be created.
                    $this->scheduleBatchContinuation(time() + MINUTE_IN_SECONDS, $mode);
                    LoggingService::error('RRZE-MSM: Prompt storage-analysis batch run could not be scheduled.', [
                        'site_id' => $siteId,
                        'assignment_mode' => $mode,
                    ]);
                }
            } elseif ($hadBatchSchedule) {
                $this->scheduleBatchContinuation(time() + MINUTE_IN_SECONDS, $mode);
            }
            return true;
        }

        return $this->scheduleIndividualAnalysisAt($siteId, $timestamp);
    }

    protected function scheduleIndividualAnalysisAt(int $siteId, int $timestamp): bool {
        $scheduled = (bool)$this->inSiteCronContext($siteId, function () use ($siteId, $timestamp): bool {
            return (bool)wp_schedule_event(
                max(time(), $timestamp),
                $this->getScheduleKey('site'),
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
            ], true);
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

        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            if (!in_array($siteId, $this->getBatchSiteIds($mode), true)) {
                continue;
            }
            $batchTimestamp = (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($mode)));

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
            if (absint($siteId) > 0 && in_array($mode, ['small-batch', 'medium-batch', 'large-batch', 'site'], true)) {
                $siteIds[absint($siteId)] = true;
            }
        }

        foreach (['small-batch', 'medium-batch', 'large-batch'] as $mode) {
            if ((int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->getBatchHook($mode))) > 0) {
                foreach ($this->getBatchSiteIds($mode) as $siteId) {
                    $siteIds[$siteId] = true;
                }
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

    protected function getScheduleKey(string $assignmentMode = 'site'): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $option = $assignmentMode === 'small-batch'
            ? 'monitoring_storage_analysis_small_batch_frequency'
            : ($assignmentMode === 'medium-batch' ? 'monitoring_storage_analysis_medium_batch_frequency' : ($assignmentMode === 'large-batch' ? 'monitoring_storage_analysis_large_batch_frequency' : 'monitoring_storage_analysis_individual_frequency'));
        $frequency = is_array($options) ? (string)($options[$option] ?? ($options['monitoring_storage_analysis_frequency'] ?? 'twiceweekly')) : 'twiceweekly';

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
        return implode(':', [$this->getScheduleKey('small-batch'), $this->getScheduleKey('medium-batch'), $this->getScheduleKey('large-batch'), $this->getScheduleKey('site'), 'resumable-v6']);
    }

    protected function getScheduleLabel(string $assignmentMode = 'site'): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $option = $assignmentMode === 'small-batch'
            ? 'monitoring_storage_analysis_small_batch_frequency'
            : ($assignmentMode === 'medium-batch' ? 'monitoring_storage_analysis_medium_batch_frequency' : ($assignmentMode === 'large-batch' ? 'monitoring_storage_analysis_large_batch_frequency' : 'monitoring_storage_analysis_individual_frequency'));
        $frequency = is_array($options) ? (string)($options[$option] ?? ($options['monitoring_storage_analysis_frequency'] ?? 'twiceweekly')) : 'twiceweekly';

        return $this->config->getSchedulerFrequencyLabel($frequency);
    }

    protected function getTimeoutSeconds(string $assignmentMode = 'site'): int {
        return $this->config->getStorageAnalysisTimeoutSeconds($assignmentMode);
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
        return $this->acquireProcessLock(self::LOCK_OPTION_PREFIX . $siteId) !== '';
    }

    protected function releaseSiteLock(int $siteId): void {
        $this->releaseProcessLock(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function acquireNetworkRunSlot(int $siteId): bool {
        if ($siteId <= 0 || !$this->acquireNetworkRunSlotsLock()) {
            return false;
        }

        try {
            $slots = get_site_option(self::NETWORK_RUN_SLOTS_OPTION, []);
            $slots = is_array($slots) ? $slots : [];
            $mode = $this->getSiteAssignmentMode($siteId);
            $modeSlots = is_array($slots[$mode] ?? null) ? $slots[$mode] : [];
            $expiresBefore = time() - self::REQUEST_LOCK_TTL;

            foreach ($modeSlots as $lockedSiteId => $startedAt) {
                if ((int)$startedAt <= $expiresBefore) {
                    unset($modeSlots[$lockedSiteId]);
                }
            }

            if (isset($modeSlots[$siteId])) {
                return true;
            }

            if (count($modeSlots) >= $this->config->getStorageAnalysisMaxConcurrentRuns()) {
                $slots[$mode] = $modeSlots;
                update_site_option(self::NETWORK_RUN_SLOTS_OPTION, $slots);
                return false;
            }

            $modeSlots[$siteId] = time();
            $slots[$mode] = $modeSlots;
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
            $mode = $this->getSiteAssignmentMode($siteId);
            $modeSlots = is_array($slots[$mode] ?? null) ? $slots[$mode] : [];
            unset($modeSlots[$siteId]);

            if (empty($modeSlots)) {
                unset($slots[$mode]);
            } else {
                $slots[$mode] = $modeSlots;
            }

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
        if ($this->acquireProcessLock(self::NETWORK_RUN_SLOTS_LOCK_OPTION) === '') {
            return false;
        }
        // Discard snapshots read before another request owned the mutex.
        $network = get_current_network_id();
        wp_cache_delete($network . ':' . self::NETWORK_RUN_SLOTS_OPTION, 'site-options');
        wp_cache_delete($network . ':' . self::CONTINUATION_QUEUE_OPTION, 'site-options');
        return true;
    }

    protected function releaseNetworkRunSlotsLock(): void {
        $this->releaseProcessLock(self::NETWORK_RUN_SLOTS_LOCK_OPTION);
    }

    protected function scheduleIndividualRetry(int $siteId): bool {
        return (bool)$this->inSiteCronContext($siteId, function () use ($siteId): bool {
            $args = $this->getScheduledAnalysisEventArgs($siteId);
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

    /** Schedule a delayed retry after a lock or resource-capacity collision. */
    protected function scheduleSiteRetry(int $siteId): bool {
        // The current slice has already reserved an immediate continuation.
        // Replace it with a real backoff instead of causing a tight retry loop.
        $this->clearIndividualRetry($siteId);

        return $this->scheduleIndividualRetry($siteId);
    }

    protected function getNextIndividualRetryTimestamp(int $siteId): int {
        return (int)$this->inSiteCronContext($siteId, function () use ($siteId): int {
            $hook = $this->config->getStorageAnalysisHook();
            $timestamp = (int)wp_next_scheduled($hook, $this->getScheduledAnalysisEventArgs($siteId));
            $legacyTimestamp = (int)wp_next_scheduled($hook, [$siteId, self::SCHEDULED_PHASE, true]);

            if ($timestamp <= 0) {
                return $legacyTimestamp;
            }

            return $legacyTimestamp > 0 ? min($timestamp, $legacyTimestamp) : $timestamp;
        });
    }

    protected function clearIndividualRetry(int $siteId): void {
        $this->inSiteCronContext($siteId, function () use ($siteId): void {
            $hook = $this->config->getStorageAnalysisHook();
            wp_clear_scheduled_hook($hook, [$siteId, self::SCHEDULED_PHASE, false]);
            wp_clear_scheduled_hook($hook, [$siteId, self::SCHEDULED_PHASE, true]);
        });
    }

    /** @return array{0: int, 1: string, 2: bool} */
    protected function getScheduledAnalysisEventArgs(int $siteId): array {
        return [
            $siteId,
            self::SCHEDULED_PHASE,
            $this->isBatchAssignmentMode($this->getSiteAssignmentMode($siteId)),
        ];
    }

    protected function hasActiveSiteLock(int $siteId): bool {
        return \RRZE\MultisiteManager\Support\AtomicLock::isLocked(self::LOCK_OPTION_PREFIX . $siteId);
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

        if ($runId !== '' && $startedTimestamp > 0 && (float)($stored['active_duration_seconds'] ?? 0) < $this->getTimeoutSeconds($this->getSiteAssignmentMode($siteId))
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

    /** Clears a stale wait message but retains the timeout baseline. */
    protected function clearResourceWaitMessage(int $siteId): void {
        $status = get_blog_option($siteId, self::OPTION_STATUS, []);
        if (!is_array($status) || empty($status['last_error'])) {
            return;
        }
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
        // The start of the first phase marks the start of a website analysis;
        // failures and aborts are always relevant. Other phase transitions
        // are intermediate steps.
        $isStart = $event === 'started' && $phase === self::BASE_PHASE;
        $isProblem = $event === 'failed' || $event === 'aborted';
        $this->logSiteInfo(sprintf('Speicherplatzanalyse: Phase %s %s', $phase, $event), $siteId, 'unassigned', [
            'site_url' => get_home_url($siteId, '/'),
            'phase' => $phase,
            'event' => $event,
            'message' => $message,
        ], !$isStart && !$isProblem);
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
        $this->recordPerformanceRun($siteId, time(), $duration);
        $this->clearPerformanceSummaryCache();
        $this->clearIndividualRetry($siteId);
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchAssignmentMode($assignmentMode)) {
            $this->advanceBatchQueue($assignmentMode, $siteId);
        }
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
        $mediaCount = $this->getSiteAttachmentCount($siteId);
        $targetMode = $this->getCompletedRunAssignmentMode($durationSeconds, $mediaCount);

        if ($currentMode === $targetMode) {
            $this->logSiteInfo('Speicherplatzanalyse-Zuordnung beibehalten', $siteId, $currentMode, [
                'duration_seconds' => $durationSeconds,
                'media_count' => $mediaCount,
                'small_runtime_threshold_seconds' => $this->config->getStorageAnalysisSmallBatchRuntimeThresholdSeconds(),
                'medium_runtime_threshold_seconds' => $this->config->getStorageAnalysisMediumBatchRuntimeThresholdSeconds(),
            ], true);
            return;
        }

        // Schedule the replacement before removing the old placement. A failed
        // scheduling call must leave the existing valid assignment intact.
        $nextRunTimestamp = $this->getNextRecurringScheduledTimestamp($siteId);
        $nextRunTimestamp = max(time(), $nextRunTimestamp);

        if ($this->isBatchAssignmentMode($targetMode)) {
            if ($this->scheduleBatchRecurringAt($nextRunTimestamp, $targetMode)) {
                $this->unscheduleSiteEvents($siteId);
                $siteIds = $this->getBatchSiteIds($targetMode);
                $siteIds[] = $siteId;
                $siteIds = array_values(array_unique(array_map('absint', $siteIds)));
                sort($siteIds, SORT_NUMERIC);
                $this->updateBatchSiteIds($targetMode, $siteIds);
                $this->removeSiteFromOtherBatchModes($siteId, $targetMode);
                $this->setSiteAssignment($siteId, $targetMode);
                $this->clearRecurringScheduleCache();
                $this->logSiteInfo('Speicherplatzanalyse-Zuordnung zu Sammelbatch geändert', $siteId, $targetMode, [
                    'previous_assignment' => $currentMode,
                    'media_count' => $mediaCount,
                    'duration_seconds' => $durationSeconds,
                    'small_runtime_threshold_seconds' => $this->config->getStorageAnalysisSmallBatchRuntimeThresholdSeconds(),
                    'medium_runtime_threshold_seconds' => $this->config->getStorageAnalysisMediumBatchRuntimeThresholdSeconds(),
                    'next_run_timestamp' => $nextRunTimestamp,
                ], true);
                return;
            }
        } elseif ($this->scheduleIndividualAnalysisAt($siteId, $nextRunTimestamp)) {
            $this->removeBatchSite($siteId);
            $this->logSiteInfo('Speicherplatzanalyse-Zuordnung zu Einzelauftrag geändert', $siteId, 'site', [
                'previous_assignment' => $currentMode,
                'media_count' => $mediaCount,
                'duration_seconds' => $durationSeconds,
                'medium_runtime_threshold_seconds' => $this->config->getStorageAnalysisMediumBatchRuntimeThresholdSeconds(),
                'next_run_timestamp' => $nextRunTimestamp,
            ], true);
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
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchAssignmentMode($assignmentMode)) {
            $this->advanceBatchQueue($assignmentMode, $siteId);
        }
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
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchAssignmentMode($assignmentMode)) {
            $this->advanceBatchQueue($assignmentMode, $siteId);
        }
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
