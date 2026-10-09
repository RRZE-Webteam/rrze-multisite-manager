<?php

namespace RRZE\MultisiteManager\Infrastructure;

defined('ABSPATH') || exit;

/**
 * Resolves and safely enters the main site of the current network.
 */
class MultisiteContext {
    public function getCentralSiteId(): int {
        $network = get_network();
        $networkId = $network instanceof \WP_Network ? (int)$network->id : get_current_network_id();
        $mainSiteId = function_exists('get_main_site_id')
            ? (int)get_main_site_id($networkId)
            : (int)($network->site_id ?? 1);

        return max(1, $mainSiteId);
    }

    public function isCentralSite(): bool {
        return get_current_blog_id() === $this->getCentralSiteId();
    }

    public function inCentralSite(callable $callback): mixed {
        $siteId = $this->getCentralSiteId();

        if ($siteId === get_current_blog_id()) {
            return $callback();
        }

        switch_to_blog($siteId);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }

    public function inSite(int $siteId, callable $callback): mixed {
        if ($siteId <= 0 || $siteId === get_current_blog_id()) {
            return $callback();
        }

        switch_to_blog($siteId);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }
}
