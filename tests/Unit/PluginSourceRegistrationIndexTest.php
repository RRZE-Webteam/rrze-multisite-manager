<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Analysis\PluginSourceRegistrationIndex;

class TestablePluginSourceRegistrationIndex extends PluginSourceRegistrationIndex {
    public function scanRoot(string $pluginFile, string $mainFile): string {
        return $this->getScanRoot($pluginFile, $mainFile);
    }

    public function createState(string $mainFile, string $root): array {
        return $this->initialState('test-signature', $mainFile, $root);
    }

    public function continueScan(array $state, string $root): array {
        return $this->scanStep($state, $root, microtime(true) + 2.0);
    }
}

class PluginSourceRegistrationIndexTest extends \WP_UnitTestCase {
    private string $temporaryDirectory = '';

    protected function setUp(): void {
        parent::setUp();
        $this->temporaryDirectory = trailingslashit(get_temp_dir()) . 'rrze-msm-plugin-index-' . wp_generate_uuid4();
        wp_mkdir_p($this->temporaryDirectory . '/includes/block');
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testScanFindsLiteralShortcodesAndBlocksAndResumesToCompletion(): void {
        $mainFile = $this->temporaryDirectory . '/plugin.php';
        file_put_contents($mainFile, "<?php\nadd_shortcode('example_tag', 'callback');\n");
        file_put_contents($this->temporaryDirectory . '/includes/block/register.php', "<?php\nregister_block_type('example/dynamic');\n");
        file_put_contents($this->temporaryDirectory . '/includes/block/block.json', wp_json_encode(['name' => 'example/metadata']));

        $index = new TestablePluginSourceRegistrationIndex();
        $state = $index->createState($mainFile, $this->temporaryDirectory);

        for ($step = 0; $step < 10 && empty($state['complete']); $step++) {
            $state = $index->continueScan($state, $this->temporaryDirectory);
        }

        self::assertTrue($state['complete']);
        self::assertContains('example_tag', $state['shortcodes']);
        self::assertContains('example/dynamic', $state['blocks']);
        self::assertContains('example/metadata', $state['blocks']);
        self::assertFalse($state['truncated']);
    }

    public function testSingleFilePluginUsesOnlyItsMainFileAsScanRoot(): void {
        $mainFile = $this->temporaryDirectory . '/single.php';
        file_put_contents($mainFile, "<?php\nadd_shortcode('single_tag', 'callback');\n");
        $index = new TestablePluginSourceRegistrationIndex();

        self::assertSame(wp_normalize_path($mainFile), $index->scanRoot('single.php', $mainFile));
        self::assertSame(wp_normalize_path($this->temporaryDirectory), $index->scanRoot('directory/plugin.php', $mainFile));
    }

    private function removeDirectory(string $directory): void {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
