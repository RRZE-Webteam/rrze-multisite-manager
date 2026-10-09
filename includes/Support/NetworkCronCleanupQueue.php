<?php

namespace RRZE\MultisiteManager\Support;

use RRZE\MultisiteManager\Config;

defined('ABSPATH') || exit;

/** Processes explicit network-wide Cron cleanup requests in bounded batches. */
class NetworkCronCleanupQueue {
    private const LEASE_SECONDS = 15 * MINUTE_IN_SECONDS;

    private Config $config;

    public function __construct(?Config $config = null) {
        $this->config = $config ?? new Config();
    }

    public function start(string $stateOption, string $hook): array {
        $state = get_site_option($stateOption, []);

        if (is_array($state) && !empty($state['running'])) {
            $this->schedule($hook);
            return $state;
        }

        $state = [
            'running' => true,
            'offset' => 0,
            'total' => (int)get_sites(['count' => true, 'number' => 1]),
            'processed' => 0,
            'removed' => 0,
            'failed' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ];
        update_site_option($stateOption, $state);
        $this->schedule($hook);

        return $state;
    }

    /** Starts a browser-driven cleanup without creating a Cron event. */
    public function startAjax(string $stateOption): array {
        $state = get_site_option($stateOption, []);

        if (is_array($state) && !empty($state['running'])) {
            $updatedAt = max(0, (int)($state['updated_at'] ?? $state['started_at'] ?? 0));
            if (($state['mode'] ?? '') !== 'ajax' || ($updatedAt > 0 && (time() - $updatedAt) < self::LEASE_SECONDS)) {
                return $state;
            }
        }

        $state = [
            'running' => true,
            'mode' => 'ajax',
            'run_id' => wp_generate_uuid4(),
            'offset' => 0,
            'total' => (int)get_sites(['count' => true, 'number' => 1]),
            'processed' => 0,
            'removed' => 0,
            'failed' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ];
        update_site_option($stateOption, $state);

        return $state;
    }

    /** Runs one browser-requested cleanup batch and never schedules Cron. */
    public function runAjax(string $stateOption, string $runId, callable $removeCurrentSite, callable $complete): array {
        $state = get_site_option($stateOption, []);

        if (!is_array($state) || empty($state['running']) || ($state['mode'] ?? '') !== 'ajax' || !hash_equals((string)($state['run_id'] ?? ''), $runId)) {
            return [];
        }

        $offset = max(0, (int)($state['offset'] ?? 0));
        $siteIds = get_sites(['fields' => 'ids', 'number' => $this->config->getNetworkCronCleanupBatchSize(), 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            $switched = $siteId > 0 && $siteId !== get_current_blog_id();
            if ($switched) {
                switch_to_blog($siteId);
            }
            try {
                $state['removed'] = (int)($state['removed'] ?? 0) + max(0, (int)$removeCurrentSite());
            } catch (\Throwable $exception) {
                $state['failed'] = (int)($state['failed'] ?? 0) + 1;
                do_action('rrze.log.error', 'RRZE-MSM: Scheduled task cleanup failed', ['site_id' => $siteId, 'message' => $exception->getMessage()]);
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
        }

        $state['processed'] = $offset + count($siteIds);
        $state['offset'] = $state['processed'];
        $state['updated_at'] = time();
        $state['complete'] = empty($siteIds) || (int)$state['processed'] >= (int)($state['total'] ?? 0);
        if ($state['complete']) {
            $state['running'] = false;
            $state['completed_at'] = time();
            $complete($state);
        }
        update_site_option($stateOption, $state);

        return $state;
    }

    public function run(string $stateOption, string $hook, callable $removeCurrentSite, callable $complete): void {
        $state = get_site_option($stateOption, []);

        if (!is_array($state) || empty($state['running'])) {
            return;
        }

        $offset = max(0, (int)($state['offset'] ?? 0));
        $siteIds = get_sites(['fields' => 'ids', 'number' => $this->config->getNetworkCronCleanupBatchSize(), 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($siteId <= 0) {
                continue;
            }

            $switched = $siteId !== get_current_blog_id();

            if ($switched) {
                switch_to_blog($siteId);
            }

            try {
                $state['removed'] = (int)($state['removed'] ?? 0) + max(0, (int)$removeCurrentSite());
            } catch (\Throwable $exception) {
                $state['failed'] = (int)($state['failed'] ?? 0) + 1;
                do_action('rrze.log.error', 'RRZE-MSM: Scheduled task cleanup failed', ['site_id' => $siteId, 'message' => $exception->getMessage()]);
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
        }

        $state['processed'] = $offset + count($siteIds);
        $state['offset'] = $state['processed'];
        $state['updated_at'] = time();

        if (empty($siteIds) || (int)$state['processed'] >= (int)($state['total'] ?? 0)) {
            $state['running'] = false;
            $state['completed_at'] = time();
            update_site_option($stateOption, $state);
            $complete($state);
            return;
        }

        update_site_option($stateOption, $state);
        $this->schedule($hook);
    }

    /**
     * A stale queue retains its lock but asks WordPress to retry its own Cron
     * event. This prevents a failed request from silently re-enabling the
     * analysis jobs before their removal was completed.
     */
    public function isRunning(string $stateOption, string $hook): bool {
        $state = get_site_option($stateOption, []);

        if (!is_array($state) || empty($state['running'])) {
            return false;
        }

        if (($state['mode'] ?? '') === 'ajax') {
            $updatedAt = max(0, (int)($state['updated_at'] ?? $state['started_at'] ?? 0));
            if ($updatedAt > 0 && (time() - $updatedAt) >= self::LEASE_SECONDS) {
                $state['running'] = false;
                $state['abandoned_at'] = time();
                update_site_option($stateOption, $state);
                return false;
            }

            return true;
        }

        $updatedAt = max(0, (int)($state['updated_at'] ?? $state['started_at'] ?? 0));

        if ($updatedAt <= 0 || (time() - $updatedAt) >= self::LEASE_SECONDS) {
            $state['retry_requested_at'] = time();
            update_site_option($stateOption, $state);
            $this->schedule($hook);
        }

        return true;
    }

    private function schedule(string $hook): void {
        $networkId = get_current_network_id();
        $siteId = function_exists('get_main_site_id') ? (int)get_main_site_id($networkId) : 1;

        if ($siteId > 0 && $siteId !== get_current_blog_id()) {
            switch_to_blog($siteId);
            try {
                if (!wp_next_scheduled($hook)) {
                    wp_schedule_single_event(time() + 5, $hook);
                }
            } finally {
                restore_current_blog();
            }
            return;
        }

        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + 5, $hook);
        }
    }
}
