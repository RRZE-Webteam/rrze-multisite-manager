<?php

namespace RRZE\MultisiteManager\Filesystem;

defined('ABSPATH') || exit;

class Path {
    public function normalizeRelative(string $path): string {
        $normalized = trim(wp_normalize_path($path), '/');

        if ($normalized === '') {
            return '';
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return $normalized;
    }

    public function toAbsolute(string $baseDirectory, string $relativePath): string {
        if ($baseDirectory === '') {
            return '';
        }

        $baseDirectory = trailingslashit(wp_normalize_path($baseDirectory));
        $relativePath = $this->normalizeRelative($relativePath);

        return $relativePath === '' ? '' : $baseDirectory . $relativePath;
    }
}
