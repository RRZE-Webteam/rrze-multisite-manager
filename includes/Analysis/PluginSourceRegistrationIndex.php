<?php

namespace RRZE\MultisiteManager\Analysis;

use RRZE\MultisiteManager\Support\AtomicLock;

defined('ABSPATH') || exit;

/**
 * Builds a bounded, network-wide index of literal shortcode and block registrations.
 *
 * The cache is shared by all sites. A scan resumes from a directory cursor instead
 * of traversing the same plugin tree again for every site analysis.
 */
class PluginSourceRegistrationIndex {
    public const GENERATION_OPTION = 'rrze_msm_plugin_source_index_generation';
    protected const CACHE_VERSION = 1;
    protected const CACHE_TTL = 30 * DAY_IN_SECONDS;
    protected const MAX_FILES_PER_STEP = 100;
    protected const MAX_BYTES_PER_STEP = 4 * MB_IN_BYTES;
    protected const MAX_SECONDS_PER_STEP = 0.5;
    protected const MAX_TOTAL_FILES = 5000;
    protected const MAX_TOTAL_BYTES = 64 * MB_IN_BYTES;
    protected const MAX_DIRECTORIES = 2000;
    protected const MAX_FILE_BYTES = 1 * MB_IN_BYTES;

    /**
     * @param array<string, mixed> $pluginData
     * @return array{complete: bool, shortcodes: string[], blocks: string[], truncated: bool, files_scanned: int, bytes_read: int}
     */
    public function registrations(string $pluginFile, array $pluginData, float $requestDeadline): array {
        $mainFile = wp_normalize_path(trailingslashit(WP_PLUGIN_DIR) . ltrim($pluginFile, '/'));
        $root = $this->getScanRoot($pluginFile, $mainFile);

        if ($root === '' || !is_readable($mainFile)) {
            return $this->result([], true);
        }

        $cacheKey = $this->getCacheKey($pluginFile);
        $signature = $this->getSignature($pluginFile, $pluginData, $mainFile, $root);
        $state = get_site_transient($cacheKey);

        if (!is_array($state) || !hash_equals($signature, (string)($state['signature'] ?? ''))) {
            $state = $this->initialState($signature, $mainFile, $root);
        }

        if (!empty($state['complete'])) {
            return $this->result($state, true);
        }

        $lockKey = 'plugin_source_index_' . hash('sha256', $pluginFile);
        $owner = AtomicLock::acquire($lockKey);

        if ($owner === null) {
            return $this->result($state, false);
        }

        try {
            $freshState = get_site_transient($cacheKey);
            if (is_array($freshState) && hash_equals($signature, (string)($freshState['signature'] ?? ''))) {
                $state = $freshState;
            }

            if (empty($state['complete'])) {
                $state = $this->scanStep($state, $root, min($requestDeadline, microtime(true) + self::MAX_SECONDS_PER_STEP));
                set_site_transient($cacheKey, $state, self::CACHE_TTL);
            }
        } finally {
            AtomicLock::release($lockKey, $owner);
        }

        return $this->result($state, !empty($state['complete']));
    }

    public static function invalidate(): void {
        update_site_option(self::GENERATION_OPTION, wp_generate_uuid4());
    }

    protected function getScanRoot(string $pluginFile, string $mainFile): string {
        if (!is_file($mainFile)) {
            return '';
        }

        // A single-file plugin must never turn WP_PLUGIN_DIR into its scan root.
        return dirname($pluginFile) === '.' ? $mainFile : wp_normalize_path(dirname($mainFile));
    }

    protected function getCacheKey(string $pluginFile): string {
        return 'rrze_msm_plugin_source_' . substr(hash('sha256', $pluginFile), 0, 32);
    }

    /** @param array<string, mixed> $pluginData */
    protected function getSignature(string $pluginFile, array $pluginData, string $mainFile, string $root): string {
        $generation = (string)get_site_option(self::GENERATION_OPTION, '1');
        $rootMtime = is_dir($root) ? (int)@filemtime($root) : 0;

        return hash('sha256', serialize([
            self::CACHE_VERSION,
            $generation,
            $pluginFile,
            (string)($pluginData['Version'] ?? ''),
            (int)@filemtime($mainFile),
            (int)@filesize($mainFile),
            $rootMtime,
        ]));
    }

    /** @return array<string, mixed> */
    protected function initialState(string $signature, string $mainFile, string $root): array {
        $singleFile = is_file($root);

        return [
            'signature' => $signature,
            'complete' => false,
            'truncated' => false,
            'shortcodes' => [],
            'blocks' => [],
            'pending_directories' => $singleFile ? [] : [''],
            'current_directory' => '',
            'directory_offset' => 0,
            'single_file' => $singleFile ? $mainFile : '',
            'single_file_done' => false,
            'files_scanned' => 0,
            'bytes_read' => 0,
            'directories_seen' => 0,
        ];
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    protected function scanStep(array $state, string $root, float $deadline): array {
        $stepFiles = 0;
        $stepBytes = 0;

        if ((string)($state['single_file'] ?? '') !== '' && empty($state['single_file_done'])) {
            $this->scanFile((string)$state['single_file'], $state, $stepFiles, $stepBytes);
            $state['single_file_done'] = true;
            $state['complete'] = true;
            return $state;
        }

        while ($stepFiles < self::MAX_FILES_PER_STEP && $stepBytes < self::MAX_BYTES_PER_STEP && microtime(true) < $deadline) {
            if ((int)($state['files_scanned'] ?? 0) >= self::MAX_TOTAL_FILES
                || (int)($state['bytes_read'] ?? 0) >= self::MAX_TOTAL_BYTES
                || (int)($state['directories_seen'] ?? 0) >= self::MAX_DIRECTORIES) {
                $state['truncated'] = true;
                $state['complete'] = true;
                break;
            }

            $relativeDirectory = (string)($state['current_directory'] ?? '');
            if ($relativeDirectory === '') {
                $relativeDirectory = (string)array_shift($state['pending_directories']);
                if ($relativeDirectory === '' && empty($state['pending_directories']) && !is_dir($root)) {
                    $state['complete'] = true;
                    break;
                }
                $state['current_directory'] = $relativeDirectory === '' ? '.' : $relativeDirectory;
                $state['directory_offset'] = 0;
                $state['directories_seen'] = (int)($state['directories_seen'] ?? 0) + 1;
            } elseif ($relativeDirectory === '.') {
                $relativeDirectory = '';
            }

            $directory = $relativeDirectory === '' ? $root : trailingslashit($root) . $relativeDirectory;
            if (!$this->scanDirectory($directory, $relativeDirectory, $state, $stepFiles, $stepBytes, $deadline)) {
                break;
            }

            $state['current_directory'] = '';
            $state['directory_offset'] = 0;
            if (empty($state['pending_directories'])) {
                $state['complete'] = true;
                break;
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function scanDirectory(string $directory, string $relativeDirectory, array &$state, int &$stepFiles, int &$stepBytes, float $deadline): bool {
        if (!is_dir($directory) || !is_readable($directory)) {
            $state['truncated'] = true;
            return true;
        }

        try {
            $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
            $offset = max(0, (int)($state['directory_offset'] ?? 0));
            if ($offset > 0) {
                $iterator->seek($offset);
            }
            $position = $offset;

            while ($iterator->valid()) {
                if ($stepFiles >= self::MAX_FILES_PER_STEP || $stepBytes >= self::MAX_BYTES_PER_STEP || microtime(true) >= $deadline) {
                    return false;
                }

                $entry = $iterator->current();
                $position++;
                $state['directory_offset'] = $position;
                $iterator->next();

                if (!$entry instanceof \SplFileInfo) {
                    continue;
                }
                if ($entry->isLink()) {
                    $state['truncated'] = true;
                    continue;
                }

                $name = $entry->getFilename();
                $relativePath = ltrim(($relativeDirectory !== '' ? $relativeDirectory . '/' : '') . $name, '/');
                if ($entry->isDir()) {
                    if (count((array)$state['pending_directories']) < self::MAX_DIRECTORIES) {
                        $state['pending_directories'][] = $relativePath;
                    } else {
                        $state['truncated'] = true;
                    }
                    continue;
                }

                if (!$entry->isFile() || (strtolower($entry->getExtension()) !== 'php' && strtolower($name) !== 'block.json')) {
                    continue;
                }

                $size = (int)@filesize($entry->getPathname());
                if ($stepBytes > 0 && $size > 0 && $stepBytes + $size > self::MAX_BYTES_PER_STEP) {
                    $state['directory_offset'] = max(0, $position - 1);
                    return false;
                }

                $this->scanFile(wp_normalize_path($entry->getPathname()), $state, $stepFiles, $stepBytes);
            }
        } catch (\UnexpectedValueException | \OutOfBoundsException $exception) {
            $state['truncated'] = true;
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    protected function scanFile(string $file, array &$state, int &$stepFiles, int &$stepBytes): void {
        $size = is_readable($file) ? (int)@filesize($file) : 0;
        $stepFiles++;
        $state['files_scanned'] = (int)($state['files_scanned'] ?? 0) + 1;

        if ($size <= 0 || $size > self::MAX_FILE_BYTES || $stepBytes + $size > self::MAX_BYTES_PER_STEP
            || (int)($state['bytes_read'] ?? 0) + $size > self::MAX_TOTAL_BYTES) {
            $state['truncated'] = true;
            return;
        }

        $source = file_get_contents($file);
        if (!is_string($source)) {
            $state['truncated'] = true;
            return;
        }

        $stepBytes += strlen($source);
        $state['bytes_read'] = (int)($state['bytes_read'] ?? 0) + strlen($source);
        $matches = [];

        if (strtolower(pathinfo($file, PATHINFO_BASENAME)) === 'block.json') {
            $block = json_decode($source, true);
            $name = is_array($block) ? sanitize_text_field((string)($block['name'] ?? '')) : '';
            if ($name !== '' && str_contains($name, '/')) {
                $state['blocks'][$name] = $name;
            }
            return;
        }

        if (preg_match_all('/\badd_shortcode\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/m', $source, $matches)) {
            foreach ((array)($matches[1] ?? []) as $tag) {
                $tag = substr((string)$tag, 0, 191);
                if ($tag !== '' && !preg_match('/[\x00-\x20<>\[\]&\/]/', $tag)) {
                    $state['shortcodes'][$tag] = $tag;
                }
            }
        }

        if (preg_match_all('/\bregister_block_type\s*\(\s*[\'\"]([^\'\"]+\/[A-Za-z0-9._-]+)[\'\"]/m', $source, $matches)) {
            foreach ((array)($matches[1] ?? []) as $blockName) {
                $blockName = sanitize_text_field((string)$blockName);
                if ($blockName !== '') {
                    $state['blocks'][$blockName] = $blockName;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array{complete: bool, shortcodes: string[], blocks: string[], truncated: bool, files_scanned: int, bytes_read: int}
     */
    protected function result(array $state, bool $complete): array {
        return [
            'complete' => $complete,
            'shortcodes' => array_values((array)($state['shortcodes'] ?? [])),
            'blocks' => array_values((array)($state['blocks'] ?? [])),
            'truncated' => !empty($state['truncated']),
            'files_scanned' => (int)($state['files_scanned'] ?? 0),
            'bytes_read' => (int)($state['bytes_read'] ?? 0),
        ];
    }
}
