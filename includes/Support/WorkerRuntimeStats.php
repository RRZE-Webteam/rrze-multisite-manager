<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/**
 * Records small, bounded diagnostics about heavy background executions.
 *
 * The executing process (normally the external Cron script) writes these
 * values itself. The Environment page can therefore show the real runtime
 * context of background work instead of the limits of the admin request that
 * renders the page. Recording is best effort: it never waits for a lock and
 * never affects admission or execution of the measured work.
 */
class WorkerRuntimeStats {
    public const OPTION = 'rrze_msm_worker_runtime_stats';
    protected const LOCK_KEY = 'worker_runtime_stats';
    protected const MAX_CONTEXTS = 10;
    protected const MAX_HOOKS = 40;

    public static function recordCompletion(string $hook, int $concurrent, float $seconds): void {
        self::record('completion', $hook, $concurrent, $seconds);
    }

    public static function recordDenial(string $hook): void {
        self::record('denial', $hook, 0, 0.0);
    }

    /** Name of the Cron hook or request type that is currently executing. */
    public static function currentHook(): string {
        $hook = function_exists('current_action') ? (string)current_action() : '';

        if ($hook !== '') {
            return $hook;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }

        return PHP_SAPI === 'cli' ? 'cli' : 'request';
    }

    /** @return array<string, mixed> */
    public static function get(): array {
        $stats = get_site_option(self::OPTION, []);
        $stats = is_array($stats) ? $stats : [];

        return [
            'since' => max(0, (int)($stats['since'] ?? 0)),
            'admissions' => max(0, (int)($stats['admissions'] ?? 0)),
            'denials' => max(0, (int)($stats['denials'] ?? 0)),
            'max_concurrent' => max(0, (int)($stats['max_concurrent'] ?? 0)),
            'max_concurrent_at' => max(0, (int)($stats['max_concurrent_at'] ?? 0)),
            'contexts' => is_array($stats['contexts'] ?? null) ? $stats['contexts'] : [],
            'hooks' => is_array($stats['hooks'] ?? null) ? $stats['hooks'] : [],
        ];
    }

    protected static function record(string $event, string $hook, int $concurrent, float $seconds): void {
        $token = '';

        try {
            $token = AtomicLock::acquire(self::LOCK_KEY);

            // A concurrent writer holds the lock: drop this sample instead of
            // waiting or overwriting the other writer's update.
            if ($token === '') {
                return;
            }

            $stats = self::get();
            $now = time();
            $hook = substr((string)preg_replace('/[^A-Za-z0-9_\-.:]/', '', $hook), 0, 100);
            $hook = $hook !== '' ? $hook : 'unknown';
            $hookStats = is_array($stats['hooks'][$hook] ?? null) ? $stats['hooks'][$hook] : [];

            if ($stats['since'] === 0) {
                $stats['since'] = $now;
            }

            if ($event === 'denial') {
                $stats['denials']++;
                $hookStats['denials'] = max(0, (int)($hookStats['denials'] ?? 0)) + 1;
                $hookStats['last_denial'] = $now;
            } else {
                $stats['admissions']++;
                $hookStats['runs'] = max(0, (int)($hookStats['runs'] ?? 0)) + 1;
                $hookStats['total_seconds'] = max(0.0, (float)($hookStats['total_seconds'] ?? 0.0)) + max(0.0, $seconds);
                $hookStats['max_seconds'] = max((float)($hookStats['max_seconds'] ?? 0.0), $seconds);
                $hookStats['max_memory'] = max((int)($hookStats['max_memory'] ?? 0), (int)memory_get_peak_usage(true));
                $hookStats['last_run'] = $now;

                if ($concurrent > $stats['max_concurrent']) {
                    $stats['max_concurrent'] = $concurrent;
                    $stats['max_concurrent_at'] = $now;
                }

                $stats['contexts'] = self::recordContext($stats['contexts'], $now);
            }

            $stats['hooks'][$hook] = $hookStats;
            $stats['hooks'] = self::limitEntries($stats['hooks'], self::MAX_HOOKS, 'compareHooksByActivity');
            update_site_option(self::OPTION, $stats);
        } catch (\Throwable $error) {
            // Diagnostics must never interrupt background work.
        } finally {
            if ($token !== '') {
                AtomicLock::release(self::LOCK_KEY, $token);
            }
        }
    }

    /**
     * @param array<string, mixed> $contexts
     * @return array<string, mixed>
     */
    protected static function recordContext(array $contexts, int $now): array {
        $host = function_exists('gethostname') ? (string)gethostname() : '';
        $host = $host !== '' ? substr($host, 0, 100) : 'unknown';
        $key = $host . '|' . PHP_SAPI;
        $context = is_array($contexts[$key] ?? null) ? $contexts[$key] : [];

        $contexts[$key] = [
            'host' => $host,
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
            'memory_limit' => (string)ini_get('memory_limit'),
            'max_execution_time' => (string)ini_get('max_execution_time'),
            'runs' => max(0, (int)($context['runs'] ?? 0)) + 1,
            'last_seen' => $now,
        ];

        return self::limitEntries($contexts, self::MAX_CONTEXTS, 'compareContextsByLastSeen');
    }

    /**
     * Keeps the most recently active entries of a bounded diagnostics list.
     *
     * @param array<string, mixed> $entries
     * @return array<string, mixed>
     */
    protected static function limitEntries(array $entries, int $limit, string $comparator): array {
        if (count($entries) <= $limit) {
            return $entries;
        }

        uasort($entries, [self::class, $comparator]);

        return array_slice($entries, 0, $limit, true);
    }

    protected static function compareContextsByLastSeen(array $first, array $second): int {
        return (int)($second['last_seen'] ?? 0) <=> (int)($first['last_seen'] ?? 0);
    }

    protected static function compareHooksByActivity(array $first, array $second): int {
        return max((int)($second['last_run'] ?? 0), (int)($second['last_denial'] ?? 0))
            <=> max((int)($first['last_run'] ?? 0), (int)($first['last_denial'] ?? 0));
    }
}
