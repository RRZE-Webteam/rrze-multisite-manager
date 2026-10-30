<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\ShortcodeBlockAnalysisSchedulerService;

class ShortcodeBlockAnalysisSchedulerTest extends \WP_UnitTestCase {
    public function testStaleSharedBatchRunRemainsRunningWhenAnotherBatchContinuationExists(): void {
        $siteId = get_current_blog_id();
        $config = new Config();
        $scheduler = new ShortcodeBlockAnalysisSchedulerService($config);
        $assignmentOption = 'rrze_msm_shortcode_block_analysis_assignments';
        $batchSiteIdsOption = 'rrze_msm_shortcode_block_analysis_batch_site_ids';
        $statusOption = 'rrze_msm_shortcode_block_analysis_status';
        (new \RRZE\MultisiteManager\Analysis\AnalysisStore())->install();
        $contentStore = new \RRZE\MultisiteManager\Analysis\ContentAnalysisStore();
        $contentStore->discard($siteId);
        $previousAssignments = get_site_option($assignmentOption, false);
        $previousBatchSiteIds = get_site_option($batchSiteIdsOption, false);
        $previousStatus = get_blog_option($siteId, $statusOption, false);

        wp_clear_scheduled_hook($config->getShortcodeBlockAnalysisBatchHook(), ['rrze_msm_shortcode_block_batch' => true]);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, $config->getShortcodeBlockAnalysisBatchHook(), ['rrze_msm_shortcode_block_batch' => true]);
        update_site_option($assignmentOption, [$siteId => 'batch']);
        update_site_option($batchSiteIdsOption, [$siteId]);
        update_blog_option($siteId, $statusOption, [
            'status' => 'running',
            'phase' => 'shortcodes',
            'processed_posts' => 40,
            'total_posts' => 100,
            'active_runtime_seconds' => 1.0,
            'last_progress_at' => gmdate('Y-m-d H:i:s', time() - (3 * HOUR_IN_SECONDS)),
        ]);
        $contentStore->save($siteId, [
            'site_id' => $siteId,
            'phase' => 'shortcodes',
            'shortcode_last_post_id' => 123,
        ]);

        try {
            self::assertFalse($this->recoverInterruptedAnalysis($scheduler, $siteId));

            $status = get_blog_option($siteId, $statusOption, []);
            self::assertSame('running', $status['status']);
            self::assertSame(123, $contentStore->load($siteId)['shortcode_last_post_id']);
        } finally {
            wp_clear_scheduled_hook($config->getShortcodeBlockAnalysisBatchHook(), ['rrze_msm_shortcode_block_batch' => true]);
            $this->restoreSiteOption($assignmentOption, $previousAssignments);
            $this->restoreSiteOption($batchSiteIdsOption, $previousBatchSiteIds);
            $this->restoreBlogOption($siteId, $statusOption, $previousStatus);
            $contentStore->discard($siteId);
        }
    }

    public function testSharedBatchRunReschedulesMissingContinuationFromSavedCheckpoint(): void {
        $siteId = get_current_blog_id();
        $config = new Config();
        $scheduler = new ShortcodeBlockAnalysisSchedulerService($config);
        $assignmentOption = 'rrze_msm_shortcode_block_analysis_assignments';
        $batchSiteIdsOption = 'rrze_msm_shortcode_block_analysis_batch_site_ids';
        $statusOption = 'rrze_msm_shortcode_block_analysis_status';
        $batchHook = $config->getShortcodeBlockAnalysisBatchHook();
        (new \RRZE\MultisiteManager\Analysis\AnalysisStore())->install();
        $contentStore = new \RRZE\MultisiteManager\Analysis\ContentAnalysisStore();
        $contentStore->discard($siteId);
        $previousAssignments = get_site_option($assignmentOption, false);
        $previousBatchSiteIds = get_site_option($batchSiteIdsOption, false);
        $previousStatus = get_blog_option($siteId, $statusOption, false);

        wp_clear_scheduled_hook($batchHook, ['rrze_msm_shortcode_block_batch' => true]);
        update_site_option($assignmentOption, [$siteId => 'batch']);
        update_site_option($batchSiteIdsOption, [$siteId]);
        update_blog_option($siteId, $statusOption, [
            'status' => 'running',
            'phase' => 'blocks',
            'processed_posts' => 50,
            'total_posts' => 100,
        ]);
        $contentStore->save($siteId, [
            'site_id' => $siteId,
            'phase' => 'blocks',
            'block_last_post_id' => 456,
        ]);

        try {
            self::assertFalse($this->recoverInterruptedAnalysis($scheduler, $siteId));
            self::assertGreaterThan(time(), (int)wp_next_scheduled($batchHook, ['rrze_msm_shortcode_block_batch' => true]));
            self::assertSame('running', get_blog_option($siteId, $statusOption, [])['status']);
            self::assertSame(456, $contentStore->load($siteId)['block_last_post_id']);
        } finally {
            wp_clear_scheduled_hook($batchHook, ['rrze_msm_shortcode_block_batch' => true]);
            $this->restoreSiteOption($assignmentOption, $previousAssignments);
            $this->restoreSiteOption($batchSiteIdsOption, $previousBatchSiteIds);
            $this->restoreBlogOption($siteId, $statusOption, $previousStatus);
            $contentStore->discard($siteId);
        }
    }

    private function recoverInterruptedAnalysis(ShortcodeBlockAnalysisSchedulerService $scheduler, int $siteId): bool {
        $method = new \ReflectionMethod(ShortcodeBlockAnalysisSchedulerService::class, 'recoverInterruptedAnalysis');
        $method->setAccessible(true);

        return (bool)$method->invoke($scheduler, $siteId, []);
    }

    private function restoreSiteOption(string $option, mixed $value): void {
        if ($value === false) {
            delete_site_option($option);
            return;
        }

        update_site_option($option, $value);
    }

    private function restoreBlogOption(int $siteId, string $option, mixed $value): void {
        if ($value === false) {
            delete_blog_option($siteId, $option);
            return;
        }

        update_blog_option($siteId, $option, $value);
    }
}
