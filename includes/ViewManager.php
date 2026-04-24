<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class ViewManager {
    protected const OPTION_KEY = 'rrze_multisite_manager_dashboard_views';

    public function getViews(array $availableWidgetIds): array {
        $defaults = $this->getDefaultViews($availableWidgetIds);
        $storedViews = get_site_option(self::OPTION_KEY, []);
        $views = $defaults;
        $slug = '';
        $view = [];

        if (!is_array($storedViews)) {
            return $defaults;
        }

        foreach ($storedViews as $slug => $view) {
            $slug = sanitize_key((string)$slug);

            if ($slug === '') {
                continue;
            }

            if (isset($defaults[$slug])) {
                if ($slug === 'all_widgets') {
                    $views[$slug]['widgets'] = $availableWidgetIds;
                    continue;
                }

                $views[$slug]['widgets'] = $this->sanitizeWidgets($view['widgets'] ?? $defaults[$slug]['widgets'], $availableWidgetIds);
                continue;
            }

            $views[$slug] = [
                'slug' => $slug,
                'label' => sanitize_text_field((string)($view['label'] ?? $slug)),
                'widgets' => $this->sanitizeWidgets($view['widgets'] ?? $availableWidgetIds, $availableWidgetIds),
                'system' => false,
            ];
        }

        return $views;
    }

    public function getCurrentView(array $views, string $fallback = 'default'): array {
        $slug = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : $fallback;

        if ($slug !== '' && isset($views[$slug])) {
            return $views[$slug];
        }

        if (isset($views[$fallback])) {
            return $views[$fallback];
        }

        return reset($views);
    }

    public function saveViews(mixed $submittedViews, array $availableWidgetIds, string $newViewName): void {
        $existingViews = $this->getViews($availableWidgetIds);
        $storedViews = [];
        $slug = '';
        $view = [];
        $submittedView = [];

        if (!is_array($submittedViews)) {
            $submittedViews = [];
        }

        foreach ($existingViews as $slug => $view) {
            $submittedView = isset($submittedViews[$slug]) && is_array($submittedViews[$slug]) ? $submittedViews[$slug] : [];

            if (empty($view['system']) && !empty($submittedView['delete'])) {
                continue;
            }

            $storedViews[$slug] = [
                'label' => !empty($view['system'])
                    ? (string)$view['label']
                    : $this->sanitizeViewLabel((string)($submittedView['label'] ?? (string)$view['label'])),
                'widgets' => $slug === 'all_widgets'
                    ? $availableWidgetIds
                    : $this->sanitizeWidgets($submittedView['widgets'] ?? $view['widgets'], $availableWidgetIds),
                'system' => !empty($view['system']),
            ];
        }

        if ($newViewName !== '') {
            $slug = $this->generateUniqueSlug($newViewName, $storedViews);
            $storedViews[$slug] = [
                'label' => $this->sanitizeViewLabel($newViewName),
                'widgets' => $availableWidgetIds,
                'system' => false,
            ];
        }

        update_site_option(self::OPTION_KEY, $storedViews);
    }

    public function getViewsSlug(): string {
        return 'rrze-multisite-manager-views';
    }

    protected function getDefaultViews(array $availableWidgetIds): array {
        return [
            'default' => [
                'slug' => 'default',
                'label' => __('Default', 'rrze-multisite-manager'),
                'widgets' => [
                    'summary',
                    'site_overview',
                    'status',
                    'growth',
                    'recent_sites',
                    'recently_updated_sites',
                    'inactive_sites',
                    'archived_sites',
                    'blocked_sites',
                    'deleted_sites',
                    'plugin_usage',
                ],
                'system' => true,
            ],
            'all_widgets' => [
                'slug' => 'all_widgets',
                'label' => __('Alle Widgets', 'rrze-multisite-manager'),
                'widgets' => $availableWidgetIds,
                'system' => true,
            ],
            'themes_editor' => [
                'slug' => 'themes_editor',
                'label' => __('Themes und Editor', 'rrze-multisite-manager'),
                'widgets' => [
                    'summary',
                    'theme_usage',
                    'editor_usage',
                    'theme_overview',
                ],
                'system' => true,
            ],
            'sites_overview' => [
                'slug' => 'sites_overview',
                'label' => __('Instanzen und Übersicht', 'rrze-multisite-manager'),
                'widgets' => [
                    'summary',
                    'site_overview',
                    'status',
                    'growth',
                    'recent_sites',
                    'recently_updated_sites',
                    'inactive_sites',
                    'archived_sites',
                    'blocked_sites',
                    'deleted_sites',
                ],
                'system' => true,
            ],
        ];
    }

    protected function sanitizeWidgets(mixed $widgetIds, array $availableWidgetIds): array {
        $sanitized = [];
        $widgetId = '';

        if (!is_array($widgetIds)) {
            return [];
        }

        foreach ($widgetIds as $widgetId) {
            $widgetId = sanitize_key((string)$widgetId);

            if (in_array($widgetId, $availableWidgetIds, true) && !in_array($widgetId, $sanitized, true)) {
                $sanitized[] = $widgetId;
            }
        }

        return $sanitized;
    }

    protected function sanitizeViewLabel(string $label): string {
        $label = sanitize_text_field($label);
        return $label === '' ? __('Neue Ansicht', 'rrze-multisite-manager') : $label;
    }

    protected function generateUniqueSlug(string $label, array $views): string {
        $baseSlug = sanitize_title($label);
        $slug = $baseSlug === '' ? 'view' : $baseSlug;
        $suffix = 2;
        $base = $slug;

        while (isset($views[$slug])) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
