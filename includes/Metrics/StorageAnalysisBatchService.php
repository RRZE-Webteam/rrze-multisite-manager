<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Coordinates resumable base and orphan storage-analysis batches.
 */
class StorageAnalysisBatchService {
    /**
     * @param callable(): array<string, mixed> $getStatus
     * @param callable(): array<string, mixed> $initialize
     * @param callable(array<string, mixed>): array<string, mixed> $process
     * @param callable(array<string, mixed>): void $finalize
     */
    public function runBase(
        int $siteId,
        bool $restart,
        string $baseStateKey,
        string $orphanStateKey,
        int $ttl,
        callable $getStatus,
        callable $initialize,
        callable $process,
        callable $finalize
    ): array {
        if ($restart) {
            delete_site_transient($baseStateKey);
            delete_site_transient($orphanStateKey);
        }

        $state = get_site_transient($baseStateKey);

        if (!is_array($state) || empty($state) || $restart) {
            $state = $initialize();

            if (($state['status'] ?? '') === 'error') {
                set_site_transient($baseStateKey, $state, $ttl);

                return [
                    'success' => false,
                    'message' => (string)($state['message'] ?? ''),
                    'status' => $getStatus(),
                ];
            }
        }

        if (($state['status'] ?? '') !== 'running') {
            return [
                'success' => true,
                'message' => (string)($state['message'] ?? ''),
                'status' => $getStatus(),
            ];
        }

        $state = $process($state);

        if (empty($state['queue_directories']) && empty($state['queue_files']) && ($state['status'] ?? '') === 'running') {
            $finalize($state);
        } else {
            set_site_transient($baseStateKey, $state, $ttl);
        }

        return [
            'success' => true,
            'message' => (string)($state['message'] ?? ''),
            'status' => $getStatus(),
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @param callable(): array<string, mixed> $getStatus
     * @param callable(array<string, mixed>): array<string, mixed> $initialize
     * @param callable(array<string, mixed>): array<string, mixed> $process
     * @param callable(array<string, mixed>): bool $isComplete
     * @param callable(array<string, mixed>): void $finalize
     */
    public function runOrphan(
        bool $restart,
        array $analysis,
        string $orphanStateKey,
        int $ttl,
        callable $getStatus,
        callable $initialize,
        callable $process,
        callable $isComplete,
        callable $finalize
    ): array {
        if (empty($analysis)) {
            return [
                'success' => false,
                'message' => __('The base analysis must be completed before the orphan check can run.', 'rrze-multisite-manager'),
                'status' => $getStatus(),
            ];
        }

        if ($restart) {
            delete_site_transient($orphanStateKey);
        }

        $state = get_site_transient($orphanStateKey);

        if (!is_array($state) || empty($state) || $restart) {
            $state = $initialize($analysis);
        }

        if (($state['status'] ?? '') !== 'running') {
            return [
                'success' => true,
                'message' => (string)($state['message'] ?? ''),
                'status' => $getStatus(),
            ];
        }

        $state = $process($state);

        if ($isComplete($state)) {
            $finalize($state);
        } else {
            set_site_transient($orphanStateKey, $state, $ttl);
        }

        return [
            'success' => true,
            'message' => (string)($state['message'] ?? ''),
            'status' => $getStatus(),
        ];
    }
}
