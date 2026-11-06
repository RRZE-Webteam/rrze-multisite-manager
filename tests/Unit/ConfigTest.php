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

    public function testAvailabilityMonitoringBatchSizeUsesTheNetworkSettingWithinBounds(): void {
        $config = new Config();
        $optionName = $config->getOptionName();
        $previous = get_site_option($optionName, false);

        try {
            delete_site_option($optionName);
            self::assertSame(10, $config->getAvailabilityMonitoringBatchSize());

            update_site_option($optionName, ['monitoring_availability_batch_size' => 18]);
            self::assertSame(18, $config->getAvailabilityMonitoringBatchSize());

            update_site_option($optionName, ['monitoring_availability_batch_size' => 99]);
            self::assertSame(25, $config->getAvailabilityMonitoringBatchSize());

            update_site_option($optionName, ['monitoring_availability_batch_size' => 0]);
            self::assertSame(1, $config->getAvailabilityMonitoringBatchSize());
        } finally {
            if ($previous === false) {
                delete_site_option($optionName);
            } else {
                update_site_option($optionName, $previous);
            }
        }
    }

    public function testShortcodeBlockBatchThresholdsAreOrderedAndBounded(): void {
        $config = new Config();
        $optionName = $config->getOptionName();
        $previous = get_site_option($optionName, false);

        try {
            update_site_option($optionName, [
                'monitoring_shortcode_block_analysis_small_batch_post_threshold' => 200,
                'monitoring_shortcode_block_analysis_medium_batch_post_threshold' => 100,
                'monitoring_shortcode_block_analysis_large_batch_post_threshold' => 50,
                'monitoring_shortcode_block_analysis_small_batch_runtime_threshold_seconds' => 20,
                'monitoring_shortcode_block_analysis_medium_batch_runtime_threshold_seconds' => 10,
                'monitoring_shortcode_block_analysis_large_batch_runtime_threshold_seconds' => 5,
            ]);

            self::assertSame(200, $config->getShortcodeBlockSmallBatchPostThreshold());
            self::assertSame(200, $config->getShortcodeBlockMediumBatchPostThreshold());
            self::assertSame(200, $config->getShortcodeBlockLargeBatchPostThreshold());
            self::assertSame(20, $config->getShortcodeBlockSmallBatchRuntimeThresholdSeconds());
            self::assertSame(20, $config->getShortcodeBlockMediumBatchRuntimeThresholdSeconds());
            self::assertSame(20, $config->getShortcodeBlockLargeBatchRuntimeThresholdSeconds());
        } finally {
            if ($previous === false) {
                delete_site_option($optionName);
            } else {
                update_site_option($optionName, $previous);
            }
        }
    }
}
