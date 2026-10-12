<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Metrics\DashboardMetricsRefreshService;

class DashboardMetricsRefreshServiceTest extends \WP_UnitTestCase {
    public function testDirtyMarkerDoesNotRewriteTheDashboardCache(): void {
        $service = new DashboardMetricsRefreshService(9876, 60);
        $cacheKey = $this->getCacheKey($service);
        $cache = [
            'version' => 9876,
            'data' => ['site_overview' => array_fill(0, 100, ['id' => 1])],
            'generated_at' => time(),
            'dirty' => false,
        ];

        update_site_option($cacheKey, $cache);
        $service->markCacheDirty();

        self::assertSame($cache, get_site_option($cacheKey));
        self::assertTrue((bool)($service->getCache()['dirty'] ?? false));

        $service->saveCompletedCache(['site_overview' => []], time());

        self::assertFalse((bool)($service->getCache()['dirty'] ?? true));
        $service->deleteCache();
    }

    public function testPartialBatchStateStaysCompactAndIsRemovedOnReset(): void {
        $service = new DashboardMetricsRefreshService(9876, 60);
        $runState = [
            'run_id' => 'test-dashboard-run',
            'phase' => 'collecting',
            'partial_offsets' => [],
        ];
        $partialState = [
            'site_overview' => [
                ['id' => 123],
            ],
        ];

        $service->saveBatchPartialState($runState['run_id'], 0, $partialState);
        $runState = $service->addBatchPartialOffset($runState, 0);
        $service->saveBatchState($runState);
        $service->saveBatchProgress(25, 100);

        self::assertSame($partialState, $service->getBatchPartialState($runState['run_id'], 0));
        self::assertSame([0], $service->getBatchPartialOffsets($runState));
        self::assertTrue($service->isInProgress());

        $service->resetBatchState();

        self::assertSame([], $service->getBatchPartialState($runState['run_id'], 0));
        self::assertSame(['offset' => 0, 'total' => 0], $service->getBatchProgress());
    }

    public function testCompositionLockPreventsConcurrentCompositionAndIsResetWithTheRun(): void {
        $service = new DashboardMetricsRefreshService(9876, 60);
        $runState = [
            'run_id' => 'test-composition-run',
            'phase' => 'composing',
            'partial_offsets' => [],
        ];

        $service->saveBatchState($runState);

        self::assertTrue($service->acquireCompositionLock($runState['run_id']));
        self::assertTrue($service->isCompositionLocked());
        self::assertFalse($service->acquireCompositionLock('other-run'));

        $service->resetBatchState();

        self::assertFalse($service->isCompositionLocked());
    }

    private function getCacheKey(DashboardMetricsRefreshService $service): string {
        $method = new \ReflectionMethod($service, 'getCacheKey');
        $method->setAccessible(true);

        return (string)$method->invoke($service);
    }
}
