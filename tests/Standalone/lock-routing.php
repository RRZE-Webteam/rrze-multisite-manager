<?php

/**
 * Named-lock statements are forced to the primary server when a database
 * drop-in such as HyperDB or LudicrousDB routes reads to replicas.
 *
 * Run: php tests/Standalone/lock-routing.php
 */

declare(strict_types=1);

// Standalone CLI test: no direct web access, never inside a WordPress request.
if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('ABSPATH', __DIR__ . '/');
} else {
    exit(1);
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test output, never rendered as HTML.
        throw new RuntimeException($message);
    }
}

function get_current_network_id(): int {
    return 1;
}

function wp_generate_uuid4(): string {
    return bin2hex(random_bytes(16));
}

/** Records the routing flag that was active for every statement. */
class ReplicaAwareDatabase {
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public $srtm = [];
    public array $seen = [];
    public array $locks = [];

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace('%s', "'%s'", $query), $args);
    }

    public function get_var(string $query) {
        $this->seen[] = [$query, $this->srtm];
        if (preg_match("/GET_LOCK\\('([^']+)'/", $query, $match)) {
            if (isset($this->locks[$match[1]])) {
                return '0';
            }
            $this->locks[$match[1]] = true;
            return '1';
        }
        if (preg_match("/RELEASE_LOCK\\('([^']+)'/", $query, $match)) {
            $held = isset($this->locks[$match[1]]);
            unset($this->locks[$match[1]]);
            return $held ? '1' : '0';
        }
        if (preg_match("/IS_USED_LOCK\\('([^']+)'\\) = CONNECTION_ID/", $query, $match)) {
            return isset($this->locks[$match[1]]) ? '1' : '0';
        }
        if (preg_match("/IS_USED_LOCK\\('([^']+)'/", $query, $match)) {
            return isset($this->locks[$match[1]]) ? '7' : null;
        }
        return null;
    }
}

/** Core wpdb has no routing flag. */
class PlainDatabase {
    public string $base_prefix = 'wp_';
}

require_once dirname(__DIR__, 2) . '/includes/Support/AtomicLock.php';

use RRZE\MultisiteManager\Support\AtomicLock;

$GLOBALS['wpdb'] = new PlainDatabase();
check(!AtomicLock::supportsPrimaryRouting(), 'Primary routing was assumed for the core database class.');

$GLOBALS['wpdb'] = new ReplicaAwareDatabase();
check(AtomicLock::supportsPrimaryRouting(), 'A public srtm flag was not detected.');
$token = AtomicLock::acquire('routing');
check($token !== '', 'Lock was not acquired.');
check(AtomicLock::owns('routing', $token), 'Lock ownership was not confirmed.');
check(AtomicLock::isLocked('routing'), 'Held lock was reported as free.');
check(AtomicLock::release('routing', $token), 'Lock was not released.');

foreach ($GLOBALS['wpdb']->seen as $entry) {
    check($entry[1] === true, 'A lock statement was not routed to the primary server: ' . $entry[0]);
}

check($GLOBALS['wpdb']->srtm === [], 'The previous routing state was not restored.');
check(AtomicLock::query('SELECT NOW()', 'x') === null, 'A statement outside the allowlist was executed.');

echo "PASS: named-lock statements are routed to the primary server and the previous routing state is restored.\n";
