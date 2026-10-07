<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Searches the network's installed plugin and theme catalogs.
 */
class AssetSearchService {
    public function searchPlugins(string $searchTerm, int $limit = 20): array {
        $availablePlugins = [];
        $results = [];
        $pluginFile = '';
        $pluginData = [];
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $haystack = '';

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 3) {
            return [];
        }

        $availablePlugins = get_plugins();

        foreach ($availablePlugins as $pluginFile => $pluginData) {
            $haystack = mb_strtolower(
                (string)($pluginData['Name'] ?? '') . ' ' .
                (string)($pluginData['Description'] ?? '') . ' ' .
                $pluginFile
            );

            if (mb_strpos($haystack, $searchNeedle) === false) {
                continue;
            }

            $results[] = [
                'id' => $pluginFile,
                'name' => (string)($pluginData['Name'] ?? $pluginFile),
                'version' => (string)($pluginData['Version'] ?? ''),
                'file' => $pluginFile,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function searchThemes(string $searchTerm, int $limit = 20): array {
        $themes = wp_get_themes();
        $results = [];
        $stylesheet = '';
        $theme = null;
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $haystack = '';

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 3) {
            return [];
        }

        foreach ($themes as $stylesheet => $theme) {
            if (!$theme instanceof \WP_Theme) {
                continue;
            }

            $haystack = mb_strtolower(
                (string)$theme->get('Name') . ' ' .
                (string)$theme->get('Description') . ' ' .
                $stylesheet
            );

            if (mb_strpos($haystack, $searchNeedle) === false) {
                continue;
            }

            $results[] = [
                'id' => $stylesheet,
                'name' => (string)$theme->get('Name'),
                'version' => (string)$theme->get('Version'),
                'stylesheet' => $stylesheet,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
