<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

/** WordPress-admin table for the grouped shortcode or block findings. */
class ContentAnalysisListTable extends \WP_List_Table {
    private string $kind;
    private array $findings;
    private int $total;
    private int $perPage;
    private ?Config $config;
    private string $pluginDetailsBaseUrl;

    public function __construct(string $kind, array $findings, int $total, int $perPage, ?Config $config = null, string $pluginDetailsBaseUrl = '') {
        $this->kind = $kind === 'blocks' ? 'blocks' : 'shortcodes';
        $this->findings = $findings;
        $this->total = max(0, $total);
        $this->perPage = max(1, $perPage);
        $this->config = $config;
        $this->pluginDetailsBaseUrl = $pluginDetailsBaseUrl;

        parent::__construct([
            'singular' => $this->kind === 'blocks' ? 'block' : 'shortcode',
            'plural' => $this->kind,
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        if ($this->kind === 'blocks') {
            return [
                'name' => _x('Block', 'Block editor content', 'rrze-multisite-manager'),
                'category' => __('Category', 'rrze-multisite-manager'),
                'description' => __('Description', 'rrze-multisite-manager'),
                'locations' => __('Location(s)', 'rrze-multisite-manager'),
            ];
        }

        $columns = [
            'name' => __('Shortcode', 'rrze-multisite-manager'),
        ];

        if (Config::SHOW_SHORTCODE_PROVIDER_COLUMN) {
            $columns['provider'] = __('Plugin', 'rrze-multisite-manager');
        }

        $columns['locations'] = __('Location(s)', 'rrze-multisite-manager');
        $columns['raw_shortcodes'] = __('Raw shortcode', 'rrze-multisite-manager');

        return $columns;
    }

    protected function get_sortable_columns() {
        return [
            'name' => ['name', false],
            'category' => ['category', false],
            'description' => ['description', false],
        ];
    }

    public function prepare_items() {
        $this->items = array_values($this->groupFindings());
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->set_pagination_args([
            'total_items' => $this->total,
            'per_page' => $this->perPage,
        ]);
    }

    public function no_items() {
        esc_html_e('No results are available yet.', 'rrze-multisite-manager');
    }

    protected function column_name(array $item): string {
        if ($this->kind === 'blocks') {
            return '<strong>' . esc_html((string)($item['block_title'] ?? $item['name'])) . '</strong><br><code>' . esc_html((string)$item['name']) . '</code>';
        }

        return '<strong>' . esc_html((string)$item['name']) . '</strong>';
    }

    protected function column_provider(array $item): string {
        $known = $this->config instanceof Config ? $this->config->getKnownShortcode((string)$item['name']) : [];

        if (empty($known)) {
            return '<span class="description">' . esc_html__('Unknown', 'rrze-multisite-manager') . '</span>';
        }

        if ($known['file'] !== '' && $this->pluginDetailsBaseUrl !== '' && file_exists(trailingslashit(WP_PLUGIN_DIR) . $known['file'])) {
            return '<a href="' . esc_url(add_query_arg('plugin', $known['file'], $this->pluginDetailsBaseUrl)) . '">' . esc_html($known['plugin']) . '</a>';
        }

        return esc_html($known['plugin']);
    }

    protected function column_locations(array $item): string {
        $locations = array_values((array)($item['locations'] ?? []));
        $output = '<ul>';
        foreach (array_slice($locations, 0, 10) as $location) {
            $output .= $this->renderLocation((array)$location);
        }
        $output .= '</ul>';

        if (count($locations) > 10) {
            $output .= '<details><summary>' . esc_html__('Show more…', 'rrze-multisite-manager') . '</summary><ul>';
            foreach (array_slice($locations, 10) as $location) {
                $output .= $this->renderLocation((array)$location);
            }
            $output .= '</ul></details>';
        }

        return $output;
    }

    protected function column_raw_shortcodes(array $item): string {
        $shortcodes = array_values((array)($item['raw_shortcodes'] ?? []));
        $output = '<ul>';
        foreach (array_slice($shortcodes, 0, 10) as $shortcode) {
            $output .= '<li><code>' . esc_html((string)$shortcode) . '</code></li>';
        }
        $output .= '</ul>';

        if (count($shortcodes) > 10) {
            $output .= '<details><summary>' . esc_html__('Show more…', 'rrze-multisite-manager') . '</summary><ul>';
            foreach (array_slice($shortcodes, 10) as $shortcode) {
                $output .= '<li><code>' . esc_html((string)$shortcode) . '</code></li>';
            }
            $output .= '</ul></details>';
        }

        return $output;
    }

    // WP_List_Table declares this extension point without parameter types.
    // Keep the signature compatible with all supported WordPress versions.
    protected function column_default($item, $columnName) {
        return esc_html((string)($item[$columnName] ?? ''));
    }

    /** @return array<string, array<string, mixed>> */
    private function groupFindings(): array {
        $groups = [];
        $nameKey = $this->kind === 'blocks' ? 'block' : 'shortcode';

        foreach ($this->findings as $finding) {
            if (!is_array($finding) || empty($finding[$nameKey])) {
                continue;
            }
            $name = (string)$finding[$nameKey];
            $key = strtolower($name);
            if (!isset($groups[$key])) {
                $groups[$key] = $finding + ['name' => $name, 'locations' => [], 'raw_shortcodes' => []];
                $groups[$key]['name'] = $name;
            }

            if ($this->kind === 'shortcodes') {
                $raw = (string)($finding['raw_shortcode'] ?? '[' . $name . ']');
                $groups[$key]['raw_shortcodes'][$raw] = $raw;
            }

            foreach (['block_title', 'origin', 'description', 'category'] as $detailKey) {
                if (empty($groups[$key][$detailKey]) && !empty($finding[$detailKey])) {
                    $groups[$key][$detailKey] = $finding[$detailKey];
                }
            }

            $locationKey = !empty($finding['post_id'])
                ? 'post-' . (int)$finding['post_id']
                : 'url-' . md5((string)($finding['post_url'] ?? '') . (string)($finding['post_title'] ?? ''));
            if (!isset($groups[$key]['locations'][$locationKey])) {
                $groups[$key]['locations'][$locationKey] = ['post_title' => (string)($finding['post_title'] ?? ''), 'post_url' => (string)($finding['post_url'] ?? ''), 'meta_keys' => [], 'occurrences' => 0];
            }
            // Block findings carry their per-post occurrence count. Results
            // from older releases stored one finding per occurrence instead.
            $groups[$key]['locations'][$locationKey]['occurrences'] += max(1, (int)($finding['occurrences'] ?? 1));
            if (!empty($finding['metadata_key'])) {
                $groups[$key]['locations'][$locationKey]['meta_keys'][(string)$finding['metadata_key']] = (string)$finding['metadata_key'];
            }
        }

        foreach ($groups as &$group) {
            $group['locations'] = array_values((array)$group['locations']);
            $group['raw_shortcodes'] = array_values((array)$group['raw_shortcodes']);
        }
        unset($group);

        return $groups;
    }

    private function renderLocation(array $location): string {
        $title = (string)($location['post_title'] ?? '');
        $url = (string)($location['post_url'] ?? '');
        $output = '<li>';
        $output .= $url !== ''
            ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($title) . '</a>'
            : esc_html($title);
        $occurrences = (int)($location['occurrences'] ?? 0);
        if ($this->kind === 'blocks' && $occurrences > 1) {
            $output .= ' <span class="description">' . esc_html(sprintf(
                /* translators: %d: number of occurrences of a block in one post. */
                _n('(%d occurrence)', '(%d occurrences)', $occurrences, 'rrze-multisite-manager'),
                $occurrences
            )) . '</span>';
        }
        $metaKeys = array_values((array)($location['meta_keys'] ?? []));
        if ($metaKeys) {
            $output .= ' <span class="description">' . esc_html(sprintf(
                /* translators: %s: comma-separated post meta field names. */
                __('Meta fields: %s', 'rrze-multisite-manager'),
                implode(', ', $metaKeys)
            )) . '</span>';
        }
        return $output . '</li>';
    }
}
