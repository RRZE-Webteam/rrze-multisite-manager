<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Owns stable transient-key construction for storage-analysis process state.
 */
class StorageAnalysisStateService {
    public function getAnalysisCacheKey(int $siteId, int $cacheVersion): string {
        return 'rrze_msm_site_storage_analysis_v3_' . $cacheVersion . '_' . $siteId;
    }

    public function getBaseStateKey(int $siteId, int $cacheVersion): string {
        return 'rrze_msm_site_storage_analysis_base_state_' . $cacheVersion . '_' . $siteId;
    }

    public function getOrphanStateKey(int $siteId, int $cacheVersion): string {
        return 'rrze_msm_site_storage_analysis_orphan_state_' . $cacheVersion . '_' . $siteId;
    }

    public function getAttachmentIndexKey(int $siteId, int $cacheVersion): string {
        return 'rrze_msm_site_storage_attachment_index_v2_' . $cacheVersion . '_' . $siteId;
    }

    public function getMediaMetadataCacheKey(int $siteId, int $cacheVersion): string {
        return 'rrze_msm_site_media_metadata_analysis_' . $cacheVersion . '_' . $siteId;
    }

    public function clearSiteProcessStates(int $siteId, int $cacheVersion): bool {
        if ($siteId <= 0 || !get_site($siteId)) {
            return false;
        }

        switch_to_blog($siteId);

        try {
            $this->clearCurrentRuntimeStates($siteId, $cacheVersion);
        } finally {
            restore_current_blog();
        }

        return true;
    }

    public function clearCurrentProcessStates(int $siteId, int $cacheVersion): void {
        delete_site_transient($this->getAnalysisCacheKey($siteId, $cacheVersion));
        $this->clearCurrentRuntimeStates($siteId, $cacheVersion);
    }

    private function clearCurrentRuntimeStates(int $siteId, int $cacheVersion): void {
        delete_site_transient($this->getBaseStateKey($siteId, $cacheVersion));
        delete_site_transient($this->getOrphanStateKey($siteId, $cacheVersion));
        delete_site_transient($this->getAttachmentIndexKey($siteId, $cacheVersion));
    }
}
