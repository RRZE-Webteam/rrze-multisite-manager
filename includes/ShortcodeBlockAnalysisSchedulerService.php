<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Support\NetworkCronCleanupQueue;

defined('ABSPATH') || exit;

class ShortcodeBlockAnalysisSchedulerService {
    use \RRZE\MultisiteManager\Support\ProcessLocks;
    protected const HOOK_FALLBACK = 'rrze_msm_run_shortcode_block_analysis';
    protected const STATUS_OPTION = 'rrze_msm_shortcode_block_analysis_status';
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_shortcode_block_analysis_lock_';
    protected const SCHEDULE_SIGNATURE_OPTION = 'rrze_msm_shortcode_block_analysis_schedule_signature';
    protected const GLOBAL_INITIALIZATION_OPTION = 'rrze_msm_shortcode_block_analysis_global_initialization';
    protected const TASK_REMOVAL_OPTION = 'rrze_msm_shortcode_block_analysis_tasks_removed';
    protected const SCHEDULE_INITIALIZATION_OFFSET_OPTION = 'rrze_msm_shortcode_block_analysis_schedule_initialization_offset';
    protected const SCHEDULE_INITIALIZATION_STATE_OPTION = 'rrze_msm_shortcode_block_analysis_schedule_initialization_state';
    protected const SCHEDULE_INITIALIZATION_LOCK_OPTION = 'rrze_msm_shortcode_block_analysis_schedule_initialization_lock';
    protected const BATCH_SITE_IDS_OPTION = 'rrze_msm_shortcode_block_analysis_batch_site_ids';
    protected const ASSIGNMENTS_OPTION = 'rrze_msm_shortcode_block_analysis_assignments';
    protected const BATCH_OFFSET_OPTION = 'rrze_msm_shortcode_block_analysis_batch_offset';
    protected const BATCH_LOCK_OPTION = 'rrze_msm_shortcode_block_analysis_batch_lock';
    protected const MEDIUM_BATCH_SITE_IDS_OPTION = 'rrze_msm_shortcode_block_analysis_medium_batch_site_ids';
    protected const MEDIUM_BATCH_OFFSET_OPTION = 'rrze_msm_shortcode_block_analysis_medium_batch_offset';
    protected const MEDIUM_BATCH_LOCK_OPTION = 'rrze_msm_shortcode_block_analysis_medium_batch_lock';
    protected const LARGE_BATCH_SITE_IDS_OPTION = 'rrze_msm_shortcode_block_analysis_large_batch_site_ids';
    protected const LARGE_BATCH_OFFSET_OPTION = 'rrze_msm_shortcode_block_analysis_large_batch_offset';
    protected const LARGE_BATCH_LOCK_OPTION = 'rrze_msm_shortcode_block_analysis_large_batch_lock';
    protected const STOPPED_BATCHES_OPTION = 'rrze_msm_shortcode_block_analysis_stopped_batches';
    protected const REMOVED_SITE_IDS_OPTION = 'rrze_msm_shortcode_block_analysis_removed_site_ids';
    protected const FREQUENCY_RESCHEDULE_HOOK = 'rrze_msm_reschedule_shortcode_block_analysis_frequency';
    protected const TASK_REMOVAL_HOOK = 'rrze_msm_remove_shortcode_block_analysis_tasks';
    protected const TASK_REMOVAL_STATE_OPTION = 'rrze_msm_shortcode_block_analysis_task_removal_state';
    protected const FREQUENCY_RESCHEDULE_STATE_OPTION = 'rrze_msm_shortcode_block_analysis_frequency_reschedule_state';
    protected const BATCH_MODES = ['small-batch', 'medium-batch', 'large-batch'];
    // A database checkpoint is written after every slice. The request and
    // finding-byte budgets below remain the hard limits for large websites.
    protected const BATCH_SIZE = 50;
    protected const BATCH_MAX_BATCHES_PER_REQUEST = 12;
    protected const INDIVIDUAL_MAX_BATCHES_PER_REQUEST = 20;
    protected const BATCH_REQUEST_BUDGET_SECONDS = 20;
    protected const INDIVIDUAL_REQUEST_BUDGET_SECONDS = 60;
    protected const CONTINUATION_DELAY_SECONDS = 5;
    protected const BATCH_RUN_STALE_AFTER_SECONDS = 2 * HOUR_IN_SECONDS;
    // Cumulative stored finding payload per phase. Sized so that the
    // configurable entry limit (up to 5,000) remains the effective bound.
    protected const RESULT_MAX_BYTES = 4194304;
    protected const OMITTED_SOURCE_LIMIT = 10;
    protected array $findingTotals = [];
    protected int $pendingFindingBytes = 0;
    protected array $pendingFindingKeys = [];
    protected const SHORTCODE_PHASE = 'shortcodes';
    protected const BLOCK_PHASE = 'blocks';

    protected Config $config;
    /** @var array<string, string> */
    protected array $blockCategoryLabels = [];
    /** @var array<int, int>|null */
    protected ?array $currentRecurringScheduleTimestamps = null;
    /** @var array<int, true>|null */
    protected ?array $recurringScheduledSiteIds = null;

    public function __construct(?Config $config = null) {
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action($this->getHook(), [$this, 'runScheduledAnalysis'], 10, 1);
        add_action($this->config->getLegacyShortcodeBlockAnalysisHook(), [$this, 'runScheduledAnalysis'], 10, 1);
        add_action($this->config->getShortcodeBlockAnalysisBatchHook(), [$this, 'runBatchScheduledAnalysis'], 10, 2);
        add_action(self::FREQUENCY_RESCHEDULE_HOOK, [$this, 'runFrequencyRescheduleBatch']);
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureRecurringSchedules'], 20);
    }

    public function registerSchedules(array $schedules): array {
        foreach ($this->config->getShortcodeBlockAnalysisScheduleKeys() as $frequency => $scheduleKey) {
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

        // Never scan and reschedule an entire network during an arbitrary
        // admin request. Explicit setup runs are processed in small batches.
        $this->markScheduleConfigurationCurrent();
    }

    /**
     * Marks the current scheduler configuration without creating site-specific tasks.
     */
    public function markScheduleConfigurationCurrent(): void {
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
    }

    /** Queues a bounded reschedule of existing recurring analyses. */
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

        if (!is_array($state) || empty($state['pending'])) {
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

            $this->unschedule($siteId);
            $this->scheduleRecurringAnalysisAt($siteId, time() + MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS)));
        }

        $state['offset'] = $offset + count($siteIds);

        if (empty($siteIds) || (int)$state['offset'] >= $total) {
            delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
            $this->markScheduleConfigurationCurrent();
            return;
        }

        update_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION, $state);
        $this->inBatchCronContext(fn(): bool => wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::FREQUENCY_RESCHEDULE_HOOK));
    }

    public function syncRecurringSchedules(): void {
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0, 'orderby' => 'id', 'order' => 'ASC']);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteActive($siteId)) {
                $this->deactivateSite($siteId);
                continue;
            }

            $this->recoverInterruptedAnalysis($siteId);

            if ($this->isRunning($siteId)) {
                continue;
            }

            // A removed schedule is an explicit administrator decision. Frequency
            // changes may reschedule existing tasks, but must never recreate them.
            if (!$this->hasRecurringScheduledAnalysis($siteId)) {
                continue;
            }

            $this->unschedule($siteId);
            $this->scheduleRecurringAnalysis($siteId);
        }

        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
    }

    public static function clearScheduledEvents(?Config $config = null, bool $acrossNetwork = true): int {
        $scheduler = new self($config);
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
                    foreach ([$scheduler->getHook(), $scheduler->config->getLegacyShortcodeBlockAnalysisHook(), $scheduler->config->getShortcodeBlockAnalysisBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK, self::TASK_REMOVAL_HOOK] as $hook) {
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
        delete_site_option(self::BATCH_OFFSET_OPTION);
        delete_site_option(self::BATCH_LOCK_OPTION);
        delete_site_option(self::MEDIUM_BATCH_OFFSET_OPTION);
        delete_site_option(self::MEDIUM_BATCH_LOCK_OPTION);
        delete_site_option(self::LARGE_BATCH_OFFSET_OPTION);
        delete_site_option(self::LARGE_BATCH_LOCK_OPTION);
        delete_site_option(self::STOPPED_BATCHES_OPTION);
        delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
        delete_site_option(self::TASK_REMOVAL_STATE_OPTION);
        // Removing tasks is an explicit administrator decision. Do not make the
        // automatic initialization action reappear and recreate these tasks.
        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::TASK_REMOVAL_OPTION, 1);

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
                LoggingService::info($this->config, 'RRZE-MSM: Shortcode and block analysis task cleanup completed.', $state);
            }
        );
    }

    public function runScheduledTaskRemoval(): void {
        (new NetworkCronCleanupQueue($this->config))->run(
            self::TASK_REMOVAL_STATE_OPTION,
            self::TASK_REMOVAL_HOOK,
            fn(): int => $this->clearScheduledEventsOnCurrentSite(),
            function (array $state): void {
                LoggingService::info($this->config, 'RRZE-MSM: Shortcode and block analysis task cleanup completed.', $state);
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
        delete_site_option(self::BATCH_OFFSET_OPTION);
        delete_site_option(self::BATCH_LOCK_OPTION);
        delete_site_option(self::MEDIUM_BATCH_OFFSET_OPTION);
        delete_site_option(self::MEDIUM_BATCH_LOCK_OPTION);
        delete_site_option(self::LARGE_BATCH_OFFSET_OPTION);
        delete_site_option(self::LARGE_BATCH_LOCK_OPTION);
        delete_site_option(self::STOPPED_BATCHES_OPTION);
        delete_site_option(self::FREQUENCY_RESCHEDULE_STATE_OPTION);
        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::TASK_REMOVAL_OPTION, 1);
    }

    protected function clearScheduledEventsOnCurrentSite(): int {
        $cron = _get_cron_array();
        $removed = 0;

        foreach ((array)$cron as $timestamp => $events) {
            foreach ([$this->getHook(), $this->config->getLegacyShortcodeBlockAnalysisHook(), $this->config->getShortcodeBlockAnalysisBatchHook(), self::FREQUENCY_RESCHEDULE_HOOK] as $hook) {
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
        (new Analysis\AnalysisStore())->removeSiteAnalysis(get_current_blog_id(), 'content');

        return $saved ? $removed : 0;
    }

    public function requestAnalysis(int $siteId): bool {
        if ($this->isAnalysisTimedOut($siteId)) {
            $this->markTimedOut($siteId);
        }

        $wasRunning = $this->isRunning($siteId);
        $this->recoverInterruptedAnalysis($siteId);

        if ($wasRunning && !$this->isRunning($siteId) && $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return true;
        }

        if (!$this->isSiteActive($siteId) || $this->isRunning($siteId)) {
            return false;
        }

        $assignment = $this->getSiteAssignmentMode($siteId);

        if ($this->isBatchMode($assignment)) {
            return false;
        }

        if ($assignment === 'unassigned') {
            $this->allowSiteScheduling($siteId);

            return $this->scheduleRecurringAnalysisAt($siteId, time(), true);
        }

        $this->unschedule($siteId);

        return $this->scheduleRecurringAnalysisAt($siteId, time());
    }

    /**
     * Retries one failed shared-batch analysis without restarting the other
     * websites in that batch.
     */
    public function restartFailedBatchAnalysisAsIndividual(int $siteId): bool {
        if (!$this->isSiteActive($siteId)) {
            return false;
        }

        $this->recoverInterruptedAnalysis($siteId);
        $status = $this->getStatus($siteId);

        if ($this->isRunning($siteId)
            || !$this->isBatchMode($this->getSiteAssignmentMode($siteId))
            || empty($status['last_error'])
        ) {
            return false;
        }

        $this->unschedule($siteId);
        $this->deleteAnalysisState($siteId);

        if (!$this->scheduleIndividualAnalysisAt($siteId, time())) {
            return false;
        }

        $this->allowSiteScheduling($siteId);
        $this->markScheduled($siteId, true);

        LoggingService::info($this->config, 'RRZE-MSM: Failed shared shortcode and block analysis restarted as an individual task.', [
            'site_id' => $siteId,
            'site_url' => get_home_url($siteId, '/'),
        ]);

        return true;
    }

    public function deactivateSite(int $siteId): void {
        $this->unschedule($siteId);
        $status = $this->getStatus($siteId);

        if (empty($status)) {
            return;
        }

        $status['status'] = 'inactive';
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    public function runScheduledAnalysis(int $siteId): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isTaskRemovalInProgress()) {
            return;
        }

        if ($siteId <= 0 || !get_site($siteId)) {
            return;
        }

        if (!$this->isSiteActive($siteId)) {
            $this->deactivateSite($siteId);
            return;
        }

        $mode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchMode($mode) && $this->isBatchStopped($mode)) {
            return;
        }

        $budget = new \RRZE\MultisiteManager\Support\WorkerBudget();
        if (!$budget->acquire($siteId)) {
            if ($this->isBatchMode($mode)) {
                $this->scheduleBatchContinuation(time() + MINUTE_IN_SECONDS, $mode);
            } else {
                $this->scheduleIndividualContinuation($siteId, MINUTE_IN_SECONDS);
            }
            return;
        }
        try {
            $this->runAdmittedScheduledAnalysis($siteId);
        } finally {
            $budget->release();
        }
    }

    protected function runAdmittedScheduledAnalysis(int $siteId): void {
        if (!$this->acquireLock($siteId)) {
            return;
        }

        try {
            $this->executeScheduledAnalysis($siteId);
        } finally {
            $this->releaseLock($siteId);
        }
    }

    protected function executeScheduledAnalysis(int $siteId): void {
        $runStartedAt = microtime(true);

        $completed = false;
        $failed = false;

        try {
            $completed = $this->runAnalysis($siteId);
        } catch (\Throwable $exception) {
            $failed = true;
            if ($this->isAnalysisTimedOut($siteId)) {
                $this->markTimedOut($siteId);
            } else {
                $this->markFailed($siteId, $exception->getMessage());
            }
            do_action(
                'rrze.log.error',
                'RRZE-MSM: Shortcode and block analysis failed',
                [
                    'site_id' => $siteId,
                    'site_url' => get_home_url($siteId, '/'),
                    'message' => $exception->getMessage(),
                ]
            );
        } finally {
            $this->recordRunDuration($siteId, microtime(true) - $runStartedAt);

            if ($this->isTaskRemovalInProgress()) {
                $this->releaseLock($siteId);
                $this->deactivateSite($siteId);
                return;
            }

            if ($completed) {
                $this->reconcileSiteAssignmentAfterRun($siteId);
            } elseif (!$failed && $this->isBatchMode($this->getSiteAssignmentMode($siteId))) {
                $mode = $this->getSiteAssignmentMode($siteId);
                if (!$this->isBatchStopped($mode)) {
                    $this->scheduleBatchContinuation(time() + self::CONTINUATION_DELAY_SECONDS, $mode);
                }
            } elseif (!$failed) {
                $this->scheduleIndividualContinuation($siteId);
            }

            // The continuation must exist before the lock is released. The
            // Monitoring page can otherwise mistake the brief handover between
            // two bounded Cron requests for an interrupted analysis.
            $this->releaseLock($siteId);
        }
    }

    /**
     * Processes one small website per invocation and schedules a short
     * continuation. This keeps the global batch hook bounded even when a
     * supposedly small website is unexpectedly expensive.
     */
    public function runBatchScheduledAnalysis(mixed $mode = 'small-batch', mixed $continuation = false): void {
        if ($mode === true) {
            $continuation = true;
            $mode = 'small-batch';
        }
        $mode = $this->normalizeBatchMode((string)$mode);
        if (MetricsService::isFullDataCleanupInProgress() || $this->isTaskRemovalInProgress() || $this->isBatchStopped($mode)) {
            return;
        }
        $budget = new \RRZE\MultisiteManager\Support\WorkerBudget();
        if (!$budget->acquire()) {
            $this->scheduleBatchContinuation(time() + MINUTE_IN_SECONDS, $mode);
            return;
        }
        try {
            $this->runAdmittedBatchScheduledAnalysis($mode, (bool)$continuation);
        } finally {
            $budget->release();
        }
    }

    protected function runAdmittedBatchScheduledAnalysis(string $mode, bool $continuation): void {
        if (MetricsService::isFullDataCleanupInProgress() || $this->isTaskRemovalInProgress() || $this->isBatchStopped($mode)) {
            return;
        }

        // Older releases could create the shared hook in a site's local Cron
        // array. Move it to the main site's central Cron array when it runs.
        if (get_current_blog_id() !== $this->getBatchCronSiteId()) {
            $this->scheduleBatchAnalysisAt(time(), $mode);
            wp_clear_scheduled_hook($this->config->getShortcodeBlockAnalysisBatchHook(), $this->getBatchRecurringArgs($mode));
            return;
        }

        if (!$this->acquireBatchLock($mode)) {
            return;
        }

        try {
            $siteIds = $this->getActiveBatchSiteIds($mode);
            $offsetOption = $this->getBatchOffsetOption($mode);
            $offset = max(0, (int)get_site_option($offsetOption, 0));

            if (empty($siteIds)) {
                return;
            }

            // A recurring trigger starts a fresh pass only when no continuation is active.
            if (!$continuation && $offset > 0) {
                if ((int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled($this->config->getShortcodeBlockAnalysisBatchHook(), $this->getBatchContinuationArgs($mode))) <= 0) {
                    $this->scheduleBatchContinuation(time() + 5, $mode);
                }
                return;
            }

            if ($offset >= count($siteIds)) {
                $offset = 0;
            }

            $siteId = (int)($siteIds[$offset] ?? 0);

            if ($siteId > 0) {
                $this->runScheduledAnalysis($siteId);
            }

            // A completed run may have moved this website between the shared
            // queue and an individual task. Re-read the queue before choosing
            // the continuation so that no remaining website is skipped.
            $siteIds = $this->getActiveBatchSiteIds($mode);
            $currentIndex = array_search($siteId, $siteIds, true);
            $nextOffset = $currentIndex === false ? $offset : $currentIndex + 1;

            if ($nextOffset >= count($siteIds)) {
                delete_site_option($offsetOption);
                return;
            }

            update_site_option($offsetOption, $nextOffset);
            $this->scheduleBatchContinuation(time() + 5, $mode);
        } finally {
            $this->releaseBatchLock($mode);
        }
    }

    public function canStartBatchNow(string $mode = 'small-batch'): bool {
        $mode = $this->normalizeBatchMode($mode);
        return !\RRZE\MultisiteManager\Support\AtomicLock::isLocked($this->getBatchLockOption($mode))
            && !empty($this->getActiveBatchSiteIds($mode))
            && ($this->isBatchStopped($mode) || $this->getNextBatchScheduledTimestamp($mode) > 0);
    }

    public function startBatchNow(string $mode = 'small-batch'): bool {
        $mode = $this->normalizeBatchMode($mode);
        if (!$this->canStartBatchNow($mode)) {
            return false;
        }

        return (bool)$this->inBatchCronContext(function () use ($mode): bool {
            $hook = $this->config->getShortcodeBlockAnalysisBatchHook();
            $this->unscheduleBatchEvents($mode);
            $scheduled = (bool)wp_schedule_event(time(), $this->getScheduleKey($mode), $hook, $this->getBatchRecurringArgs($mode));
            if ($scheduled) {
                $this->markBatchStopped($mode, false);
                LoggingService::info($this->config, 'RRZE-MSM: Shared shortcode and block analysis batch started early.', ['cron_site_id' => $this->getBatchCronSiteId(), 'batch_mode' => $mode, 'batch_site_count' => count($this->getActiveBatchSiteIds($mode))]);
            }
            return $scheduled;
        });
    }

    /** Stops one shared batch, aborting its active website and retaining assignments. */
    public function stopBatch(string $mode): bool {
        if (!in_array($mode, self::BATCH_MODES, true)) {
            return false;
        }

        foreach ($this->getActiveBatchSiteIds($mode) as $siteId) {
            $siteId = (int)$siteId;
            if ($siteId > 0 && $this->isRunning($siteId)) {
                $this->abortBatchSiteAnalysis($siteId, __('The shared shortcode and block analysis batch was stopped.', 'rrze-multisite-manager'));
            }
        }

        $this->unscheduleBatchEvents($mode);
        $this->markBatchStopped($mode, true);

        LoggingService::info($this->config, 'RRZE-MSM: Shared shortcode and block analysis batch stopped.', [
            'cron_site_id' => $this->getBatchCronSiteId(),
            'batch_mode' => $mode,
        ]);

        return true;
    }

    public function isBatchRunning(string $mode): bool {
        $mode = $this->normalizeBatchMode($mode);

        if ($this->isBatchStopped($mode)) {
            return false;
        }

        foreach ($this->getActiveBatchSiteIds($mode) as $siteId) {
            if ($this->isRunning((int)$siteId)) {
                return true;
            }
        }

        return false;
    }

    /** Aborts the currently active website of a shared batch. */
    public function abortBatchSiteAnalysis(int $siteId, string $message = ''): bool {
        $mode = $this->getSiteAssignmentMode($siteId);
        if (!$this->isBatchMode($mode) || !$this->isRunning($siteId)) {
            return false;
        }

        $this->markAborted($siteId, $message !== '' ? $message : __('The shortcode and block analysis was stopped manually.', 'rrze-multisite-manager'));

        return true;
    }

    public function getStatus(int $siteId): array {
        $status = get_blog_option($siteId, self::STATUS_OPTION, []);

        return is_array($status) ? $status : [];
    }

    public function getResult(int $siteId): array {
        return (new Analysis\AnalysisStore())->summary($siteId);
    }

    public function getResultPage(int $siteId, string $kind, int $page, string $filter, string $sort = 'name', string $order = 'asc'): array {
        $store = new Analysis\AnalysisStore();
        $summary = $store->summary($siteId);
        if (!$summary) {
            return [];
        }
        $rows = $store->groupedFindings($siteId, $summary['run_id'], $kind, $page, $filter, $sort, $order);
        return $summary + [$kind => $rows['entries'], 'total' => $rows['total'], 'page' => $rows['page'], 'per_page' => $rows['per_page']];
    }

    public function getNextScheduledRunTimestamp(int $siteId): int {
        return $siteId > 0 ? $this->getNextRecurringScheduledTimestamp($siteId) : 0;
    }

    /** @return 'inactive'|'running'|'failed' */
    public function getRequestAnalysisFailureState(int $siteId): string {
        if (!$this->isSiteActive($siteId)) {
            return 'inactive';
        }

        return $this->isRunning($siteId) ? 'running' : 'failed';
    }

    /**
     * Returns the explicitly stored scheduler assignment for a website.
     *
     * @return 'small-batch'|'medium-batch'|'large-batch'|'site'|'unassigned'
     */
    public function getSiteAssignmentMode(int $siteId): string {
        if ($siteId <= 0) {
            return 'unassigned';
        }

        $assignments = $this->getSiteAssignments();

        return $assignments[$siteId] ?? 'unassigned';
    }

    /**
     * Returns the planning distribution for active websites without loading
     * any shortcode or block analysis results.
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
        $assignments = $this->getSiteAssignments();
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ]);

        foreach ($siteIds as $siteId) {
            $mode = $assignments[absint($siteId)] ?? 'unassigned';
            $counts[$mode]++;
        }

        return $counts;
    }

    /**
     * Writes a site-specific information log entry in one consistent form.
     *
     * @param 'small-batch'|'medium-batch'|'large-batch'|'site'|'unassigned' $assignmentMode
     * @param array<string, mixed>         $context
     */
    protected function logSiteInfo(string $label, int $siteId, string $assignmentMode = 'unassigned', array $context = []): void {
        if ($assignmentMode === 'unassigned') {
            $assignmentMode = $this->getSiteAssignmentMode($siteId);
        }

        $assignment = $assignmentMode === 'large-batch' ? 'Large batch'
            : ($assignmentMode === 'medium-batch' ? 'Medium batch' : ($this->isBatchMode($assignmentMode) ? 'Small batch' : 'Single'));
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

    public function getSiteProcesses(): array {
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0, 'orderby' => 'id', 'order' => 'ASC']);
        $processes = [];

        foreach ($siteIds as $siteId) {
            $processes[] = $this->getSiteProcess((int)$siteId);
        }

        return $processes;
    }

    /**
     * Returns one bounded page for the monitoring table.  This deliberately
     * includes unscheduled sites so pagination never requires scanning every
     * site merely to find rows with an existing analysis record.
     *
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool, total: int}
     */
    public function getSiteProcessesPage(int $page, int $perPage, string $urlSearch = ''): array {
        return $this->getSiteProcessesPageBySchedule($page, $perPage, $urlSearch, true);
    }

    /**
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool, total: int}
     */
    public function getUnassignedActiveSiteProcessesPage(int $page, int $perPage, string $urlSearch = ''): array {
        return $this->getSiteProcessesPageBySchedule($page, $perPage, $urlSearch, false);
    }

    /**
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool, total: int}
     */
    protected function getSiteProcessesPageBySchedule(int $page, int $perPage, string $urlSearch, bool $scheduled): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $scheduledSiteIds = $this->getScheduledSiteIds();

        if ($scheduled && empty($scheduledSiteIds)) {
            return ['processes' => [], 'has_more' => false, 'total' => 0];
        }

        $queryArgs = [
            'fields' => 'ids',
            'number' => $perPage + 1,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'domain',
            'order' => 'ASC',
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ];

        if ($scheduled) {
            $queryArgs['site__in'] = $scheduledSiteIds;
        } elseif (!empty($scheduledSiteIds)) {
            $queryArgs['site__not_in'] = $scheduledSiteIds;
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
            $processes[] = $this->getSiteProcess((int)$siteId);
        }

        return [
            'processes' => $processes,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    /** @return array<int, int> */
    protected function getScheduledSiteIds(): array {
        $assignments = $this->getSiteAssignments();
        $scheduledSiteIds = [];

        foreach ($assignments as $siteId => $mode) {
            $siteId = absint($siteId);

            if ($siteId <= 0) {
                continue;
            }

            // Individual schedules are stored in each website's local Cron
            // array. The monitoring screen runs in the network site's
            // context, so it must use the persisted assignment instead of
            // only inspecting the current Cron array.
            if ($mode === 'site' || $this->isBatchMode($mode)) {
                // A stopped shared batch deliberately has no Cron event. Its
                // persisted assignment must nevertheless remain visible so
                // it can be started again from the monitoring table.
                $scheduledSiteIds[] = $siteId;
            }
        }

        $cron = _get_cron_array();

        foreach ((array)$cron as $events) {
            foreach ([$this->getHook(), $this->config->getLegacyShortcodeBlockAnalysisHook()] as $hook) {
                foreach ((array)($events[$hook] ?? []) as $event) {
                    if (empty($event['schedule'])) {
                        continue;
                    }

                    $siteId = absint(((array)($event['args'] ?? []))[0] ?? 0);

                    if ($siteId > 0) {
                        $scheduledSiteIds[] = $siteId;
                    }
                }
            }
        }

        return array_values(array_unique($scheduledSiteIds));
    }

    /** @return array<string, mixed> */
    protected function getSiteProcess(int $siteId): array {
        $status = $this->getStatus($siteId);
        $result = $this->getResult($siteId);
        $nextRun = $this->getNextRecurringScheduledTimestamp($siteId);
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        $batchRunState = $this->isBatchMode($assignmentMode) && $this->isBatchStopped($assignmentMode) ? 'stopped' : '';

        $this->recoverInterruptedAnalysis($siteId, $status);
        $status = $this->getStatus($siteId);
        $isActive = $this->isSiteActive($siteId);
        $isRunning = $this->isRunning($siteId);
        // A newly scheduled analysis intentionally hides the timestamps from
        // the preceding run, even though its result remains available until
        // the new run replaces it.
        $lastFinishedAt = (string)($status['status'] ?? '') === 'complete'
            ? (string)($result['generated_at'] ?? '')
            : '';

        // Older completed runs may not yet have stored a separate result.
        if ($lastFinishedAt === '' && (string)($status['status'] ?? '') === 'complete' && empty($status['last_error'])) {
            $lastFinishedAt = (string)($status['last_finished_at'] ?? '');
        }

        $statusKey = $batchRunState === 'stopped'
            ? 'stopped'
            : $this->getProcessStatusKey($isActive, $isRunning, $nextRun, $status, $lastFinishedAt);

        return [
            'site_id' => $siteId,
            'name' => (string)get_blog_option($siteId, 'blogname', ''),
            'url' => get_home_url($siteId, '/'),
            'status' => $this->getProcessStatusLabel($statusKey),
            'status_key' => $statusKey,
            'is_active' => $isActive,
            'website_status_key' => $isActive ? 'active' : 'inactive',
            'is_running' => $isRunning,
            'last_started_at' => (string)($status['last_started_at'] ?? ''),
            // Only a persisted result represents a successfully completed analysis.
            'last_finished_at' => $lastFinishedAt,
            'last_runtime_seconds' => isset($status['last_runtime_seconds']) ? (float)$status['last_runtime_seconds'] : null,
            'next_run_timestamp' => $nextRun,
            'assignment_mode' => $assignmentMode,
            'batch_run_state' => $batchRunState,
            'cycle' => $isActive ? $this->getScheduleLabel($assignmentMode) : '',
            'processed_posts' => (int)($status['processed_posts'] ?? 0),
            'total_posts' => (int)($status['total_posts'] ?? 0),
            'last_error' => (string)($status['last_error'] ?? ''),
            'phases' => $this->getProcessPhases($status, $lastFinishedAt),
        ];
    }

    protected function getProcessStatusKey(bool $isActive, bool $isRunning, int $nextRun, array $status, string $lastFinishedAt): string {
        if (!$isActive) {
            return 'inactive';
        }

        if ($isRunning) {
            return 'running';
        }

        if (!empty($status['last_error'])) {
            return 'error';
        }

        if ($nextRun > 0 && $nextRun <= time()) {
            return 'waiting_for_cron';
        }

        if ($lastFinishedAt !== '' && $nextRun > time()) {
            return 'ok';
        }

        return $nextRun > 0 ? 'scheduled' : 'not_scheduled';
    }

    protected function getProcessStatusLabel(string $statusKey): string {
        $labels = [
            'inactive' => __('Inactive', 'rrze-multisite-manager'),
            'running' => __('Running', 'rrze-multisite-manager'),
            'error' => __('Error', 'rrze-multisite-manager'),
            'stopped' => __('Stopped', 'rrze-multisite-manager'),
            'waiting_for_cron' => __('Waiting for cron', 'rrze-multisite-manager'),
            'ok' => __('Ok', 'rrze-multisite-manager'),
            'scheduled' => __('Scheduled', 'rrze-multisite-manager'),
            'not_scheduled' => __('Not scheduled', 'rrze-multisite-manager'),
        ];

        return $labels[$statusKey] ?? $labels['not_scheduled'];
    }

    protected function getProcessPhases(array $status, string $lastFinishedAt): array {
        $phases = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();

        if ($lastFinishedAt === '' || (string)($status['status'] ?? '') !== 'complete') {
            return $phases;
        }

        foreach ([self::SHORTCODE_PHASE, self::BLOCK_PHASE] as $phase) {
            $phaseStatus = is_array($phases[$phase] ?? null) ? $phases[$phase] : [];

            if (in_array((string)($phaseStatus['status'] ?? ''), ['idle', 'scheduled'], true) && empty($phaseStatus['started_at'])) {
                $phaseStatus['status'] = 'complete';
                $phaseStatus['started_at'] = $lastFinishedAt;
                $phaseStatus['finished_at'] = $lastFinishedAt;
                $phases[$phase] = $phaseStatus;
            }
        }

        return $phases;
    }

    public function getUnscheduledActiveSiteCount(): int {
        // Kept for backward compatibility. The monitoring page always shows
        // the explicit setup action instead of scanning every site on load.
        return 0;
    }

    public function initializeUnscheduledActiveSites(): int {
        $initialized = 0;
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);

        delete_site_option(self::TASK_REMOVAL_OPTION);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($this->isSiteUnscheduled($siteId)) {
                if ($this->scheduleRecurringAnalysis($siteId, true)) {
                    $initialized++;
                }
            }
        }

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return $initialized;
    }

    /**
     * Schedules one bounded group of active websites after an explicit
     * administrator request.
     *
     * @return array{initialized: int, processed: int, total: int, complete: bool}
     */
    public function initializeUnscheduledActiveSitesBatch(int $batchSize = 25): array {
        $state = $this->startScheduleInitialization();

        if (empty($state['run_id'])) {
            return ['initialized' => 0, 'processed' => 0, 'total' => 0, 'complete' => false];
        }

        return $this->runScheduleInitializationBatch((string)$state['run_id'], $batchSize);
    }

    /**
     * Starts, or returns, an explicit browser-driven initialization run.
     * A stable site-ID snapshot makes this independent of table pagination,
     * searches, and changes to the network during the run.
     *
     * @return array{run_id: string, initialized: int, processed: int, total: int, complete: bool}
     */
    public function startScheduleInitialization(): array {
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
            $scheduledSiteIds = $this->getScheduledSiteIds();

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
     * Processes one bounded browser-requested group. The next group is
     * requested by the progress dialog, not by WP-Cron.
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

            delete_site_option(self::TASK_REMOVAL_OPTION);

            foreach ($siteIds as $siteId) {
                if (!$this->refreshScheduleInitializationLock($runId, $lockToken)) {
                    $progress = $this->getScheduleInitializationProgress($state, false);
                    $progress['busy'] = true;
                    return $progress;
                }

                $siteId = (int)$siteId;

                if ($this->isSiteUnscheduled($siteId) && $this->scheduleRecurringAnalysis($siteId, true)) {
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
                update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
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
        $initialized = 0;

        foreach (array_unique(array_map('absint', $siteIds)) as $siteId) {
            $this->allowSiteScheduling($siteId);

            if ($siteId <= 0 || !$this->isSiteActive($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
                continue;
            }

            if ($this->scheduleRecurringAnalysis($siteId, true)) {
                $initialized++;
            }
        }

        return $initialized;
    }

    /** @param array<int, int> $siteIds */
    public function removeSelectedScheduledSites(array $siteIds): int {
        $removed = 0;

        foreach (array_unique(array_map('absint', $siteIds)) as $siteId) {
            if ($siteId <= 0 || $this->getNextRecurringScheduledTimestamp($siteId) <= 0) {
                continue;
            }

            $this->unschedule($siteId);
            (new Analysis\AnalysisStore())->removeSiteAnalysis($siteId, 'content');
            $this->markSiteSchedulingRemoved($siteId);
            $removed++;
        }

        return $removed;
    }

    public function resetAllSiteAnalyses(): int {
        $scheduled = 0;
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteActive($siteId) || $this->isRunning($siteId)) {
                continue;
            }

            $this->unschedule($siteId);
            $this->scheduleRecurringAnalysis($siteId, true);
            $scheduled++;
        }

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return $scheduled;
    }

    protected function runAnalysis(int $siteId): bool {
        Analysis\AnalysisLifecycle::prepareSite($siteId);
        $state = $this->getAnalysisState($siteId);
        $processedBatches = 0;
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchMode($assignmentMode) && $this->isBatchStopped($assignmentMode)) {
            return false;
        }
        $requestBudgetSeconds = $this->isBatchMode($assignmentMode)
            ? self::BATCH_REQUEST_BUDGET_SECONDS
            : self::INDIVIDUAL_REQUEST_BUDGET_SECONDS;
        $maxBatchesPerRequest = $this->isBatchMode($assignmentMode)
            ? self::BATCH_MAX_BATCHES_PER_REQUEST
            : self::INDIVIDUAL_MAX_BATCHES_PER_REQUEST;
        $deadline = microtime(true) + $requestBudgetSeconds;
        $completed = false;

        $this->extendRuntimeLimit((int)ceil($deadline));

        if (empty($state) || (int)($state['site_id'] ?? 0) !== $siteId) {
            $state = $this->createState($siteId);
            $this->markStarted($siteId, $state);
        }

        $this->findingTotals = $state;
        $this->pendingFindingBytes = 0;
        $this->pendingFindingKeys = [];
        switch_to_blog($siteId);

        try {
            if ($this->isTaskRemovalInProgress() || ($this->isBatchMode($assignmentMode) && $this->isBatchStopped($assignmentMode))) {
                return false;
            }

            while ($processedBatches < $maxBatchesPerRequest && microtime(true) < $deadline) {
                if ($this->isTaskRemovalInProgress() || ($this->isBatchMode($assignmentMode) && $this->isBatchStopped($assignmentMode))) {
                    return false;
                }

                $phase = (string)($state['phase'] ?? self::SHORTCODE_PHASE);

                if (!in_array($phase, [self::SHORTCODE_PHASE, self::BLOCK_PHASE], true)) {
                    $phase = self::SHORTCODE_PHASE;
                    $state['phase'] = $phase;
                }

                if (empty($state['phase_started'])) {
                    $this->markPhaseStarted($siteId, $phase, $state);
                    $state['phase_started'] = true;
                }

                $completed = $phase === self::SHORTCODE_PHASE
                    ? $this->runShortcodeBatch($state, $deadline)
                    : $this->runBlockBatch($state, $deadline);
                if ($this->isBatchMode($assignmentMode) && $this->isBatchStopped($assignmentMode)) {
                    return false;
                }
                $processedBatches++;
                $state['updated_at'] = current_time('mysql', true);
                $this->saveAnalysisState($siteId, $state);
                $this->markProgress($siteId, $phase, $state);

                if (!$completed) {
                    continue;
                }

                $this->markPhaseFinished($siteId, $phase, $state);

                if ($phase === self::BLOCK_PHASE) {
                    $completed = true;
                    break;
                }

                $state['phase'] = self::BLOCK_PHASE;
                $state['phase_started'] = false;
                $completed = false;
            }
        } finally {
            restore_current_blog();
        }

        if ($this->isBatchMode($assignmentMode) && $this->isBatchStopped($assignmentMode)) {
            return false;
        }

        if ($completed) {
            $this->finish($siteId, $state);
            $this->deleteAnalysisState($siteId);

            return true;
        }

        $this->saveAnalysisState($siteId, $state);

        return false;
    }

    protected function getPostIdsAfter(int $lastPostId): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
            WHERE post_type IN ('post', 'page')
            AND post_status NOT IN ('auto-draft', 'inherit', 'trash')
            AND ID > %d
            ORDER BY ID ASC
            LIMIT %d",
            max(0, $lastPostId),
            self::BATCH_SIZE
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Keyset pagination keeps long analyses bounded and avoids expensive SQL offsets.
        $ids = $wpdb->get_col($sql);
        if ($wpdb->last_error) {
            Analysis\AnalysisStorageException::raise('source-post-ids', get_current_blog_id());
        }
        return array_map('absint', (array)$ids);
    }

    protected function runShortcodeBatch(array &$state, float $deadline): bool {
        $postIds = $this->getPostIdsAfter((int)($state['shortcode_last_post_id'] ?? 0));
        $processed = 0;

        foreach ($postIds as $postId) {
            if (microtime(true) >= $deadline || $this->pendingFindingBytes >= 131072) {
                break;
            }

            if (!$this->analysePostShortcodes((int)$postId, $state, $deadline)) {
                break;
            }
            $state['shortcode_last_post_id'] = (int)$postId;
            $state['shortcode_processed_posts'] = (int)($state['shortcode_processed_posts'] ?? 0) + 1;
            $processed++;
        }

        return $processed === count($postIds) && count($postIds) < self::BATCH_SIZE;
    }

    protected function runBlockBatch(array &$state, float $deadline): bool {
        $postIds = $this->getPostIdsAfter((int)($state['block_last_post_id'] ?? 0));
        $processed = 0;

        foreach ($postIds as $postId) {
            if (microtime(true) >= $deadline || $this->pendingFindingBytes >= 131072) {
                break;
            }

            $this->analysePostBlocks((int)$postId, $state);
            $state['block_last_post_id'] = (int)$postId;
            $state['block_processed_posts'] = (int)($state['block_processed_posts'] ?? 0) + 1;
            $processed++;
        }

        return $processed === count($postIds) && count($postIds) < self::BATCH_SIZE;
    }

    protected function createState(int $siteId): array {
        switch_to_blog($siteId);

        try {
            $counts = wp_count_posts('post');
            $pageCounts = wp_count_posts('page');
            $total = array_sum((array)$counts) + array_sum((array)$pageCounts);
        } finally {
            restore_current_blog();
        }

        return [
            'site_id' => $siteId,
            'run_id' => (new Analysis\AnalysisStore())->ensureRun($siteId, 'content', 'content')->run_id,
            'phase' => self::SHORTCODE_PHASE,
            'phase_started' => false,
            'shortcode_last_post_id' => 0,
            'block_last_post_id' => 0,
            'shortcode_processed_posts' => 0,
            'block_processed_posts' => 0,
            'total_posts' => $total,
            'shortcodes' => [],
            'blocks' => [],
            'updated_at' => current_time('mysql', true),
        ];
    }

    protected function getAnalysisState(int $siteId): array {
        return (new Analysis\ContentAnalysisStore())->load($siteId);
    }

    protected function saveAnalysisState(int $siteId, array &$state): void {
        foreach ($this->findingTotals as $key => $value) {
            if (preg_match('/^(shortcode|block)_(bytes|count|truncated|unregistered|omitted_count|omitted_sources|limit_omitted)$/', (string)$key)) {
                $state[$key] = $value;
            }
        }
        (new Analysis\ContentAnalysisStore())->save($siteId, $state);
        $state['shortcodes'] = [];
        $state['blocks'] = [];
        $this->pendingFindingBytes = 0;
        $this->pendingFindingKeys = [];
    }

    protected function deleteAnalysisState(int $siteId): void {
        (new Analysis\ContentAnalysisStore())->discard($siteId);
    }

    /** Bound each row and each phase before it can enter a persisted checkpoint. */
    protected function appendFinding(string $kind, array $entry, array &$entries): void {
        $prefix = $kind === 'blocks' ? 'block_' : 'shortcode_';
        $limit = $this->config->getShortcodeBlockResultEntryLimit();
        if ((int)($this->findingTotals[$prefix . 'count'] ?? 0) >= $limit) {
            $this->recordResultLimitOmission($kind, (int)($entry['post_id'] ?? 0), (string)($entry['post_title'] ?? ''), $limit);
            return;
        }
        // Preserve identities separately; shortening display text must not merge two findings.
        $entry['identity_hash'] = hash('sha256', serialize([(int)($entry['post_id'] ?? 0), (string)($entry['metadata_id'] ?? $entry['metadata_key'] ?? ''), (string)($entry['raw_shortcode'] ?? $entry['block'] ?? ''), (int)($entry['occurrence'] ?? 0)]));
        if (isset($this->pendingFindingKeys[$kind . ':' . $entry['identity_hash']])) {
            return;
        }
        foreach ($entry as $key => $value) {
            if (is_string($value) && strlen($value) > ($key === 'raw_shortcode' ? 4096 : 1024)) {
                $entry[$key] = wp_check_invalid_utf8(substr($value, 0, $key === 'raw_shortcode' ? 4096 : 1024), true);
                if ($key === 'post_url' || $key === 'provider_plugin_file') {
                    $entry[$key] = '';
                }
                $this->recordOmittedSource(
                    $kind,
                    'finding_value_shortened',
                    (int)($entry['post_id'] ?? 0),
                    (string)($entry['post_title'] ?? ''),
                    '',
                    (string)$key
                );
            }
        }
        $bytes = strlen(serialize($entry));
        if ($bytes > Analysis\AnalysisStore::FINDING_BYTES || $this->pendingFindingBytes + $bytes > 262144
            || (int)($this->findingTotals[$prefix . 'bytes'] ?? 0) + $bytes > self::RESULT_MAX_BYTES) {
            $this->recordOmittedSource($kind, 'finding_bytes_limit', (int)($entry['post_id'] ?? 0), (string)($entry['post_title'] ?? ''));
            return;
        }
        $this->pendingFindingKeys[$kind . ':' . $entry['identity_hash']] = true;
        $entries[] = $entry;
        $this->pendingFindingBytes += $bytes;
        $this->findingTotals[$prefix . 'bytes'] = (int)($this->findingTotals[$prefix . 'bytes'] ?? 0) + $bytes;
        $this->findingTotals[$prefix . 'count'] = (int)($this->findingTotals[$prefix . 'count'] ?? 0) + 1;
    }

    /**
     * Records a bounded explanation for content deliberately excluded by an
     * input or result cap. The same reason for the same post and field is
     * recorded once; repeated hits do not count as separate events.
     */
    protected function recordOmittedSource(string $kind, string $reason, int $postId, string $postTitle = '', string $metadataKey = '', string $field = '', int $limit = 0): void {
        $prefix = $kind === 'blocks' ? 'block_' : 'shortcode_';
        $sources = (array)($this->findingTotals[$prefix . 'omitted_sources'] ?? []);
        $source = [];
        $metadataKey = substr($metadataKey, 0, 512);
        $field = substr($field, 0, 128);

        $this->findingTotals[$prefix . 'truncated'] = true;

        foreach ($sources as $source) {
            if (is_array($source)
                && (string)($source['reason'] ?? '') === $reason
                && (int)($source['post_id'] ?? 0) === $postId
                && (string)($source['metadata_key'] ?? '') === $metadataKey
                && (string)($source['field'] ?? '') === $field) {
                return;
            }
        }

        $this->findingTotals[$prefix . 'omitted_count'] = (int)($this->findingTotals[$prefix . 'omitted_count'] ?? 0) + 1;

        if (count($sources) >= self::OMITTED_SOURCE_LIMIT) {
            return;
        }

        $sources[] = [
            'reason' => $reason,
            'post_id' => $postId,
            'post_title' => substr($postTitle, 0, 512),
            'metadata_key' => $metadataKey,
            'field' => $field,
            'limit' => $limit,
        ];
        $this->findingTotals[$prefix . 'omitted_sources'] = $sources;
    }

    /**
     * The result limit is cumulative for one website. It is recorded once,
     * with the first post whose findings could no longer be stored; every
     * later finding is only counted.
     */
    protected function recordResultLimitOmission(string $kind, int $postId, string $postTitle, int $limit): void {
        $prefix = $kind === 'blocks' ? 'block_' : 'shortcode_';

        if ((int)($this->findingTotals[$prefix . 'limit_omitted'] ?? 0) === 0) {
            $this->recordOmittedSource($kind, 'finding_count_limit', $postId, $postTitle, '', '', $limit);
        }

        $this->findingTotals[$prefix . 'limit_omitted'] = (int)($this->findingTotals[$prefix . 'limit_omitted'] ?? 0) + 1;
    }

    protected function getBoundedAnalysisPost(int $postId): ?\WP_Post {
        global $wpdb;
        $maxBytes = $this->config->getShortcodeBlockAnalysisValueMaxBytes();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Size-gated read of one post in a background analysis; avoids loading oversized content into the post cache.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_status, LEFT(post_title, 512) AS post_title, post_name, post_parent, post_date, post_author,
            CASE WHEN OCTET_LENGTH(post_content) > %d THEN 1 ELSE 0 END AS content_exceeds_limit,
            CASE WHEN OCTET_LENGTH(post_content) <= %d THEN post_content ELSE '' END AS post_content
            FROM {$wpdb->posts} WHERE ID = %d",
            $maxBytes,
            $maxBytes,
            $postId
        ));
        if ($wpdb->last_error) {
            Analysis\AnalysisStorageException::raise('source-post-read', get_current_blog_id());
        }
        if (!$row) {
            return null;
        }
        if (!empty($row->content_exceeds_limit)) {
            $this->recordOmittedSource('shortcodes', 'post_content_too_large', (int)$row->ID, (string)$row->post_title);
            $this->recordOmittedSource('blocks', 'post_content_too_large', (int)$row->ID, (string)$row->post_title);
            return null;
        }
        return new \WP_Post($row);
    }

    protected function analysePostShortcodes(int $postId, array &$state, float $deadline): bool {
        $post = $this->getBoundedAnalysisPost($postId);

        if (!$post instanceof \WP_Post) {
            return true;
        }

        $location = [
            'post_id' => $postId,
            'post_title' => (string)$post->post_title,
            'post_type' => $post->post_type,
            'post_url' => get_permalink($post),
        ];
        $content = (string)$post->post_content;

        if (strlen($content) > $this->config->getShortcodeBlockAnalysisValueMaxBytes()) {
            return true;
        }

        // Only post_content of posts and pages is analysed; post metadata is
        // deliberately not read (see Todo: optional further post types).
        $this->collectShortcodes($content, $location, $state['shortcodes']);
        unset($state['shortcode_active_post'], $state['shortcode_meta_cursor']);
        return true;
    }

    protected function analysePostBlocks(int $postId, array &$state): void {
        $post = $this->getBoundedAnalysisPost($postId);

        if (!$post instanceof \WP_Post) {
            return;
        }

        $location = [
            'post_id' => $postId,
            'post_title' => (string)$post->post_title,
            'post_type' => $post->post_type,
            'post_url' => get_permalink($post),
        ];
        $content = (string)$post->post_content;

        if (strlen($content) <= $this->config->getShortcodeBlockAnalysisValueMaxBytes()) {
            $this->collectBlocks($content, $location, $state['blocks']);
        }
    }

    protected function collectShortcodes(string $content, array $location, array &$shortcodes): void {

        // Group 1 distinguishes a closing tag (whitespace only) from an opening tag with attributes.
        if ($content === '' || !preg_match_all('~(?<!\[)\[(?!\[)(/)?([A-Za-z_][A-Za-z0-9_.-]{0,190})(?(1)\s*+|(?:\s++[^\[\]]*+)?/?)\](?!\])~', $content, $matches)) {
            return;
        }

        $matchesByRawShortcode = [];

        foreach ((array)($matches[0] ?? []) as $index => $rawShortcode) {
            $tag = (string)($matches[2][$index] ?? '');
            $rawShortcode = (string)$rawShortcode;

            if ($tag === '' || $rawShortcode === '') {
                continue;
            }

            $matchesByRawShortcode[$tag . "\0" . $rawShortcode] = [
                'tag' => $tag,
                'raw_shortcode' => $rawShortcode,
            ];
        }

        foreach ($matchesByRawShortcode as $match) {

            $tag = (string)$match['tag'];
            $this->appendFinding('shortcodes', array_merge(
                [
                    'shortcode' => $tag,
                    'raw_shortcode' => (string)$match['raw_shortcode'],
                ],
                $location
            ), $shortcodes);
        }
    }

    /**
     * Stores one finding per block name and post with its number of
     * occurrences. Individual paragraphs or headings are not stored as
     * separate findings, so the result limit counts distinct uses.
     */
    protected function collectBlocks(string $content, array $location, array &$blocks): void {
        $occurrences = [];
        $block = [];
        $blockName = '';
        $count = 0;

        foreach ($this->flattenBlocks(parse_blocks($content)) as $block) {
            $blockName = (string)($block['blockName'] ?? '');

            if ($blockName === '') {
                continue;
            }

            $occurrences[$blockName] = (int)($occurrences[$blockName] ?? 0) + 1;
        }

        foreach ($occurrences as $blockName => $count) {
            $this->appendFinding('blocks', array_merge(['block' => (string)$blockName, 'occurrence' => 0, 'occurrences' => $count], $this->getBlockDetails((string)$blockName), $location), $blocks);
        }
    }

    protected function getBlockDetails(string $blockName): array {
        $details = [
            'block_title' => $blockName,
            'origin' => str_starts_with($blockName, 'core/')
                ? __('WordPress Core', 'rrze-multisite-manager')
                : __('Unknown', 'rrze-multisite-manager'),
            'description' => '',
        ];
        $blockType = \WP_Block_Type_Registry::get_instance()->get_registered($blockName);

        if (!$blockType instanceof \WP_Block_Type) {
            return $details;
        }

        $details['block_title'] = (string)($blockType->title ?: $blockName);
        $details['description'] = (string)($blockType->description ?? '');
        $details['category'] = $this->getBlockCategoryLabel((string)($blockType->category ?? ''));

        if (str_starts_with($blockName, 'core/')) {
            return $details;
        }

        $details['origin'] = $this->getBlockOrigin($blockType);

        return $details;
    }

    protected function getBlockCategoryLabel(string $category): string {
        if ($category === '') {
            return '';
        }

        if (isset($this->blockCategoryLabels[$category])) {
            return $this->blockCategoryLabels[$category];
        }

        $label = $category;

        if (function_exists('get_block_categories_all') && class_exists('\WP_Block_Editor_Context')) {
            try {
                $categories = get_block_categories_all(new \WP_Block_Editor_Context());

                foreach ((array)$categories as $categoryData) {
                    if ((string)($categoryData['slug'] ?? '') === $category) {
                        $label = (string)($categoryData['title'] ?? $category);
                        break;
                    }
                }
            } catch (\Throwable $exception) {
                $label = $category;
            }
        }

        $this->blockCategoryLabels[$category] = $label;

        return $label;
    }

    protected function getBlockOrigin(\WP_Block_Type $blockType): string {
        $assetHandles = array_merge(
            (array)($blockType->editor_script_handles ?? []),
            (array)($blockType->script_handles ?? []),
            (array)($blockType->view_script_handles ?? []),
            (array)($blockType->editor_style_handles ?? []),
            (array)($blockType->style_handles ?? []),
            (array)($blockType->view_style_handles ?? [])
        );
        $scripts = wp_scripts();
        $styles = wp_styles();

        foreach (array_unique(array_filter($assetHandles, 'is_string')) as $handle) {
            $source = '';

            if (isset($scripts->registered[$handle])) {
                $source = (string)($scripts->registered[$handle]->src ?? '');
            } elseif (isset($styles->registered[$handle])) {
                $source = (string)($styles->registered[$handle]->src ?? '');
            }

            $origin = $this->getAssetOrigin($source);

            if ($origin !== '') {
                return $origin;
            }
        }

        return __('Unknown', 'rrze-multisite-manager');
    }

    protected function getAssetOrigin(string $source): string {
        if ($source === '') {
            return '';
        }

        $path = (string)wp_parse_url($source, PHP_URL_PATH);

        if ($path === '') {
            return '';
        }

        $pluginsPath = (string)wp_parse_url(plugins_url(), PHP_URL_PATH);
        $muPluginsPath = (string)wp_parse_url(WPMU_PLUGIN_URL, PHP_URL_PATH);
        $themesPath = (string)wp_parse_url(get_theme_root_uri(), PHP_URL_PATH);

        if (($pluginsPath !== '' && str_starts_with($path, $pluginsPath)) || ($muPluginsPath !== '' && str_starts_with($path, $muPluginsPath))) {
            return __('Plugin', 'rrze-multisite-manager');
        }

        if ($themesPath !== '' && str_starts_with($path, $themesPath)) {
            return __('Theme', 'rrze-multisite-manager');
        }

        return '';
    }

    protected function flattenBlocks(array $blocks): array {
        $flat = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $flat[] = $block;
            $flat = array_merge($flat, $this->flattenBlocks((array)($block['innerBlocks'] ?? [])));
        }

        return $flat;
    }

    protected function markStarted(int $siteId, array $state): void {
        $previousStatus = $this->getStatus($siteId);
        update_blog_option($siteId, self::STATUS_OPTION, [
            'status' => 'running',
            'last_started_at' => current_time('mysql', true),
            'last_finished_at' => '',
            'last_error' => '',
            'phase' => self::SHORTCODE_PHASE,
            'processed_posts' => 0,
            'total_posts' => (int)($state['total_posts'] ?? 0),
            'active_runtime_seconds' => 0.0,
            'phases' => $this->getScheduledPhaseStatuses(),
            'schedule_mode' => (string)($previousStatus['schedule_mode'] ?? 'site'),
        ]);
        $this->logSiteInfo('Shortcode- und Blockanalyse gestartet', $siteId, 'unassigned', ['site_url' => get_home_url($siteId, '/')]);
    }

    protected function recordRunDuration(int $siteId, float $duration): void {
        $status = $this->getStatus($siteId);

        if (!in_array((string)($status['status'] ?? ''), ['running', 'complete', 'error'], true)) {
            return;
        }

        $status['last_slice_runtime_seconds'] = round(max(0, $duration), 3);
        $status['active_runtime_seconds'] = round(max(0, (float)($status['active_runtime_seconds'] ?? 0)) + max(0, $duration), 3);
        $status['last_runtime_seconds'] = $status['active_runtime_seconds'];
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function markPhaseStarted(int $siteId, string $phase, array $state): void {
        $status = $this->getStatus($siteId);
        $status['status'] = 'running';
        $status['phase'] = $phase;
        $status['total_posts'] = (int)($state['total_posts'] ?? 0);
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getScheduledPhaseStatuses();
        $status['phases'][$phase] = [
            'status' => 'running',
            'started_at' => current_time('mysql', true),
            'finished_at' => '',
            'processed_posts' => 0,
            'total_posts' => (int)($state['total_posts'] ?? 0),
        ];
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function markProgress(int $siteId, string $phase, array $state): void {
        $status = $this->getStatus($siteId);
        $processedKey = $phase === self::SHORTCODE_PHASE ? 'shortcode_processed_posts' : 'block_processed_posts';
        $processed = (int)($state[$processedKey] ?? 0);

        $status['status'] = 'running';
        $status['phase'] = $phase;
        $status['processed_posts'] = $processed;
        $status['total_posts'] = (int)($state['total_posts'] ?? 0);
        $status['last_progress_at'] = current_time('mysql', true);
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getScheduledPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'running',
                'processed_posts' => $processed,
                'total_posts' => (int)($state['total_posts'] ?? 0),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function markPhaseFinished(int $siteId, string $phase, array $state): void {
        $status = $this->getStatus($siteId);
        $processedKey = $phase === self::SHORTCODE_PHASE ? 'shortcode_processed_posts' : 'block_processed_posts';

        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getScheduledPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'complete',
                'finished_at' => current_time('mysql', true),
                'processed_posts' => (int)($state[$processedKey] ?? 0),
                'total_posts' => (int)($state['total_posts'] ?? 0),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function finish(int $siteId, array $state): void {
        $result = (new Analysis\ContentAnalysisStore())->finish($siteId, $state);
        $status = $this->getStatus($siteId);
        $status = array_merge($status, [
            'status' => 'complete',
            'last_started_at' => (string)($status['last_started_at'] ?? ''),
            'last_finished_at' => current_time('mysql', true),
            'last_error' => '',
            'phase' => 'complete',
            'processed_posts' => (int)($state['block_processed_posts'] ?? 0),
            'total_posts' => (int)($state['total_posts'] ?? 0),
        ]);
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        $this->logSiteInfo('Shortcode- und Blockanalyse beendet', $siteId, 'unassigned', [
            'site_url' => get_home_url($siteId, '/'),
            'shortcodes' => (int)$result['shortcodes_count'],
            'blocks' => (int)$result['blocks_count'],
        ]);
    }

    protected function markFailed(int $siteId, string $message): void {
        $status = $this->getStatus($siteId);
        $phase = (string)($status['phase'] ?? self::SHORTCODE_PHASE);

        $status['status'] = 'error';
        $status['last_finished_at'] = current_time('mysql', true);
        $status['last_error'] = $message;
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'error',
                'finished_at' => current_time('mysql', true),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        $this->deleteAnalysisState($siteId);
    }

    /** Marks an interrupted analysis as an explicit administrative abort. */
    protected function markAborted(int $siteId, string $message): void {
        $status = $this->getStatus($siteId);
        $phase = (string)($status['phase'] ?? self::SHORTCODE_PHASE);

        $status['status'] = 'error';
        $status['last_finished_at'] = current_time('mysql', true);
        $status['last_error'] = $message;
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'aborted',
                'finished_at' => current_time('mysql', true),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        $this->deleteAnalysisState($siteId);
        $this->releaseLock($siteId);
        $this->logSiteInfo('Shortcode- und Blockanalyse abgebrochen', $siteId, 'unassigned', ['message' => $message]);
    }

    protected function markTimedOut(int $siteId): void {
        $message = __('The shortcode and block analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager');
        $status = $this->getStatus($siteId);
        $phase = (string)($status['phase'] ?? self::SHORTCODE_PHASE);

        $status['status'] = 'error';
        $status['last_finished_at'] = current_time('mysql', true);
        $status['last_error'] = $message;
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'error',
                'finished_at' => current_time('mysql', true),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        $this->deleteAnalysisState($siteId);
        $this->releaseLock($siteId);
        do_action(
            'rrze.log.error',
            'RRZE-MSM: Shortcode and block analysis exceeded its runtime limit',
            [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'timeout_seconds' => $this->config->getShortcodeBlockAnalysisTimeoutSeconds(),
            ]
        );
    }

    protected function getDefaultPhaseStatuses(): array {
        return [
            self::SHORTCODE_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'processed_posts' => 0, 'total_posts' => 0],
            self::BLOCK_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'processed_posts' => 0, 'total_posts' => 0],
        ];
    }

    protected function getScheduledPhaseStatuses(): array {
        $phases = $this->getDefaultPhaseStatuses();

        foreach (array_keys($phases) as $phase) {
            $phases[$phase]['status'] = 'scheduled';
        }

        return $phases;
    }

    protected function extendRuntimeLimit(int $deadline): void {
        if (!function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit(max(1, $deadline - time() + MINUTE_IN_SECONDS));
    }

    protected function getLockTtl(): int {
        return max(5 * MINUTE_IN_SECONDS, self::INDIVIDUAL_REQUEST_BUDGET_SECONDS + MINUTE_IN_SECONDS);
    }

    protected function unschedule(int $siteId): void {
        $this->unscheduleIndividualEvents($siteId);

        foreach (self::BATCH_MODES as $mode) {
            $option = $this->getBatchSiteIdsOption($mode);
            $siteIds = array_values(array_filter(
                array_map('absint', (array)get_site_option($option, [])),
                static fn(int $id): bool => $id > 0 && $id !== $siteId
            ));
            update_site_option($option, $siteIds);
            if (empty($siteIds)) {
                $this->unscheduleBatchEvents($mode);
            }
        }

        $this->removeSiteAssignment($siteId);

        $this->clearRecurringScheduleCache();
    }

    protected function unscheduleIndividualEvents(int $siteId): void {
        foreach ([$this->getHook(), $this->config->getLegacyShortcodeBlockAnalysisHook()] as $hook) {
            $this->inSiteCronContext($siteId, function () use ($hook, $siteId): void {
                $timestamp = (int)wp_next_scheduled($hook, [$siteId]);

                while ($timestamp > 0) {
                    wp_unschedule_event($timestamp, $hook, [$siteId]);
                    $timestamp = (int)wp_next_scheduled($hook, [$siteId]);
                }
            });
        }
    }

    public function reconcileSiteSchedule(int $siteId): void {
        if (!$this->isSiteActive($siteId)) {
            $this->deactivateSite($siteId);
        }
    }

    public function scheduleNewSiteRecurringAnalysis(int $siteId): void {
        // Scheduling is deliberately only initiated by an explicit user action.
        // A newly active site must not recreate a removed recurring event.
    }

    protected function scheduleRecurringAnalysis(int $siteId, bool $resetPreviousRun = false): bool {
        if ($resetPreviousRun) {
            $this->allowSiteScheduling($siteId);
        }

        $delay = MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS));
        return $this->scheduleRecurringAnalysisAt($siteId, time() + $delay, $resetPreviousRun);
    }

    protected function scheduleRecurringAnalysisAt(int $siteId, int $timestamp, bool $resetPreviousRun = false): bool {
        if (!$this->isSiteActive($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return false;
        }

        $this->unschedule($siteId);
        $assignmentMode = $this->getExpectedAssignmentMode($siteId);
        $scheduled = $assignmentMode === 'site'
            ? $this->scheduleIndividualAnalysisAt($siteId, $timestamp)
            : $this->scheduleBatchAnalysisAt($timestamp, $assignmentMode);

        if (!$scheduled) {
            LoggingService::error('RRZE-MSM: Shortcode and block analysis task could not be scheduled.', [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'assignment_mode' => $assignmentMode,
            ]);

            return false;
        }

        if ($this->isBatchMode($assignmentMode)) {
            $option = $this->getBatchSiteIdsOption($assignmentMode);
            $siteIds = array_values(array_unique(array_merge(
                array_map('absint', (array)get_site_option($option, [])),
                [$siteId]
            )));
            update_site_option($option, $siteIds);
            $this->setSiteAssignment($siteId, $assignmentMode);
            $status = $this->getStatus($siteId);
            $status['schedule_mode'] = $assignmentMode;
            update_blog_option($siteId, self::STATUS_OPTION, $status);

            // The recurring shared batch may be days away. Explicitly adding
            // a site must not make its first analysis wait for that cycle.
            if ($resetPreviousRun && !$this->scheduleBatchAnalysisSoon($assignmentMode)) {
                LoggingService::error('RRZE-MSM: Shortcode and block shared-batch task could not be scheduled promptly.', [
                    'site_id' => $siteId,
                    'site_url' => get_home_url($siteId, '/'),
                    'assignment_mode' => $assignmentMode,
                ]);
            }
        }

        $this->clearRecurringScheduleCache();

        if ($this->getNextRecurringScheduledTimestamp($siteId) <= 0) {
            $this->unschedule($siteId);
            $this->clearRecurringScheduleCache();
            LoggingService::error('RRZE-MSM: Shortcode and block analysis task could not be verified after scheduling.', [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'assignment_mode' => $assignmentMode,
            ]);

            return false;
        }

        $this->markScheduled($siteId, $resetPreviousRun);

        return true;
    }

    protected function scheduleIndividualAnalysisAt(int $siteId, int $timestamp): bool {
        $scheduled = (bool)$this->inSiteCronContext($siteId, function () use ($siteId, $timestamp): bool {
            return (bool)wp_schedule_event(max(time(), $timestamp), $this->getScheduleKey('site'), $this->getHook(), [$siteId]);
        });

        if (!$scheduled) {
            return false;
        }

        $this->setSiteAssignment($siteId, 'site');
        $status = $this->getStatus($siteId);
        $status['schedule_mode'] = 'site';
        update_blog_option($siteId, self::STATUS_OPTION, $status);

        return true;
    }

    protected function scheduleBatchAnalysisAt(int $timestamp, string $mode = 'small-batch'): bool {
        $mode = $this->normalizeBatchMode($mode);
        return (bool)$this->inBatchCronContext(function () use ($timestamp, $mode): bool {
            $hook = $this->config->getShortcodeBlockAnalysisBatchHook();
            $args = $this->getBatchRecurringArgs($mode);

            if ((int)wp_next_scheduled($hook, $args) > 0) {
                return true;
            }
            if ($mode === 'small-batch' && (int)wp_next_scheduled($hook, []) > 0) {
                return true;
            }

            return (bool)wp_schedule_event(max(time(), $timestamp), $this->getScheduleKey($mode), $hook, $args);
        });
    }

    /**
     * Adds one prompt pass without changing the established recurring cycle.
     */
    protected function scheduleBatchAnalysisSoon(string $mode = 'small-batch'): bool {
        $mode = $this->normalizeBatchMode($mode);
        return (bool)$this->inBatchCronContext(function () use ($mode): bool {
            $hook = $this->config->getShortcodeBlockAnalysisBatchHook();
            $args = $this->getBatchRecurringArgs($mode);
            $nextRun = (int)wp_next_scheduled($hook, $args);

            if ($nextRun > 0 && $nextRun <= time() + (10 * MINUTE_IN_SECONDS)) {
                return true;
            }

            return (bool)wp_schedule_single_event(
                time() + MINUTE_IN_SECONDS,
                $hook,
                $args
            );
        });
    }

    protected function getSitePostAndPageCount(int $siteId): int {
        switch_to_blog($siteId);

        try {
            $postCounts = wp_count_posts('post');
            $pageCounts = wp_count_posts('page');
            $total = array_sum((array)$postCounts) + array_sum((array)$pageCounts);
        } finally {
            restore_current_blog();
        }

        return $total;
    }

    /** @return 'small-batch'|'medium-batch'|'large-batch'|'site' */
    protected function getExpectedAssignmentMode(int $siteId): string {
        $count = $this->getSitePostAndPageCount($siteId);
        $contentMode = $count <= $this->config->getShortcodeBlockSmallBatchPostThreshold()
            ? 'small-batch'
            : ($count <= $this->config->getShortcodeBlockMediumBatchPostThreshold()
                ? 'medium-batch'
                : ($count <= $this->config->getShortcodeBlockLargeBatchPostThreshold() ? 'large-batch' : 'site'));
        $status = $this->getStatus($siteId);
        if (($status['status'] ?? '') !== 'complete' || !isset($status['last_runtime_seconds'])) {
            return $contentMode;
        }

        $runtime = (float)$status['last_runtime_seconds'];
        $runtimeMode = $runtime > $this->config->getShortcodeBlockLargeBatchRuntimeThresholdSeconds()
            ? 'site'
            : ($runtime > $this->config->getShortcodeBlockMediumBatchRuntimeThresholdSeconds()
                ? 'large-batch'
                : ($runtime > $this->config->getShortcodeBlockSmallBatchRuntimeThresholdSeconds() ? 'medium-batch' : 'small-batch'));
        $weights = ['small-batch' => 0, 'medium-batch' => 1, 'large-batch' => 2, 'site' => 3];

        return $weights[$runtimeMode] > $weights[$contentMode] ? $runtimeMode : $contentMode;
    }

    /**
     * @return array<int, 'small-batch'|'medium-batch'|'large-batch'|'site'>
     */
    protected function getSiteAssignments(): array {
        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];
        $normalized = [];

        foreach ($assignments as $siteId => $mode) {
            $siteId = absint($siteId);

            if ($siteId > 0 && ($this->isBatchMode((string)$mode) || $mode === 'site')) {
                $normalized[$siteId] = $this->isBatchMode((string)$mode) ? $this->normalizeBatchMode((string)$mode) : 'site';
            }
        }

        // Existing schedules from the preceding implementation remain valid
        // after the update and are represented without forcing a network-wide
        // write during an admin page request.
        foreach ((array)get_site_option(self::BATCH_SITE_IDS_OPTION, []) as $siteId) {
            $siteId = absint($siteId);

            if ($siteId > 0) {
                $normalized[$siteId] = 'small-batch';
            }
        }

        foreach (['medium-batch' => self::MEDIUM_BATCH_SITE_IDS_OPTION, 'large-batch' => self::LARGE_BATCH_SITE_IDS_OPTION] as $mode => $option) {
            foreach ((array)get_site_option($option, []) as $siteId) {
                $siteId = absint($siteId);
                if ($siteId > 0) {
                    $normalized[$siteId] = $mode;
                }
            }
        }

        foreach ((array)_get_cron_array() as $events) {
            foreach ([$this->getHook(), $this->config->getLegacyShortcodeBlockAnalysisHook()] as $hook) {
                foreach ((array)($events[$hook] ?? []) as $event) {
                    if (empty($event['schedule'])) {
                        continue;
                    }

                    $args = (array)($event['args'] ?? []);
                    $siteId = absint($args[0] ?? 0);

                    if ($siteId > 0) {
                        $normalized[$siteId] = 'site';
                    }
                }
            }
        }

        return $normalized;
    }

    protected function setSiteAssignment(int $siteId, string $mode): void {
        if ($siteId <= 0 || (!$this->isBatchMode($mode) && $mode !== 'site')) {
            return;
        }
        $mode = $this->isBatchMode($mode) ? $this->normalizeBatchMode($mode) : 'site';

        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
        $assignments = is_array($assignments) ? $assignments : [];
        $assignments[$siteId] = $mode;
        update_site_option(self::ASSIGNMENTS_OPTION, $assignments);
    }

    protected function removeSiteAssignment(int $siteId): void {
        if ($siteId <= 0) {
            return;
        }

        $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);

        if (!is_array($assignments) || !isset($assignments[$siteId])) {
            return;
        }

        unset($assignments[$siteId]);
        update_site_option(self::ASSIGNMENTS_OPTION, $assignments);
    }

    /** @return array<int, int> */
    protected function getActiveBatchSiteIds(string $mode = 'small-batch'): array {
        $mode = $this->normalizeBatchMode($mode);
        $option = $this->getBatchSiteIdsOption($mode);
        $batchSiteIds = array_values(array_unique(array_filter(
            array_map('absint', (array)get_site_option($option, [])),
            static fn(int $siteId): bool => $siteId > 0
        )));

        if (empty($batchSiteIds)) {
            $this->unscheduleBatchEvents($mode);
            return [];
        }

        $activeSiteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'site__in' => $batchSiteIds,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        ]);
        $activeSiteIds = array_map('absint', $activeSiteIds);

        if ($activeSiteIds !== $batchSiteIds) {
            update_site_option($option, $activeSiteIds);
            $assignments = get_site_option(self::ASSIGNMENTS_OPTION, []);
            $assignments = is_array($assignments) ? $assignments : [];

            foreach (array_diff($batchSiteIds, $activeSiteIds) as $siteId) {
                unset($assignments[$siteId]);
            }

            update_site_option(self::ASSIGNMENTS_OPTION, $assignments);

            if (empty($activeSiteIds)) {
                $this->unscheduleBatchEvents($mode);
            }
        }

        return $activeSiteIds;
    }

    protected function reconcileSiteAssignmentAfterRun(int $siteId): void {
        if ($this->isSiteSchedulingRemoved($siteId)) {
            return;
        }

        if ($siteId <= 0 || !$this->isSiteActive($siteId)) {
            $this->deactivateSite($siteId);
            return;
        }

        $currentMode = $this->getSiteAssignmentMode($siteId);
        $expectedMode = $this->getExpectedAssignmentMode($siteId);

        if ($currentMode === $expectedMode || $this->isRunning($siteId)) {
            return;
        }

        $scheduled = $expectedMode === 'site'
            ? $this->scheduleIndividualAnalysisAt($siteId, time() + MINUTE_IN_SECONDS)
            : $this->scheduleBatchAnalysisAt(time() + MINUTE_IN_SECONDS, $expectedMode);
        if (!$scheduled) {
            return;
        }

        if ($expectedMode !== 'site') {
            $this->unscheduleIndividualEvents($siteId);
        }
        foreach (self::BATCH_MODES as $mode) {
            $option = $this->getBatchSiteIdsOption($mode);
            $siteIds = array_values(array_filter(
                array_map('absint', (array)get_site_option($option, [])),
                static fn(int $id): bool => $id > 0 && $id !== $siteId
            ));
            if ($mode === $expectedMode && $expectedMode !== 'site') {
                $siteIds[] = $siteId;
                $siteIds = array_values(array_unique($siteIds));
            }
            update_site_option($option, $siteIds);
            if (empty($siteIds)) {
                $this->unscheduleBatchEvents($mode);
            }
        }
        $this->setSiteAssignment($siteId, $expectedMode);
        $status = $this->getStatus($siteId);
        $status['schedule_mode'] = $expectedMode;
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        $this->markScheduled($siteId);

        $this->clearRecurringScheduleCache();
    }

    protected function getNextRecurringScheduledTimestamp(int $siteId): int {
        $mode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchMode($mode)) {
            return $this->getNextBatchScheduledTimestamp($mode);
        }

        return (int)$this->inSiteCronContext($siteId, function () use ($siteId): int {
            $timestamp = (int)wp_next_scheduled($this->getHook(), [$siteId]);

            if ($timestamp > 0) {
                return $timestamp;
            }

            return (int)wp_next_scheduled($this->config->getLegacyShortcodeBlockAnalysisHook(), [$siteId]);
        });
    }

    protected function hasRecurringScheduledAnalysis(int $siteId): bool {
        return $this->getNextRecurringScheduledTimestamp($siteId) > 0;
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
        $expectedSchedule = $this->getScheduleKey('site');

        foreach ((array)$cron as $timestamp => $events) {
            foreach ((array)($events[$this->getHook()] ?? []) as $event) {
                $args = (array)($event['args'] ?? []);
                $siteId = (int)($args[0] ?? 0);

                if (empty($event['schedule']) || $siteId <= 0) {
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

    protected function hasAnyRecurringScheduledAnalysis(): bool {
        $cron = _get_cron_array();

        foreach ((array)$cron as $events) {
            foreach ((array)($events[$this->getHook()] ?? []) as $event) {
                if (!empty($event['schedule'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function getScheduleKey(string $mode = 'site'): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $option = $mode === 'large-batch' ? 'monitoring_shortcode_block_analysis_large_batch_frequency'
            : ($mode === 'medium-batch' ? 'monitoring_shortcode_block_analysis_medium_batch_frequency'
                : ($this->isBatchMode($mode) ? 'monitoring_shortcode_block_analysis_small_batch_frequency' : 'monitoring_shortcode_block_analysis_individual_frequency'));
        $frequency = is_array($options) ? (string)($options[$option] ?? $options['monitoring_shortcode_block_analysis_frequency'] ?? 'twiceweekly') : 'twiceweekly';

        return $this->config->getShortcodeBlockAnalysisScheduleKey($frequency);
    }

    protected function getScheduleSignature(): string {
        return implode(':', [
            $this->getScheduleKey('small-batch'),
            $this->getScheduleKey('medium-batch'),
            $this->getScheduleKey('large-batch'),
            $this->getScheduleKey('site'),
            'three-batches-v1',
        ]);
    }

    protected function getScheduleLabel(string $mode = 'site'): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $option = $mode === 'large-batch' ? 'monitoring_shortcode_block_analysis_large_batch_frequency'
            : ($mode === 'medium-batch' ? 'monitoring_shortcode_block_analysis_medium_batch_frequency'
                : ($this->isBatchMode($mode) ? 'monitoring_shortcode_block_analysis_small_batch_frequency' : 'monitoring_shortcode_block_analysis_individual_frequency'));
        $frequency = is_array($options) ? (string)($options[$option] ?? $options['monitoring_shortcode_block_analysis_frequency'] ?? 'twiceweekly') : 'twiceweekly';

        return $this->config->getSchedulerFrequencyLabel($frequency);
    }

    protected function markScheduled(int $siteId, bool $resetPreviousRun = false): void {
        $status = $this->getStatus($siteId);
        $hasCompletedResult = !empty($this->getResult($siteId)['generated_at']);

        if ($resetPreviousRun) {
            unset(
                $status['last_started_at'],
                $status['last_finished_at'],
                $status['last_runtime_seconds'],
                $status['last_slice_runtime_seconds'],
                $status['active_runtime_seconds'],
                $status['last_progress_at']
            );
        }

        $status = array_merge(
            $status,
            [
                'status' => !$resetPreviousRun && $hasCompletedResult ? 'complete' : 'scheduled',
                'last_error' => '',
                'phase' => !$resetPreviousRun && $hasCompletedResult ? 'complete' : self::SHORTCODE_PHASE,
                'processed_posts' => !$resetPreviousRun && $hasCompletedResult ? (int)($status['processed_posts'] ?? 0) : 0,
                'total_posts' => !$resetPreviousRun && $hasCompletedResult ? (int)($status['total_posts'] ?? 0) : 0,
            ]
        );

        // A normal frequency change preserves the last successful run. An
        // explicit re-enrollment starts with a clean monitoring state.
        if ($resetPreviousRun || !$hasCompletedResult || !is_array($status['phases'] ?? null)) {
            $status['phases'] = $this->getScheduledPhaseStatuses();
        }

        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function isSiteUnscheduled(int $siteId): bool {
        if (!$this->isSiteActive($siteId)) {
            return false;
        }

        // Setting up the next recurring task is safe while a previous run is
        // still marked as running. The per-site lock prevents overlap when the
        // new event becomes due. Excluding such a site here left it without a
        // task after the setup run, most visibly for the main site.
        return $this->getNextRecurringScheduledTimestamp($siteId) <= 0;
    }

    protected function isRunning(int $siteId): bool {
        return (string)($this->getStatus($siteId)['status'] ?? '') === 'running';
    }

    protected function hasActiveLock(int $siteId): bool {
        return \RRZE\MultisiteManager\Support\AtomicLock::isLocked(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function recoverInterruptedAnalysis(int $siteId, array $status = []): bool {
        if ($siteId <= 0) {
            return false;
        }

        if (empty($status)) {
            $status = $this->getStatus($siteId);
        }

        if ((string)($status['status'] ?? '') !== 'running' || $this->hasActiveLock($siteId)) {
            return false;
        }

        if ($this->isAnalysisTimedOut($siteId)) {
            $this->markTimedOut($siteId);
            return true;
        }

        $assignmentMode = $this->getSiteAssignmentMode($siteId);

        if ($this->hasPendingContinuation($siteId)) {
            return false;
        }

        // A completed request always persists its checkpoint before releasing
        // the lock. If the follow-up event was removed or lost afterwards, the
        // analysis can safely continue from that checkpoint. Treating this as
        // a terminal error stranded large sites although no analysed data was
        // lost. This applies to shared-batch jobs as well: their checkpoint is
        // stored independently from the central batch Cron event.
        if ($this->scheduleContinuationForRunningAnalysis($siteId)) {
            do_action(
                'rrze.log.warning',
                'RRZE-MSM: Missing shortcode and block analysis continuation was rescheduled from its saved checkpoint.',
                [
                    'site_id' => $siteId,
                    'site_url' => get_home_url($siteId, '/'),
                    'assignment_mode' => $assignmentMode,
                    'phase' => (string)($status['phase'] ?? self::SHORTCODE_PHASE),
                    'processed_posts' => (int)($status['processed_posts'] ?? 0),
                    'total_posts' => (int)($status['total_posts'] ?? 0),
                    'was_stale' => $this->isBatchMode($assignmentMode) && $this->isBatchAnalysisStale($status),
                ]
            );

            return false;
        }

        $state = $this->getAnalysisState($siteId);
        $phase = (string)($status['phase'] ?? self::SHORTCODE_PHASE);
        $processed = (int)($status['processed_posts'] ?? 0);
        $total = (int)($status['total_posts'] ?? 0);
        $message = sprintf(
            /* translators: 1: analysis phase, 2: processed posts, 3: total posts. */
            __('The analysis was interrupted in the %1$s phase after %2$d of %3$d posts. The last saved checkpoint is included in the error log.', 'rrze-multisite-manager'),
            $phase,
            $processed,
            $total
        );

        $this->markFailed($siteId, $message);
        do_action(
            'rrze.log.error',
            'RRZE-MSM: Shortcode and block analysis interrupted unexpectedly',
            [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'phase' => $phase,
                'processed_posts' => $processed,
                'total_posts' => $total,
                'last_started_at' => (string)($status['last_started_at'] ?? ''),
                'last_progress_at' => (string)($status['last_progress_at'] ?? ''),
                'active_runtime_seconds' => (float)($status['active_runtime_seconds'] ?? 0),
                'checkpoint_updated_at' => (string)($state['updated_at'] ?? ''),
                'checkpoint_phase' => (string)($state['phase'] ?? ''),
                'checkpoint_shortcode_last_post_id' => (int)($state['shortcode_last_post_id'] ?? 0),
                'checkpoint_block_last_post_id' => (int)($state['block_last_post_id'] ?? 0),
                'assignment_mode' => $this->getSiteAssignmentMode($siteId),
            ]
        );

        return true;
    }

    /**
     * A shared continuation can process other websites for a while. It must
     * not keep a website that has stopped making progress indefinitely marked
     * as running.
     *
     * @param array<string, mixed> $status
     */
    protected function isBatchAnalysisStale(array $status): bool {
        $latestTimestamp = 0;

        foreach (['last_progress_at', 'last_started_at'] as $key) {
            $value = (string)($status[$key] ?? '');

            if ($value !== '') {
                $latestTimestamp = max($latestTimestamp, (int)strtotime($value . ' UTC'));
            }
        }

        $phase = (string)($status['phase'] ?? '');
        $phaseStatus = is_array($status['phases'][$phase] ?? null) ? $status['phases'][$phase] : [];
        $phaseStartedAt = (string)($phaseStatus['started_at'] ?? '');

        if ($phaseStartedAt !== '') {
            $latestTimestamp = max($latestTimestamp, (int)strtotime($phaseStartedAt . ' UTC'));
        }

        return $latestTimestamp > 0 && (time() - $latestTimestamp) >= self::BATCH_RUN_STALE_AFTER_SECONDS;
    }

    protected function isAnalysisTimedOut(int $siteId): bool {
        $status = $this->getStatus($siteId);
        $startedAt = (string)($status['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;
        $activeRuntime = (float)($status['active_runtime_seconds'] ?? 0);

        // Older in-progress states have no accumulated active duration yet.
        // Preserve their former wall-clock timeout behaviour once, while new
        // resumable runs exclude time spent waiting for external Cron.
        if (!array_key_exists('active_runtime_seconds', $status) && $startedTimestamp > 0) {
            $activeRuntime = max(0, time() - $startedTimestamp);
        }

        return (string)($status['status'] ?? '') === 'running'
            && $activeRuntime >= $this->config->getShortcodeBlockAnalysisTimeoutSeconds();
    }

    protected function hasPendingContinuation(int $siteId): bool {
        $assignmentMode = $this->getSiteAssignmentMode($siteId);
        if ($this->isBatchMode($assignmentMode)) {
            return (int)$this->inBatchCronContext(fn(): int => (int)wp_next_scheduled(
                $this->config->getShortcodeBlockAnalysisBatchHook(),
                $this->getBatchContinuationArgs($assignmentMode)
            )) > 0;
        }

        return (bool)$this->inSiteCronContext($siteId, function () use ($siteId): bool {
            $cron = _get_cron_array();

            foreach ([$this->getHook(), $this->config->getLegacyShortcodeBlockAnalysisHook()] as $hook) {
                foreach ((array)$cron as $events) {
                    foreach ((array)($events[$hook] ?? []) as $event) {
                        if (!empty($event['schedule']) || (array)($event['args'] ?? []) !== [$siteId]) {
                            continue;
                        }

                        return true;
                    }
                }
            }

            return false;
        });
    }

    protected function scheduleContinuationForRunningAnalysis(int $siteId): bool {
        $assignmentMode = $this->getSiteAssignmentMode($siteId);

        if ($assignmentMode === 'unassigned') {
            // A previous task may have been removed after its last slice. Its
            // checkpoint is still valid, but a one-time continuation alone
            // would leave the website invisible in the scheduled-task table.
            // Recreate the normal assignment so the resumed run is both
            // visible and continued by the regular scheduler afterwards.
            return $this->scheduleRecurringAnalysisAt($siteId, time() + self::CONTINUATION_DELAY_SECONDS);
        }

        if ($this->isBatchMode($assignmentMode)) {
            return $this->scheduleBatchContinuation(time() + self::CONTINUATION_DELAY_SECONDS, $assignmentMode);
        }

        return $this->scheduleIndividualContinuation($siteId);
    }

    protected function acquireLock(int $siteId): bool {
        return $this->acquireProcessLock(self::LOCK_OPTION_PREFIX . $siteId) !== '';
    }

    protected function releaseLock(int $siteId): void {
        $this->releaseProcessLock(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function acquireBatchLock(string $mode = 'small-batch'): bool {
        return $this->acquireProcessLock($this->getBatchLockOption($mode)) !== '';
    }

    protected function releaseBatchLock(string $mode = 'small-batch'): void {
        $this->releaseProcessLock($this->getBatchLockOption($mode));
    }

    protected function unscheduleBatchEvents(?string $onlyMode = null): void {
        $modes = $onlyMode === null ? self::BATCH_MODES : [$this->normalizeBatchMode($onlyMode)];
        $this->inBatchCronContext(function () use ($modes, $onlyMode): void {
            foreach ($modes as $mode) {
                wp_clear_scheduled_hook($this->config->getShortcodeBlockAnalysisBatchHook(), $this->getBatchRecurringArgs($mode));
                wp_clear_scheduled_hook($this->config->getShortcodeBlockAnalysisBatchHook(), $this->getBatchContinuationArgs($mode));
            }
            if ($onlyMode === null || in_array('small-batch', $modes, true)) {
                // Remove recurring/continuation events written by releases that
                // had no explicit batch-mode argument.
                wp_clear_scheduled_hook($this->config->getShortcodeBlockAnalysisBatchHook(), []);
                wp_clear_scheduled_hook($this->config->getShortcodeBlockAnalysisBatchHook(), [true]);
            }
        });
        foreach ($modes as $mode) {
            delete_site_option($this->getBatchOffsetOption($mode));
        }
    }

    protected function getBatchCronSiteId(): int {
        $networkId = get_current_network_id();

        if (function_exists('get_main_site_id')) {
            return (int)get_main_site_id($networkId);
        }

        $network = get_network($networkId);

        return $network instanceof \WP_Network ? (int)$network->site_id : 1;
    }

    protected function getNextBatchScheduledTimestamp(string $mode = 'small-batch'): int {
        $mode = $this->normalizeBatchMode($mode);
        return (int)$this->inBatchCronContext(function () use ($mode): int {
            $hook = $this->config->getShortcodeBlockAnalysisBatchHook();
            $timestamp = (int)wp_next_scheduled($hook, $this->getBatchRecurringArgs($mode));
            if ($timestamp <= 0 && $mode === 'small-batch') {
                $timestamp = (int)wp_next_scheduled($hook, []);
            }
            return $timestamp;
        });
    }

    protected function scheduleBatchContinuation(int $timestamp, string $mode = 'small-batch'): bool {
        $mode = $this->normalizeBatchMode($mode);
        if ($this->isTaskRemovalInProgress() || $this->isBatchStopped($mode)) {
            return false;
        }

        return (bool)$this->inBatchCronContext(function () use ($timestamp, $mode): bool {
            $hook = $this->config->getShortcodeBlockAnalysisBatchHook();
            $args = $this->getBatchContinuationArgs($mode);

            if ((int)wp_next_scheduled($hook, $args) <= 0) {
                return (bool)wp_schedule_single_event($timestamp, $hook, $args);
            }

            return true;
        });
    }

    protected function normalizeBatchMode(string $mode): string {
        if ($mode === 'batch') {
            return 'small-batch';
        }

        return in_array($mode, self::BATCH_MODES, true) ? $mode : 'small-batch';
    }

    protected function isBatchMode(string $mode): bool {
        return $mode === 'batch' || in_array($mode, self::BATCH_MODES, true);
    }

    protected function markBatchStopped(string $mode, bool $stopped): void {
        $mode = $this->normalizeBatchMode($mode);
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
        $mode = $this->normalizeBatchMode($mode);
        $stoppedModes = get_site_option(self::STOPPED_BATCHES_OPTION, []);

        return is_array($stoppedModes) && !empty($stoppedModes[$mode]);
    }

    protected function getBatchSiteIdsOption(string $mode): string {
        $mode = $this->normalizeBatchMode($mode);
        return $mode === 'large-batch' ? self::LARGE_BATCH_SITE_IDS_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_SITE_IDS_OPTION : self::BATCH_SITE_IDS_OPTION);
    }

    protected function getBatchOffsetOption(string $mode): string {
        $mode = $this->normalizeBatchMode($mode);
        return $mode === 'large-batch' ? self::LARGE_BATCH_OFFSET_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_OFFSET_OPTION : self::BATCH_OFFSET_OPTION);
    }

    protected function getBatchLockOption(string $mode): string {
        $mode = $this->normalizeBatchMode($mode);
        return $mode === 'large-batch' ? self::LARGE_BATCH_LOCK_OPTION
            : ($mode === 'medium-batch' ? self::MEDIUM_BATCH_LOCK_OPTION : self::BATCH_LOCK_OPTION);
    }

    /** @return array{0: string} */
    protected function getBatchRecurringArgs(string $mode): array {
        return [$this->normalizeBatchMode($mode)];
    }

    /** @return array{0: string, 1: bool} */
    protected function getBatchContinuationArgs(string $mode): array {
        return [$this->normalizeBatchMode($mode), true];
    }

    protected function scheduleIndividualContinuation(int $siteId, int $delay = self::CONTINUATION_DELAY_SECONDS): bool {
        if ($siteId <= 0 || $this->isTaskRemovalInProgress()) {
            return false;
        }

        return (bool)$this->inSiteCronContext($siteId, function () use ($siteId, $delay): bool {
            $hook = $this->getHook();
            $cron = _get_cron_array();

            foreach ((array)$cron as $events) {
                foreach ((array)($events[$hook] ?? []) as $event) {
                    if (!empty($event['schedule']) || (array)($event['args'] ?? []) !== [$siteId]) {
                        continue;
                    }

                    return true;
                }
            }

            return (bool)wp_schedule_single_event(time() + max(5, $delay), $hook, [$siteId]);
        });
    }

    protected function inBatchCronContext(callable $callback): mixed {
        $batchCronSiteId = $this->getBatchCronSiteId();

        if ($batchCronSiteId <= 0 || $batchCronSiteId === get_current_blog_id()) {
            return $callback();
        }

        switch_to_blog($batchCronSiteId);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
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

    protected function getHook(): string {
        return $this->config->getShortcodeBlockAnalysisHook();
    }

    public function isSiteActive(int $siteId): bool {
        $site = $siteId > 0 ? get_site($siteId) : null;

        return $site instanceof \WP_Site
            && (int)$site->archived === 0
            && (int)$site->spam === 0
            && (int)$site->deleted === 0;
    }
}
