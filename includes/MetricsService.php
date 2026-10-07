<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Metrics\MetricsImplementationService;

defined('ABSPATH') || exit;

/**
 * Stable public facade for metrics used by the dashboard, AJAX handlers and
 * schedulers. Metric implementation details live behind this boundary.
 */
class MetricsService {
    protected MetricsImplementationService $implementation;

    public function __construct(?Settings $settings = null, ?Config $config = null) {
        $this->implementation = new MetricsImplementationService($settings, $config);
    }

    /**
     * Forwards the established public instance API without exposing internal
     * metric-service dependencies to callers.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed {
        if (!method_exists($this->implementation, $method)) {
            throw new \BadMethodCallException(sprintf('Unknown metrics method: %s', $method));
        }

        return $this->implementation->{$method}(...$arguments);
    }

    public static function isFullDataCleanupInProgress(): bool {
        return MetricsImplementationService::isFullDataCleanupInProgress();
    }

    public static function disableMaintenanceScheduling(): int {
        return MetricsImplementationService::disableMaintenanceScheduling();
    }
}
