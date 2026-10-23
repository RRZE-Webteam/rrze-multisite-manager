<?php
/**
 * Isolated resource regressions. No WordPress installation or database is loaded.
 * Run: php tests/Standalone/storage-resources.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

if (defined('ABSPATH')) {
    throw new RuntimeException('Run this test in a separate PHP process, outside WordPress.');
}
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);

spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\MultisiteManager\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__, 2) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

// In-memory boundaries replace WordPress persistence, scheduling and display helpers.
$GLOBALS['test_options'] = [];
$GLOBALS['test_network_options'] = [];
$GLOBALS['test_transients'] = [];
$GLOBALS['test_events'] = [];
$GLOBALS['test_actions'] = [];
$GLOBALS['test_resource_mutex_acquisitions'] = 0;
$GLOBALS['test_blog'] = 1;
$GLOBALS['test_blog_stack'] = [];
$GLOBALS['test_uploads'] = '';
$GLOBALS['test_external_cache'] = false;
$GLOBALS['test_runtime_flushes'] = 0;
$GLOBALS['test_cache_deletions'] = [];
$GLOBALS['test_write_failure'] = '';
$GLOBALS['test_forbidden_reads'] = [];
$GLOBALS['test_forbidden_option_reads'] = [];
function __($message, $domain = '') { return $message; }
function _n($one, $many, $count, $domain = '') { return $count === 1 ? $one : $many; }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
function untrailingslashit($path) { return rtrim($path, '/\\'); }
function number_format_i18n($number, $decimals = 0) { return number_format($number, $decimals); }
function size_format($bytes) { return (string)$bytes . ' bytes'; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function get_sites($args = []) { check(($args['network_id'] ?? 0) === 1 && ($args['number'] ?? 0) === 25, 'Maintenance site traversal must be network-scoped and bounded.'); return array_slice($GLOBALS['test_maintenance_sites'] ?? [], $args['offset'], $args['number']); }
function get_current_blog_id() { return $GLOBALS['test_blog']; }
function get_current_network_id() { return 1; }
function get_main_site_id($network = null) { return 1; }
function switch_to_blog($id) { $GLOBALS['test_blog_stack'][] = $GLOBALS['test_blog']; $GLOBALS['test_blog'] = $id; }
function restore_current_blog() { $GLOBALS['test_blog'] = array_pop($GLOBALS['test_blog_stack']); }
function wp_get_upload_dir() { return ['basedir' => $GLOBALS['test_uploads'], 'baseurl' => 'https://example.org/uploads']; }
function is_multisite() { return true; }
function is_main_site() { return get_current_blog_id() === 1; }
class WP_Post { public int $ID = 7; public string $post_type = 'attachment'; public string $post_mime_type = 'application/pdf'; public string $post_title = 'Document'; public string $post_excerpt = ''; public string $post_content = ''; }
function get_post($id) { check($id === 7, 'Debug loaded an unrelated post.'); return new WP_Post(); }
function get_post_meta($id, $key, $single = false) { check($id === 7, 'Debug loaded unrelated metadata.'); return $key === '_wp_attached_file' ? 'document.pdf' : []; }
function get_post_types($args = [], $output = 'names') {
    check(in_array($args, [['public' => true], []], true) && $output === 'names', 'Reference scan requested unexpected post types.');
    $types = ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'event' => 'event'];
    return $args === [] ? $types + ['wp_template' => 'wp_template'] : $types;
}
function wp_parse_url($url, $component = -1) {
    return parse_url($url, $component);
}
function get_space_used() { throw new RuntimeException('A synchronous storage scan must not be started.'); }
function get_option($key, $default = false) { check(!isset($GLOBALS['test_forbidden_option_reads'][$key]), 'Oversized legacy option was loaded through WordPress.'); return $GLOBALS['test_options'][get_current_blog_id()][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { if ($key === $GLOBALS['test_write_failure']) { return false; } if (($GLOBALS['test_options'][get_current_blog_id()][$key] ?? null) === $value) { return false; } $GLOBALS['test_options'][get_current_blog_id()][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = true) {
    if ($key === $GLOBALS['test_write_failure'] || array_key_exists($key, $GLOBALS['test_options'][get_current_blog_id()] ?? [])) {
        return false;
    }
    $GLOBALS['test_options'][get_current_blog_id()][$key] = $value;
    if ($key === 'rrze_msm_storage_resource_mutex') {
        $GLOBALS['test_resource_mutex_acquisitions']++;
    }
    return true;
}
function delete_option($key) { unset($GLOBALS['test_options'][get_current_blog_id()][$key]); return true; }
function get_blog_option($id, $key, $default = false) { return $GLOBALS['test_options'][$id][$key] ?? $default; }
function update_blog_option($id, $key, $value) { $GLOBALS['test_options'][$id][$key] = $value; return true; }
function get_site_option($key, $default = false) { return $GLOBALS['test_network_options'][$key] ?? $default; }
function update_site_option($key, $value) { $GLOBALS['test_network_options'][$key] = $value; return true; }
function add_site_option($key, $value) { if (array_key_exists($key, $GLOBALS['test_network_options'])) { return false; } return update_site_option($key, $value); }
function delete_site_option($key) { unset($GLOBALS['test_network_options'][$key]); return true; }
function wp_using_ext_object_cache() { return $GLOBALS['test_external_cache']; }
function wp_cache_supports($feature) { return $feature === 'flush_runtime'; }
function wp_cache_flush_runtime() { $GLOBALS['test_runtime_flushes']++; return true; }
function wp_cache_delete($key, $group) { $GLOBALS['test_cache_deletions'][] = [$key, $group]; return true; }
function wp_rand($min, $max) { return $min; }
function absint($value) { return abs((int)$value); }
function get_transient($key) { check(!isset($GLOBALS['test_forbidden_reads'][$key]), 'Cleanup attempted to load an oversized stale value.'); return $GLOBALS['test_transients'][$key] ?? false; }
function get_site_transient($key) { return get_transient($key); }
function set_site_transient($key, $value, $ttl) { $GLOBALS['test_transients'][$key] = $value; return true; }
function delete_site_transient($key) { unset($GLOBALS['test_transients'][$key]); return true; }
function get_admin_url($id, $path = '') { return 'https://example.org/' . $id . '/wp-admin/' . $path; }
function get_home_url($id, $path = '') { return 'https://example.org/' . $id . $path; }
function get_edit_post_link($id, $context = '') { return 'https://example.org/wp-admin/post.php?post=' . $id; }
function get_permalink($id) {
    return 'https://example.org/post/' . $id;
}
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_convert_hr_to_bytes($value) { return (int)$value * (str_ends_with(strtolower($value), 'g') ? 1073741824 : (str_ends_with(strtolower($value), 'm') ? 1048576 : (str_ends_with(strtolower($value), 'k') ? 1024 : 1))); }
function wp_next_scheduled($hook, $args = []) { return $GLOBALS['test_events'][$hook][serialize($args)] ?? false; }
function wp_schedule_event($timestamp, $schedule, $hook, $args = []) { return wp_schedule_single_event($timestamp, $hook, $args); }
function wp_schedule_single_event($timestamp, $hook, $args = []) { $GLOBALS['test_events'][$hook][serialize($args)] = $timestamp; return true; }
function wp_clear_scheduled_hook($hook, $args = []) {
    unset($GLOBALS['test_events'][$hook][serialize($args)]);
    return 1;
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function do_action(...$args) { $GLOBALS['test_actions'][] = $args; }
function is_serialized($value) { return is_string($value) && preg_match('/^[abisOdN]:/', $value) === 1; }

function check(bool $condition, string $message): void {
    if (!$condition) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The standalone test reports its failure to the CLI, not to a browser.
        throw new RuntimeException($message);
    }
}

class ResourceMetrics extends RRZE\MultisiteManager\Metrics\MetricsImplementationService {
    public array $seen = [];
    public bool $recordOnly = true;
    public array $saved = [];
    public function __construct() {
        $this->config = new RRZE\MultisiteManager\Config();
        $this->path = new RRZE\MultisiteManager\Filesystem\Path();
        $this->storageResources = new RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService($this->config);
        $this->storageAnalysisState = new RRZE\MultisiteManager\Metrics\StorageAnalysisStateService();
        $this->storageUsage = new RRZE\MultisiteManager\Metrics\StorageUsageService();
        $this->storageUploadScanner = new RRZE\MultisiteManager\Metrics\StorageUploadScannerService();
        $this->serializedValue = new RRZE\MultisiteManager\Support\SafeSerializedValue();
    }
    public function initial(): array { return $this->initializeCurrentSiteStorageAnalysisBaseState(1); }
    public function process(array $state): array { return $this->processCurrentSiteStorageAnalysisBaseState($state); }
    public function finish(array &$state): void { $this->finalizeCurrentSiteStorageAnalysisBaseState(1, $state); }
    public function getCachedSiteStorageAnalysis(int $siteId): array { return []; }
    protected function saveCurrentSiteStorageAnalysisResult(array $result): void { $this->saved = $result; }
    public function directory(array &$state, int $limit = 250): int { return $this->processCurrentSiteStorageAnalysisDirectory($state, $limit); }
    public function stats(array $state): array { return $this->processCurrentSiteStorageAttachmentStats($state); }
    public function index(array $state): array { return $this->buildCurrentSiteStorageAnalysisAttachmentIndexBatch($state); }
    public function entry(array $state, string $path): array { return $this->getCurrentSiteStorageAnalysisAttachmentEntry($state, $path); }
    public function referencePostRows(int $cursor): array {
        return $this->getCurrentSiteAttachmentUsagePostRows($cursor);
    }
    public function referenceMetaRows(int $cursor): array {
        return $this->getCurrentSiteAttachmentUsageMetaRows($cursor);
    }
    public function initialOrphan(array $analysis): array {
        return $this->initializeCurrentSiteStorageAnalysisOrphanState($analysis);
    }
    public function processOrphan(array $state): array {
        return $this->processCurrentSiteStorageAnalysisOrphanState($state);
    }
    public function mediaPaths(string $value, array $state): array {
        return $this->addCurrentSiteMediaUsagePaths($value, 'https://example.org/uploads', [], $state);
    }
    public function mediaIds(string $value, array $paths): array {
        return $this->extractCurrentSiteAttachmentReferenceIds($value, $paths, 'https://example.org/uploads', true);
    }
    public function normalized(array $analysis): array {
        return $this->normalizeSiteStorageAnalysis($analysis);
    }
    public function unusedPage(array &$state): void {
        $this->processCurrentSiteUnusedAttachmentsBatch($state);
    }
    public function finishOrphan(array $analysis, array &$state): void {
        $this->finalizeCurrentSiteStorageAnalysisOrphanState(get_current_blog_id(), $analysis, $state);
    }
    public function cacheSize(): int { return count($this->currentSiteStorageAnalysisAttachmentIndexBucketCache); }
    public function initialStats(): array { return ['attachment_count' => 0, 'base_file_count' => 0, 'derived_variant_count' => 0, 'referenced_physical_file_count' => 0, 'media_types' => $this->getEmptyStorageAttachmentMediaTypes()]; }
    public function payload(array $scan): array { return $this->buildStorageAnalysisPayload('', '', ['used_bytes' => null], $scan, current_time('mysql', true)); }
    public function aggregate(array $storage): array {
        $state = [];
        $this->accumulateDashboardBatchStorageUsage($state, 1, 'Unknown', ['used_bytes' => null, 'max_bytes' => 100]);
        $this->accumulateDashboardBatchStorageUsage($state, 2, 'Known', $storage);
        return $this->finalizeDashboardBatchStorageUsage($state);
    }
    protected function getDetailCacheVersion(): int { return 123; }
    protected function getCurrentSiteRegisteredImageSizeSlugs(): array { return ['thumbnail' => true]; }
    protected function processCurrentSiteStorageAnalysisFile(array &$state, string $path): void {
        if ($this->recordOnly) { $this->seen[] = $path; return; }
        parent::processCurrentSiteStorageAnalysisFile($state, $path);
    }
    protected function searchCurrentSiteFileUsageMatches(string $url, string $path, int $id = 0): array { return []; }
    protected function getCurrentSiteUploadAttachmentStats(): array { throw new RuntimeException('Full attachment-statistics query is forbidden.'); }
    protected function getCurrentSiteReferencedUploadFiles(): array { throw new RuntimeException('Full attachment-index query is forbidden.'); }
}

final class MetadataProbe extends RRZE\MultisiteManager\Metrics\MediaMetadataAnalysisService {
    public function entry(object $row): array { return $this->buildEntry($row, ['alt' => false], 'images'); }
}

final class ReferenceRows {
    public string $posts = 'posts';
    public string $postmeta = 'postmeta';
    public array $queries = [];
    public function prepare(string $query, ...$args): string {
        $postTypes = str_contains($query, 'FROM posts p') ? array_slice($args, 0, 4) : array_slice($args, 1, 4);
        check($postTypes === ['post', 'page', 'event', 'wp_template'], 'Reference scan must include public content and templates while excluding attachments.');
        return vsprintf($query, $args);
    }
    public function get_results(string $query): array {
        $this->queries[] = $query;
        return [];
    }
}

final class OrphanRows {
    public string $posts = 'posts';
    public string $postmeta = 'postmeta';
    public string $options = 'options';
    public string $sitemeta = 'sitemeta';
    public string $referenceValue = '';
    public function prepare(string $query, ...$args): string {
        return vsprintf($query, $args);
    }
    public function query(string $query): int {
        preg_match_all('/_site_transient_[A-Za-z0-9_]+/', $query, $matches);
        foreach ($matches[0] as $key) {
            if (!str_starts_with($key, '_site_transient_timeout_')) {
                unset($GLOBALS['test_transients'][substr($key, strlen('_site_transient_'))]);
            }
        }
        return 0;
    }
    public function get_results(string $query): array {
        if (str_contains($query, "p.post_type = 'attachment'")) {
            if (str_contains($query, 'p.ID <')) {
                return str_contains($query, 'p.ID < 30') ? [] : [(object)['ID' => 30, 'post_mime_type' => 'application/pdf', 'attached_file' => 'new.pdf', 'path_bytes' => 7]];
            }
            return str_contains($query, 'p.ID > 0') ? [(object)['ID' => 30, 'post_mime_type' => 'application/pdf', 'attached_file' => 'new.pdf', 'attachment_metadata' => '', 'path_bytes' => 7, 'metadata_bytes' => 0]] : [];
        }
        if (str_contains($query, 'SELECT ID, post_type, post_title, post_content')) {
            return $this->referenceValue !== '' && str_contains($query, 'ID > 0')
                ? [(object)['ID' => 40, 'post_type' => 'post', 'post_title' => 'Test', 'post_content' => $this->referenceValue]]
                : [];
        }
        return [];
    }
}

final class AttachmentPageRows {
    public string $posts = 'posts';
    public string $postmeta = 'postmeta';
    public function prepare(string $query, ...$args): string {
        return vsprintf($query, $args);
    }
    public function get_results(string $query): array {
        preg_match('/p\.ID < (\d+)/', $query, $cursor);
        $rows = [];
        for ($id = min(252, (int)($cursor[1] ?? 0) - 1); $id >= 1 && count($rows) < 50; $id--) {
            $rows[] = (object)['ID' => $id, 'post_mime_type' => 'application/pdf', 'attached_file' => 'page-' . $id . '.pdf', 'path_bytes' => strlen('page-' . $id . '.pdf')];
        }
        return $rows;
    }
}

final class AttachmentRows {
    public string $posts = 'posts';
    public string $postmeta = 'postmeta';
    public string $sitemeta = 'sitemeta';
    public string $options = 'options';
    private array $parameters = [];
    public function get_row(string $query): ?object {
        check(str_contains($query, 'CASE WHEN OCTET_LENGTH(option_value)'), 'Result query did not gate payload bytes.');
        [$maximum, $key] = $this->parameters;
        $value = $GLOBALS['test_options'][get_current_blog_id()][$key] ?? null;
        if ($value === null) { return null; }
        $raw = serialize($value);
        return (object)['value_bytes' => strlen($raw), 'bounded_value' => strlen($raw) <= $maximum ? $raw : null];
    }
    public function query(string $query): int|false {
        if (str_starts_with($query, 'UPDATE options SET')) {
            [$raw, $key, $maximum] = $this->parameters;
            if ($GLOBALS['test_write_failure'] === $key) { return false; }
            if (strlen(serialize($GLOBALS['test_options'][get_current_blog_id()][$key] ?? [])) <= $maximum) { return 0; }
            $GLOBALS['test_options'][get_current_blog_id()][$key] = unserialize($raw, ['allowed_classes' => false]);
            return 1;
        }
        check(str_contains($query, 'DELETE FROM sitemeta WHERE site_id = 1 AND meta_key IN'), 'Cleanup query escaped its network/key scope.');
        preg_match_all('/_site_transient_[A-Za-z0-9_]+/', $query, $matches);
        foreach ($matches[0] as $key) {
            if (!str_starts_with($key, '_site_transient_timeout_')) { unset($GLOBALS['test_transients'][substr($key, strlen('_site_transient_'))]); }
        }
        return count($matches[0]);
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%'); }
    public function get_col(string $query): array {
        if (str_starts_with($query, 'SELECT option_name FROM options')) {
            [$first, $second, $resultLimit, $meta, $metaLimit] = $this->parameters;
            return array_values(array_filter([$first, $second, $meta], static fn($key) => strlen(serialize($GLOBALS['test_options'][get_current_blog_id()][$key] ?? [])) > ($key === $meta ? $metaLimit : $resultLimit)));
        }
        check(str_contains($query, 'WHERE site_id = 1') && str_contains($query, 'LIMIT 250') && !str_contains($query, 'SELECT meta_value'), 'Expired cleanup must fetch only bounded, network-scoped keys.');
        return [];
    }
    public array $rows = [];
    public function prepare(string $query, ...$args): string { $this->parameters = $args; return vsprintf($query, $args); }
    public function get_results(string $query): array {
        check((bool)preg_match('/p.ID > (\d+)/', $query, $match), 'Index query must use an ID cursor.');
        check((bool)preg_match('/LIMIT (\d+)/', $query, $limit), 'Index query must be paginated.');
        return array_slice(array_values(array_filter($this->rows, static fn($row) => $row->ID > (int)$match[1])), 0, (int)$limit[1]);
    }
}

final class SliceAnalysis extends RRZE\MultisiteManager\Metrics\StorageAnalysisService {
    public array $calls = ['base' => 0, 'orphan' => 0, 'metadata' => 0];
    public int $baseBatchesUntilComplete = 7;
    public int $batchDelayMicroseconds = 0;
    public array $metadataRestarts = [];
    public int $clears = 0;
    public bool $fail = false;
    public bool $busy = false;
    public bool $assertReserved = false;
    public function __construct() {}
    public function clearProcessStates(int $siteId): void { $this->clears++; }
    public function getProgressContext(int $siteId, string $phase): array { return []; }
    public function getProcessStatus(int $siteId): array { return []; }
    public function runBaseBatch(int $siteId, string $assignmentMode = 'batch'): array {
        if ($this->batchDelayMicroseconds > 0) { usleep($this->batchDelayMicroseconds); }
        if ($this->busy) { throw new RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException('Simulated occupied worker'); }
        if ($this->assertReserved) {
            $queue = get_site_option('rrze_msm_storage_analysis_continuation_queue', []);
            $status = get_blog_option($siteId, 'rrze_msm_storage_analysis_scheduler_status', []);
            check(($queue[$siteId] ?? '') === ($status['run_id'] ?? null), 'The next slice must be reserved before processing starts.');
        }
        if ($this->fail) { throw new RuntimeException('Simulated failure'); }
        return ['success' => true, 'status' => ['base' => ['status' => ++$this->calls['base'] >= $this->baseBatchesUntilComplete ? 'complete' : 'running']]];
    }
    public function runOrphanBatch(int $siteId, string $assignmentMode = 'batch'): array { return ['success' => true, 'status' => ['orphan' => ['status' => ++$this->calls['orphan'] >= 3 ? 'complete' : 'running']]]; }
    public function runMediaMetadataBatch(int $siteId, bool $restart): array {
        $this->metadataRestarts[] = $restart;
        return ['success' => true, 'analysis' => ['status' => ++$this->calls['metadata'] >= 2 ? 'complete' : 'running']];
    }
}

class SliceScheduler extends RRZE\MultisiteManager\StorageAnalysisSchedulerService {
    public function runSlice(int $id): void { $this->runSingleProcessAnalysis($id); }
    public function recover(int $id): bool { return $this->recoverInterruptedRun($id); }
    public function getStatus(int $id): array { return (array)get_blog_option($id, 'rrze_msm_storage_analysis_scheduler_status', []); }
    public function getSiteAssignmentMode(int $id): string { return 'site'; }
    public function isSiteEligible(int $id): bool { return true; }
    public function isScheduleMigrationInProgress(): bool { return false; }
    protected function isTaskRemovalInProgress(): bool { return false; }
    protected function isSiteSchedulingRemoved(int $id): bool { return parent::isSiteSchedulingRemoved($id); }
    protected function updateCompletedRunAssignment(int $id, float $duration): void {}
    protected function logSiteInfo(string $label, int $id, string $mode = 'unassigned', array $context = []): void {}
    protected function logStorageAnalysisError(int $id, string $phase, string $message, array $status = []): void {}
}

final class ExpiredDeadlineScheduler extends SliceScheduler {
    protected function getAnalysisRequestDeadline(): float { return microtime(true) - 1; }
}

final class RestartScheduler extends SliceScheduler {
    protected function unscheduleSite(int $siteId): void {
    }

    protected function scheduleIndividualAnalysisAt(int $siteId, int $timestamp): bool {
        return wp_schedule_single_event($timestamp, 'rrze_msm_run_site_storage_analysis', [$siteId, 'scheduled']);
    }
}

final class ReinitializeScheduler extends SliceScheduler {
    protected function getNextRecurringScheduledTimestamp(int $siteId): int {
        return 0;
    }

    protected function scheduleRecurringAnalysis(int $siteId, bool $prioritizeFirstRun = false): bool {
        return wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'test_shared_storage_batch', [$siteId]);
    }
}

final class ShortDeadlineScheduler extends SliceScheduler {
    protected function getAnalysisRequestDeadline(): float { return microtime(true) + 0.02; }
}

final class LockedSliceScheduler extends SliceScheduler {
    protected function acquireSiteLock(int $siteId): bool { return false; }
}

final class PausedSliceScheduler extends SliceScheduler {
    public function isScheduleMigrationInProgress(): bool { return true; }
}

final class BatchSliceScheduler extends RRZE\MultisiteManager\StorageAnalysisSchedulerService {
    public function getSiteAssignmentMode(int $id): string { return 'batch'; }
    public function isSiteEligible(int $id): bool { return true; }
    public function isScheduleMigrationInProgress(): bool { return false; }
    protected function isTaskRemovalInProgress(): bool { return false; }
    protected function isSiteSchedulingRemoved(int $id): bool { return parent::isSiteSchedulingRemoved($id); }
    protected function updateCompletedRunAssignment(int $id, float $duration): void {}
    protected function logSiteInfo(string $label, int $id, string $mode = 'unassigned', array $context = []): void {}
    protected function logStorageAnalysisError(int $id, string $phase, string $message, array $status = []): void {}
}

$temporary = sys_get_temp_dir() . '/msm-regression-' . bin2hex(random_bytes(8));
mkdir($temporary);
$GLOBALS['test_uploads'] = $temporary;
try {
    file_put_contents($temporary . '/new.pdf', 'unused media');
    foreach (['', 'https://example.org/uploads/new.pdf', 'https:\/\/example.org\/uploads\/new.pdf', 'wp-image-30', '[gallery ids="30"]'] as $referenceValue) {
        $GLOBALS['wpdb'] = new OrphanRows();
        $GLOBALS['wpdb']->referenceValue = $referenceValue;
        $metrics = new ResourceMetrics();
        $state = $metrics->initialOrphan([
            'upload_basedir' => $temporary,
            'upload_baseurl' => 'https://example.org/uploads',
            'largest_orphan_files' => [],
        ]);
        $steps = 0;
        do {
            if (($state['media_usage_stage'] ?? '') === 'references') {
                check((int)($metrics->entry($state, 'new.pdf')['attachment_id'] ?? 0) === 30, 'Media reference index lost the attachment path.');
                $paths = $metrics->mediaPaths('https://example.org/uploads/new.pdf', $state);
                check($paths === ['new.pdf' => 30], 'Media reference URL did not resolve to its attachment.');
                check($metrics->mediaIds('https://example.org/uploads/new.pdf', $paths) === [30], 'Media reference URL was not extracted.');
            }
            $state = $metrics->processOrphan($state);
            check(++$steps < 30, 'Media usage scan did not terminate: ' . json_encode([
                'stage' => $state['media_usage_stage'] ?? '',
                'reference_stage' => $state['attachment_usage_stage'] ?? '',
                'cursor' => $state['media_attachment_cursor'] ?? 0,
            ]));
        } while (($state['media_usage_stage'] ?? '') !== 'complete');
        check((int)$state['unused_attachment_file_count'] === ($referenceValue !== '' ? 0 : 1), 'Media library reference classification is incorrect.');
        check(count($state['unused_attachment_files']) === ($referenceValue !== '' ? 0 : 1), 'Unused media detail list is incorrect.');
        if ($referenceValue === '') {
            check(str_contains((string)($state['unused_attachment_files'][0]['media_edit_url'] ?? ''), 'post=30&action=edit'), 'Unused media entry has no attachment detail URL.');
        }
        $normalized = $metrics->normalized([
            'unused_attachment_files' => $state['unused_attachment_files'],
            'unused_attachment_file_count' => $state['unused_attachment_file_count'],
            'unused_attachment_total_bytes' => $state['unused_attachment_total_bytes'],
            'orphan_file_count' => 0,
            'orphan_total_bytes' => 0,
        ]);
        check((int)$normalized['unused_attachment_file_count'] === ($referenceValue !== '' ? 0 : 1), 'Normalization discarded unused media totals.');
        check(count($normalized['unused_attachment_files']) === ($referenceValue !== '' ? 0 : 1), 'Normalization discarded unused media rows.');
        $metrics->finishOrphan(['orphan_file_count' => 0, 'orphan_total_bytes' => 0], $state);
        check((int)($metrics->saved['unused_attachment_file_count'] ?? -1) === ($referenceValue !== '' ? 0 : 1), 'Completed analysis did not publish unused media totals.');
        check(count((array)($metrics->saved['unused_attachment_files'] ?? [])) === ($referenceValue !== '' ? 0 : 1), 'Completed analysis did not publish unused media rows.');
    }
    unlink($temporary . '/new.pdf');
    (new RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService())->clearSite(1);
    for ($id = 1; $id <= 252; $id++) {
        file_put_contents($temporary . '/page-' . $id . '.pdf', 'x');
    }
    $GLOBALS['wpdb'] = new AttachmentPageRows();
    $pagedMetrics = new ResourceMetrics();
    $pagedState = [
        'media_attachment_cursor' => 253,
        'media_attachment_processed' => 0,
        'used_attachment_ids' => [250 => true],
        'unused_attachment_file_count' => 0,
        'unused_attachment_total_bytes' => 0,
        'unused_attachment_files' => [],
        'normalized_base_dir' => trailingslashit($temporary),
        'upload_baseurl' => 'https://example.org/uploads',
    ];
    $pagedMetrics->unusedPage($pagedState);
    check(($pagedState['media_usage_stage'] ?? '') !== 'complete' && $pagedState['media_attachment_cursor'] === 203, 'First media page lost its cursor.');
    for ($page = 0; $page < 6 && ($pagedState['media_usage_stage'] ?? '') !== 'complete'; $page++) {
        $pagedMetrics->unusedPage($pagedState);
    }
    check($pagedState['media_usage_stage'] === 'complete' && $pagedState['media_attachment_processed'] === 252, 'Media scan did not finish after its final page.');
    check($pagedState['unused_attachment_file_count'] === 251 && count($pagedState['unused_attachment_files']) === 250, 'Media scan did not retain an exact count with a bounded preview.');
    foreach (range(1, 252) as $id) {
        unlink($temporary . '/page-' . $id . '.pdf');
    }

    foreach ([0, 249, 250, 251, 500, 501, 1001] as $count) {
        $directory = $temporary . '/files-' . $count;
        mkdir($directory);
        for ($i = 0; $i < $count; $i++) { touch($directory . '/file-' . $i); }
        $metrics = new ResourceMetrics();
        $state = ['normalized_base_dir' => $directory . '/', 'queue_directories' => [['path' => '.', 'offset' => 0]]];
        $steps = 0;
        while ($state['queue_directories']) {
            check($metrics->directory($state) <= 250, 'Directory step exceeded its entry budget.');
            check(++$steps < 20, 'Directory scan did not terminate.');
        }
        check(count($metrics->seen) === $count && count(array_unique($metrics->seen)) === $count, 'Files were skipped or processed twice at boundary ' . $count);
    }
    $metrics = new ResourceMetrics();
    $state = ['normalized_base_dir' => $temporary . '/files-501/', 'queue_directories' => [['path' => '.', 'offset' => 0]]];
    check($metrics->directory($state, 7) === 7, 'Remaining request budget must limit the directory step.');
    foreach (new FilesystemIterator($temporary . '/files-501') as $file) { unlink($file->getPathname()); }
    $metrics->directory($state);
    check($state['queue_directories'] === [], 'Shrinking directory should terminate without an uncaught seek exception.');

    $nested = $temporary . '/nested';
    mkdir($nested);
    mkdir($nested . '/sites');
    touch($nested . '/sites/excluded');
    for ($i = 0; $i < 251; $i++) { mkdir($nested . '/child-' . $i); touch($nested . '/child-' . $i . '/file'); }
    $metrics = new ResourceMetrics();
    $state = ['normalized_base_dir' => $nested . '/', 'queue_directories' => [['path' => '.', 'offset' => 0]], 'excluded_top_level_directories' => ['sites']];
    while ($state['queue_directories']) { $metrics->directory($state); }
    check(count($metrics->seen) === 251 && count(array_unique($metrics->seen)) === 251, 'Nested/main-site-excluded directories were not scanned correctly.');

    $usage = new RRZE\MultisiteManager\Metrics\StorageUsageService();
    check($usage->getCurrentSiteUsage()['used_bytes'] === null, 'Unknown usage must not be zero.');
    $GLOBALS['test_transients']['dirsize_cache'] = [$temporary => null];
    check($usage->getCurrentSiteUsage()['used_bytes'] === null, 'Core timeout must remain unknown.');
    $GLOBALS['test_transients']['dirsize_cache'] = [$temporary => 6543210123];
    check($usage->getCurrentSiteUsage()['used_bytes'] === 6543210123, 'Core snapshot must preserve exact bytes above 6 GB.');
    $GLOBALS['test_options'][1]['rrze_msm_site_storage_analysis_result_meta'] = ['actual_bytes' => 0, 'generated_at' => current_time('mysql', true)];
    unset($GLOBALS['test_transients']['dirsize_cache']);
    check($usage->getCurrentSiteUsage()['used_bytes'] === 0, 'A measured zero must remain a known value.');

    $referenceRows = new ReferenceRows();
    $GLOBALS['wpdb'] = $referenceRows;
    $referenceMetrics = new ResourceMetrics();
    check($referenceMetrics->referencePostRows(0) === [], 'Post reference scan did not run.');
    check($referenceMetrics->referenceMetaRows(0) === [], 'Metadata reference scan did not run.');
    check(count($referenceRows->queries) === 2, 'Both reference sources must be queried.');

    $wpdb = new AttachmentRows();
    $GLOBALS['wpdb'] = $wpdb;
    for ($id = 1; $id <= 251; $id++) {
        // All entries deliberately share a variant, which must only be counted once.
        $wpdb->rows[] = (object)['ID' => $id, 'post_mime_type' => 'image/jpeg', 'attached_file' => 'image-' . $id . '.jpg', 'attachment_metadata' => serialize(['file' => 'image-' . $id . '.jpg', 'sizes' => ['thumbnail' => ['file' => 'shared.jpg']], 'original_image' => 'original.jpg'])];
        file_put_contents($temporary . '/image-' . $id . '.jpg', '123');
    }
    file_put_contents($temporary . '/shared.jpg', '12');
    file_put_contents($temporary . '/original.jpg', '12345');
    $metrics = new ResourceMetrics();
    $state = ['site_id' => 1, 'normalized_base_dir' => $temporary . '/', 'attachment_stats' => $metrics->initialStats(), 'pending_index_buckets' => [], 'attachment_stats_bucket' => 0, 'attachment_stats_offset' => 0];
    $steps = 0;
    while (empty($state['attachment_index_complete'])) {
        $state = $metrics->index($state);
        check($metrics->cacheSize() === 0, 'Index construction retained all written buckets.');
        check(++$steps < 100, 'Index build did not terminate.');
    }
    while (empty($state['attachment_stats_complete'])) { $state = $metrics->stats($state); }
    check($state['attachment_stats']['attachment_count'] === 251, 'Attachment count did not survive index pagination.');
    check($state['attachment_stats']['base_file_count'] === 251, 'Base paths were not counted correctly.');
    check($state['attachment_stats']['derived_variant_count'] === 1, 'Shared variants must be deduplicated across batches.');
    check($state['attachment_stats']['referenced_physical_file_count'] === 253, 'Referenced paths were not deduplicated.');
    check($state['attachment_stats']['media_types']['images']['bytes'] === 760, 'Media sizes must include original and variant files exactly once.');
    $payload = $metrics->payload(['attachment_stats' => $state['attachment_stats'], 'referenced_file_count' => 253, 'total_bytes' => 760]);
    check($payload['attachment_stats']['attachment_count'] === 251, 'Final payload did not use the accumulated summary.');
    check($payload['difference_bytes'] === null, 'Missing comparison must remain unknown.');
    foreach ($wpdb->rows as $row) { $metrics->entry($state, $row->attached_file); check($metrics->cacheSize() <= 32, 'Index segment cache grew beyond 32 buckets.'); }
    check((bool)array_filter($GLOBALS['test_cache_deletions'], static fn($entry) => str_contains($entry[0], ':_site_transient_rrze_msm_site_storage_attachment_index_bucket_')), 'Database-backed segments stayed in the WordPress runtime cache.');
    $GLOBALS['test_external_cache'] = true;
    $deletedBefore = count($GLOBALS['test_cache_deletions']);
    $externalMetrics = new ResourceMetrics();
    foreach ($wpdb->rows as $row) { $externalMetrics->entry($state, $row->attached_file); }
    check($GLOBALS['test_runtime_flushes'] > 0 && count($GLOBALS['test_cache_deletions']) === $deletedBefore, 'External-cache handling must flush only runtime data, not delete persistent buckets.');
    $GLOBALS['test_external_cache'] = false;
    $aggregate = $metrics->aggregate(['used_bytes' => 20, 'max_bytes' => 100]);
    check($aggregate['unknown_site_count'] === 1 && $aggregate['percent'] === null, 'Unknown usage must suppress capacity percentages.');
    check(count($aggregate['items']) === 1, 'Unknown usage must not be shown as free capacity.');

    // Run the complete base pipeline against real files and paginated in-memory DB rows.
    $pipelineDirectory = $temporary . '/pipeline';
    mkdir($pipelineDirectory);
    $GLOBALS['test_uploads'] = $pipelineDirectory;
    foreach ($wpdb->rows as $row) { file_put_contents($pipelineDirectory . '/' . $row->attached_file, '123'); }
    file_put_contents($pipelineDirectory . '/shared.jpg', '12');
    file_put_contents($pipelineDirectory . '/original.jpg', '12345');
    $pipeline = new ResourceMetrics();
    $pipeline->recordOnly = false;
    $state = $pipeline->initial();
    $steps = 0;
    do {
        $state = $pipeline->process($state);
        check(++$steps < 200, 'Full base pipeline did not terminate.');
    } while (empty($state['attachment_stats_complete']) || empty($state['attachment_index_complete']) || $state['queue_directories']);
    $pipeline->finish($state);
    check($pipeline->saved['total_files'] === 253 && $pipeline->saved['actual_bytes'] === 760, 'Full scan produced incorrect totals.');
    check($pipeline->saved['attachment_stats']['media_types']['images']['bytes'] === 760, 'Finalization lost paginated statistics.');
    check($pipeline->cacheSize() <= 32, 'Base finalization retained more than the bounded index cache for orphan analysis.');
    $debug = $metrics->getSiteStorageAttachmentDebug(1, 7);
    check($debug['attachment_id'] === 7 && $debug['in_attachment_index'], 'Single-ID debug did not produce its local metadata.');
    (new RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService())->clearSite(1);

    $states = new RRZE\MultisiteManager\Metrics\StorageAnalysisStateService();
    check($states->getBaseStateKey(1, 10) === $states->getBaseStateKey(1, 11), 'Detail cache invalidation must not lose an active storage run.');
    check($states->getAttachmentIndexBucketKey(1, 10, 'aa') === $states->getAttachmentIndexBucketKey(1, 11, 'aa'), 'Index keys must remain stable during a run.');

    $analysis = new SliceAnalysis();
    $analysis->baseBatchesUntilComplete = 1020;
    $analysis->assertReserved = true;
    $scheduler = new SliceScheduler($analysis);
    $scheduler->runSlice(2);
    check(array_sum($analysis->calls) === 1000 && !empty($scheduler->getStatus(2)['is_running']), 'The first Cron slice must use its emergency cap and remain resumable.');
    $startedLogs = array_filter($GLOBALS['test_actions'], static fn($action) => ($action[0] ?? '') === 'rrze.log.info' && ($action[2]['event'] ?? '') === 'batch_started' && ($action[2]['site_id'] ?? 0) === 2);
    $completedLogs = array_filter($GLOBALS['test_actions'], static fn($action) => ($action[0] ?? '') === 'rrze.log.info' && ($action[2]['event'] ?? '') === 'batch_completed' && ($action[2]['site_id'] ?? 0) === 2);
    check(count($startedLogs) === 63 && count($completedLogs) === 63, 'A long slice must sample batch diagnostics without logging every step.');
    $expiredAnalysis = new SliceAnalysis();
    $expiredScheduler = new ExpiredDeadlineScheduler($expiredAnalysis);
    $expiredScheduler->runSlice(16);
    check($expiredAnalysis->calls['base'] === 1, 'An exhausted request deadline must still process the first batch.');
    check(!empty($expiredScheduler->getStatus(16)['is_running']), 'The bounded slice must retain its continuation.');
    unset($GLOBALS['test_network_options']['rrze_msm_storage_analysis_continuation_queue'][16]);
    $shortAnalysis = new SliceAnalysis();
    $shortAnalysis->baseBatchesUntilComplete = 1020;
    $shortAnalysis->batchDelayMicroseconds = 3000;
    (new ShortDeadlineScheduler($shortAnalysis))->runSlice(17);
    check($shortAnalysis->calls['base'] >= 1 && $shortAnalysis->calls['base'] < 1000, 'A slice must yield at its time budget before the emergency step cap.');
    unset($GLOBALS['test_network_options']['rrze_msm_storage_analysis_continuation_queue'][17]);
    $first = $scheduler->getStatus(2);
    check(!empty($first['is_running']) && !empty($first['run_id']), 'A partial run must persist its identity.');
    check(!$scheduler->recover(2), 'A pending continuation must not be treated as interrupted.');
    $oldRunId = $first['run_id'];
    $queueHook = 'rrze_msm_continue_storage_analysis';
    for ($request = 0; $request < 10 && !empty($scheduler->getStatus(2)['is_running']); $request++) {
        $before = array_sum($analysis->calls);
        unset($GLOBALS['test_events'][$queueHook]);
        $scheduler = new SliceScheduler($analysis); // Fresh service for the next request.
        if ($request === 0) {
            switch_to_blog(2); // A legacy continuation may be fired from a subsite's Cron table.
        }
        try {
            $scheduler->runAnalysisContinuation();
        } finally {
            if ($request === 0) {
                restore_current_blog();
            }
        }
        check(array_sum($analysis->calls) - $before <= 1000, 'A continuation exceeded its emergency step cap.');
        check(array_sum($analysis->calls) > $before, 'A subsite Cron continuation did not process its queued slice.');
    }
    check(empty($scheduler->getStatus(2)['is_running']), 'Resumed phases never completed.');
    check($analysis->calls === ['base' => 1020, 'orphan' => 3, 'metadata' => 2], 'Completed phases were restarted.');
    check($analysis->metadataRestarts === [true, false], 'Metadata phase must restart only once.');
    check($analysis->clears === 1, 'Continuation cleared progress instead of resuming it.');
    $waitingStatus = ['is_running' => true, 'run_id' => 'waiting-run', 'run_phase' => 'base', 'last_started_at' => current_time('mysql', true)];
    update_blog_option(15, 'rrze_msm_storage_analysis_scheduler_status', $waitingStatus);
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_continuation_queue'] = [15 => 'waiting-run'];
    unset($GLOBALS['test_events'][$queueHook]);
    (new LockedSliceScheduler(new SliceAnalysis()))->runAnalysisContinuation();
    $waitingStatus = get_blog_option(15, 'rrze_msm_storage_analysis_scheduler_status');
    check(!empty($waitingStatus['last_error']) && !empty($waitingStatus['last_attempt_at']), 'A locked continuation did not record why it skipped work.');
    unset($GLOBALS['test_events'][$queueHook]);
    (new PausedSliceScheduler(new SliceAnalysis()))->runAnalysisContinuation();
    check((int)wp_next_scheduled($queueHook) >= time() + MINUTE_IN_SECONDS - 1, 'Paused maintenance lost the continuation event.');
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_continuation_queue'] = [];
    update_blog_option(12, 'rrze_msm_storage_analysis_scheduler_status', ['last_was_aborted' => true, 'last_duration_seconds' => 42]);
    $busyAnalysis = new SliceAnalysis();
    $busyAnalysis->busy = true;
    $busyScheduler = new SliceScheduler($busyAnalysis);
    $busyScheduler->runSlice(12);
    $busyStatus = $busyScheduler->getStatus(12);
    check(empty($busyStatus['last_was_aborted']) && ($busyStatus['phases']['base']['status'] ?? '') === 'scheduled', 'A blocked new run must not display a previous abort or claim file-index progress.');
    check(($busyStatus['last_error'] ?? '') === 'Simulated occupied worker', 'The capacity reason was not saved.');
    $individualRetryHook = 'rrze_msm_run_site_storage_analysis';
    check((int)wp_next_scheduled($individualRetryHook, [12, 'scheduled', true]) >= time() + MINUTE_IN_SECONDS - 1, 'A blocked individual run has no near-term site Cron retry.');
    check(empty(get_site_option('rrze_msm_storage_analysis_continuation_queue', [])), 'An individual capacity wait kept occupying the central continuation queue.');
    $capacityWaitLogs = array_filter($GLOBALS['test_actions'], static fn($action) => ($action[0] ?? '') === 'rrze.log.info' && ($action[2]['event'] ?? '') === 'capacity_wait' && ($action[2]['site_id'] ?? 0) === 12);
    check(count($capacityWaitLogs) === 1, 'The first capacity wait did not record its retry in the info log.');
    $busyStatus['resource_wait_started_at'] = time() - DAY_IN_SECONDS - 301;
    update_blog_option(12, 'rrze_msm_storage_analysis_scheduler_status', $busyStatus);
    unset($GLOBALS['test_events'][$individualRetryHook][serialize([12, 'scheduled', true])]);
    $busyScheduler->runScheduledAnalysis(12, 'scheduled');
    $busyStatus = $busyScheduler->getStatus(12);
    check(empty($busyStatus['is_running']) && ($busyStatus['phases']['base']['status'] ?? '') === 'error', 'A permanently occupied worker left an endless running state.');
    check(str_contains((string)$busyStatus['last_error'], 'more than 24 hours'), 'An exhausted capacity wait still promises another retry.');
    check(empty(get_site_option('rrze_msm_storage_analysis_continuation_queue', [])), 'The failed run remained queued.');
    $recoveringAnalysis = new SliceAnalysis();
    $recoveringAnalysis->baseBatchesUntilComplete = 1020;
    $recoveringAnalysis->busy = true;
    $recoveringScheduler = new SliceScheduler($recoveringAnalysis);
    $recoveringScheduler->runSlice(13);
    $recoveringAnalysis->busy = false;
    check((int)wp_next_scheduled($individualRetryHook, [13, 'scheduled', true]) > 0, 'The recoverable individual run lost its retry.');
    $recoveringScheduler->recover(13);
    check(empty(get_site_option('rrze_msm_storage_analysis_continuation_queue', [])), 'Monitoring bypassed the individual retry backoff.');
    unset($GLOBALS['test_events'][$individualRetryHook][serialize([13, 'scheduled', true])]);
    $recoveringScheduler->runScheduledAnalysis(13, 'scheduled');
    $recoveringStatus = $recoveringScheduler->getStatus(13);
    check(!empty($recoveringStatus['is_running']) && empty($recoveringStatus['last_error']) && empty($recoveringStatus['resource_wait_started_at']), 'A successful retry kept the capacity error visible.');
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_continuation_queue'] = [];
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_batch_site_ids'] = [10, 11];
    $batchHook = 'rrze_msm_run_site_storage_analysis_batch';
    $GLOBALS['test_events'][$batchHook][serialize([])] = time() + DAY_IN_SECONDS;
    $batchAnalysis = new SliceAnalysis();
    $batchAnalysis->baseBatchesUntilComplete = 1020;
    $batchScheduler = new BatchSliceScheduler($batchAnalysis);
    $batchScheduler->runBatchScheduledAnalysis();
    check(!empty(get_blog_option(10, 'rrze_msm_storage_analysis_scheduler_status')['is_running']), 'The first batch site did not start.');
    check(empty(get_blog_option(11, 'rrze_msm_storage_analysis_scheduler_status')), 'The next batch site started before the active analysis finished.');
    check((int)get_site_option('rrze_msm_storage_analysis_batch_last_site_id') === 10, 'The batch cursor did not retain the active site.');
    $batchScheduler->runBatchScheduledAnalysis(true);
    check(empty(get_blog_option(11, 'rrze_msm_storage_analysis_scheduler_status')), 'A scheduled batch continuation ignored the active analysis.');
    for ($request = 0; $request < 10 && !empty(get_site_option('rrze_msm_storage_analysis_continuation_queue', [])); $request++) {
        unset($GLOBALS['test_events'][$queueHook]);
        $batchScheduler = new BatchSliceScheduler($batchAnalysis);
        $batchScheduler->runAnalysisContinuation();
    }
    check(empty(get_site_option('rrze_msm_storage_analysis_continuation_queue', [])), 'The active batch site did not finish.');
    check((int)wp_next_scheduled($batchHook, ['rrze_msm_storage_analysis_batch' => true]) > 0, 'The next batch site was not scheduled after completion.');
    $batchCursor = (int)get_site_option('rrze_msm_storage_analysis_batch_last_site_id', 0);
    $recurringBatchTimestamp = (int)wp_next_scheduled($batchHook);
    update_blog_option(10, 'rrze_msm_storage_analysis_scheduler_status', ['last_was_aborted' => true, 'last_error' => 'Previous batch failed']);
    $targetedAnalysis = new SliceAnalysis();
    $targetedAnalysis->baseBatchesUntilComplete = 1020;
    $targetedScheduler = new BatchSliceScheduler($targetedAnalysis);
    check($targetedScheduler->startAnalysisNow(10), 'An aborted shared-batch website could not be restarted on its own.');
    check((int)wp_next_scheduled($batchHook, [10, true]) > 0, 'The targeted shared-batch Cron event was not scheduled.');
    check((int)wp_next_scheduled($batchHook) === $recurringBatchTimestamp && (int)get_site_option('rrze_msm_storage_analysis_batch_last_site_id', 0) === $batchCursor, 'Targeted restart changed the shared recurring event or its cursor.');
    check(get_site_option('rrze_msm_storage_analysis_batch_site_ids', []) === [10, 11], 'Targeted restart changed other batch assignments.');
    check(empty($targetedScheduler->getStatus(10)['last_was_aborted']), 'Targeted restart retained the old abort.');
    unset($GLOBALS['test_events'][$batchHook][serialize([10, true])]); // WP-Cron consumes a single event before invoking its callback.
    update_site_option('rrze_msm_storage_analysis_batch_lock', ['started_at' => time(), 'token' => 'other-request']);
    $targetedScheduler->runBatchScheduledAnalysis(10, true);
    check((int)wp_next_scheduled($batchHook, [10, true]) >= time() + MINUTE_IN_SECONDS - 1, 'A busy shared batch discarded the targeted retry.');
    delete_site_option('rrze_msm_storage_analysis_batch_lock');
    unset($GLOBALS['test_events'][$batchHook][serialize([10, true])]);
    $targetedScheduler->runBatchScheduledAnalysis(10, true);
    check(!empty($targetedScheduler->getStatus(10)['is_running']) && (int)get_site_option('rrze_msm_storage_analysis_batch_last_site_id', 0) === $batchCursor, 'Targeted batch execution did not start independently.');
    for ($request = 0; $request < 10 && !empty(get_site_option('rrze_msm_storage_analysis_continuation_queue', [])); $request++) {
        unset($GLOBALS['test_events'][$queueHook]);
        $targetedScheduler = new BatchSliceScheduler($targetedAnalysis);
        $targetedScheduler->runAnalysisContinuation();
    }
    check(empty($targetedScheduler->getStatus(10)['is_running']) && $targetedAnalysis->calls === ['base' => 1020, 'orphan' => 3, 'metadata' => 2], 'Targeted batch restart did not complete all phases.');
    check(empty(get_blog_option(11, 'rrze_msm_storage_analysis_scheduler_status', [])), 'Targeted restart also analyzed another batch website.');
    check(!$targetedScheduler->startAnalysisNow(10), 'A healthy shared-batch website was restarted outside its scheduled run.');
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_removed_site_ids'] = [10];
    update_blog_option(10, 'rrze_msm_storage_analysis_scheduler_status', [
        'last_was_aborted' => false,
        'last_error' => 'Scheduling for this website has been removed.',
    ]);
    check($targetedScheduler->startAnalysisNow(10), 'A failed shared-batch website could not be restarted on its own.');
    check((int)wp_next_scheduled($batchHook, [10, true]) > 0, 'A failed shared-batch restart did not schedule its targeted Cron event.');
    check(empty(get_site_option('rrze_msm_storage_analysis_removed_site_ids', [])), 'A failed shared-batch restart retained the scheduling removal marker.');
    check(empty($targetedScheduler->getStatus(10)['last_error']), 'A failed shared-batch restart retained the old error.');
    check((int)wp_next_scheduled($batchHook) === $recurringBatchTimestamp && (int)get_site_option('rrze_msm_storage_analysis_batch_last_site_id', 0) === $batchCursor, 'A failed shared-batch restart changed the recurring event or cursor.');
    check(get_site_option('rrze_msm_storage_analysis_batch_site_ids', []) === [10, 11], 'A failed shared-batch restart changed other batch assignments.');
    unset($GLOBALS['test_network_options']['rrze_msm_storage_analysis_batch_site_ids'], $GLOBALS['test_network_options']['rrze_msm_storage_analysis_batch_last_site_id'], $GLOBALS['test_events'][$batchHook]);
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_continuation_queue'] = [2 => $oldRunId];
    $before = array_sum($analysis->calls);
    $scheduler->runAnalysisContinuation();
    check(array_sum($analysis->calls) === $before, 'A stale continuation restarted a completed run.');

    $queueKey = 'rrze_msm_storage_analysis_continuation_queue';
    foreach ([4, 5] as $id) {
        update_blog_option($id, 'rrze_msm_storage_analysis_scheduler_status', ['is_running' => true, 'run_id' => 'run-' . $id, 'run_phase' => 'base', 'last_started_at' => current_time('mysql', true)]);
    }
    $GLOBALS['test_network_options'][$queueKey] = [4 => 'run-4', 5 => 'run-5'];
    $queuedAnalysis = new SliceAnalysis();
    $queuedAnalysis->baseBatchesUntilComplete = 1020;
    $scheduler = new SliceScheduler($queuedAnalysis);
    unset($GLOBALS['test_events'][$queueHook]);
    $scheduler->runAnalysisContinuation();
    check(array_sum($queuedAnalysis->calls) === 1000 && array_key_first($GLOBALS['test_network_options'][$queueKey]) === 5, 'One queue event must process only one website and preserve FIFO fairness.');
    check(count($GLOBALS['test_events'][$queueHook]) === 1, 'Queue must use one central continuation event.');
    $GLOBALS['test_network_options'][$queueKey] = [];

    $restartSiteId = 18;
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_removed_site_ids'] = [$restartSiteId];
    update_blog_option($restartSiteId, 'rrze_msm_storage_analysis_scheduler_status', [
        'last_was_aborted' => true,
        'last_error' => 'Scheduling for this website has been removed.',
        'last_attempt_at' => current_time('mysql', true),
    ]);
    $restartAnalysis = new SliceAnalysis();
    $restartAnalysis->baseBatchesUntilComplete = 1020;
    $restartScheduler = new RestartScheduler($restartAnalysis);
    check($restartScheduler->startAnalysisNow($restartSiteId), 'An explicitly restarted website was not scheduled.');
    check(empty(get_site_option('rrze_msm_storage_analysis_removed_site_ids', [])), 'An explicit restart retained the scheduling removal marker.');
    $restartStatus = $restartScheduler->getStatus($restartSiteId);
    check(empty($restartStatus['last_was_aborted']) && empty($restartStatus['last_error']) && empty($restartStatus['last_attempt_at']), 'An explicit restart retained the old abort status.');
    $GLOBALS['test_events'][$queueHook][serialize([])] = time() + 245 * HOUR_IN_SECONDS;
    $restartScheduler->runSlice($restartSiteId);
    check(!empty($restartScheduler->getStatus($restartSiteId)['is_running']), 'The restarted single assignment did not begin.');
    check((int)wp_next_scheduled($queueHook) <= time() + 10, 'A distant continuation delayed the orphan and metadata phases.');
    unset($GLOBALS['test_events'][$queueHook]);
    (new RestartScheduler($restartAnalysis))->runAnalysisContinuation();
    check(empty($restartScheduler->getStatus($restartSiteId)['is_running']), 'The single assignment did not finish all phases after its index.');
    check($restartAnalysis->calls === ['base' => 1020, 'orphan' => 3, 'metadata' => 2], 'The single assignment skipped or repeated a phase.');
    unset($GLOBALS['test_events'][$queueHook]);
    $GLOBALS['test_network_options']['rrze_msm_storage_analysis_removed_site_ids'] = [19];
    update_blog_option(19, 'rrze_msm_storage_analysis_scheduler_status', ['last_was_aborted' => true, 'last_error' => 'Old batch stopped']);
    $reinitialized = new ReinitializeScheduler(new SliceAnalysis());
    check($reinitialized->scheduleSelectedActiveSites([19]) === 1, 'A removed batch assignment was not rescheduled.');
    check(empty(get_site_option('rrze_msm_storage_analysis_removed_site_ids', [])) && empty($reinitialized->getStatus(19)['last_was_aborted']), 'Reinitializing a shared batch retained its removal marker or abort.');

    $GLOBALS['test_options'][1]['rrze_msm_storage_analysis_run_slots_lock'] = ['token' => 'other-request', 'expires_at' => time() + 60];
    $collidingAnalysis = new SliceAnalysis();
    $collidingAnalysis->baseBatchesUntilComplete = 1020;
    $scheduler = new SliceScheduler($collidingAnalysis);
    $scheduler->runSlice(6);
    check(!empty($scheduler->getStatus(6)['is_running']) && $collidingAnalysis->clears === 1, 'Queue-lock contention must preserve progress and schedule a retry.');
    check($GLOBALS['test_options'][1]['rrze_msm_storage_analysis_run_slots_lock']['token'] === 'other-request', 'A foreign lock must not be released.');
    unset($GLOBALS['test_options'][1]['rrze_msm_storage_analysis_run_slots_lock']);

    $GLOBALS['test_network_options']['rrze-multisite-manager'] = ['monitoring_storage_analysis_timeout_minutes' => 1];
    update_blog_option(8, 'rrze_msm_storage_analysis_scheduler_status', ['is_running' => true, 'run_id' => 'timed-out', 'run_phase' => 'base', 'last_started_at' => current_time('mysql', true), 'active_duration_seconds' => 61]);
    $timedOut = new SliceAnalysis();
    $scheduler = new SliceScheduler($timedOut);
    $scheduler->runSlice(8);
    check(array_sum($timedOut->calls) === 0 && empty($scheduler->getStatus(8)['is_running']), 'Active-time budget must abort before executing another batch.');

    $failure = new SliceAnalysis();
    $failure->fail = true;
    $scheduler = new SliceScheduler($failure);
    $scheduler->runSlice(3);
    check($failure->clears === 2 && empty($scheduler->getStatus(3)['is_running']), 'Failure must clean temporary states and stop the run.');
    $bounded = RRZE\MultisiteManager\Support\BoundedArray::class;
    foreach ([[], ['x' => 'äöü'], ['a' => [1, 2, false, null, 3.5]]] as $value) {
        $size = strlen(serialize($value));
        check($bounded::fits($value, $size) && !$bounded::fits($value, $size - 1), 'Serialized-size estimator did not match exact boundaries.');
    }
    $huge = ['actual_bytes' => 6543210123, 'total_files' => 999999, 'top_level_directories' => []];
    for ($i = 0; $i < 20000; $i++) { $huge['top_level_directories'][] = ['path' => str_repeat('p', 240), 'size_bytes' => 12345]; }
    $huge['message'] = str_repeat('ü', 1500000);
    $limited = $bounded::compact($huge, 2097152);
    check(strlen(serialize($limited)) <= 2097152 && $limited['actual_bytes'] === 6543210123 && $limited['total_files'] === 999999 && $limited['result_truncated'], 'Result cap failed to preserve counters or enforce its hard size limit.');
    $identities = $bounded::compact(['largest_files' => [['path' => str_repeat('p', 3000), 'file_url' => str_repeat('u', 3000), 'size_bytes' => 100]]], 2097152);
    check($identities['largest_files'][0]['path'] === '' && $identities['largest_files'][0]['file_url'] === '', 'Truncated identifiers must not become actionable paths.');
    unset($huge, $limited);

    $resultService = new RRZE\MultisiteManager\Metrics\StorageAnalysisResultService();
    $metadata = ['status' => 'running', 'last_attachment_id' => 987, 'processed' => 987, 'counts' => ['images' => 987], 'results' => ['images' => [['title' => str_repeat('x', 3000000)]]]];
    $saved = $resultService->saveCurrentMediaMetadataResult($metadata);
    check(strlen(serialize($saved)) <= 2097152 && $saved['last_attachment_id'] === 987 && $saved['counts']['images'] === 987, 'Metadata compaction lost progress/counts.');
    $GLOBALS['test_write_failure'] = 'rrze_msm_site_media_metadata_analysis_result';
    $failed = false;
    try { $resultService->saveCurrentMediaMetadataResult(['status' => 'failed']); } catch (RuntimeException $exception) { $failed = true; }
    check($failed && get_option('rrze_msm_site_media_metadata_analysis_result')['last_attachment_id'] === 987, 'Failed result writes must report an error and preserve the previous value.');
    $GLOBALS['test_write_failure'] = '';
    unset($metadata, $saved, $identities);

    $metadataProbe = new MetadataProbe(new RRZE\MultisiteManager\Metrics\StorageAnalysisStateService(), $resultService, 1);
    $entry = $metadataProbe->entry((object)['ID' => 80, 'post_title' => 'Bounded image', 'attached_file' => '2026/image.jpg', 'post_mime_type' => 'image/jpeg', 'post_modified_gmt' => '2026-01-01 00:00:00', 'attachment_metadata' => serialize(['sizes' => ['medium' => ['file' => 'image-300.jpg']]])]);
    check($entry['preview_url'] === 'https://example.org/uploads/2026/image-300.jpg' && str_contains($entry['media_edit_url'], 'post=80'), 'Bounded metadata previews/edit links must work without Core post-meta loading.');
    $legacyKey = 'rrze_msm_site_storage_analysis_result';
    $GLOBALS['test_options'][1][$legacyKey] = ['huge' => str_repeat('x', 3000000)];
    $GLOBALS['test_options'][1]['rrze_msm_site_storage_analysis_result_meta'] = ['actual_bytes' => 6543210123, 'total_files' => 9000];
    $GLOBALS['test_forbidden_option_reads'][$legacyKey] = true;
    $legacy = $resultService->getCurrentResult();
    check(($legacy['actual_bytes'] ?? 0) === 6543210123 && !empty($legacy['requires_reanalysis']) && strlen(serialize($GLOBALS['test_options'][1][$legacyKey])) <= 2097152, 'Legacy result was not replaced through the bounded-read path.');
    unset($GLOBALS['test_forbidden_option_reads'][$legacyKey]);
    $wide = ['actual_bytes' => 123456789, 'first' => array_fill(0, 500, ['title' => str_repeat('x', 4096)]), 'second' => array_fill(0, 500, ['title' => str_repeat('y', 4096)]), 'third' => array_fill(0, 500, ['title' => str_repeat('z', 4096)])];
    $wide = $bounded::compact($wide, 2097152);
    check(strlen(serialize($wide)) <= 2097152 && $wide['actual_bytes'] === 123456789 && $wide['result_truncated'], 'Multiple large detail lists exceeded the exact result budget.');
    unset($wide, $legacy);

    $resources = new RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService();
    $resources->acquire(40);
    $resources->acquire(41);
    $resources->acquire(42);
    $resources->acquire(43);
    $blocked = false;
    try { $resources->acquire(44); } catch (RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException $exception) { $blocked = str_contains($exception->getMessage(), '40'); }
    check($blocked, 'Default admission must allow four temporary indexes.');
    $resources->reserveIndexBytes(40, 'ab', ['sample' => ['i' => 40, 'm' => 'image/jpeg', 'f' => 1]]);
    $group = [
        '01' => ['first' => ['i' => 40, 'f' => 1]],
        '02' => ['second' => ['i' => 40, 'f' => 1]],
    ];
    $written = 0;
    $mutexAcquisitions = $GLOBALS['test_resource_mutex_acquisitions'];
    $resources->writeIndexBucketGroup(40, $group, function () use (&$written): void {
        $reservation = get_option('rrze_msm_storage_resource_slot_0', []);
        check(!empty($reservation['buckets']['01']) && !empty($reservation['buckets']['02']), 'Index writes must be reserved before the callback runs.');
        $written++;
        set_site_transient('index-group-01', ['first' => true], DAY_IN_SECONDS);
        set_site_transient('index-group-02', ['second' => true], DAY_IN_SECONDS);
    });
    $reservation = get_option('rrze_msm_storage_resource_slot_0', []);
    check($written === 1 && $GLOBALS['test_resource_mutex_acquisitions'] === $mutexAcquisitions + 1 && !empty($reservation['buckets']['01']) && !empty($reservation['buckets']['02']), 'A group of index segments must use one budget lock and account for both writes.');
    $failed = false;
    try {
        $resources->writeIndexBucketGroup(40, ['03' => ['third' => ['i' => 40, 'f' => 1]]], function (): void {
            set_site_transient('index-group-03', ['third' => true], DAY_IN_SECONDS);
            throw new RuntimeException('Interrupted group write');
        });
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    check($failed && !empty(get_option('rrze_msm_storage_resource_slot_0', [])['buckets']['03']) && get_site_transient('index-group-03') !== false, 'A partial group write must keep its reservation for safe replay.');
    $resources->writeIndexBucketGroup(40, ['03' => ['third' => ['i' => 40, 'f' => 1]]], static function (): void {});
    check(!empty(get_option('rrze_msm_storage_resource_slot_0', [])['buckets']['03']), 'Replaying an interrupted group must reserve its completed segment.');
    $reservation = get_option('rrze_msm_storage_resource_slot_0', []);
    $fullReservation = $reservation;
    $fullReservation['buckets']['ff'] = RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService::MAX_INDEX_BYTES;
    update_option('rrze_msm_storage_resource_slot_0', $fullReservation);
    $wroteOverBudget = false;
    $blocked = false;
    try {
        $resources->writeIndexBucketGroup(40, ['04' => ['fourth' => ['i' => 40, 'f' => 1]]], function () use (&$wroteOverBudget): void { $wroteOverBudget = true; });
    } catch (RuntimeException $exception) {
        $blocked = true;
    }
    check($blocked && !$wroteOverBudget, 'An over-budget group must be rejected before any segment is written.');
    update_option('rrze_msm_storage_resource_slot_0', $reservation);
    $blocked = false;
    try { $resources->reserveIndexBytes(40, 'cd', ['oversized' => str_repeat('x', 131073)]); } catch (RuntimeException $exception) { $blocked = true; }
    check($blocked, 'An oversized segment must be rejected before storage.');
    $missing = false;
    try { $resources->assertBucketPresent(40, 'ab', false); } catch (RuntimeException $exception) { $missing = true; }
    check($missing, 'A missing reserved bucket must not silently classify files as orphaned.');
    $blocked = false;
    try { RRZE\MultisiteManager\Metrics\StorageAnalysisResourceService::saveState('bounded-test', ['huge' => str_repeat('x', 1048577)], DAY_IN_SECONDS); } catch (RuntimeException $exception) { $blocked = true; }
    check($blocked && get_site_transient('bounded-test') === false, 'Working-state cap must be enforced before writing.');
    foreach (range(40, 43) as $siteId) {
        $resources->release($siteId);
    }
    $GLOBALS['test_network_options']['rrze-multisite-manager']['monitoring_storage_analysis_max_concurrent_runs'] = 1;
    check((new RRZE\MultisiteManager\Config())->getStorageAnalysisMaxConcurrentRuns() === 4, 'A saved one-site limit must not starve an existing network.');
    $GLOBALS['test_network_options']['rrze-multisite-manager']['monitoring_storage_analysis_max_concurrent_runs'] = 100;
    check((new RRZE\MultisiteManager\Config())->getStorageAnalysisMaxConcurrentRuns() === 8, 'Old large concurrency values must be clamped to eight.');
    foreach (range(40, 47) as $siteId) {
        $resources->acquire($siteId);
    }
    $blocked = false;
    try { $resources->acquire(48); } catch (RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException $exception) { $blocked = true; }
    check($blocked, 'Network temporary indexes exceeded the hard admission cap.');
    $resources->run(40, function () use ($resources): void {
        $blocked = false;
        try { $resources->run(40, static fn() => null); } catch (RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException $exception) { $blocked = true; }
        check($blocked, 'Two requests must not process the same website concurrently.');
        foreach ($GLOBALS['test_options'][1] as $key => $record) {
            if (str_starts_with($key, 'rrze_msm_storage_worker_')) { $GLOBALS['test_options'][1][$key]['expires_at'] = time() - 1; }
        }
        $resources->run(41, function () use ($resources): void {
            for ($slot = 2; $slot < 8; $slot++) {
                update_option('rrze_msm_storage_worker_' . $slot, ['site_id' => 40 + $slot, 'host' => 'other-host.invalid', 'pid' => 120 + $slot, 'token' => 'remote-' . $slot, 'expires_at' => time() + DAY_IN_SECONDS]);
            }
            $blocked = false;
            try { $resources->run(48, static fn() => null); } catch (RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException $exception) { $blocked = true; }
            check($blocked, 'Global workers exceeded eight.');
            for ($slot = 2; $slot < 8; $slot++) {
                delete_option('rrze_msm_storage_worker_' . $slot);
            }
        });
    });
    $staleKey = 'rrze_msm_site_storage_attachment_index_bucket_resumable_v2_40_ab';
    $GLOBALS['test_transients'][$staleKey] = str_repeat('x', 3000000);
    $GLOBALS['test_forbidden_reads'][$staleKey] = true;
    foreach ($GLOBALS['test_options'][1] as $key => $value) {
        if (str_starts_with($key, 'rrze_msm_storage_resource_slot_') && ($value['site_id'] ?? 0) === 40) {
            $GLOBALS['test_options'][1][$key]['expires_at'] = time() - 1;
        }
    }
    $resources->maintain();
    check(!isset($GLOBALS['test_transients'][$staleKey]), 'Abandoned-run cleanup left its payload behind.');
    $resources->acquire(48); // The expired reservation must have been reclaimed.
    foreach (range(41, 48) as $siteId) {
        $resources->release($siteId);
    }
    foreach (range(0, 7) as $slot) {
        check(empty(get_option('rrze_msm_storage_worker_' . $slot)), 'Worker token was not released from slot ' . $slot . '.');
    }
    for ($slot = 0; $slot < 8; $slot++) {
        $GLOBALS['test_options'][1]['rrze_msm_storage_worker_' . $slot] = ['site_id' => 70 + $slot, 'host' => 'other-host.invalid', 'pid' => 123 + $slot, 'token' => 'remote-' . $slot, 'expires_at' => time() - 1];
    }
    $blocked = false;
    try { $resources->run(72, static fn() => null); } catch (RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException $exception) { $blocked = true; }
    check($blocked, 'An expired lease must not reclaim a worker whose termination cannot be verified.');
    $blocked = false;
    try { $resources->clearSite(70); } catch (RRZE\MultisiteManager\Metrics\StorageAnalysisBusyException $exception) { $blocked = true; }
    check($blocked, 'Reset must not delete an index while another worker can still be using it.');
    for ($slot = 0; $slot < 8; $slot++) {
        delete_option('rrze_msm_storage_worker_' . $slot);
    }
    $GLOBALS['test_maintenance_sites'] = range(100, 125);
    $GLOBALS['test_options'][125][$legacyKey] = ['huge' => str_repeat('x', 3000000)];
    $GLOBALS['test_forbidden_option_reads'][$legacyKey] = true;
    $resources->maintain();
    check(get_site_option('rrze_msm_storage_resource_maintenance_offset') === 25 && isset($GLOBALS['test_options'][125][$legacyKey]['huge']), 'Maintenance must process at most 25 sites in one invocation.');
    $resources->maintain();
    check(get_site_option('rrze_msm_storage_resource_maintenance_offset') === 0 && !empty($GLOBALS['test_options'][125][$legacyKey]['requires_reanalysis']) && get_current_blog_id() === 1, 'Maintenance must resume, repair legacy rows without full reads, and restore blog context.');
    unset($GLOBALS['test_forbidden_option_reads'][$legacyKey]);
    echo "PASS: resource/result/state caps, admission/worker limits, stale cleanup without payload reads, failed writes, and previous scanner/scheduler regressions.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($temporary);
}
