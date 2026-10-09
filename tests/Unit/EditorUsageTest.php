<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Metrics\MetricsImplementationService;

class EditorUsageTest extends \WP_UnitTestCase {
    public function testNetworkBlockEditorSettingIsReadFromObjectOptions(): void {
        $previousSettings = get_site_option('rrze_settings', false);

        update_site_option('rrze_settings', (object)[
            'writing' => (object)[
                'enable_block_editor' => 1,
            ],
        ]);

        try {
            self::assertTrue($this->isBlockEditorEnabledNetworkWide());
        } finally {
            if ($previousSettings === false) {
                delete_site_option('rrze_settings');
            } else {
                update_site_option('rrze_settings', $previousSettings);
            }
        }
    }

    public function testBlockEditorIsDetectedWhenItIsTheSiteDefault(): void {
        $previousSettings = get_option('rrze_settings', false);

        update_option('rrze_settings', (object)[
            'writing' => (object)[
                'try_enable_block_editor' => 0,
                'enable_classic_editor' => 0,
            ],
        ]);

        try {
            self::assertTrue($this->isBlockEditorEnabledForSite(get_current_blog_id()));
        } finally {
            if ($previousSettings === false) {
                delete_option('rrze_settings');
            } else {
                update_option('rrze_settings', $previousSettings);
            }
        }
    }

    public function testClassicEditorIsDetectedWhenItIsTheSiteDefault(): void {
        $previousSettings = get_option('rrze_settings', false);

        update_option('rrze_settings', [
            'writing' => [
                'try_enable_block_editor' => 1,
                'enable_classic_editor' => 1,
            ],
        ]);

        try {
            self::assertFalse($this->isBlockEditorEnabledForSite(get_current_blog_id()));
        } finally {
            if ($previousSettings === false) {
                delete_option('rrze_settings');
            } else {
                update_option('rrze_settings', $previousSettings);
            }
        }
    }

    private function isBlockEditorEnabledForSite(int $siteId): bool {
        $reflection = new \ReflectionClass(MetricsImplementationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isBlockEditorEnabledForSite');
        $method->setAccessible(true);

        return (bool)$method->invoke($service, $siteId, false);
    }

    private function isBlockEditorEnabledNetworkWide(): bool {
        $reflection = new \ReflectionClass(MetricsImplementationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isBlockEditorEnabledNetworkWide');
        $method->setAccessible(true);

        return (bool)$method->invoke($service);
    }
}
