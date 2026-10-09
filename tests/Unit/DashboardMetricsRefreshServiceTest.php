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

    private function getCacheKey(DashboardMetricsRefreshService $service): string {
        $method = new \ReflectionMethod($service, 'getCacheKey');
        $method->setAccessible(true);

        return (string)$method->invoke($service);
    }
}
