<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Creates a safe, filtered iterator for a site's uploads directory.
 */
class StorageUploadScannerService {
    /**
     * @return \RecursiveIteratorIterator<\SplFileInfo>|null
     */
    public function createIterator(string $baseDir, array $excludedTopLevelDirectories = []): ?\RecursiveIteratorIterator {
        $normalizedBaseDir = trailingslashit(wp_normalize_path($baseDir));

        try {
            $directoryIterator = new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS);
            $filteredIterator = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                function ($current) use ($normalizedBaseDir, $excludedTopLevelDirectories): bool {
                    if (!$current instanceof \SplFileInfo) {
                        return false;
                    }

                    $pathname = wp_normalize_path((string)$current->getPathname());
                    $relativePath = ltrim(substr($pathname, strlen($normalizedBaseDir)), '/');
                    return !$this->isExcludedRelativePath($relativePath, $excludedTopLevelDirectories);
                }
            );

            return new \RecursiveIteratorIterator(
                $filteredIterator,
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function isExcludedRelativePath(string $relativePath, array $excludedTopLevelDirectories): bool {
        $normalizedRelativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($normalizedRelativePath === '' || empty($excludedTopLevelDirectories)) {
            return false;
        }

        $firstSegment = strtok($normalizedRelativePath, '/');

        return is_string($firstSegment)
            && $firstSegment !== ''
            && in_array($firstSegment, $excludedTopLevelDirectories, true);
    }
}
