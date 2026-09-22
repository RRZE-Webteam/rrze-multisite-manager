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

    public static function storageAnalysisError(array $context = []): void {
        do_action('rrze.log.error', 'RRZE-MSM: Fehler bei der Speicherplatzanalyse', $context);
    }

    public static function warning(string $message, array $context = []): void {
        do_action('rrze.log.warning', $message, $context);
    }

    protected static function isInfoLoggingEnabled(Config $config): bool {
        $options = get_site_option($config->getOptionName(), []);

        return is_array($options) && !empty($options['debugging_logging']);
    }
}
