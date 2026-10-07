<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Coordinates optional detail sections for a single website in its own context.
 */
class SiteDetailMetricsService {
    /**
     * @param array<string, mixed> $load
     * @param array<string, callable> $providers
     * @return array<string, mixed>
     */
    public function collect(int $siteId, array $load, array $providers, int $sectionMaxRows): array {
        $result = [
            'theme' => $this->getEmptyTheme(),
            'plugins' => [],
            'users' => [],
            'content_types' => [],
            'custom_post_types' => [],
            'block_template_types' => [],
            'image_sizes' => [],
            'options_overview' => [
                'groups' => [],
                'selected_group' => [],
            ],
            'process_stats' => [
                'transients' => 0,
                'cron_events' => 0,
            ],
            'transients' => [],
            'cron_events' => [],
            'transients_truncated' => false,
            'cron_events_truncated' => false,
        ];

        if ($siteId <= 0) {
            return $result;
        }

        $loadTheme = !empty($load['theme']);
        $loadPlugins = !empty($load['plugins']);
        $loadImageSizes = !empty($load['image_sizes']);
        $optionsGroup = !empty($load['options_values_group']) ? (string)$load['options_values_group'] : '';

        switch_to_blog($siteId);

        try {
            if ($loadTheme || $loadImageSizes) {
                $result['theme'] = $providers['theme']();
            }

            if ($loadPlugins || $loadImageSizes) {
                $result['plugins'] = $providers['plugins']();
            }

            if (!empty($load['users'])) {
                $result['users'] = $providers['users']();
            }

            if ($loadImageSizes) {
                $result['image_sizes'] = $providers['image_sizes']($result['theme'], $result['plugins']);
            }

            if (!empty($load['content'])) {
                $result['content_types'] = $providers['content_types']();
                $result['custom_post_types'] = $providers['custom_post_types']();
                $result['block_template_types'] = $providers['block_template_types']();
            }

            if (!empty($load['options_summary'])) {
                $result['options_overview']['groups'] = $providers['options_groups']();
            }

            if ($optionsGroup !== '') {
                $result['options_overview']['selected_group'] = $providers['option_group']($optionsGroup);
            }

            if (!empty($load['process_stats'])) {
                $result['process_stats'] = $providers['process_stats']();
            }

            if (!empty($load['transients'])) {
                $result['transients'] = $providers['transients']();
            }

            if (!empty($load['cron_events'])) {
                $result['cron_events'] = $providers['cron_events']();
            }
        } finally {
            restore_current_blog();
        }

        $result['transients_truncated'] = count($result['transients']) >= $sectionMaxRows;
        $result['cron_events_truncated'] = count($result['cron_events']) >= $sectionMaxRows;

        return $result;
    }

    /** @return array<string, string> */
    protected function getEmptyTheme(): array {
        return [
            'name' => '',
            'version' => '',
            'description' => '',
            'screenshot' => '',
        ];
    }
}
