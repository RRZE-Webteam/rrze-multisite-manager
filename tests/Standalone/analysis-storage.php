<?php
/** Run outside WordPress: php -d memory_limit=64M tests/Standalone/analysis-storage.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    throw new RuntimeException('Run this test outside WordPress.');
}
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
require dirname(__DIR__) . '/Support/AnalysisDatabase.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\MultisiteManager\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__, 2) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$GLOBALS['network'] = 1;
$GLOBALS['options'] = [];
$GLOBALS['blog'] = 1;
$GLOBALS['blog_stack'] = [];
$GLOBALS['wpdb'] = new AnalysisDatabase();
function do_action(...$args) { $GLOBALS['diagnostics'][] = $args; }
function get_current_network_id() { return $GLOBALS['network']; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function is_serialized($value) { return is_string($value) && preg_match('/^[abisOdN]:/', $value); }
function __($value, $domain = '') { return $value; }
function current_time($format, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function get_blog_option($id, $key, $default = false) { return $GLOBALS['options'][$id][$key] ?? $default; }
function update_blog_option($id, $key, $value) { $GLOBALS['options'][$id][$key] = $value; return true; }
function get_main_site_id($network = null) { return 1; }
function delete_option($key) { unset($GLOBALS['options'][get_current_blog_id()][$key]); return true; }
function get_home_url($id, $path = '') { return 'https://example.test/' . $id . $path; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function switch_to_blog($id) { $GLOBALS['blog_stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop($GLOBALS['blog_stack']); }
function get_option($key, $default = false) {
    if (str_ends_with($key, '_result')) { throw new RuntimeException('Migration loaded a legacy payload.'); }
    return get_blog_option(get_current_blog_id(), $key, $default);
}
function update_option($key, $value, $autoload = null) { return update_blog_option(get_current_blog_id(), $key, $value); }
function add_option($key, $value, $deprecated = '', $autoload = true) { return update_option($key, $value, $autoload); }
function wp_cache_delete_multiple($keys, $group) { return array_fill_keys($keys, true); }
function wp_cache_delete($key, $group) { return true; }
function wp_check_invalid_utf8($value, $strip = false) { return $value; }
function wp_next_scheduled($hook, $args = []) { return false; }
function wp_schedule_single_event($timestamp, $hook, $args = []) { $GLOBALS['scheduled_events'][] = [$timestamp, $hook, $args]; return true; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

use RRZE\MultisiteManager\Analysis\AnalysisStore;
use RRZE\MultisiteManager\Analysis\ContentAnalysisStore;

class FindingCollector extends RRZE\MultisiteManager\ShortcodeBlockAnalysisSchedulerService {
    public function collect(string $kind, array $entry, array &$buffer): void { $this->appendFinding($kind, $entry, $buffer); }
    public function omit(string $kind, string $reason, int $postId, string $title, string $metaKey = ''): void { $this->recordOmittedSource($kind, $reason, $postId, $title, $metaKey); }
    public function totals(): array { return $this->findingTotals; }
    public function nextBatch(): void { $this->pendingFindingBytes = 0; }
}

$store = new AnalysisStore();
$content = new ContentAnalysisStore();
$run = $store->ensureRun(2, 'content', 'content');
$state = ['site_id' => 2, 'run_id' => $run->run_id, 'phase' => 'shortcodes', 'shortcode_last_post_id' => 60, 'shortcode_processed_posts' => 60, 'block_last_post_id' => 0, 'block_processed_posts' => 0, 'total_posts' => 60, 'shortcodes' => [], 'blocks' => []];
for ($id = 1; $id <= 60; $id++) {
    $state['shortcodes'][] = ['post_id' => $id, 'shortcode' => $id <= 30 ? 'alpha' : 'beta', 'raw_shortcode' => '[test]', 'registered' => $id % 2 === 0];
}
$content->save(2, $state);
$content->save(2, $state); // Replay must not duplicate findings.
check(count($store->findings(2, $run->run_id, 'shortcodes')['entries']) === 50, 'Page size exceeded.');
check($store->findings(2, $run->run_id, 'shortcodes')['has_more'], 'Missing continuation.');
check(count($store->findings(2, $run->run_id, 'shortcodes', 2)['entries']) === 10, 'Duplicate or missing replay entries.');
check(count($store->findings(2, $run->run_id, 'shortcodes', 1, 'beta')['entries']) === 30, 'Filter must apply before paging.');
check($store->summary(2) === [], 'Unfinished findings were published.');
$checkpoint = $content->load(2);
check($checkpoint['shortcodes'] === [] && $checkpoint['blocks'] === [], 'Checkpoint loaded the complete findings.');
check($checkpoint['shortcode_last_post_id'] === 60 && $checkpoint['block_last_post_id'] === 0, 'Phase cursors were mixed.');
$main = $store->decode($store->active(2, 'content')->payload);
check(!isset($main['shortcode_last_post_id']) && !isset($main['blocks']), 'Phase checkpoint leaked into orchestration row.');
check($store->readEntry('shortcodes', 2, $run->run_id, 'checkpoint')['shortcode_last_post_id'] === 60, 'Shortcode checkpoint missing.');
check(!isset($store->readEntry('blocks', 2, $run->run_id, 'checkpoint')['shortcode_last_post_id']), 'Shortcode checkpoint leaked into block table.');

$state['shortcodes'] = [];
$state['blocks'] = [['post_id' => 61, 'block' => 'core/image']];
$state['block_last_post_id'] = 61;
$GLOBALS['wpdb']->failOn = 'INSERT INTO wp_rrze_msm_analysis_blocks';
$failed = false;
try { $content->save(2, $state); } catch (RuntimeException $error) { $failed = true; }
$GLOBALS['wpdb']->failOn = '';
check($failed && $content->load(2)['block_last_post_id'] === 0, 'Failed write advanced the checkpoint.');
check($store->findings(2, $run->run_id, 'blocks')['entries'] === [], 'Failed transaction left partial findings.');
$content->save(2, $state);
$summary = $content->finish(2, $state);
check($summary['shortcodes_count'] === 60 && $summary['blocks_count'] === 1, 'Wrong completed counts.');
check($summary['unregistered_shortcodes'] === 2, 'Missing compact registration summary.');
check($content->load(2) === [], 'Completed run remained resumable.');
$old = $store->summary(2)['run_id'];
$new = $store->ensureRun(2, 'content', 'content');
check($old !== $new->run_id && $store->summary(2)['run_id'] === $old, 'New run replaced the previous result prematurely.');
$content->discard(2);
check($store->summary(2)['run_id'] === $old, 'Abort removed the successful result.');
$GLOBALS['network'] = 2;
check($store->summary(2) === [] && $content->load(2) === [], 'Network data leaked.');
check($store->findings(2, $old, 'shortcodes')['entries'] === [], 'Cross-network findings leaked.');
$GLOBALS['network'] = 1;
check($store->findings(3, $old, 'shortcodes')['entries'] === [], 'Cross-site findings leaked.');

$run = $store->ensureRun(3, 'content', 'content');
$GLOBALS['wpdb']->query("UPDATE wp_rrze_msm_analysis_runs SET expires_at = 1 WHERE blog_id = 3");
check($content->load(3) === [], 'Expired checkpoint was resumed.');
$failed = false;
try { $store->writeEntry('blocks', 3, $run->run_id, 'late', ['post_id' => 1]); } catch (RuntimeException $error) { $failed = true; }
check($failed, 'Expired worker wrote new findings.');
check($store->findings(3, $run->run_id, 'blocks')['entries'] === [], 'Expired worker left an orphan entry.');

$collector = new FindingCollector();
$buffer = [];
for ($id = 1; $id <= 3000; $id++) {
    $collector->collect('shortcodes', ['post_id' => $id, 'shortcode' => 'test', 'raw_shortcode' => str_repeat('x', 20000)], $buffer);
    if ($id % 20 === 0) { $buffer = []; $collector->nextBatch(); }
}
check($collector->totals()['shortcode_bytes'] <= 1048576 && $collector->totals()['shortcode_truncated'], 'Collection exceeded its byte budget.');
check(($collector->totals()['shortcode_omitted_sources'][0]['reason'] ?? '') === 'finding_value_shortened', 'Shortened finding values were not diagnosed.');
$collector->collect('blocks', ['post_id' => 4, 'block' => 'core/image'], $buffer);
check($collector->totals()['block_count'] === 1, 'Shortcode limit affected block collection.');
$detailCollector = new FindingCollector();
$detailCollector->omit('shortcodes', 'post_meta_too_large', 7, 'Large metadata post', 'builder_payload');
check($detailCollector->totals()['shortcode_omitted_count'] === 1 && $detailCollector->totals()['shortcode_omitted_sources'][0]['metadata_key'] === 'builder_payload', 'Oversized input source was not recorded.');
$detailRun = $store->ensureRun(80, 'content', 'content');
$detailState = ['site_id' => 80, 'run_id' => $detailRun->run_id, 'phase' => 'blocks', 'total_posts' => 1, 'shortcode_last_post_id' => 1, 'shortcode_processed_posts' => 1, 'block_last_post_id' => 1, 'block_processed_posts' => 1, 'shortcode_truncated' => true, 'shortcode_omitted_count' => 1, 'shortcode_omitted_sources' => $detailCollector->totals()['shortcode_omitted_sources'], 'shortcodes' => [], 'blocks' => []];
$content->save(80, $detailState);
$detailSummary = $content->finish(80, $detailState);
check($detailSummary['shortcodes_omitted_count'] === 1 && $detailSummary['shortcodes_omitted_sources'][0]['post_id'] === 7, 'Completed result lost oversized input detail.');
$oversize = false;
try { $store->saveCheckpoint(4, 'content', 'content', ['data' => str_repeat('x', 1048576)]); } catch (RuntimeException $error) { $oversize = true; }
check($oversize, 'Unbounded checkpoint accepted.');

// Publication failure must preserve the old complete generation, including its status.
$new = $store->ensureRun(2, 'content', 'content');
$GLOBALS['wpdb']->failOn = "SET status = 'complete'";
$failed = false;
try { $store->publish(2, $new->run_id, ['generated_at' => 'new']); } catch (RuntimeException $error) { $failed = true; }
$GLOBALS['wpdb']->failOn = '';
check($failed && $store->summary(2)['run_id'] === $old, 'Failed publication lost the last successful result.');
// Expiry cleanup is bounded and never removes the retained completed generation.
$cleanupRun = $store->ensureRun(5, 'content', 'content');
for ($id = 1; $id <= 300; $id++) {
    $store->writeEntry('blocks', 5, $cleanupRun->run_id, (string)$id, ['post_id' => $id, 'block' => 'core/image'], 'core/image', $id);
}
$content->discard(5);
$lifecycle = new RRZE\MultisiteManager\Analysis\AnalysisLifecycle();
$lifecycle->cleanupExpired();
$left = (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM wp_rrze_msm_analysis_blocks WHERE blog_id = 5");
check($left === 50, 'Expiry cleanup exceeded its row batch or did not remove expired findings.');
check($store->summary(2)['run_id'] === $old, 'Expiry cleanup removed a retained result.');
$lifecycle->cleanupExpired();
check((int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM wp_rrze_msm_analysis_blocks WHERE blog_id = 5") === 0, 'Cleanup did not resume.');
check((int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM wp_rrze_msm_analysis_runs WHERE blog_id = 5") === 0, 'Empty expired run remained.');
$contentRemoval = $store->ensureRun(79, 'content', 'content');
$store->writeEntry('shortcodes', 79, $contentRemoval->run_id, 'remove-content', ['post_id' => 1, 'shortcode' => 'remove'], 'remove', 1);
$store->publish(79, $contentRemoval->run_id, ['generated_at' => 'now']);
$storageRemoval = $store->ensureRun(79, 'storage_media', 'mediaindex');
$store->writeEntry('mediaindex', 79, $storageRemoval->run_id, 'u01', [1 => true]);
$store->removeSiteAnalysis(79, 'content');
$store->removeSiteAnalysis(79, 'storage');
check($store->summary(79) === [] && $store->active(79, 'storage_media') === null, 'Removed analyses remained visible.');
check((int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM wp_rrze_msm_analysis_runs WHERE blog_id = 79 AND status = 'obsolete'") === 2, 'Removed analyses were not marked for bounded cleanup.');
check(!empty($GLOBALS['scheduled_events']), 'Removing an analysis did not request cleanup.');
$GLOBALS['network'] = 2;
$other = $store->ensureRun(2, 'content', 'content');
$store->writeEntry('blocks', 2, $other->run_id, 'other', ['post_id' => 1], 'other', 1);
$GLOBALS['network'] = 1;
for ($attempt = 0; $attempt < 20 && $lifecycle->purgeNetworkBatch(); $attempt++) {}
check($store->summary(2) === [], 'Full cleanup left results.');
$GLOBALS['network'] = 2;
check(count($store->findings(2, $other->run_id, 'blocks')['entries']) === 1, 'Full cleanup crossed network boundary.');
$GLOBALS['network'] = 1;
// Phase transition on the final permitted sub-batch must not publish prematurely.
class SchedulerHarness extends FindingCollector {
    public function step(int $id): bool { return $this->runAnalysis($id); }
    public function getSiteAssignmentMode(int $siteId): string { return 'batch'; }
    protected function isTaskRemovalInProgress(): bool { return false; }
    protected function extendRuntimeLimit(int $deadline): void {}
    protected function markStarted(int $siteId, array $state): void {}
    protected function markPhaseStarted(int $siteId, string $phase, array $state): void {}
    protected function markProgress(int $siteId, string $phase, array $state): void {}
    protected function markPhaseFinished(int $siteId, string $phase, array $state): void {}
    protected function logSiteInfo(string $label, int $siteId, string $assignmentMode = 'unassigned', array $context = []): void {}
    protected function createState(int $siteId): array {
        return ['site_id' => $siteId, 'run_id' => (new AnalysisStore())->ensureRun($siteId, 'content', 'content')->run_id, 'phase' => 'shortcodes', 'phase_started' => false, 'shortcode_last_post_id' => 0, 'block_last_post_id' => 0, 'shortcode_processed_posts' => 0, 'block_processed_posts' => 0, 'total_posts' => 300, 'shortcodes' => [], 'blocks' => []];
    }
    protected function getPostIdsAfter(int $lastPostId): array { return $lastPostId >= 300 ? [] : range($lastPostId + 1, min(300, $lastPostId + 50)); }
    protected function analysePostShortcodes(int $postId, array &$state, float $deadline): bool {
        $this->appendFinding('shortcodes', ['post_id' => $postId, 'shortcode' => 'test', 'raw_shortcode' => '[test]'], $state['shortcodes']);
        return true;
    }
    protected function analysePostBlocks(int $postId, array &$state): void {
        $this->appendFinding('blocks', ['post_id' => $postId, 'block' => 'core/image'], $state['blocks']);
    }
}
$GLOBALS['options'][77]['rrze_msm_analysis_storage_version'] = 1;
$harness = new SchedulerHarness();
check(!$harness->step(77), 'Shortcode completion prematurely completed the whole analysis.');
check($store->summary(77) === [], 'Half-complete generation was visible.');
check($content->load(77)['phase'] === 'blocks', 'Phase transition was not persisted.');
check($harness->step(77), 'Block phase did not complete on resume.');
check($store->summary(77)['blocks_count'] === 300 && $store->summary(77)['shortcodes_count'] === 300, 'Resumed phase lost findings.');
$missing = $store->ensureRun(78, 'content', 'content');
$content->save(78, ['site_id' => 78, 'run_id' => $missing->run_id, 'shortcode_last_post_id' => 10, 'blocks' => [], 'shortcodes' => []]);
$GLOBALS['wpdb']->delete($store->table('blocks'), ['network_id' => 1, 'blog_id' => 78, 'run_id' => $missing->run_id, 'entry_key' => 'checkpoint']);
$failed = false;
try { $content->load(78); } catch (RuntimeException $error) { $failed = true; }
check($failed, 'Missing checkpoint silently reset the phase cursor.');

// Diagnostics must survive rollback without publishing SQL or content from server errors.
$GLOBALS['wpdb']->last_error = "Unknown column 'private-payload-token' in 'field list'";
$diagnostic = '';
try { $store->check(false, 'shortcodes-entry-write'); } catch (RuntimeException $error) { $diagnostic = $error->getMessage(); }
check(str_contains($diagnostic, 'shortcodes-entry-write') && str_contains($diagnostic, 'missing-column'), 'Monitoring did not identify the failed operation.');
check(!str_contains($diagnostic, 'private-payload-token'), 'Monitoring exposed server error content.');
$logged = end($GLOBALS['diagnostics']);
check(!str_contains(serialize($logged), 'private-payload-token') && $logged[2]['reason'] === 'missing-column', 'Persistence logging exposed raw database content.');
$GLOBALS['wpdb']->last_error = '';
// The reported failure: an existing InnoDB table without its required finding columns.
$verify = new ReflectionMethod(AnalysisStore::class, 'verifySchema');
$verify->invoke($store);
$GLOBALS['wpdb']->query('ALTER TABLE wp_rrze_msm_analysis_shortcodes DROP COLUMN registered');
$failed = false;
try { $verify->invoke($store); } catch (RuntimeException $error) {
    $failed = str_contains($error->getMessage(), 'schema-columns-shortcodes');
}
check($failed, 'Schema validation accepted an InnoDB table without registered.');
$GLOBALS['wpdb']->query('ALTER TABLE wp_rrze_msm_analysis_shortcodes ADD COLUMN registered INTEGER DEFAULT 1');
$verify->invoke($store);
check(AnalysisStore::SCHEMA_VERSION > AnalysisStore::DATA_VERSION, 'Schema repair must not repeat destructive data migration.');

// Upgrade reads names only, preserves unrelated data and retains a reanalysis request.
class LegacyFixtureDatabase {
    public string $options = 'wp_options';
    public string $sitemeta = 'wp_sitemeta';
    public array $names = ['rrze_msm_site_storage_analysis_result', 'rrze_msm_shortcode_block_analysis_result'];
    public array $deleted = [];
    public function prepare(string $query, ...$args): string { return vsprintf($query, $args); }
    public function get_col(string $query): array {
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) { return [1]; }
        check(str_starts_with($query, 'SELECT option_name FROM wp_options WHERE option_name IN'), 'Migration read a value or an unrelated table.');
        return $this->names;
    }
    public function query(string $query): int {
        if (str_starts_with($query, 'DELETE FROM wp_sitemeta WHERE site_id = 1 AND meta_key IN')) { return 0; }
        check(str_starts_with($query, 'DELETE FROM wp_options WHERE option_name IN'), 'Migration deletion escaped its allowlist.');
        $this->deleted = $this->names;
        $this->names = [];
        return count($this->deleted);
    }
}
$legacy = new LegacyFixtureDatabase();
$GLOBALS['wpdb'] = new AnalysisDatabase($legacy);
$GLOBALS['options'][9]['unrelated_setting'] = 'keep';
$GLOBALS['options'][9]['rrze_msm_shortcode_block_analysis_status'] = ['status' => 'complete'];
RRZE\MultisiteManager\Analysis\AnalysisLifecycle::prepareSite(9);
check(count($legacy->deleted) === 2, 'Upgrade left legacy results.');
check(get_blog_option(9, 'rrze_msm_analysis_reanalysis') === ['storage' => true, 'content' => true], 'Upgrade did not request both analyses.');
check(get_blog_option(9, 'unrelated_setting') === 'keep', 'Upgrade removed unrelated data.');
check(get_blog_option(9, 'rrze_msm_analysis_storage_version') === 1, 'Upgrade did not checkpoint completion.');
$legacy->names = ['rrze_msm_site_storage_analysis_result'];
RRZE\MultisiteManager\Analysis\AnalysisLifecycle::prepareSite(9);
check(count($legacy->names) === 1, 'Repeated upgrade removed a newly generated result.');
RRZE\MultisiteManager\Analysis\AnalysisLifecycle::completed(9, 'content');
check(get_blog_option(9, 'rrze_msm_analysis_reanalysis') === ['storage' => true], 'Completing one analysis cleared the other reanalysis request.');
echo "PASS: separated checkpoints/findings, replay, rollback, publication, paging, network/site isolation, expiry, bounded cleanup, legacy removal and collection byte budgets.\n";
