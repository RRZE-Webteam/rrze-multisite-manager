<?php

namespace RRZE\MultisiteManager\Filesystem;

defined('ABSPATH') || exit;

class FileLocator {
    /** @param array<int, string> $paths */
    public function firstExisting(array $paths): string {
        foreach ($paths as $path) {
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }

        return '';
    }
}
