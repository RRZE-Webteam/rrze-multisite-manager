<?php
/** Run outside WordPress: php tests/Standalone/plugin-registrations.php */
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
require dirname(__DIR__, 2) . '/includes/Metrics/MetricsServicePluginTrait.php';
require dirname(__DIR__, 2) . '/includes/Support/ProcessLocks.php';
require dirname(__DIR__, 2) . '/includes/Metrics/PluginThemeUsageMetricsService.php';

function plugin_basename(string $path): string { return basename($path); }
function get_plugins(): array { throw new RuntimeException('Unexpected plugin enumeration.'); }
function get_site_option(string $key, mixed $default): mixed { return $GLOBALS['state'] ?? $default; }
function update_site_option(string $key, mixed $value): void { $GLOBALS['state'] = $value; }

class WP_Block_Type_Registry {
    public static function get_instance(): self { return new self(); }
    public function get_all_registered(): array {
        return ['example/rendered' => (object)['render_callback' => 'sampleCallback'], 'example/static' => (object)['render_callback' => null]];
    }
}
function sampleCallback(): void { throw new RuntimeException('The callback must never execute.'); }
class RegistrationProbe {
    use RRZE\MultisiteManager\Metrics\MetricsServicePluginTrait;
    public function inspect(string $plugin): array { return $this->getLivePluginRegistrations($plugin); }
}
$shortcode_tags = ['owned' => 'sampleCallback', 'unrelated' => 'strlen'];
$wp_filter = ['init' => (object)['callbacks' => [10 => [['function' => 'sampleCallback']]]]];
$probe = new RegistrationProbe();
if ($probe->inspect(basename(__FILE__)) !== ['shortcodes' => ['owned'], 'blocks' => ['example/rendered'], 'hooks' => ['init']]
    || $probe->inspect('absent/plugin.php') !== ['shortcodes' => [], 'blocks' => [], 'hooks' => []]) {
    throw new RuntimeException('Incorrect attribution of live registrations.');
}

class LegacyUsageProbe extends RRZE\MultisiteManager\Metrics\PluginThemeUsageMetricsService {
    public function __construct() {}
    protected function isActiveRun(array $state): bool { return true; }
    public function step(): void { $this->runBatch(); }
}
foreach (['plugin_sources', 'theme_sources'] as $phase) {
    $GLOBALS['state'] = ['status' => 'running', 'phase' => $phase, 'total' => 7];
    (new LegacyUsageProbe())->step();
    if ($GLOBALS['state']['status'] !== 'completed' || $GLOBALS['state']['offset'] !== 7) {
        throw new RuntimeException('Legacy source phase did not finish.');
    }
}
echo "PASS: current registrations only, callback isolation and legacy usage-index continuation.\n";
