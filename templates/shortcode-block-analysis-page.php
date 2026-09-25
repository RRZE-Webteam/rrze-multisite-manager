<?php

defined('ABSPATH') || exit;

$analysisTab = $analysis_tab === 'blocks' ? 'blocks' : 'shortcodes';
$filter = isset($_GET['analysis_filter']) ? sanitize_text_field((string)wp_unslash($_GET['analysis_filter'])) : '';
$filterTerms = array_values(array_unique(array_filter(array_map(
    static fn(string $term): string => strtolower(trim($term)),
    explode(',', $filter)
), static fn(string $term): bool => $term !== '')));
$entries = is_array($analysis_result[$analysisTab] ?? null) ? $analysis_result[$analysisTab] : [];
$entryNameKey = $analysisTab === 'blocks' ? 'block' : 'shortcode';
$baseArgs = ['site_id' => (int)$site_id, 'analysis_tab' => $analysisTab];
$canViewPluginDetails = !empty($can_view_plugin_details);
$shortcodeUrl = add_query_arg(['site_id' => (int)$site_id, 'analysis_tab' => 'shortcodes'], $analysis_base_url);
$blockUrl = add_query_arg(['site_id' => (int)$site_id, 'analysis_tab' => 'blocks'], $analysis_base_url);
$sortColumns = $analysisTab === 'shortcodes'
    ? ['shortcode', 'registered', 'provider']
    : ['block', 'category', 'origin', 'description'];
$sortBy = isset($_GET['analysis_sort']) ? sanitize_key((string)wp_unslash($_GET['analysis_sort'])) : $entryNameKey;
$sortBy = in_array($sortBy, $sortColumns, true) ? $sortBy : $entryNameKey;
$sortOrder = isset($_GET['analysis_order']) ? sanitize_key((string)wp_unslash($_GET['analysis_order'])) : 'asc';
$sortOrder = $sortOrder === 'desc' ? 'desc' : 'asc';
$analysisGeneratedAt = (string)($analysis_result['generated_at'] ?? '');
$analysisNextRunTimestamp = (int)$analysis_next_run_timestamp;
$getDistinctEntryCount = static function (array $items, string $key): int {
    $names = [];

    foreach ($items as $item) {
        if (!is_array($item) || empty($item[$key])) {
            continue;
        }

        $names[strtolower((string)$item[$key])] = true;
    }

    return count($names);
};
$foundShortcodeCount = $getDistinctEntryCount((array)($analysis_result['shortcodes'] ?? []), 'shortcode');
$foundBlockCount = $getDistinctEntryCount((array)($analysis_result['blocks'] ?? []), 'block');
$unregisteredShortcodes = [];

foreach ((array)($analysis_result['shortcodes'] ?? []) as $shortcode) {
    if (!is_array($shortcode) || !empty($shortcode['registered']) || empty($shortcode['shortcode'])) {
        continue;
    }

    $unregisteredShortcodes[strtolower((string)$shortcode['shortcode'])] = true;
}

$unregisteredShortcodeCount = count($unregisteredShortcodes);
$analysedPostCount = max(
    (int)($analysis_status['total_posts'] ?? 0),
    (int)($analysis_status['processed_posts'] ?? 0),
    (int)($analysis_status['phases']['shortcodes']['total_posts'] ?? 0),
    (int)($analysis_status['phases']['blocks']['total_posts'] ?? 0),
    (int)($analysis_result['total_posts'] ?? 0),
    (int)($analysis_result['processed_posts'] ?? 0)
);
$getEntrySortValue = static function (array $entry, string $column): string {
    if ($column === 'registered') {
        return !empty($entry['registered']) ? '1' : '0';
    }

    return (string)($entry[$column] ?? '');
};
$getSortUrl = static function (string $column) use ($analysis_base_url, $site_id, $analysisTab, $filter, $sortBy, $sortOrder): string {
    $nextOrder = $column === $sortBy && $sortOrder === 'asc' ? 'desc' : 'asc';
    $args = [
        'site_id' => (int)$site_id,
        'analysis_tab' => $analysisTab,
        'analysis_sort' => $column,
        'analysis_order' => $nextOrder,
    ];

    if ($filter !== '') {
        $args['analysis_filter'] = $filter;
    }

    return add_query_arg($args, $analysis_base_url);
};
$renderSortHeader = static function (string $column, string $label) use ($getSortUrl, $sortBy, $sortOrder): string {
    $isCurrent = $column === $sortBy;
    $indicator = $isCurrent ? ($sortOrder === 'asc' ? ' ▲' : ' ▼') : '';
    $ariaSort = $isCurrent ? ($sortOrder === 'asc' ? 'ascending' : 'descending') : 'none';

    return '<th aria-sort="' . esc_attr($ariaSort) . '"><a href="' . esc_url($getSortUrl($column)) . '">' . esc_html($label . $indicator) . '</a></th>';
};
$renderLocation = static function (array $location): string {
    $title = (string)($location['post_title'] ?? '');
    $url = (string)($location['post_url'] ?? '');
    $metaKeys = (array)($location['meta_keys'] ?? []);
    $html = '<li>';

    if ($url !== '') {
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($title) . '</a>';
    } else {
        $html .= esc_html($title);
    }

    if (!empty($metaKeys)) {
        /* translators: %s: comma-separated post meta field names. */
        $html .= ' <span class="description">' . esc_html(sprintf(__('Meta fields: %s', 'rrze-multisite-manager'), implode(', ', $metaKeys))) . '</span>';
    }

    return $html . '</li>';
};

if (!empty($filterTerms)) {
    $entries = array_values(array_filter($entries, static function ($entry) use ($entryNameKey, $filterTerms) {
        if (!is_array($entry)) {
            return false;
        }

        $entryName = strtolower((string)($entry[$entryNameKey] ?? ''));

        foreach ($filterTerms as $filterTerm) {
            if (str_contains($entryName, $filterTerm)) {
                return true;
            }
        }

        return false;
    }));
}

$groupedEntries = [];

foreach ($entries as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $entryName = (string)($entry[$entryNameKey] ?? '');

    if ($entryName === '') {
        continue;
    }

    $groupKey = strtolower($entryName);

    if (!isset($groupedEntries[$groupKey])) {
        if ($analysisTab === 'shortcodes' && empty($entry['provider'])) {
            $entry['provider'] = __('Unknown', 'rrze-multisite-manager');
        }

        $entry['locations'] = [];
        $entry['raw_shortcodes'] = [];
        $entry['location_sort'] = '';
        $groupedEntries[$groupKey] = $entry;
    }

    if ($analysisTab === 'shortcodes') {
        $rawShortcode = (string)($entry['raw_shortcode'] ?? '[' . $entryName . ']');
        $groupedEntries[$groupKey]['raw_shortcodes'][$rawShortcode] = $rawShortcode;
    }

    foreach (['block_title', 'origin', 'description', 'category'] as $detailKey) {
        if (empty($groupedEntries[$groupKey][$detailKey]) && !empty($entry[$detailKey])) {
            $groupedEntries[$groupKey][$detailKey] = $entry[$detailKey];
        }
    }

    $locationKey = !empty($entry['post_id'])
        ? 'post-' . (int)$entry['post_id']
        : 'url-' . md5((string)($entry['post_url'] ?? '') . (string)($entry['post_title'] ?? ''));

    if (!isset($groupedEntries[$groupKey]['locations'][$locationKey])) {
        $groupedEntries[$groupKey]['locations'][$locationKey] = [
            'post_title' => (string)($entry['post_title'] ?? ''),
            'post_url' => (string)($entry['post_url'] ?? ''),
            'meta_keys' => [],
        ];
    }

    if (!empty($entry['meta_key'])) {
        $groupedEntries[$groupKey]['locations'][$locationKey]['meta_keys'][(string)$entry['meta_key']] = (string)$entry['meta_key'];
    }
}

$entries = array_values($groupedEntries);

foreach ($entries as &$entry) {
    $entry['locations'] = array_values((array)($entry['locations'] ?? []));
    $entry['raw_shortcodes'] = array_values((array)($entry['raw_shortcodes'] ?? []));
    $entry['location_sort'] = implode(' ', array_map(static fn ($location): string => (string)($location['post_title'] ?? ''), $entry['locations']));
}
unset($entry);

$showBlockOrigin = $analysisTab === 'blocks' && (bool)array_filter($entries, static fn ($entry): bool => trim((string)($entry['origin'] ?? '')) !== '');
$showBlockDescription = $analysisTab === 'blocks' && (bool)array_filter($entries, static fn ($entry): bool => trim((string)($entry['description'] ?? '')) !== '');
$columnCount = $analysisTab === 'shortcodes'
    ? 5
    : 3 + (int)$showBlockOrigin + (int)$showBlockDescription;

usort($entries, static function ($left, $right) use ($getEntrySortValue, $sortBy, $sortOrder) {
    $left = is_array($left) ? $left : [];
    $right = is_array($right) ? $right : [];
    $comparison = strnatcasecmp($getEntrySortValue($left, $sortBy), $getEntrySortValue($right, $sortBy));

    if ($comparison === 0) {
        $comparison = strnatcasecmp((string)($left['location_sort'] ?? ''), (string)($right['location_sort'] ?? ''));
    }

    return $sortOrder === 'desc' ? -$comparison : $comparison;
});
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Shortcodes and Blocks', 'rrze-multisite-manager'); ?></h1>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr(str_contains($mode_class, 'dark') ? 'light' : 'dark'); ?>"><?php echo esc_html($mode_toggle_label); ?></button>
            </div>
        </div>

        <?php if (!$is_local_page && $site_id <= 0) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-details-selector rrze-msm-details-selector-empty">
                <div class="rrze-msm-site-search-wrap">
                    <input id="rrze-msm-site-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($site_search_placeholder); ?>" autocomplete="off">
                    <div class="rrze-msm-site-search-results" id="rrze-msm-site-search-results"></div>
                </div>
            </section>
        <?php } elseif (!empty($site_summary)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html((string)($site_summary['name'] ?? '')); ?></h2>
                    <p><?php echo esc_html((string)($site_summary['url'] ?? '')); ?></p>
                </header>
                <?php if ($requested === '1') { ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html__('The shortcode and block analysis has been scheduled.', 'rrze-multisite-manager'); ?></p></div>
                <?php } elseif ($requested === 'running') { ?>
                    <div class="notice notice-info inline"><p><?php echo esc_html__('The shortcode and block analysis is already running.', 'rrze-multisite-manager'); ?></p></div>
                <?php } ?>
                <ul>
                    <li>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: date and time of the last analysis, 2: date and time of the next scheduled analysis. */
                                __('Last analysis: %1$s (Next scheduled analysis: %2$s)', 'rrze-multisite-manager'),
                                $analysisGeneratedAt !== '' ? get_date_from_gmt($analysisGeneratedAt, 'd.m.Y H:i') : '-',
                                $analysisNextRunTimestamp > 0 ? wp_date('d.m.Y H:i', $analysisNextRunTimestamp) : '-'
                            )
                        );
                        ?>
                    </li>
                    <li><?php
                    /* translators: %d: number of searched posts and pages. */
                    echo esc_html(sprintf(__('Searched posts and pages: %d', 'rrze-multisite-manager'), $analysedPostCount));
                    ?></li>
                    <li><?php
                    /* translators: %d: number of distinct blocks found. */
                    echo esc_html(sprintf(__('Found blocks: %d', 'rrze-multisite-manager'), $foundBlockCount));
                    ?></li>
                    <li><?php
                    /* translators: %d: number of distinct shortcodes found. */
                    echo esc_html(sprintf(__('Found shortcodes: %d', 'rrze-multisite-manager'), $foundShortcodeCount));
                    ?></li>
                </ul>
                <?php if ($unregisteredShortcodeCount > 0) { ?>
                    <div class="notice notice-warning inline"><p><?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of unregistered shortcodes found. */
                            _n(
                                'Notice: %d unregistered shortcode was found. It is not executed and is therefore output without interpretation.',
                                'Notice: %d unregistered shortcodes were found. They are not executed and are therefore output without interpretation.',
                                $unregisteredShortcodeCount,
                                'rrze-multisite-manager'
                            ),
                            $unregisteredShortcodeCount
                        )
                    );
                    ?></p></div>
                <?php } ?>
                <?php if (!empty($can_request_analysis)) { ?>
                    <form method="post" action="<?php echo esc_url($request_action); ?>">
                        <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr(add_query_arg(['site_id' => (int)$site_id, 'analysis_tab' => $analysisTab], $analysis_base_url)); ?>">
                        <?php wp_nonce_field('rrze_msm_request_shortcode_block_analysis_' . (int)$site_id); ?>
                        <button type="submit" class="button button-secondary"><?php echo esc_html__('Request analysis', 'rrze-multisite-manager'); ?></button>
                    </form>
                <?php } ?>
            </section>

            <nav class="rrze-msm-subtabs" aria-label="<?php echo esc_attr__('Analysis result types', 'rrze-multisite-manager'); ?>">
                <a class="rrze-msm-subtab<?php echo $analysisTab === 'shortcodes' ? ' is-active' : ''; ?>" href="<?php echo esc_url($shortcodeUrl); ?>"><?php echo esc_html__('Shortcodes', 'rrze-multisite-manager'); ?></a>
                <a class="rrze-msm-subtab<?php echo $analysisTab === 'blocks' ? ' is-active' : ''; ?>" href="<?php echo esc_url($blockUrl); ?>"><?php echo esc_html__('Blocks', 'rrze-multisite-manager'); ?></a>
            </nav>

            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html($analysisTab === 'blocks' ? __('Blocks', 'rrze-multisite-manager') : __('Shortcodes', 'rrze-multisite-manager')); ?></h2>
                </header>
                <form method="get" class="rrze-msm-table-filter">
                    <?php foreach ($baseArgs as $key => $value) { ?>
                        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string)$value); ?>">
                    <?php } ?>
                    <input type="hidden" name="page" value="<?php echo esc_attr((string)($_GET['page'] ?? '')); ?>">
                    <label for="rrze-msm-analysis-filter"><?php echo esc_html($analysisTab === 'blocks' ? __('Filter blocks', 'rrze-multisite-manager') : __('Filter shortcodes', 'rrze-multisite-manager')); ?></label>
                    <input id="rrze-msm-analysis-filter" type="search" name="analysis_filter" value="<?php echo esc_attr($filter); ?>">
                    <button type="submit" class="button button-secondary"><?php echo esc_html__('Filter', 'rrze-multisite-manager'); ?></button>
                </form>
                <table class="widefat striped rrze-msm-table rrze-msm-shortcode-analysis-table">
                    <thead><tr>
                        <?php echo $renderSortHeader($entryNameKey, $analysisTab === 'blocks' ? _x('Block', 'Block editor content', 'rrze-multisite-manager') : __('Shortcode', 'rrze-multisite-manager')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Header helper escapes URL and label. ?>
                        <?php if ($analysisTab === 'shortcodes') { ?>
                            <?php echo $renderSortHeader('registered', __('Registered', 'rrze-multisite-manager')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Header helper escapes URL and label. ?>
                            <?php echo $renderSortHeader('provider', __('Provider', 'rrze-multisite-manager')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Header helper escapes URL and label. ?>
                        <?php } else { ?>
                            <?php echo $renderSortHeader('category', __('Category', 'rrze-multisite-manager')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Header helper escapes URL and label. ?>
                            <?php if ($showBlockOrigin) { ?><?php echo $renderSortHeader('origin', __('Origin', 'rrze-multisite-manager')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Header helper escapes URL and label. ?><?php } ?>
                        <?php } ?>
                        <th><?php echo esc_html__('Location(s)', 'rrze-multisite-manager'); ?></th>
                        <?php if ($analysisTab === 'shortcodes') { ?><th><?php echo esc_html__('Raw shortcode', 'rrze-multisite-manager'); ?></th><?php } ?>
                        <?php if ($analysisTab === 'blocks' && $showBlockDescription) { ?><?php echo $renderSortHeader('description', __('Description', 'rrze-multisite-manager')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Header helper escapes URL and label. ?><?php } ?>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($entries)) { ?>
                            <tr><td colspan="<?php echo esc_attr((string)$columnCount); ?>"><?php echo esc_html__('No results are available yet.', 'rrze-multisite-manager'); ?></td></tr>
                        <?php } else { foreach ($entries as $entry) { ?>
                            <tr>
                                <td><strong><?php echo esc_html((string)($entry[$analysisTab === 'blocks' ? 'block_title' : $entryNameKey] ?? ($entry[$entryNameKey] ?? ''))); ?></strong><?php if ($analysisTab === 'blocks') { ?><br><code><?php echo esc_html((string)($entry['block'] ?? '')); ?></code><?php } ?></td>
                                <?php if ($analysisTab === 'shortcodes') { ?>
                                    <td>
                                        <?php if (!empty($entry['registered'])) { ?>
                                            <?php echo esc_html__('Yes', 'rrze-multisite-manager'); ?>
                                        <?php } else { ?>
                                            <span class="rrze-msm-shortcode-unregistered"><span class="dashicons dashicons-no" aria-hidden="true"></span><?php echo esc_html__('No', 'rrze-multisite-manager'); ?></span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php
                                        $providerName = (string)($entry['provider'] ?? __('Unknown', 'rrze-multisite-manager'));
                                        $providerPluginFile = (string)($entry['provider_plugin_file'] ?? '');

                                        if ($canViewPluginDetails && $providerPluginFile !== '') {
                                            $providerUrl = add_query_arg('plugin', $providerPluginFile, $plugin_details_base_url);
                                            echo '<a href="' . esc_url($providerUrl) . '">' . esc_html($providerName) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link URL and label are escaped above.
                                        } else {
                                            echo esc_html($providerName);
                                        }
                                        ?>
                                    </td>
                                <?php } else { ?>
                                    <td><?php echo esc_html((string)($entry['category'] ?? '')); ?></td>
                                    <?php if ($showBlockOrigin) { ?><td><?php echo esc_html((string)($entry['origin'] ?? '')); ?></td><?php } ?>
                                <?php } ?>
                                <td>
                                    <?php $locations = (array)($entry['locations'] ?? []); ?>
                                    <ul>
                                        <?php foreach (array_slice($locations, 0, 10) as $location) { ?>
                                            <?php echo $renderLocation($location); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Location helper escapes all values. ?>
                                        <?php } ?>
                                    </ul>
                                    <?php if (count($locations) > 10) { ?>
                                        <details><summary><?php echo esc_html__('Show more…', 'rrze-multisite-manager'); ?></summary>
                                            <ul>
                                                <?php foreach (array_slice($locations, 10) as $location) { ?>
                                                    <?php echo $renderLocation($location); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Location helper escapes all values. ?>
                                                <?php } ?>
                                            </ul>
                                        </details>
                                    <?php } ?>
                                </td>
                                <?php if ($analysisTab === 'shortcodes') { ?>
                                    <td>
                                        <?php $rawShortcodes = (array)($entry['raw_shortcodes'] ?? []); ?>
                                        <ul><?php foreach (array_slice($rawShortcodes, 0, 10) as $rawShortcode) { ?><li><code><?php echo esc_html((string)$rawShortcode); ?></code></li><?php } ?></ul>
                                        <?php if (count($rawShortcodes) > 10) { ?>
                                            <details><summary><?php echo esc_html__('Show more…', 'rrze-multisite-manager'); ?></summary>
                                                <ul><?php foreach (array_slice($rawShortcodes, 10) as $rawShortcode) { ?><li><code><?php echo esc_html((string)$rawShortcode); ?></code></li><?php } ?></ul>
                                            </details>
                                        <?php } ?>
                                    </td>
                                <?php } ?>
                                <?php if ($analysisTab === 'blocks' && $showBlockDescription) { ?><td><?php echo esc_html((string)($entry['description'] ?? '')); ?></td><?php } ?>
                            </tr>
                        <?php } } ?>
                    </tbody>
                </table>
            </section>
        <?php } ?>
    </div>
</div>
