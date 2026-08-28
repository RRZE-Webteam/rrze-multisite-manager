<?php
defined('ABSPATH') || exit;
$metadataAnalysisStatus = (string)($media_metadata_analysis['status'] ?? 'idle');
$metadataCounts = is_array($media_metadata_analysis['counts'] ?? null) ? (array)$media_metadata_analysis['counts'] : [];
$metadataResults = is_array($media_metadata_analysis['results'] ?? null) ? (array)$media_metadata_analysis['results'] : [];
$metadataTables = [
    'images' => [
        'title' => __('Images with missing metadata', 'rrze-multisite-manager'),
        'fields' => [
            'alt' => __('Alternative text', 'rrze-multisite-manager'),
            'caption' => __('Caption', 'rrze-multisite-manager'),
            'description' => __('Description', 'rrze-multisite-manager'),
        ],
    ],
    'documents' => [
        'title' => __('Documents with missing metadata', 'rrze-multisite-manager'),
        'fields' => [
            'caption' => __('Short description', 'rrze-multisite-manager'),
            'description' => __('Description', 'rrze-multisite-manager'),
        ],
    ],
    'spreadsheets' => [
        'title' => __('Spreadsheets with missing metadata', 'rrze-multisite-manager'),
        'fields' => [
            'caption' => __('Short description', 'rrze-multisite-manager'),
            'description' => __('Description', 'rrze-multisite-manager'),
        ],
    ],
    'audio_video' => [
        'title' => __('Audio and video files with missing metadata', 'rrze-multisite-manager'),
        'fields' => [
            'caption' => __('Short description', 'rrze-multisite-manager'),
            'description' => __('Description', 'rrze-multisite-manager'),
        ],
    ],
];
?>
<section class="rrze-msm-widget rrze-msm-widget-span-12">
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html__('Missing metadata', 'rrze-multisite-manager'); ?></h2>
        <p><?php echo esc_html__('Checks media library entries for missing accessibility and descriptive metadata.', 'rrze-multisite-manager'); ?></p>
    </header>
    <div id="rrze-msm-media-metadata-runner" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-status="<?php echo esc_attr($metadataAnalysisStatus); ?>">
        <p id="rrze-msm-media-metadata-message"><?php echo esc_html((string)($media_metadata_analysis['message'] ?? __('Start the analysis to check the media library.', 'rrze-multisite-manager'))); ?></p>
        <form class="rrze-msm-site-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="rrze_multisite_manager_run_site_media_metadata_analysis">
            <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
            <input type="hidden" name="restart" value="<?php echo esc_attr($metadataAnalysisStatus === 'complete' ? '1' : '0'); ?>">
            <?php wp_nonce_field('rrze-msm-site-media-metadata-analysis', 'rrze_msm_site_media_metadata_nonce'); ?>
            <button type="submit" class="button button-primary rrze-msm-start-media-metadata-analysis"><?php echo esc_html($metadataAnalysisStatus === 'complete' ? __('Refresh analysis', 'rrze-multisite-manager') : __('Start analysis', 'rrze-multisite-manager')); ?></button>
        </form>
    </div>
</section>
<?php if ($metadataAnalysisStatus === 'complete') { ?>
    <?php foreach ($metadataTables as $category => $table) { ?>
        <?php if ((int)($metadataCounts[$category] ?? 0) <= 0) { continue; } ?>
        <section class="rrze-msm-widget rrze-msm-widget-span-12">
            <header class="rrze-msm-widget-header"><h2><?php echo esc_html((string)$table['title']); ?></h2></header>
            <?php if (empty($metadataResults[$category])) { ?>
                <p><?php echo esc_html__('No incomplete metadata was found.', 'rrze-multisite-manager'); ?></p>
            <?php } else { ?>
                <div class="rrze-msm-site-table-wrap" data-table-id="media-metadata-<?php echo esc_attr($category); ?>" data-default-per-page="20" data-current-page="1" data-sort-key="missing" data-sort-direction="desc">
                    <table class="widefat striped rrze-msm-table">
                        <thead><tr>
                            <?php if ($category === 'images') { ?><th><?php echo esc_html__('Preview', 'rrze-multisite-manager'); ?></th><?php } ?>
                            <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                            <?php foreach ((array)$table['fields'] as $fieldLabel) { ?><th class="rrze-msm-media-metadata-status"><?php echo esc_html((string)$fieldLabel); ?></th><?php } ?>
                            <th><?php echo esc_html__('Last modified', 'rrze-multisite-manager'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ((array)$metadataResults[$category] as $entry) { ?>
                                <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($entry['title'] ?? ''))); ?>" data-sort-missing="<?php echo esc_attr((string)($entry['missing_count'] ?? 0)); ?>">
                                    <?php if ($category === 'images') { ?><td><?php if (!empty($entry['preview_url'])) { ?><a href="<?php echo esc_url((string)$entry['media_edit_url']); ?>"><img class="rrze-msm-media-metadata-preview" src="<?php echo esc_url((string)$entry['preview_url']); ?>" alt=""></a><?php } ?></td><?php } ?>
                                    <td><?php if (!empty($entry['media_edit_url'])) { ?><a href="<?php echo esc_url((string)$entry['media_edit_url']); ?>"><?php echo esc_html((string)($entry['title'] ?? '')); ?></a><?php } else { echo esc_html((string)($entry['title'] ?? '')); } ?></td>
                                    <?php foreach (array_keys((array)$table['fields']) as $fieldName) { ?><td class="rrze-msm-media-metadata-status"><?php if (!empty($entry['fields'][$fieldName])) { ?><span class="dashicons dashicons-yes-alt rrze-msm-media-metadata-present"><span class="screen-reader-text"><?php echo esc_html__('Available', 'rrze-multisite-manager'); ?></span></span><?php } else { ?><span class="dashicons dashicons-dismiss rrze-msm-media-metadata-missing"><span class="screen-reader-text"><?php echo esc_html__('Missing', 'rrze-multisite-manager'); ?></span></span><?php } ?></td><?php } ?>
                                    <td><?php echo esc_html((string)($entry['modified_label'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                    <div class="tablenav bottom"><div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div></div>
                </div>
            <?php } ?>
        </section>
    <?php } ?>
<?php } ?>
