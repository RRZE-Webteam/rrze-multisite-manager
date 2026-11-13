<?php

namespace RRZE\MultisiteManager\Support;

use RRZE\MultisiteManager\Config;

defined('ABSPATH') || exit;

/** Bounds executing heavy requests, not persisted analyses waiting for Cron. */
class WorkerBudget {
    private static array $reservations = [];
    private int $networkId = 0;
    private bool $acquired = false;

    public function acquire(int $siteId = 0): bool {
        if ($this->acquired) {
            if ($siteId > 0) {
                self::setCurrentSite($siteId);
            }
            return true;
        }
        $this->networkId = get_current_network_id();
        if (isset(self::$reservations[$this->networkId])) {
            $reservation = self::$reservations[$this->networkId];
            if (!AtomicLock::owns($reservation['key'], $reservation['token'], $this->networkId)) {
                return false;
            }
            self::$reservations[$this->networkId]['depth']++;
            $this->acquired = true;
            return true;
        }
        $limit = (new Config())->getMaxConcurrentWorkers();
        for ($slot = 0; $slot < $limit; $slot++) {
            $key = 'background_worker_' . $slot;
            $token = AtomicLock::acquire($key, $this->networkId);
            if ($token !== '') {
                self::$reservations[$this->networkId] = [
                    'key' => $key,
                    'token' => $token,
                    'depth' => 1,
                    'hook' => WorkerRuntimeStats::currentHook(),
                    'started' => microtime(true),
                    'concurrent' => self::countOccupiedSlots($limit, $this->networkId),
                ];
                self::registerLiveWorker($this->networkId, $key, $token, $siteId);
                $this->acquired = true;
                return true;
            }
        }
        WorkerRuntimeStats::recordDenial(WorkerRuntimeStats::currentHook());
        return false;
    }

    public function release(): void {
        if (!$this->acquired) {
            return;
        }
        $this->acquired = false;
        if (--self::$reservations[$this->networkId]['depth'] === 0) {
            $reservation = self::$reservations[$this->networkId];
            AtomicLock::release($reservation['key'], $reservation['token'], $this->networkId);
            self::unregisterLiveWorker($this->networkId, $reservation['key'], $reservation['token']);
            unset(self::$reservations[$this->networkId]);
            WorkerRuntimeStats::recordCompletion(
                (string)$reservation['hook'],
                (int)$reservation['concurrent'],
                microtime(true) - (float)$reservation['started']
            );
        }
    }

    /** Number of occupied execution slots, including the caller's own slot. */
    public static function countOccupiedSlots(int $limit, ?int $networkId = null): int {
        $occupied = 0;

        for ($slot = 0; $slot < $limit; $slot++) {
            if (AtomicLock::isLocked('background_worker_' . $slot, $networkId)) {
                $occupied++;
            }
        }

        return $occupied;
    }

    /**
     * Associates the active worker in this PHP process with the website it is
     * currently handling. Network-wide jobs deliberately retain site ID 0.
     */
    public static function setCurrentSite(int $siteId): void {
        $networkId = get_current_network_id();
        $reservation = self::$reservations[$networkId] ?? [];
        $key = (string)($reservation['key'] ?? '');
        $token = (string)($reservation['token'] ?? '');

        if ($siteId <= 0 || $key === '' || $token === '') {
            return;
        }

        $record = get_site_option(self::getLiveWorkerOptionName($key), []);
        if (!is_array($record) || (string)($record['token'] ?? '') !== $token) {
            return;
        }

        $record['site_id'] = $siteId;
        update_site_option(self::getLiveWorkerOptionName($key), $record);
    }

    /**
     * Returns only workers whose corresponding connection-owned slot is still
     * held. This makes stale records from interrupted PHP requests invisible.
     *
     * @return array<int, array{slot: string, hook: string, site_id: int, started_at: int, host: string}>
     */
    public static function getActiveWorkers(int $limit, ?int $networkId = null): array {
        $networkId = $networkId ?? get_current_network_id();
        $workers = [];

        for ($slot = 0; $slot < $limit; $slot++) {
            $key = 'background_worker_' . $slot;
            $optionName = self::getLiveWorkerOptionName($key);
            $record = get_site_option($optionName, []);

            if (!is_array($record) || (string)($record['slot'] ?? '') !== $key || (int)($record['started_at'] ?? 0) <= 0) {
                continue;
            }

            if (!AtomicLock::isLocked($key, $networkId)) {
                delete_site_option($optionName);
                continue;
            }

            $workers[] = [
                'slot' => $key,
                'hook' => (string)($record['hook'] ?? ''),
                'site_id' => max(0, (int)($record['site_id'] ?? 0)),
                'started_at' => max(0, (int)($record['started_at'] ?? 0)),
                'host' => (string)($record['host'] ?? ''),
            ];
        }

        return $workers;
    }

    private static function getLiveWorkerOptionName(string $key): string {
        return 'rrze_msm_live_worker_' . preg_replace('/[^a-z0-9_]/', '', strtolower($key));
    }

    private static function registerLiveWorker(int $networkId, string $key, string $token, int $siteId): void {
        $host = function_exists('gethostname') ? (string)gethostname() : '';
        $host = $host !== '' ? substr($host, 0, 100) : 'unknown';
        update_site_option(self::getLiveWorkerOptionName($key), [
            'token' => $token,
            'slot' => $key,
            'hook' => WorkerRuntimeStats::currentHook(),
            'site_id' => max(0, $siteId),
            'started_at' => time(),
            'host' => $host,
            'network_id' => $networkId,
        ]);
    }

    private static function unregisterLiveWorker(int $networkId, string $key, string $token): void {
        $optionName = self::getLiveWorkerOptionName($key);
        $record = get_site_option($optionName, []);

        if (is_array($record)
            && (string)($record['token'] ?? '') === $token
            && (int)($record['network_id'] ?? 0) === $networkId
        ) {
            delete_site_option($optionName);
        }
    }

    public static function clearRetries(string $hook): void {
        foreach ([false, true] as $continuation) {
            wp_clear_scheduled_hook($hook, ['rrze_msm_worker_retry' => $continuation]);
        }
    }

    /** Distinct arguments keep retries separate from distant recurring events. */
    public static function retry(string $hook, array $args): void {
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, $hook, $args);
        }
    }
}
