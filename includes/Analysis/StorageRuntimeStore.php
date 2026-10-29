<?php

namespace RRZE\MultisiteManager\Analysis;

defined('ABSPATH') || exit;

/** Adapts the existing bounded scanner segments to durable, run-scoped storage. */
class StorageRuntimeStore {
    private AnalysisStore $store;

    public function __construct() {
        $this->store = new AnalysisStore();
    }

    private function identity(string $key): array {
        if (preg_match('/^rrze_msm_site_storage_attachment_index_bucket_resumable_v2_(\d+)_([a-f0-9]{2})$/', $key, $match)) {
            return [(int)$match[1], 'storage_media', $match[2]];
        }
        if (preg_match('/^rrze_msm_site_storage_analysis_(base|orphan)_state_resumable_v2_(\d+)$/', $key, $match)) {
            return [(int)$match[2], 'storage_' . $match[1], ''];
        }
        throw new \InvalidArgumentException('Invalid storage checkpoint key.');
    }

    public function read(string $key): array|false {
        [$siteId, $slot, $bucket] = $this->identity($key);
        $run = $this->store->active($siteId, $slot);
        if (!$run) {
            return false;
        }
        return $bucket !== '' ? ($this->store->readEntry('mediaindex', $siteId, $run->run_id, $bucket) ?? false) : $this->store->decode($run->payload);
    }

    public function save(string $key, array $state, int $ttl): void {
        [$siteId, $slot, $bucket] = $this->identity($key);
        if ($bucket === '') {
            $this->store->saveCheckpoint($siteId, $slot, $slot, $state, min($ttl, DAY_IN_SECONDS));
            return;
        }
        $run = $this->store->ensureRun($siteId, $slot, 'mediaindex', 7 * DAY_IN_SECONDS);
        $this->store->writeEntry('mediaindex', $siteId, $run->run_id, $bucket, $state);
        $this->store->saveCheckpoint($siteId, $slot, 'mediaindex', [], 7 * DAY_IN_SECONDS);
    }

    public function delete(string $key): void {
        [$siteId, $slot] = $this->identity($key);
        $this->store->retire($siteId, $slot);
    }

    public function saveUsage(int $siteId, array $ids): void {
        if (!$ids) {
            return;
        }
        $groups = [];
        foreach ($ids as $id => $used) {
            $groups['u' . sprintf('%02x', ((int)$id) % 256)][(int)$id] = true;
        }
        $run = $this->store->ensureRun($siteId, 'storage_media', 'mediaindex', 7 * DAY_IN_SECONDS);
        $resources = new \RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService();
        // Read/write one segment at a time; never materialize all previously stored usage IDs.
        foreach ($groups as $bucket => $additions) {
            $stored = $this->store->readEntry('mediaindex', $siteId, $run->run_id, $bucket);
            $resources->assertBucketPresent($siteId, $bucket, $stored !== null);
            $entries = (array)$stored + $additions;
            // The callback keeps admission and persistence under the existing budget mutex.
            $resources->writeIndexBucketGroup($siteId, [$bucket => $entries], function () use ($bucket, $entries, $siteId, $run): void {
                $this->store->writeEntry('mediaindex', $siteId, $run->run_id, $bucket, $entries);
            });
        }
    }

    public function isUsed(int $siteId, int $id): bool {
        $run = $this->store->active($siteId, 'storage_media');
        $bucket = 'u' . sprintf('%02x', $id % 256);
        $entries = $run ? $this->store->readEntry('mediaindex', $siteId, $run->run_id, $bucket) : null;
        (new \RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService())->assertBucketPresent($siteId, $bucket, $entries !== null);
        return !empty($entries[$id]);
    }

    public function clearSite(int $siteId): void {
        foreach (['storage_base', 'storage_orphan', 'storage_media', 'storage_metadata'] as $slot) {
            $this->store->retire($siteId, $slot);
        }
    }
}
