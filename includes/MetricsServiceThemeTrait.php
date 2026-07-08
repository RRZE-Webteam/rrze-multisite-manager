<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

trait MetricsServiceThemeTrait {
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

    public function getThemeDetails(string $stylesheet): array {
        $themes = $this->getThemes();
        $cacheKey = '';
        $cached = null;
        $themeItem = [];
        $supplementary = [];
        $analysis = [];
        $installTimestamp = 0;
        $modifiedTimestamp = 0;

        foreach ($themes as $themeItem) {
            if ((string)($themeItem['stylesheet'] ?? '') === $stylesheet) {
                break;
            }
        }

        if (empty($themeItem) || (string)($themeItem['stylesheet'] ?? '') !== $stylesheet) {
            return [];
        }

        $cacheKey = $this->getThemeDetailsCacheKey($stylesheet);
        $cached = get_site_transient($cacheKey);

        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $supplementary = $this->getThemeSupplementaryData($stylesheet);
        $analysis = $this->analyzeThemeCode($stylesheet);
        $installTimestamp = $this->getThemeInstallTimestamp($stylesheet);
        $modifiedTimestamp = $this->getThemeModifiedTimestamp($stylesheet);

        if ((string)($themeItem['author'] ?? '') === '' && !empty($supplementary['author']['name'])) {
            $themeItem['author'] = (string)$supplementary['author']['name'];
        }

        if ((string)($themeItem['author_url'] ?? '') === '' && !empty($supplementary['author']['url'])) {
            $themeItem['author_url'] = (string)$supplementary['author']['url'];
        }

        if ((string)($themeItem['description'] ?? '') === '' && !empty($supplementary['description'])) {
            $themeItem['description'] = (string)$supplementary['description'];
        }

        $themeItem = array_merge(
            $themeItem,
            [
                'author_email' => (string)($supplementary['author']['email'] ?? ''),
                'compatibility' => (array)($supplementary['compatibility'] ?? []),
                'supports' => (array)($supplementary['supports'] ?? []),
                'license' => (array)($supplementary['license'] ?? []),
                'repository' => (array)($supplementary['repository'] ?? []),
                'metadata_sources' => (array)($supplementary['sources'] ?? []),
                'readme_markdown' => (string)($supplementary['readme_markdown'] ?? ''),
                'translation_languages' => $this->getTranslationLanguages(
                    $stylesheet,
                    (string)($themeItem['text_domain'] ?? ''),
                    'theme'
                ),
                'shortcodes' => (array)($analysis['shortcodes'] ?? []),
                'blocks' => (array)($analysis['blocks'] ?? []),
                'block_patterns' => (array)($analysis['block_patterns'] ?? []),
                'image_sizes' => (array)($analysis['image_sizes'] ?? []),
                'provided_hooks' => (array)($analysis['provided_hooks'] ?? []),
                'installation_date_label' => $installTimestamp > 0 ? $this->formatTimestamp($installTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'last_release_date_label' => $modifiedTimestamp > 0 ? $this->formatTimestamp($modifiedTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'main_file_path' => $this->getThemeMainFilePath($stylesheet),
            ]
        );

        set_site_transient($cacheKey, $themeItem, $this->getDetailCacheTtl());

        return $themeItem;
    }

    protected function getThemes(): array {
        $themes = wp_get_themes();
        $allowedThemes = $this->getAllowedThemes();
        $themeSiteAggregate = $this->getThemeSiteAggregate();
        $siteCounts = (array)($themeSiteAggregate['counts'] ?? []);
        $siteUsageMap = (array)($themeSiteAggregate['usage_map'] ?? []);
        $siteUsageTruncated = (array)($themeSiteAggregate['truncated'] ?? []);
        $results = [];
        $stylesheet = '';
        $theme = null;
        $themeData = [];
        $description = '';
        $screenshot = '';
        $tags = [];

        foreach ($themes as $stylesheet => $theme) {
            if (!$theme instanceof \WP_Theme) {
                continue;
            }

            $description = (string)$theme->get('Description');
            $screenshot = $theme->get_screenshot();
            $tags = $theme->get('Tags');
            $themeData = [
                'stylesheet' => $stylesheet,
                'name' => $theme->get('Name') ?: $stylesheet,
                'version' => $theme->get('Version') ?: 'n/a',
                'description' => wp_strip_all_tags($description),
                'site_count' => (int)($siteCounts[$stylesheet] ?? 0),
                'network_enabled' => isset($allowedThemes[$stylesheet]),
                'active_sites' => (array)($siteUsageMap[$stylesheet] ?? []),
                'active_sites_truncated' => !empty($siteUsageTruncated[$stylesheet]),
                'author' => (string)$theme->get('Author'),
                'author_url' => (string)$theme->get('AuthorURI'),
                'theme_uri' => (string)$theme->get('ThemeURI'),
                'text_domain' => (string)$theme->get('TextDomain'),
                'template' => (string)$theme->get_template(),
                'screenshot' => is_string($screenshot) ? $screenshot : '',
                'is_block_theme' => method_exists($theme, 'is_block_theme') ? (bool)$theme->is_block_theme() : false,
                'tags' => is_array($tags) ? $this->normalizeStringList($tags) : [],
            ];
            $themeData['status'] = $this->getThemeStatus($themeData);
            $results[] = $themeData;
        }

        usort($results, [self::class, 'compareThemeUsage']);

        return $results;
    }

    protected function getThemeUsage(): array {
        $themes = $this->getThemes();
        return $this->buildThemeUsageDistribution($themes);
    }

    protected function getInactiveThemes(): array {
        $themes = $this->getThemes();

        return array_values(
            array_filter(
                $themes,
                [self::class, 'isUnusedTheme']
            )
        );
    }
}
