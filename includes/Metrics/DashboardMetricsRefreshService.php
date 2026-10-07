<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\LoggingService;

defined('ABSPATH') || exit;

/**
 * Persists and coordinates dashboard refresh state for the current network.
 */
class DashboardMetricsRefreshService {
    protected const CACHE_KEY_PREFIX = 'rrze_multisite_manager_dashboard_metrics_v7_';
    protected const LOCK_OPTION = 'rrze_msm_dashboard_metrics_refresh_lock_state';
    protected const LEGACY_LOCK_KEY = 'rrze_msm_dashboard_metrics_refresh_lock';
    protected const BATCH_OFFSET_OPTION = 'rrze_msm_dashboard_metrics_batch_offset';
    protected const BATCH_TOTAL_OPTION = 'rrze_msm_dashboard_metrics_batch_total';
    protected const BATCH_STATE_OPTION = 'rrze_msm_dashboard_metrics_batch_state';

    protected int $cacheVersion;
    protected int $lockTtl;

    public function __construct(int $cacheVersion, int $lockTtl) {
        $this->cacheVersion = $cacheVersion;
        $this->lockTtl = $lockTtl;
    }

    public function getCache(): array {
        $cached = get_site_option($this->getCacheKey(), []);

        return is_array($cached) ? $cached : [];
    }

    public function markCacheDirty(): void {
        $cached = $this->getCache();
        $cached['dirty'] = true;
        update_site_option($this->getCacheKey(), $cached);
    }

    public function saveCompletedCache(array $data, int $startedAt): void {
        $generatedAt = time();

        update_site_option($this->getCacheKey(), [
            'version' => $this->cacheVersion,
            'data' => $data,
            'generated_at' => $generatedAt,
            'started_at' => $startedAt,
            'duration_seconds' => max(0, $generatedAt - $startedAt),
            'dirty' => false,
        ]);
    }

    public function deleteCache(): void {
        delete_site_option($this->getCacheKey());
    }

    public function acquireLock(): bool {
        $timestamp = time();

        if (add_site_option(self::LOCK_OPTION, $timestamp)) {
            return true;
        }

        $existingLock = (int)get_site_option(self::LOCK_OPTION, 0);

        if ($existingLock > 0 && ($timestamp - $existingLock) < $this->lockTtl) {
            return false;
        }

        delete_site_option(self::LOCK_OPTION);

        return add_site_option(self::LOCK_OPTION, $timestamp);
    }

    public function releaseLock(): void {
        delete_site_option(self::LOCK_OPTION);
        delete_site_transient(self::LEGACY_LOCK_KEY);
    }

    public function isLocked(): bool {
        $timestamp = (int)get_site_option(self::LOCK_OPTION, 0);

        if ($timestamp <= 0) {
            return false;
        }

        if ((time() - $timestamp) >= $this->lockTtl) {
            delete_site_option(self::LOCK_OPTION);
            return false;
        }

        return true;
    }

    public function isInProgress(): bool {
        $progress = $this->getBatchProgress();
        $offset = $progress['offset'];
        $total = $progress['total'];

        return $total > 0 && $offset < $total && !empty($this->getBatchState());
    }

    public function getBatchState(): array {
        $state = get_site_option(self::BATCH_STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    public function saveBatchState(array $state): void {
        update_site_option(self::BATCH_STATE_OPTION, $state);
    }

    public function resetBatchState(): void {
        update_site_option(self::BATCH_OFFSET_OPTION, 0);
        update_site_option(self::BATCH_TOTAL_OPTION, 0);
        delete_site_option(self::BATCH_STATE_OPTION);
    }

    /**
     * Runs the cron entrypoint while keeping the stateful scheduling policy in
     * one place. The caller supplies the domain-specific batch aggregation.
     *
     * @param array<int, mixed> $args
     * @param callable(): bool $canRun
     * @param callable(array<int, mixed>): bool $isBatchContinuation
     * @param callable(): bool $isInProgress
     * @param callable(): array{complete: bool, checked_sites: int, total_sites: int} $runBatch
     */
    public function handleScheduledRefresh(
        array $args,
        Config $config,
        callable $canRun,
        callable $isBatchContinuation,
        callable $isInProgress,
        callable $runBatch
    ): void {
        if (!$canRun()) {
            return;
        }

        $isContinuation = $isBatchContinuation($args);

        if ($isContinuation && !$isInProgress()) {
            return;
        }

        if (!$isContinuation) {
            LoggingService::info($config, 'RRZE-MSM: Metrics-Scheduler gestartet', []);
        }

        try {
            $result = $runBatch();

            if (!empty($result['complete'])) {
                LoggingService::info(
                    $config,
                    'RRZE-MSM: Metrics-Scheduler vollständig beendet',
                    [
                        'success' => true,
                        'checked_sites' => (int)($result['checked_sites'] ?? 0),
                        'total_sites' => (int)($result['total_sites'] ?? 0),
                        'websites_checked' => (int)($result['checked_sites'] ?? 0),
                    ]
                );
            }
        } catch (\Throwable $exception) {
            do_action(
                'rrze.log.error',
                'RRZE-MSM: Fehler bei der Metrics-Aktualisierung',
                ['message' => $exception->getMessage()]
            );
            LoggingService::info(
                $config,
                'RRZE-MSM: Metrics-Scheduler beendet',
                [
                    'success' => false,
                    'message' => $exception->getMessage(),
                ]
            );
        }
    }

    /** @return array{offset: int, total: int} */
    public function getBatchProgress(): array {
        return [
            'offset' => max(0, (int)get_site_option(self::BATCH_OFFSET_OPTION, 0)),
            'total' => max(0, (int)get_site_option(self::BATCH_TOTAL_OPTION, 0)),
        ];
    }

    public function saveBatchProgress(int $offset, int $total): void {
        update_site_option(self::BATCH_OFFSET_OPTION, max(0, $offset));
        update_site_option(self::BATCH_TOTAL_OPTION, max(0, $total));
    }

    public function getCacheKey(): string {
        return self::CACHE_KEY_PREFIX . (string)get_current_network_id();
    }
}
