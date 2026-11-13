<?php
/** Isolated coordination tests. The DB boundary simulates MySQL named locks. */
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
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);
$GLOBALS['network'] = 1;
$GLOBALS['options'] = [];
$GLOBALS['events'] = [];
$GLOBALS['cache_deletions'] = [];
function __($text, $domain = '') { return $text; }
function get_current_network_id() { return $GLOBALS['network']; }
function get_site_option($key, $default = false) { return $GLOBALS['options'][get_current_network_id()][$key] ?? $default; }
function update_site_option($key, $value) { $GLOBALS['options'][get_current_network_id()][$key] = $value; return true; }
function delete_site_option($key) { unset($GLOBALS['options'][get_current_network_id()][$key]); return true; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function get_site($id) { return (object)['blog_id' => $id]; }
function wp_next_scheduled($hook, $args = []) { return $GLOBALS['events'][$hook . serialize($args)] ?? false; }
function wp_schedule_single_event($at, $hook, $args = []) { $GLOBALS['events'][$hook . serialize($args)] = $at; return true; }
function wp_clear_scheduled_hook($hook, $args = []) { unset($GLOBALS['events'][$hook . serialize($args)]); return true; }
function wp_cache_delete($key, $group) { $GLOBALS['cache_deletions'][] = [$key, $group]; return true; }
// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output, never rendered as HTML.
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
spl_autoload_register(static function ($class) {
    $prefix = 'RRZE\\MultisiteManager\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__, 2) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
final class LockDatabase {
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public array $owners = [];
    public bool $fail = false;
    public bool $singleLockMode = false;
    public function prepare($sql, ...$args) { return str_replace('%s', "'" . $args[0] . "'", $sql); }
    public function get_var($sql) {
        $this->last_error = $this->fail ? 'Lock database unavailable' : '';
        if ($this->fail) { return null; }
        check((bool)preg_match("/^SELECT (GET_LOCK|RELEASE_LOCK|IS_USED_LOCK)\\('([^']+)'(?:, 0)?\\)(.*)$/", $sql, $m), 'Unexpected SQL: ' . $sql);
        [$all, $op, $key, $suffix] = $m;
        if ($op === 'GET_LOCK') {
            if ($this->singleLockMode) { $this->owners = []; }
            if (isset($this->owners[$key])) { return 0; }
            $this->owners[$key] = 1;
            return 1;
        }
        if ($op === 'RELEASE_LOCK') {
            if (($this->owners[$key] ?? 0) !== 1) { return 0; }
            unset($this->owners[$key]);
            return 1;
        }
        return $suffix !== '' ? (int)(($this->owners[$key] ?? 0) === 1) : ($this->owners[$key] ?? null);
    }
}
$GLOBALS['wpdb'] = new LockDatabase();
use RRZE\MultisiteManager\Support\AtomicLock;
use RRZE\MultisiteManager\Support\WorkerBudget;
use RRZE\MultisiteManager\Config;

function nameFor(string $key): string {
    $method = new ReflectionMethod(AtomicLock::class, 'name');
    return substr($method->invoke(null, $key), 0, 64);
}
function occupyBudget(): void {
    global $wpdb;
    for ($i = 0; $i < (new Config())->getMaxConcurrentWorkers(); $i++) {
        $wpdb->owners[nameFor('background_worker_' . $i)] = 999;
    }
}
function freeForeignBudget(): void {
    global $wpdb;
    $wpdb->owners = array_filter($wpdb->owners, static fn($owner) => $owner !== 999);
}
class LockUser {
    use RRZE\MultisiteManager\Support\ProcessLocks;
    public function acquire(): string { return $this->acquireProcessLock('test-process'); }
    public function release(string $token = ''): void { $this->releaseProcessLock('test-process', $token); }
}
$wpdb->singleLockMode = true;
check(AtomicLock::acquire('legacy-server') === '' && $wpdb->owners === [], 'Single-lock server admitted unsafe nested locks.');
$wpdb->singleLockMode = false;
$owner = new LockUser();
$other = new LockUser();
$token = $owner->acquire();
check($token !== '' && $other->acquire() === '', 'Two service instances acquired the same lock.');
$other->release();
$owner->release('wrong-token');
check(AtomicLock::isLocked('test-process'), 'A foreign release removed the active lock.');
update_site_option('test-process', time() - DAY_IN_SECONDS);
check($other->acquire() === '', 'An old marker evicted a live worker.');
$owner->release($token);
check(!AtomicLock::isLocked('test-process'), 'Owner did not release the process lock.');
$refresh = new RRZE\MultisiteManager\Metrics\DashboardMetricsRefreshService(1, 900);
check($refresh->acquireLock(), 'Dashboard process lock unavailable.');
check(in_array(['1:rrze_msm_dashboard_metrics_batch_offset', 'site-options'], $GLOBALS['cache_deletions'], true), 'Dashboard admission retained a stale progress cursor.');
$refresh->releaseLock();
$replacement = $other->acquire();
$owner->release($token);
check(AtomicLock::isLocked('test-process'), 'Old owner released a replacement worker.');
$other->release($replacement);

check((new Config())->getMaxConcurrentWorkers() === 3, 'Unexpected default worker limit.');
foreach ([0 => 1, 1 => 1, 16 => 16, 100 => 16] as $input => $expected) {
    update_site_option('rrze-multisite-manager', ['monitoring_max_concurrent_workers' => $input]);
    check((new Config())->getMaxConcurrentWorkers() === $expected, 'Worker setting outside its supported bounds.');
}
update_site_option('rrze-multisite-manager', ['monitoring_max_concurrent_workers' => 1]);
$first = new WorkerBudget();
$nested = new WorkerBudget();
check($first->acquire(42) && $nested->acquire(), 'Nested storage phases consumed two slots.');
check(count($wpdb->owners) === 1, 'Nested calls increased the global worker count.');
WorkerBudget::setCurrentSite(43);
$liveWorkers = WorkerBudget::getActiveWorkers(1);
check(count($liveWorkers) === 1 && $liveWorkers[0]['site_id'] === 43 && $liveWorkers[0]['slot'] === 'background_worker_0', 'Live worker diagnostics did not identify the slot and current website.');
$nested->release();
check(count($wpdb->owners) === 1, 'Nested call released the outer reservation.');
$first->release();
check($wpdb->owners === [], 'Last scope did not release its reservation.');
check(WorkerBudget::getActiveWorkers(1) === [], 'Released workers remained in live diagnostics.');
occupyBudget();
check(!(new WorkerBudget())->acquire(), 'Full network budget admitted another worker.');
$before = count($wpdb->owners);
$GLOBALS['network'] = 2;
$anotherNetwork = new WorkerBudget();
check($anotherNetwork->acquire(), 'Different networks incorrectly share a budget.');
$GLOBALS['network'] = 1;
$anotherNetwork->release();
check(count($wpdb->owners) === $before, 'Release used the current rather than the reserved network.');
freeForeignBudget();
$wpdb->fail = true;
check(!(new WorkerBudget())->acquire(), 'Unavailable DB lock service admitted work.');
check(AtomicLock::isLocked('test-process'), 'Unknown lock state was treated as idle.');
$wpdb->fail = false;

class ContentProbe extends RRZE\MultisiteManager\ShortcodeBlockAnalysisSchedulerService {
    public int $executed = 0;
    public array $retries = [];
    public bool $fail = false;
    public function __construct() { $this->config = new Config(); }
    protected function isTaskRemovalInProgress(): bool { return false; }
    public function isSiteActive(int $siteId): bool { return true; }
    public function getSiteAssignmentMode(int $siteId): string { return 'site'; }
    protected function scheduleIndividualContinuation(int $siteId, int $delay = 5): bool { $this->retries[] = [$siteId, $delay]; return true; }
    protected function scheduleBatchContinuation(int $timestamp, string $mode = 'small-batch'): bool { $this->retries[] = [$mode, $timestamp]; return true; }
    protected function runAdmittedBatchScheduledAnalysis(string $mode, bool $continuation): void { $this->executed++; $this->runScheduledAnalysis(5); }
    protected function executeScheduledAnalysis(int $siteId): void { $this->executed++; if ($this->fail) { throw new RuntimeException('Injected failure'); } }
}
class ContentClassificationProbe extends RRZE\MultisiteManager\ShortcodeBlockAnalysisSchedulerService {
    public int $contentCount = 0;
    public array $status = [];
    public function __construct() {
        $this->config = new class extends Config {
            public function getShortcodeBlockSmallBatchPostThreshold(): int { return 100; }
            public function getShortcodeBlockMediumBatchPostThreshold(): int { return 1000; }
            public function getShortcodeBlockLargeBatchPostThreshold(): int { return 5000; }
            public function getShortcodeBlockSmallBatchRuntimeThresholdSeconds(): int { return 3; }
            public function getShortcodeBlockMediumBatchRuntimeThresholdSeconds(): int { return 15; }
            public function getShortcodeBlockLargeBatchRuntimeThresholdSeconds(): int { return 60; }
        };
    }
    protected function getSitePostAndPageCount(int $siteId): int { return $this->contentCount; }
    public function getStatus(int $siteId): array { return $this->status; }
    public function classify(): string { return $this->getExpectedAssignmentMode(5); }
}
class StorageProbe extends RRZE\MultisiteManager\StorageAnalysisSchedulerService {
    public int $executed = 0;
    public array $retries = [];
    public function __construct() { $this->config = new Config(); }
    public function isScheduleMigrationInProgress(): bool { return false; }
    protected function isTaskRemovalInProgress(): bool { return false; }
    public function isSiteEligible(int $siteId): bool { return true; }
    public function getSiteAssignmentMode(int $siteId): string { return $siteId === 6 ? 'small-batch' : 'site'; }
    protected function scheduleSiteRetry(int $siteId): bool { $this->retries[] = $siteId; return true; }
    protected function runAdmittedScheduledAnalysis(int $siteId, string $phase, bool $fromBatch, string $mode): void { $this->executed++; }
}
class MetricsProbe extends RRZE\MultisiteManager\Metrics\MetricsImplementationService {
    public int $executed = 0;
    public bool $running = false;
    public function __construct() {}
    protected function isCentralNetworkCronSite(): bool { return true; }
    protected function isDashboardSchedulingEnabled(): bool { return true; }
    protected function isDashboardHistoryCompactionEnabled(): bool { return true; }
    public function isDashboardRefreshInProgress(): bool { return $this->running; }
    public function run(): array { return $this->runDashboardRefreshBatch(); }
    protected function runAdmittedDashboardRefreshBatch(bool $manual): array { $this->executed++; return []; }
    protected function runAdmittedDashboardComposition(string $runId): void { $this->executed++; }
    protected function runAdmittedDashboardHistoryCompaction(): void { $this->executed++; }
}
class MonitoringProbe extends RRZE\MultisiteManager\MonitoringService {
    public int $executed = 0;
    public bool $running = false;
    public function __construct() { $this->config = new Config(); }
    public function isMonitoringRunInProgress(): bool { return $this->running; }
    public function run(): array { return $this->runMonitoringBatch(); }
    protected function runAdmittedMonitoringBatch(bool $manual): array { $this->executed++; return []; }
}
$content = new ContentProbe();
$classification = new ContentClassificationProbe();
$classification->contentCount = 100;
check($classification->classify() === 'small-batch', 'Small content inventory was assigned to the wrong batch.');
$classification->contentCount = 101;
check($classification->classify() === 'medium-batch', 'Medium content inventory was assigned to the wrong batch.');
$classification->contentCount = 1001;
check($classification->classify() === 'large-batch', 'Large content inventory was assigned to the wrong batch.');
$classification->contentCount = 5001;
check($classification->classify() === 'site', 'Oversized content inventory was not assigned individually.');
$classification->contentCount = 10;
$classification->status = ['status' => 'complete', 'last_runtime_seconds' => 16.0];
check($classification->classify() === 'large-batch', 'Completed runtime did not promote the website to the large batch.');
$classification->status = ['status' => 'complete', 'last_runtime_seconds' => 61.0];
check($classification->classify() === 'site', 'Completed runtime did not promote the website to an individual task.');
$storage = new StorageProbe();
$metrics = new MetricsProbe();
$monitoring = new MonitoringProbe();
occupyBudget();
$content->runScheduledAnalysis(5);
$content->runBatchScheduledAnalysis(true);
$storage->runScheduledAnalysis(5, 'scheduled', false);
$storage->runScheduledAnalysis(6, 'scheduled', true);
$metrics->run();
$metrics->handleScheduledDashboardComposition('run-1');
$metrics->handleScheduledDashboardHistoryCompaction();
$monitoring->run();
check($content->executed + $storage->executed + $metrics->executed === 0, 'An entrypoint bypassed the common budget.');
check($monitoring->executed === 1, 'Availability checks waited for analysis capacity.');
check(count($content->retries) === 2 && count($storage->retries) === 2 && count($GLOBALS['events']) === 3, 'Denied work lost its continuation.');
check($content->retries[0] === [5, 60], 'Capacity exhaustion caused a tight retry loop.');
$events = $GLOBALS['events'];
$metrics->run();
$monitoring->run();
check($GLOBALS['events'] === $events, 'Repeated denials duplicated retry events.');
check(isset($events['rrze_msm_refresh_dashboard_metrics' . serialize(['rrze_msm_worker_retry' => false])]), 'A denied initial run was scheduled as a stale continuation.');
freeForeignBudget();
$content->runBatchScheduledAnalysis(true);
check($content->executed === 2 && $wpdb->owners === [], 'Shared content batch did not reuse and release its single slot.');
$content->fail = true;
try { $content->runScheduledAnalysis(5); } catch (RuntimeException $error) { check($error->getMessage() === 'Injected failure', 'Unexpected error.'); }
check($wpdb->owners === [], 'Exception leaked a site lock or worker reservation.');
$storage->runScheduledAnalysis(5, 'scheduled', false);
$storage->runScheduledAnalysis(6, 'scheduled', true);
$metrics->run();
$metrics->handleScheduledDashboardComposition('run-1');
$metrics->handleScheduledDashboardHistoryCompaction();
$monitoring->run();
check($storage->executed === 2 && $metrics->executed === 3 && $monitoring->executed === 3 && $wpdb->owners === [], 'Deferred work could not run after capacity was released.');
check(!isset($GLOBALS['events']['rrze_msm_refresh_dashboard_metrics' . serialize(['rrze_msm_worker_retry' => false])]), 'Admitted work left a stale start retry behind.');
$runtimeStats = RRZE\MultisiteManager\Support\WorkerRuntimeStats::get();
check($runtimeStats['admissions'] > 0 && $runtimeStats['denials'] > 0, 'Worker runtime diagnostics did not record admissions and postponements.');
check($runtimeStats['max_concurrent'] >= 1 && count($runtimeStats['contexts']) === 1, 'Worker runtime diagnostics did not record parallelism or the runtime context.');
check(isset($runtimeStats['hooks']['cli']['runs']) && (int)$runtimeStats['hooks']['cli']['runs'] > 0, 'Worker runtime diagnostics did not record the executing hook.');
check($wpdb->owners === [], 'Worker runtime diagnostics left its lock behind.');
AtomicLock::acquire('shutdown-lock');
AtomicLock::releaseAll();
check($wpdb->owners === [], 'Shutdown cleanup left connection-owned locks behind.');
echo "PASS: owner-safe locks, shared capacity, nested scopes, network isolation, fail-closed admission, retries, exception cleanup and runtime diagnostics.\n";
