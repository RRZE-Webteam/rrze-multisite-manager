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
     * @param callable(array<string, mixed>): void $finalize
     * @param callable(int, bool): void $schedule
     * @return array{complete: bool, checked_sites: int, total_sites: int}
     */
    public function run(
        int $batchSize,
        bool $manual,
        callable $createInitialState,
        callable $formatSite,
        callable $accumulateSite,
        callable $finalize,
        callable $schedule
    ): array {
        if (!$this->refresh->acquireLock()) {
            return ['complete' => false, 'checked_sites' => 0, 'total_sites' => 0];
        }

        try {
            $progress = $this->refresh->getBatchProgress();
            $offset = $progress['offset'];
            $totalSites = $progress['total'];
            $state = $this->refresh->getBatchState();

            if ($offset <= 0 || $totalSites <= 0 || empty($state)) {
                $totalSites = (int)get_sites(['count' => true, 'number' => 1]);
                $this->refresh->saveBatchProgress($offset, $totalSites);
                $state = $createInitialState();
            }

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

            $this->refresh->saveBatchState($state);
            $nextOffset = $offset + count($siteIds);

            if (empty($siteIds) || $nextOffset >= $totalSites) {
                $finalize($state);
                $checkedSites = count((array)($state['site_overview'] ?? []));
                $this->refresh->resetBatchState();
                $schedule(60, false);

                return [
                    'complete' => true,
                    'checked_sites' => $checkedSites,
                    'total_sites' => $totalSites,
                ];
            }

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
