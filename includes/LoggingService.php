<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class LoggingService {
    public static function info(Config $config, string $message, array $context = []): void {
        if (!self::isInfoLoggingEnabled($config)) {
            return;
        }

        do_action('rrze.log.info', $message, $context);
    }

    /**
     * Intermediate steps of background runs (phases, slices, queue handling).
     * Sent to the info channel only when info logging and the additional
     * fine-grained debugging option are both enabled.
     */
    public static function debug(Config $config, string $message, array $context = []): void {
        if (!self::isInfoLoggingEnabled($config) || !self::isFineGrainedDebuggingEnabled($config)) {
            return;
        }

        do_action('rrze.log.info', $message, $context);
    }

    public static function storageAnalysisError(array $context = []): void {
        do_action('rrze.log.error', 'RRZE-MSM: Fehler bei der Speicherplatzanalyse', $context);
    }

    /** Errors are always logged via rrze-log, independently of the debugging options. */
    public static function error(string $message, array $context = []): void {
        do_action('rrze.log.error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        do_action('rrze.log.warning', $message, $context);
    }

    protected static function isInfoLoggingEnabled(Config $config): bool {
        $options = get_site_option($config->getOptionName(), []);

        return is_array($options) && !empty($options['debugging_logging']);
    }

    protected static function isFineGrainedDebuggingEnabled(Config $config): bool {
        $options = get_site_option($config->getOptionName(), []);

        return is_array($options) && !empty($options['debugging_fine_grained']);
    }
}
