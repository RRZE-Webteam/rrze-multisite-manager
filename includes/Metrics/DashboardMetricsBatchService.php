<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Processes the bounded network-site loop for dashboard metrics refreshes.
 */
class DashboardMetricsBatchService {
    protected DashboardMetricsRefreshService $refresh;

    public function __construct(DashboardMetricsRefreshService $refresh) {
        $this->refresh = $refresh;
    }

    /**
     * @param callable(): array<string, mixed> $createInitialState
     * @param callable(\WP_Site): array<string, mixed> $formatSite
     * @param callable(array<string, mixed>&, \WP_Site, array<string, mixed>): void $accumulateSite
     * @param callable(string): void $queueComposition
     * @param callable(string): void $queueCompositionWatchdog
     * @param callable(int, bool): void $schedule
     * @return array{complete: bool, checked_sites: int, total_sites: int}
     */
    public function run(
        int $batchSize,
        bool $manual,
        callable $createInitialState,
        callable $formatSite,
        callable $accumulateSite,
        callable $queueComposition,
        callable $queueCompositionWatchdog,
        callable $schedule
    ): array {
        if (!$this->refresh->acquireLock()) {
            return ['complete' => false, 'checked_sites' => 0, 'total_sites' => 0];
        }

        try {
            $progress = $this->refresh->getBatchProgress();
            $offset = $progress['offset'];
            $totalSites = $progress['total'];
            $runState = $this->refresh->getBatchState();

            if ($offset <= 0 || $totalSites <= 0 || empty($runState['run_id'])) {
                $totalSites = (int)get_sites(['count' => true, 'number' => 1]);
                $this->refresh->saveBatchProgress($offset, $totalSites);
                $runState = [
                    'run_id' => wp_generate_uuid4(),
                    'started_at' => time(),
                    'phase' => 'collecting',
                    'partial_offsets' => [],
                ];
                $this->refresh->saveBatchState($runState);
            }

            if ((string)($runState['phase'] ?? 'collecting') !== 'collecting') {
                return ['complete' => false, 'checked_sites' => $offset, 'total_sites' => $totalSites];
            }

            $state = $createInitialState();
            $state['started_at'] = (int)($runState['started_at'] ?? time());

            $siteIds = get_sites([
                'fields' => 'ids',
                'number' => max(1, $batchSize),
                'offset' => max(0, $offset),
                'orderby' => 'registered',
                'order' => 'DESC',
            ]);

            foreach ($siteIds as $siteId) {
                $site = get_site((int)$siteId);

                if (!$site instanceof \WP_Site) {
                    continue;
                }

                $formattedSite = $formatSite($site);

                if (empty($formattedSite)) {
                    continue;
                }

                $state['site_overview'][] = $formattedSite;
                $accumulateSite($state, $site, $formattedSite);
            }

            $this->refresh->saveBatchPartialState((string)$runState['run_id'], $offset, $state);
            $runState = $this->refresh->addBatchPartialOffset($runState, $offset);
            $nextOffset = $offset + count($siteIds);

            if (empty($siteIds) || $nextOffset >= $totalSites) {
                $runState['phase'] = 'composing';
                $runState['composition_started_at'] = time();
                $runState['composition_attempts'] = 0;
                $runState['composition_error'] = '';
                $this->refresh->saveBatchState($runState);
                $this->refresh->saveBatchProgress($totalSites, $totalSites);
                $queueComposition((string)$runState['run_id']);
                $queueCompositionWatchdog((string)$runState['run_id']);

                return [
                    'complete' => false,
                    'checked_sites' => $nextOffset,
                    'total_sites' => $totalSites,
                ];
            }

            $this->refresh->saveBatchState($runState);
            $this->refresh->saveBatchProgress($nextOffset, $totalSites);
            $schedule($manual ? 5 : 20, true);

            return [
                'complete' => false,
                'checked_sites' => count((array)($state['site_overview'] ?? [])),
                'total_sites' => $totalSites,
            ];
        } finally {
            $this->refresh->releaseLock();
        }
    }
}
