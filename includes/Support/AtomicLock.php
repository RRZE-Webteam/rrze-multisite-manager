<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/** Connection-owned locks; no TTL can evict a worker that is still executing. */
class AtomicLock {
    private static array $owners = [];
    private static bool $shutdownRegistered = false;
    private static bool $multipleLocksVerified = false;

    private static function name(string $key, ?int $networkId = null): string {
        global $wpdb;
        return 'rrze_msm_' . hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->base_prefix . ':' . ($networkId ?? get_current_network_id()) . ':' . $key);
    }

    public static function acquire(string $key, ?int $networkId = null): string {
        global $wpdb;
        // MySQL permits recursive acquisition on the same connection. Do not
        // allow a second service instance in this request to bypass exclusion.
        $name = substr(self::name($key, $networkId), 0, 64);
        if (isset(self::$owners[$name])) {
            return '';
        }
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== 1) {
            return '';
        }
        // Older servers may silently drop the first named lock when a second
        // one is acquired. Detect that before nesting a process lock in a slot.
        if (!self::$multipleLocksVerified) {
            $probe = 'rrze_msm_probe_' . wp_generate_uuid4();
            $probeAcquired = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $probe)) === 1;
            $retained = (int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name)) === 1;
            if ($probeAcquired) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $probe));
            }
            if (!$probeAcquired || !$retained) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
                return '';
            }
            self::$multipleLocksVerified = true;
        }
        $token = wp_generate_uuid4();
        self::$owners[$name] = $token;
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'releaseAll']);
            self::$shutdownRegistered = true;
        }
        return $token;
    }

    public static function owns(string $key, string $token, ?int $networkId = null): bool {
        global $wpdb;
        $name = substr(self::name($key, $networkId), 0, 64);
        return $token !== '' && (self::$owners[$name] ?? '') === $token
            && (int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name)) === 1;
    }

    public static function release(string $key, string $token, ?int $networkId = null): bool {
        $name = substr(self::name($key, $networkId), 0, 64);
        if ($token === '' || (self::$owners[$name] ?? '') !== $token) {
            return false;
        }
        return self::releaseName($name);
    }

    private static function releaseName(string $name): bool {
        global $wpdb;
        // RELEASE_LOCK itself only releases locks owned by this connection.
        $released = (int)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)) === 1;
        unset(self::$owners[$name]);
        return $released;
    }

    public static function isLocked(string $key, ?int $networkId = null): bool {
        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', substr(self::name($key, $networkId), 0, 64)));
        // An unavailable lock service must never be interpreted as idle work.
        return $owner !== null || $wpdb->last_error !== '';
    }

    public static function releaseAll(): void {
        foreach (array_keys(self::$owners) as $name) {
            self::releaseName($name);
        }
    }
}
