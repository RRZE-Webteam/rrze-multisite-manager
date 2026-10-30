<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

trait MetricsServiceThemeTrait {

    public function getThemeDetails(string $stylesheet, bool $includeSourceAnalysis = false): array {
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

        $themeItem = $this->hydrateThemeRuntimeState($themeItem);

        $cacheKey = $this->getThemeDetailsCacheKey($stylesheet, $includeSourceAnalysis);
        $cached = get_site_transient($cacheKey);

        if (is_array($cached) && !empty($cached)) {
            return $this->hydrateThemeRuntimeState($cached);
        }

        $supplementary = $this->getThemeSupplementaryData($stylesheet);
        $analysis = $includeSourceAnalysis ? $this->analyzeThemeCode($stylesheet) : [];
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
                'source_analysis_completed' => $includeSourceAnalysis,
                'shortcodes' => (array)($analysis['shortcodes'] ?? []),
                'blocks' => (array)($analysis['blocks'] ?? []),
                'block_patterns' => (array)($analysis['block_patterns'] ?? []),
                'image_sizes' => (array)($analysis['image_sizes'] ?? []),
                'provided_hooks' => (array)($analysis['provided_hooks'] ?? []),
                'installation_date_label' => $installTimestamp > 0 ? $this->formatTimestamp($installTimestamp) : __('Not available.', 'rrze-multisite-manager'),
                'last_release_date_label' => $modifiedTimestamp > 0 ? $this->formatTimestamp($modifiedTimestamp) : __('Not available.', 'rrze-multisite-manager'),
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
        $defaultThemeStylesheet = $this->getWordPressDefaultThemeStylesheet();
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
                'is_default_theme' => $stylesheet === $defaultThemeStylesheet,
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

    /**
     * Adds live theme state to the cached dashboard payload.
     *
     * Themes are installed globally in a multisite. The dashboard payload is
     * intentionally cached, but it must not conceal a newly installed theme
     * merely because it is not network-enabled yet. The costly per-site usage
     * aggregation remains cached; newly discovered themes start with no cached
     * usage until the next dashboard refresh completes.
     */
    protected function hydrateDashboardThemeRuntimeState(array $dashboardData): array {
        $defaultThemeStylesheet = $this->getWordPressDefaultThemeStylesheet();
        $allowedThemes = $this->getAllowedThemes();
        $installedThemes = wp_get_themes();
        $themes = [];
        $knownStylesheets = [];
        $stylesheet = '';
        $installedTheme = null;

        foreach ((array)($dashboardData['themes'] ?? []) as $theme) {
            if (!is_array($theme)) {
                continue;
            }

            $stylesheet = (string)($theme['stylesheet'] ?? '');

            // Do not retain themes that have been removed since the cache was
            // generated. WordPress returns all installed themes here,
            // independently of their network availability.
            if ($stylesheet === '' || !isset($installedThemes[$stylesheet])) {
                continue;
            }

            $theme['network_enabled'] = isset($allowedThemes[$stylesheet]);
            $themes[] = $this->hydrateThemeRuntimeState($theme, $defaultThemeStylesheet, $allowedThemes);
            $knownStylesheets[$stylesheet] = true;
        }

        foreach ($installedThemes as $stylesheet => $installedTheme) {
            if (isset($knownStylesheets[$stylesheet]) || !$installedTheme instanceof \WP_Theme) {
                continue;
            }

            $themes[] = $this->createUncachedThemeData(
                (string)$stylesheet,
                $installedTheme,
                $allowedThemes,
                $defaultThemeStylesheet
            );
        }

        usort($themes, [self::class, 'compareThemeUsage']);
        $dashboardData['themes'] = $themes;

        return $dashboardData;
    }

    /** Builds a display item for a theme discovered after the last metrics run. */
    protected function createUncachedThemeData(
        string $stylesheet,
        \WP_Theme $theme,
        array $allowedThemes,
        string $defaultThemeStylesheet
    ): array {
        $description = (string)$theme->get('Description');
        $screenshot = $theme->get_screenshot();
        $tags = $theme->get('Tags');
        $themeData = [
            'stylesheet' => $stylesheet,
            'name' => $theme->get('Name') ?: $stylesheet,
            'version' => $theme->get('Version') ?: 'n/a',
            'description' => wp_strip_all_tags($description),
            'site_count' => 0,
            'network_enabled' => isset($allowedThemes[$stylesheet]),
            'is_default_theme' => $stylesheet === $defaultThemeStylesheet,
            'active_sites' => [],
            'active_sites_truncated' => false,
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

        return $themeData;
    }

    /** Adds the live WordPress default-theme marker and its status badge. */
    protected function hydrateThemeRuntimeState(
        array $theme,
        ?string $defaultThemeStylesheet = null,
        ?array $allowedThemes = null
    ): array {
        $defaultThemeStylesheet = $defaultThemeStylesheet ?? $this->getWordPressDefaultThemeStylesheet();
        $allowedThemes = $allowedThemes ?? $this->getAllowedThemes();
        $stylesheet = (string)($theme['stylesheet'] ?? '');

        $theme['is_default_theme'] = $defaultThemeStylesheet !== ''
            && $stylesheet === $defaultThemeStylesheet;
        $theme['network_enabled'] = $stylesheet !== '' && isset($allowedThemes[$stylesheet]);
        $theme['status'] = $this->getThemeStatus($theme);

        return $theme;
    }

    /** Returns the installed default theme configured in RRZE Settings or WordPress. */
    protected function getWordPressDefaultThemeStylesheet(): string {
        $settings = get_site_option('rrze_settings', []);
        $generalSettings = is_array($settings)
            ? ($settings['general'] ?? [])
            : (is_object($settings) ? ($settings->general ?? []) : []);
        $configuredTheme = is_array($generalSettings)
            ? (string)($generalSettings['default_theme'] ?? '')
            : (is_object($generalSettings) ? (string)($generalSettings->default_theme ?? '') : '');

        if ($configuredTheme === '' && defined('WP_DEFAULT_THEME')) {
            $configuredTheme = (string)constant('WP_DEFAULT_THEME');
        }

        if ($configuredTheme === '' || preg_match('/^[A-Za-z0-9._-]+$/', $configuredTheme) !== 1) {
            return '';
        }

        $theme = wp_get_theme($configuredTheme);

        return $theme instanceof \WP_Theme && $theme->exists()
            ? (string)$theme->get_stylesheet()
            : '';
    }
}
