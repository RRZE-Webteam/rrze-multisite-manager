<?php

declare(strict_types=1);

if (defined('ABSPATH')) {
    fwrite(STDERR, "This standalone test must not run inside WordPress.\n");
    exit(1);
}

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);
define('WP_PLUGIN_DIR', sys_get_temp_dir());

function wp_normalize_path(string $path): string {
    return str_replace('\\', '/', $path);
}

function trailingslashit(string $path): string {
    return rtrim($path, '/\\') . '/';
}

function sanitize_text_field(string $value): string {
    return trim(strip_tags($value));
}

require_once dirname(__DIR__, 2) . '/includes/Analysis/PluginSourceRegistrationIndex.php';

class TestPluginSourceRegistrationIndex extends \RRZE\MultisiteManager\Analysis\PluginSourceRegistrationIndex {
    public function scanRoot(string $pluginFile, string $mainFile): string {
        return $this->getScanRoot($pluginFile, $mainFile);
    }

    public function createState(string $mainFile, string $root): array {
        return $this->initialState('test', $mainFile, $root);
    }

    public function scan(array $state, string $root): array {
        return $this->scanStep($state, $root, microtime(true) + 5.0);
    }
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporary = sys_get_temp_dir() . '/rrze-msm-plugin-source-' . bin2hex(random_bytes(8));
mkdir($temporary . '/includes/block', 0777, true);

try {
    $mainFile = $temporary . '/plugin.php';
    file_put_contents($mainFile, "<?php\nadd_shortcode('example_tag', 'callback');\n");
    file_put_contents($temporary . '/includes/block/register.php', "<?php\nregister_block_type('example/dynamic');\n");
    file_put_contents($temporary . '/includes/block/block.json', json_encode(['name' => 'example/metadata']));

    // More than one step's file allowance proves that the directory cursor resumes.
    for ($index = 0; $index < 105; $index++) {
        file_put_contents($temporary . '/includes/file-' . $index . '.php', "<?php\n// bounded continuation fixture\n");
    }

    $scanner = new TestPluginSourceRegistrationIndex();
    $state = $scanner->createState($mainFile, $temporary);
    $state = $scanner->scan($state, $temporary);
    check(empty($state['complete']), 'The first bounded step unexpectedly completed the large plugin tree.');

    for ($step = 0; $step < 10 && empty($state['complete']); $step++) {
        $state = $scanner->scan($state, $temporary);
    }

    check(!empty($state['complete']), 'The plugin source scan did not resume to completion.');
    check(in_array('example_tag', $state['shortcodes'], true), 'The literal shortcode registration was not indexed.');
    check(in_array('example/dynamic', $state['blocks'], true), 'The literal PHP block registration was not indexed.');
    check(in_array('example/metadata', $state['blocks'], true), 'The block.json registration was not indexed.');
    check(empty($state['truncated']), 'The bounded fixture was incorrectly marked as truncated.');
    check($scanner->scanRoot('single.php', $mainFile) === wp_normalize_path($mainFile), 'A single-file plugin would scan the complete plugin root.');
    check($scanner->scanRoot('directory/plugin.php', $mainFile) === wp_normalize_path($temporary), 'A directory plugin did not use its own directory.');

    echo "PASS: shared plugin registration source scanning is bounded, resumable, and isolates single-file plugins.\n";
} finally {
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
}
