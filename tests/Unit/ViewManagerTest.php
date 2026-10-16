<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\ViewManager;

class ViewManagerTest extends \WP_UnitTestCase {
    protected const OPTION_KEY = 'rrze_multisite_manager_dashboard_views';

    protected function set_up(): void {
        parent::set_up();
        delete_site_option(self::OPTION_KEY);
    }

    protected function tear_down(): void {
        delete_site_option(self::OPTION_KEY);
        parent::tear_down();
    }

    public function testDefaultViewKeepsTheExplicitlySavedWidgetSelection(): void {
        $manager = new ViewManager();
        $widgets = ['summary', 'website_status', 'status'];

        $manager->saveViews([
            'default' => [
                'widgets_present' => '1',
                'widgets' => ['summary'],
            ],
        ], $widgets, '');

        $views = $manager->getViews($widgets);

        self::assertSame(['summary'], $views['default']['widgets']);
        self::assertSame($widgets, $views['all_widgets']['widgets']);
    }

    public function testViewCanSaveAnEmptyWidgetSelection(): void {
        $manager = new ViewManager();
        $widgets = ['summary', 'website_status'];

        $manager->saveViews([
            'default' => [
                'widgets_present' => '1',
            ],
        ], $widgets, '');

        $views = $manager->getViews($widgets);

        self::assertSame([], $views['default']['widgets']);
    }
}
