<?php

namespace RRZE\MultisiteManager\Support;

use RRZE\MultisiteManager\Config;

defined('ABSPATH') || exit;

/** Bounds executing heavy requests, not persisted analyses waiting for Cron. */
class WorkerBudget {
    private static array $reservations = [];
    private int $networkId = 0;
    private bool $acquired = false;

    public function acquire(): bool {
        if ($this->acquired) {
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
                self::$reservations[$this->networkId] = ['key' => $key, 'token' => $token, 'depth' => 1];
                $this->acquired = true;
                return true;
            }
        }
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
            unset(self::$reservations[$this->networkId]);
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
