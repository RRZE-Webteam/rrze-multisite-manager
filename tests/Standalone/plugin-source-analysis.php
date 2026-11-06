<?php

/**
 * Isolated regression tests for the static plugin/theme source analysis used
 * by the plugin and theme usage index.
 *
 * Run: php tests/Standalone/plugin-source-analysis.php
 */

declare(strict_types=1);

namespace {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
        fwrite(STDERR, "This standalone test must not run inside WordPress.\n");
        exit(1);
    }

    define('ABSPATH', __DIR__ . '/');
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    define('WEEK_IN_SECONDS', 604800);
    define('MB_IN_BYTES', 1048576);
    define('ARRAY_A', 'ARRAY_A');
    define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/rrze-msm-source-analysis-' . bin2hex(random_bytes(8)));

    $GLOBALS['network_options'] = [];
    $GLOBALS['analyzed_assets'] = [];
    $GLOBALS['attempts_seen_by_analyzer'] = [];

    function __($text, $domain = '') {
        return $text;
    }

    function trailingslashit(string $path): string {
        return rtrim($path, '/\\') . '/';
    }

    function sanitize_text_field(string $value): string {
        return trim(strip_tags($value));
    }

    function get_current_network_id(): int {
        return 1;
    }

    function get_site_option($key, $default = false) {
        return $GLOBALS['network_options'][$key] ?? $default;
    }

    function update_site_option($key, $value): bool {
        $GLOBALS['network_options'][$key] = $value;
        return true;
    }

    function get_plugins(): array {
        return ['broken/broken.php' => [], 'fine/fine.php' => []];
    }

    function wp_get_themes(): array {
        return [];
    }

    function wp_cache_delete($key, $group = ''): bool {
        return true;
    }

    function get_current_blog_id(): int {
        return 1;
    }

    function get_main_site_id($networkId = null): int {
        return 1;
    }

    function _get_cron_array(): array {
        return $GLOBALS['cron'] ?? [];
    }

    function wp_unschedule_event($timestamp, $hook, $args = []): bool {
        unset($GLOBALS['cron'][$timestamp][$hook]);
        return true;
    }

    function wp_clear_scheduled_hook($hook, $args = []): int {
        return 0;
    }

    function wp_next_scheduled($hook, $args = []) {
        return false;
    }

    function wp_schedule_single_event($timestamp, $hook, $args = []): bool {
        return true;
    }

    function check(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function testAnalyzer(string $asset): array {
        $state = get_site_option('rrze_msm_plugin_theme_usage_metrics_state', []);
        $GLOBALS['analyzed_assets'][] = $asset;
        $GLOBALS['attempts_seen_by_analyzer'][] = (int)($state['source_attempts'] ?? 0);

        return ['shortcodes' => []];
    }

    function testFingerprint(string $asset): string {
        return 'fingerprint-' . $asset;
    }

    function removeDirectory(string $directory): void {
        $entry = '';

        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($directory . '/' . $entry) && !is_link($directory . '/' . $entry)) {
                removeDirectory($directory . '/' . $entry);
            } else {
                unlink($directory . '/' . $entry);
            }
        }

        rmdir($directory);
    }

    class TestDatabase {
        public string $base_prefix = 'wp_';
        public string $last_error = '';

        public function prepare(string $query, ...$args): string {
            return $query;
        }

        public function get_var(string $query) {
            return null;
        }

        public array $sourceRows = [];

        public function get_results(string $query, $output = null): array {
            return $this->sourceRows;
        }

        public function query(string $query) {
            // No published result can be reused: copying from it affects no rows.
            return str_contains($query, ' SELECT ') ? 0 : 1;
        }
    }

    $GLOBALS['wpdb'] = new TestDatabase();

    require_once dirname(__DIR__, 2) . '/includes/Config.php';
    require_once dirname(__DIR__, 2) . '/includes/Support/ProcessLocks.php';
    require_once dirname(__DIR__, 2) . '/includes/Support/WorkerBudget.php';
    require_once dirname(__DIR__, 2) . '/includes/Analysis/AnalysisStore.php';
    require_once dirname(__DIR__, 2) . '/includes/Metrics/MetricsServiceSiteTrait.php';
    require_once dirname(__DIR__, 2) . '/includes/Metrics/MetricsServicePluginTrait.php';
    require_once dirname(__DIR__, 2) . '/includes/Metrics/MetricsServiceThemeTrait.php';
    require_once dirname(__DIR__, 2) . '/includes/Metrics/MetricsImplementationService.php';
    require_once dirname(__DIR__, 2) . '/includes/Metrics/PluginThemeUsageMetricsService.php';
    require_once dirname(__DIR__, 2) . '/includes/ShortcodeBlockAnalysisSchedulerService.php';

    class ScopedMetrics extends \RRZE\MultisiteManager\Metrics\MetricsImplementationService {
        public int $depth = 1;
        public int $phpFiles = 100;

        protected function getSourceAnalysisMaxDepth(): int {
            return $this->depth;
        }

        protected function getSourceAnalysisMaxPhpFiles(): int {
            return $this->phpFiles;
        }
    }

    function callProtected(object $object, string $method, array $arguments) {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invokeArgs($object, $arguments);
    }

    function setProtected(object $object, string $property, $value): void {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    $metrics = (new ReflectionClass(\RRZE\MultisiteManager\Metrics\MetricsImplementationService::class))->newInstanceWithoutConstructor();
    $root = WP_PLUGIN_DIR;

    mkdir($root . '/other/includes', 0777, true);
    mkdir($root . '/multi/includes', 0777, true);
    mkdir($root . '/multi/node_modules/pkg', 0777, true);
    mkdir($root . '/multi/.git', 0777, true);

    try {
        file_put_contents($root . '/hello.php', "<?php\nadd_shortcode('hello_tag', 'callback');\n");
        file_put_contents($root . '/other/other.php', "<?php\nadd_shortcode('foreign_tag', 'callback');\n");
        file_put_contents($root . '/other/includes/more.php', "<?php\n");
        file_put_contents($root . '/multi/multi.php', "<?php\nadd_shortcode('multi_tag', 'callback');\n");
        file_put_contents($root . '/multi/includes/block.json', json_encode(['name' => 'multi/block']));
        file_put_contents($root . '/multi/node_modules/pkg/index.js', "registerBlockType('dependency/block', {});\n");
        file_put_contents($root . '/multi/.git/hook.php', "<?php\n");
        file_put_contents($root . '/multi/large.php', "<?php\n" . str_repeat('/* padding */', 90000));
        symlink($root . '/other', $root . '/multi/linked');

        // A single-file plugin must not turn WP_PLUGIN_DIR into its scan root.
        $single = callProtected($metrics, 'getPluginAnalysisFiles', ['hello.php', microtime(true) + 5.0]);
        check($single['files'] === [$root . '/hello.php'], 'Single-file plugin scanned more than its own file.');
        check($single['truncated'] === false, 'Single-file plugin was reported as incomplete.');

        // Dependency, hidden and symlinked directories are skipped; files above
        // the per-file limit are omitted and reported as incomplete.
        $multi = callProtected($metrics, 'getPluginAnalysisFiles', ['multi/multi.php', microtime(true) + 5.0]);
        sort($multi['files']);
        check($multi['files'] === [$root . '/multi/includes/block.json', $root . '/multi/multi.php'], 'Unexpected multi-file plugin scan set: ' . implode(', ', $multi['files']));
        check($multi['truncated'] === true, 'Oversized file was not reported as incomplete.');

        // The configured depth limits the scope without marking the result
        // incomplete; the PHP file limit reads the main directory first.
        mkdir($root . '/deep/a/b', 0777, true);
        file_put_contents($root . '/deep/deep.php', "<?php\n");
        file_put_contents($root . '/deep/z-last.php', "<?php\n");
        file_put_contents($root . '/deep/a/first-level.php', "<?php\n");
        file_put_contents($root . '/deep/a/b/second-level.php', "<?php\n");
        file_put_contents($root . '/deep/a/b/block.json', '{}');
        $scoped = (new ReflectionClass(ScopedMetrics::class))->newInstanceWithoutConstructor();
        $scoped->depth = 0;
        $result = callProtected($scoped, 'getPluginAnalysisFiles', ['deep/deep.php', microtime(true) + 5.0]);
        check($result['files'] === [$root . '/deep/deep.php', $root . '/deep/z-last.php'] && $result['truncated'] === false, 'Depth 0 read subdirectories or marked the scope as incomplete.');
        $scoped->depth = 1;
        $result = callProtected($scoped, 'getPluginAnalysisFiles', ['deep/deep.php', microtime(true) + 5.0]);
        check(in_array($root . '/deep/a/first-level.php', $result['files'], true) && !in_array($root . '/deep/a/b/second-level.php', $result['files'], true), 'Depth 1 did not stop after the first subdirectory level.');
        $scoped->depth = 2;
        $scoped->phpFiles = 2;
        $result = callProtected($scoped, 'getPluginAnalysisFiles', ['deep/deep.php', microtime(true) + 5.0]);
        check(array_slice($result['files'], 0, 2) === [$root . '/deep/deep.php', $root . '/deep/z-last.php'], 'Main-directory PHP files were not read first.');
        check(!in_array($root . '/deep/a/first-level.php', $result['files'], true) && $result['truncated'] === true, 'PHP file limit was not applied or not reported.');
        check(in_array($root . '/deep/a/b/block.json', $result['files'], true), 'The PHP file limit also excluded block metadata.');
        $scoped->depth = 1;
        $scoped->phpFiles = 100;
        $before = callProtected($scoped, 'getPluginCacheFingerprint', ['deep/deep.php']);
        $scoped->depth = 3;
        check(callProtected($scoped, 'getPluginCacheFingerprint', ['deep/deep.php']) !== $before, 'A changed analysis scope did not invalidate stored results.');

        // An exhausted deadline stops the traversal before reading anything.
        $expired = callProtected($metrics, 'getPluginAnalysisFiles', ['multi/multi.php', microtime(true) - 1.0]);
        check($expired['files'] === [] && $expired['truncated'] === true, 'Expired deadline did not stop the scan.');

        // The fingerprint of a single-file plugin ignores WP_PLUGIN_DIR.
        $before = callProtected($metrics, 'getPluginCacheFingerprint', ['hello.php']);
        touch($root, time() + 120);
        clearstatcache();
        check(callProtected($metrics, 'getPluginCacheFingerprint', ['hello.php']) === $before, 'Single-file fingerprint depends on WP_PLUGIN_DIR.');
        check(str_starts_with($before, 'source-v'), 'Fingerprint lacks the source analysis version.');

        // The usage index persists each attempt before scanning and skips an
        // asset after repeated aborted attempts instead of retrying forever.
        $usage = (new ReflectionClass(\RRZE\MultisiteManager\Metrics\PluginThemeUsageMetricsService::class))->newInstanceWithoutConstructor();
        setProtected($usage, 'store', new \RRZE\MultisiteManager\Analysis\AnalysisStore());
        setProtected($usage, 'config', new \RRZE\MultisiteManager\Config());

        // The static source analysis is optional and disabled by default:
        // a run in its source phase is completed without reading any file.
        $disabledRun = ['status' => 'running', 'run_id' => 'run-off', 'started_at' => 10, 'phase' => 'plugin_sources', 'source_offset' => 0, 'source_total' => 2, 'total' => 3];
        update_site_option('rrze_msm_plugin_theme_usage_metrics_state', $disabledRun);
        callProtected($usage, 'runSourceBatch', [$disabledRun]);
        $afterDisabled = get_site_option('rrze_msm_plugin_theme_usage_metrics_state');
        check($GLOBALS['analyzed_assets'] === [] && $afterDisabled['status'] === 'completed' && $afterDisabled['source_analysis'] === false, 'Disabled source analysis still read plugin sources.');
        update_site_option('rrze-multisite-manager', ['monitoring_source_analysis_enabled' => 1]);
        $usage->setSourceAnalysisProviders('testAnalyzer', 'testAnalyzer', 'testFingerprint', 'testFingerprint');

        $state = ['status' => 'running', 'phase' => 'plugin_sources', 'source_offset' => 0, 'source_total' => 2];
        update_site_option('rrze_msm_plugin_theme_usage_metrics_state', $state);
        callProtected($usage, 'runSourceBatch', [$state]);
        $state = get_site_option('rrze_msm_plugin_theme_usage_metrics_state');
        check($GLOBALS['analyzed_assets'] === ['broken/broken.php'], 'First asset was not analysed.');
        check($GLOBALS['attempts_seen_by_analyzer'] === [1], 'Attempt was not persisted before the scan.');
        check((int)$state['source_offset'] === 1 && !isset($state['source_attempts']), 'Successful scan did not advance and clear its attempt.');

        // Simulate two requests that ended inside the scan of the same asset.
        $state = ['status' => 'running', 'phase' => 'plugin_sources', 'source_offset' => 0, 'source_total' => 2, 'source_attempt_asset' => 'broken/broken.php', 'source_attempts' => 2];
        $GLOBALS['analyzed_assets'] = [];
        callProtected($usage, 'runSourceBatch', [$state]);
        $state = get_site_option('rrze_msm_plugin_theme_usage_metrics_state');
        check($GLOBALS['analyzed_assets'] === [], 'Repeatedly aborted asset was analysed again.');
        check((int)$state['source_offset'] === 1 && (int)$state['source_errors'] === 1, 'Skipped asset was not counted or not passed.');
        check(str_contains((string)$state['last_error'], 'broken/broken.php'), 'Skipped asset is not named in the error.');
        $runtimeState = $state;

        // Cancelling stops a worker that is still executing with an older
        // copy of the run state: it neither scans nor overwrites the state.
        $running = ['status' => 'running', 'run_id' => 'run-a', 'started_at' => 100, 'phase' => 'plugin_sources', 'source_offset' => 0, 'source_total' => 2, 'finished_at' => 50];
        update_site_option('rrze_msm_plugin_theme_usage_metrics_state', $running);
        $GLOBALS['cron'] = [time() + 20 => ['rrze_msm_collect_plugin_theme_usage_metrics' => ['x' => ['args' => ['rrze_msm_plugin_theme_usage_metrics_batch' => true]]]]];
        check($usage->cancelRun() === 'cancelled', 'A running collection could not be cancelled.');
        $cancelled = get_site_option('rrze_msm_plugin_theme_usage_metrics_state');
        check($cancelled['status'] === 'cancelled' && $cancelled['finished_at'] === 50, 'Cancellation lost the last published snapshot marker.');
        check(empty(array_filter($GLOBALS['cron'])), 'Cancellation left a continuation event behind.');
        $GLOBALS['analyzed_assets'] = [];
        callProtected($usage, 'runSourceBatch', [$running]);
        check($GLOBALS['analyzed_assets'] === [] && get_site_option('rrze_msm_plugin_theme_usage_metrics_state')['status'] === 'cancelled', 'A stale worker continued or revived a cancelled run.');
        check($usage->cancelRun() === 'not-running', 'Cancelling an idle collection was reported as cancelled.');
        $usage->getStatus();
        check($usage->getStatus()['is_cancelled'] === true && $usage->getStatus()['is_running'] === false, 'Cancelled state is not reported.');
        $state = $runtimeState;
        update_site_option('rrze_msm_plugin_theme_usage_metrics_state', $state);

        // An attempt counter of another asset does not affect the next asset.
        $GLOBALS['analyzed_assets'] = [];
        callProtected($usage, 'runSourceBatch', [$state]);
        check($GLOBALS['analyzed_assets'] === ['fine/fine.php'], 'Next asset was not analysed after a skipped one.');
        // The shortcode/block analysis reads provider data from the stored
        // source findings instead of scanning plugin files itself.
        $GLOBALS['wpdb']->sourceRows = [
            ['plugin_file' => 'fine/fine.php', 'payload' => json_encode(['shortcodes' => ['fine_tag'], 'blocks' => [['name' => 'fine/block', 'source' => 'php']]])],
            ['plugin_file' => 'broken/broken.php', 'payload' => 'not json'],
        ];
        check($usage->getPluginSourceAnalyses(['fine/fine.php']) === [], 'Source findings were used before the usage index completed a run.');
        update_site_option('rrze_msm_plugin_theme_usage_metrics_state', ['status' => 'completed', 'finished_at' => time()]);
        $analyses = $usage->getPluginSourceAnalyses(['fine/fine.php', 'broken/broken.php', 'fine/fine.php', '']);
        check(array_keys($analyses) === ['fine/fine.php'], 'Stored source findings were not decoded per plugin.');

        $scheduler = (new ReflectionClass(\RRZE\MultisiteManager\ShortcodeBlockAnalysisSchedulerService::class))->newInstanceWithoutConstructor();
        $shortcodes = ['fine_tag' => ['name' => 'Earlier', 'plugin_file' => 'earlier.php']];
        $blocks = [];
        $provider = ['name' => 'Fine', 'plugin_file' => 'fine/fine.php'];
        $method = new ReflectionMethod($scheduler, 'addSourceRegistrations');
        $method->invokeArgs($scheduler, [&$shortcodes, &$blocks, $analyses['fine/fine.php'], $provider]);
        check($shortcodes['fine_tag']['name'] === 'Earlier', 'A later provider replaced the first matching registration.');
        check(($blocks['fine/block'] ?? []) === $provider, 'Stored block registration was not attributed to its plugin.');
    } finally {
        removeDirectory($root);
    }

    echo "PASS: source analysis isolates single-file plugins, skips dependency/hidden/symlinked directories, honours depth, PHP-file, size and time limits, stops retrying aborted assets, can be cancelled safely, is optional, and provides stored findings for shortcode/block provider attribution.\n";
}
