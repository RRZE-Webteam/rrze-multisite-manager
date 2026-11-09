<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/** Connection-owned locks; no TTL can evict a worker that is still executing. */
class AtomicLock {
    private static array $owners = [];
    private static bool $shutdownRegistered = false;
    private static bool $multipleLocksVerified = false;
    private const STATEMENTS = [
        'SELECT GET_LOCK(%s, 0)',
        'SELECT RELEASE_LOCK(%s)',
        'SELECT IS_USED_LOCK(%s)',
        'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()',
    ];

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
        if ((int)self::query('SELECT GET_LOCK(%s, 0)', $name) !== 1) {
            return '';
        }
        // Older servers may silently drop the first named lock when a second
        // one is acquired. Detect that before nesting a process lock in a slot.
        if (!self::$multipleLocksVerified) {
            $probe = 'rrze_msm_probe_' . wp_generate_uuid4();
            $probeAcquired = (int)self::query('SELECT GET_LOCK(%s, 0)', $probe) === 1;
            $retained = (int)self::query('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name) === 1;
            if ($probeAcquired) {
                self::query('SELECT RELEASE_LOCK(%s)', $probe);
            }
            if (!$probeAcquired || !$retained) {
                self::query('SELECT RELEASE_LOCK(%s)', $name);
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
            && (int)self::query('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name) === 1;
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
        $released = (int)self::query('SELECT RELEASE_LOCK(%s)', $name) === 1;
        unset(self::$owners[$name]);
        return $released;
    }

    public static function isLocked(string $key, ?int $networkId = null): bool {
        global $wpdb;
        $owner = self::query('SELECT IS_USED_LOCK(%s)', substr(self::name($key, $networkId), 0, 64));
        // An unavailable lock service must never be interpreted as idle work.
        return $owner !== null || $wpdb->last_error !== '';
    }

    /**
     * Runs one named-lock statement on the primary database server.
     *
     * Database drop-ins with replicas (HyperDB, LudicrousDB) route SELECT
     * statements to read servers. GET_LOCK, IS_USED_LOCK and RELEASE_LOCK are
     * SELECT statements, but a lock is only effective on one server and one
     * connection. While the statement runs, the drop-in's
     * send-reads-to-masters flag ($wpdb->srtm) is set, so every lock statement
     * of this request uses the same primary connection. The previous value is
     * restored afterwards, so other reads of the request are not affected.
     * With the core wpdb class nothing changes.
     */
    public static function query(string $statement, string $name): ?string {
        global $wpdb;
        $routed = self::supportsPrimaryRouting();
        $previous = $routed ? $wpdb->srtm : null;
        $value = null;

        if (!in_array($statement, self::STATEMENTS, true)) {
            return null;
        }

        if ($routed) {
            $wpdb->srtm = true;
        }

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed named-lock statement from the allowlist; the lock name is prepared. Locks must reach the primary server directly and must never be cached.
            $value = $wpdb->get_var($wpdb->prepare($statement, $name));
        } finally {
            if ($routed) {
                $wpdb->srtm = $previous;
            }
        }

        return $value === null ? null : (string)$value;
    }

    /** Whether the active database class routes reads and supports forcing the primary server. */
    public static function supportsPrimaryRouting(): bool {
        global $wpdb;

        if (!is_object($wpdb) || !property_exists($wpdb, 'srtm')) {
            return false;
        }

        return (new \ReflectionProperty($wpdb, 'srtm'))->isPublic();
    }

    public static function releaseAll(): void {
        foreach (array_keys(self::$owners) as $name) {
            self::releaseName($name);
        }
    }
}
