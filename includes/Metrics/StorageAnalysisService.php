<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\MetricsService;

defined('ABSPATH') || exit;

/**
 * Boundary between storage scheduling and the storage-analysis implementation.
 *
 * The implementation remains in MetricsService during the incremental
 * migration; Scheduler code no longer depends on unrelated metric features.
 */
class StorageAnalysisService {
    protected MetricsService $metrics;

    public function __construct(MetricsService $metrics) {
        $this->metrics = $metrics;
    }

    public function clearProcessStates(int $siteId): void {
        $this->metrics->clearSiteStorageAnalysisProcessStates($siteId);
    }

    public function getProcessStatus(int $siteId): array {
        return $this->metrics->getSiteStorageAnalysisProcessStatus($siteId);
    }

    public function getProgressContext(int $siteId, string $phase): array {
        return $this->metrics->getSiteStorageAnalysisProgressContext($siteId, $phase);
    }

    public function runBaseBatch(int $siteId): array {
        return $this->metrics->runSiteStorageAnalysisBatch($siteId);
    }

    public function runOrphanBatch(int $siteId): array {
        return $this->metrics->runSiteStorageOrphanAnalysisBatch($siteId);
    }

    public function runMediaMetadataBatch(int $siteId, bool $restart): array {
        return $this->metrics->runSiteMediaMetadataAnalysisBatch($siteId, $restart);
    }
}
