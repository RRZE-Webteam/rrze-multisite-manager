<?php

namespace RRZE\MultisiteManager\SiteOptions;

defined('ABSPATH') || exit;

/**
 * Classifies WordPress options for site-management interfaces.
 */
class OptionClassifier {
    /** @param callable(string): bool $isCoreOption */
    public function __construct(protected $isCoreOption) {
    }

    public function getGroupKey(string $optionName): string {
        if ($this->isCoreOption($optionName)) {
            return 'wordpress-core';
        }

        if (str_starts_with($optionName, 'theme_mods_')) {
            return 'theme_mods';
        }

        if (str_starts_with($optionName, 'widget_') || str_starts_with($optionName, 'sidebars_')) {
            return 'widgets';
        }

        $segments = preg_split('/[_-]+/', ltrim($optionName, '_'));

        return is_array($segments) && !empty($segments[0]) ? sanitize_key((string)$segments[0]) : 'misc';
    }

    public function isCoreOption(string $optionName): bool {
        $isCoreOption = $this->isCoreOption;

        return (bool)$isCoreOption($optionName);
    }
}
