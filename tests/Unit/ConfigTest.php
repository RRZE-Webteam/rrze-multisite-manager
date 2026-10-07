<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Config;

class ConfigTest extends \WP_UnitTestCase {
    public function testAvailabilityMonitoringTimeoutsAreBounded(): void {
        $config = new Config();

        self::assertSame(4, $config->getAvailabilityMonitoringHttpTimeoutSeconds());
        self::assertSame(2, $config->getAvailabilityMonitoringHttpFallbackTimeoutSeconds());
        self::assertSame(45, $config->getAvailabilityMonitoringRequestBudgetSeconds());
    }
}
