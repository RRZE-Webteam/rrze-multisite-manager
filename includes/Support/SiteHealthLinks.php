<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/**
 * Direct links into the Info tab of the WordPress Site Health screen.
 *
 * Core renders all Info sections as collapsed accordions without an anchor
 * that opens them. A link built here carries the section key in an own
 * query argument; on the Site Health screen a small script opens exactly that
 * section, scrolls to it and moves the focus to its heading button.
 *
 * Section keys are those of WP_Debug_Data::debug_data(). In multisite core
 * omits "wp-paths-sizes", so it is not offered.
 */
class SiteHealthLinks {
    public const QUERY_ARG = 'rrze-msm-section';
    public const SCRIPT_HANDLE = 'rrze-multisite-manager-site-health-link';
    public const SECTIONS = [
        'wp-core',
        'wp-dropins',
        'wp-active-theme',
        'wp-mu-plugins',
        'wp-plugins-active',
        'wp-media',
        'wp-server',
        'wp-database',
        'wp-constants',
        'wp-filesystem',
    ];

    public static function register(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueueSectionOpener']);
    }

    /**
     * Whether the current user may open Site Health on the main site. In
     * multisite core maps view_site_health_checks to super admins.
     */
    public static function canView(): bool {
        return current_user_can('view_site_health_checks');
    }

    /**
     * URL of the Info tab of the main site's Site Health screen, optionally
     * opening one section. Returns '' when the user may not view it.
     */
    public static function getUrl(string $section = ''): string {
        $args = ['tab' => 'debug'];

        if (!self::canView()) {
            return '';
        }

        if (in_array($section, self::SECTIONS, true)) {
            $args[self::QUERY_ARG] = $section;
        }

        return add_query_arg($args, get_admin_url(get_main_site_id(), 'site-health.php'));
    }

    /**
     * Link rows for the given sections, in the given order.
     *
     * @param array<string, string> $sections Section key => link label
     * @return array<int, array{label: string, url: string}>
     */
    public static function getLinks(array $sections): array {
        $links = [];
        $section = '';
        $label = '';
        $url = '';

        foreach ($sections as $section => $label) {
            $url = self::getUrl((string)$section);

            if ($url !== '') {
                $links[] = ['label' => (string)$label, 'url' => $url];
            }
        }

        return $links;
    }

    public static function getRequestedSection(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
        $section = isset($_GET[self::QUERY_ARG]) ? sanitize_key((string)wp_unslash($_GET[self::QUERY_ARG])) : '';

        return in_array($section, self::SECTIONS, true) ? $section : '';
    }

    public static function enqueueSectionOpener(string $hookSuffix): void {
        $section = '';

        if ($hookSuffix !== 'site-health.php') {
            return;
        }

        $section = self::getRequestedSection();

        if ($section === '') {
            return;
        }

        wp_register_script(self::SCRIPT_HANDLE, false, [], false, true);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, self::getSectionOpenerScript($section));
    }

    /**
     * Runs on window load, after the Site Health script has bound its
     * accordion handler. If the click does not open the panel, the panel is
     * opened directly.
     */
    public static function getSectionOpenerScript(string $section): string {
        return 'function rrzeMsmOpenSiteHealthSection() {' . "\n"
            . '    var section = ' . wp_json_encode($section) . ';' . "\n"
            . '    var trigger = document.querySelector(\'button.health-check-accordion-trigger[aria-controls="health-check-accordion-block-\' + section + \'"]\');' . "\n"
            . '    var panel = document.getElementById(\'health-check-accordion-block-\' + section);' . "\n"
            . '    if (!trigger || !panel) {' . "\n"
            . '        return;' . "\n"
            . '    }' . "\n"
            . '    if (trigger.getAttribute(\'aria-expanded\') !== \'true\') {' . "\n"
            . '        trigger.click();' . "\n"
            . '    }' . "\n"
            . '    if (panel.hasAttribute(\'hidden\')) {' . "\n"
            . '        panel.removeAttribute(\'hidden\');' . "\n"
            . '        trigger.setAttribute(\'aria-expanded\', \'true\');' . "\n"
            . '    }' . "\n"
            . '    trigger.scrollIntoView({block: \'start\'});' . "\n"
            . '    trigger.focus({preventScroll: true});' . "\n"
            . '}' . "\n"
            . 'window.addEventListener(\'load\', rrzeMsmOpenSiteHealthSection);';
    }
}
