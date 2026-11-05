<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/** Keeps legacy status markers informational; only the DB lock grants access. */
trait ProcessLocks {
    private array $processLockTokens = [];

    protected function acquireProcessLock(string $key, mixed $marker = null, array $refreshOptions = []): string {
        $token = AtomicLock::acquire($key);
        if ($token !== '') {
            $this->processLockTokens[$key] = $token;
            update_site_option($key, $marker ?? time());
            // Initialization/status rendering may have cached an older cursor
            // before this request obtained exclusive access to the process.
            if ($refreshOptions) {
                $network = get_current_network_id();
                wp_cache_delete($network . ':notoptions', 'site-options');
                foreach ($refreshOptions as $option) {
                    wp_cache_delete($network . ':' . $option, 'site-options');
                }
            }
        }
        return $token;
    }

    protected function releaseProcessLock(string $key, string $token = ''): void {
        $ownedToken = $this->processLockTokens[$key] ?? '';
        if ($ownedToken === '' || ($token !== '' && $token !== $ownedToken)) {
            return;
        }
        if (AtomicLock::owns($key, $ownedToken)) {
            delete_site_option($key);
        }
        AtomicLock::release($key, $ownedToken);
        unset($this->processLockTokens[$key]);
    }
}
