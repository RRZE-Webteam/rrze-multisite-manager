<?php

namespace RRZE\MultisiteManager\Analysis;

defined('ABSPATH') || exit;

/** Capture diagnostics before a rollback replaces wpdb's last error. Never log SQL or payloads. */
class AnalysisStorageException extends \RuntimeException {
    public static function raise(string $operation, int $siteId = 0): never {
        global $wpdb;
        $error = (string)($wpdb->last_error ?? '');
        $reason = self::classify($error);
        $context = [
            'operation' => $operation,
            'reason' => $reason,
            'network_id' => get_current_network_id(),
            'site_id' => $siteId,
            'error_fingerprint' => $error !== '' ? substr(hash('sha256', $error), 0, 12) : '',
        ];
        do_action('rrze.log.error', 'RRZE-MSM: Analysis persistence failed', $context);
        throw new self(sprintf(
            /* translators: 1: internal operation identifier, 2: database error category. */
            __('Analysis data could not be read or saved (operation: %1$s; reason: %2$s). See the error log.', 'rrze-multisite-manager'),
            $operation,
            $reason
        ));
    }

    public static function classify(string $error): string {
        $error = strtolower($error);
        foreach ([
            'unknown column' => 'missing-column',
            "doesn't exist" => 'missing-table',
            'syntax' => 'sql-syntax',
            'ambiguous' => 'ambiguous-column',
            'incorrect string' => 'invalid-encoding',
            'invalid data' => 'invalid-encoding',
            'data too long' => 'column-size',
            'max_allowed_packet' => 'packet-size',
            'deadlock' => 'deadlock',
            'lock wait timeout' => 'lock-timeout',
            'denied' => 'permission-denied',
            'duplicate entry' => 'duplicate-key',
            'gone away' => 'connection-lost',
            'lost connection' => 'connection-lost',
            'binary log' => 'binary-log',
        ] as $needle => $reason) {
            if (str_contains($error, $needle)) {
                return $reason;
            }
        }
        return $error === '' ? 'validation-failed' : 'database-error';
    }
}
