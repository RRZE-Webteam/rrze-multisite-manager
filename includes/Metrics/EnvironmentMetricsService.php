<?php

namespace RRZE\MultisiteManager\Metrics;


defined('ABSPATH') || exit;

/**
 * Builds the network environment overview independently from scheduling and
 * storage analysis. MetricsService remains the source of dashboard data.
 */
class EnvironmentMetricsService {
    use MetricsServiceEnvironmentTrait;

    protected MetricsImplementationService $metrics;

    public function __construct(MetricsImplementationService $metrics) {
        $this->metrics = $metrics;
    }

    protected function getDashboardData(): array {
        return $this->metrics->getDashboardData();
    }

    protected static function isUnusedTheme(array $theme): bool {
        return (int)($theme['site_count'] ?? 0) === 0;
    }
}
