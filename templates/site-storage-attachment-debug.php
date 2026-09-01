<?php
defined('ABSPATH') || exit;
?>
<section class="rrze-msm-widget rrze-msm-widget-span-12">
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html__('Media file details', 'rrze-multisite-manager'); ?></h2>
        <p><?php echo esc_html__('Checks a specific media library file and shows how the storage analysis currently classifies it.', 'rrze-multisite-manager'); ?></p>
    </header>
    <form method="get" class="rrze-msm-site-actions">
        <input type="hidden" name="page" value="<?php echo esc_attr(isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : ''); ?>">
        <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
        <input type="hidden" name="storage_tab" value="debug">
        <label for="rrze-msm-debug-attachment-id"><?php echo esc_html__('Attachment ID', 'rrze-multisite-manager'); ?></label>
        <input id="rrze-msm-debug-attachment-id" name="debug_attachment_id" type="number" min="1" value="<?php echo esc_attr((string)$debug_attachment_id); ?>">
        <button type="submit" class="button button-secondary"><?php echo esc_html__('Run debug', 'rrze-multisite-manager'); ?></button>
    </form>
    <?php if (!empty($attachment_debug['error'])) { ?>
        <div class="notice notice-error inline"><p><?php echo esc_html((string)$attachment_debug['error']); ?></p></div>
    <?php } elseif (!empty($attachment_debug)) { ?>
        <table class="striped rrze-msm-datatable">
            <tbody>
                <tr><th><?php echo esc_html__('Attachment ID', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['attachment_id'] ?? '')); ?></td></tr>
                <tr><th><?php echo esc_html__('Media type', 'rrze-multisite-manager'); ?></th><td><code><?php echo esc_html((string)($attachment_debug['mime_type'] ?? '')); ?></code></td></tr>
                <tr><th><?php echo esc_html__('Attachment exists', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['exists']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('Title', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['title'] ?? '')); ?></td></tr>
                <?php if (!empty($attachment_debug['is_image'])) { ?>
                    <tr><th><?php echo esc_html__('Alternative text', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(trim((string)($attachment_debug['metadata_fields']['alternative_text'] ?? '')) !== '' ? (string)$attachment_debug['metadata_fields']['alternative_text'] : __('Not set', 'rrze-multisite-manager')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Caption', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(trim((string)($attachment_debug['metadata_fields']['caption'] ?? '')) !== '' ? (string)$attachment_debug['metadata_fields']['caption'] : __('Not set', 'rrze-multisite-manager')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Description', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(trim((string)($attachment_debug['metadata_fields']['description'] ?? '')) !== '' ? (string)$attachment_debug['metadata_fields']['description'] : __('Not set', 'rrze-multisite-manager')); ?></td></tr>
                <?php } else { ?>
                    <tr><th><?php echo esc_html__('Short description', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(trim((string)($attachment_debug['metadata_fields']['short_description'] ?? '')) !== '' ? (string)$attachment_debug['metadata_fields']['short_description'] : __('Not set', 'rrze-multisite-manager')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Description', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(trim((string)($attachment_debug['metadata_fields']['description'] ?? '')) !== '' ? (string)$attachment_debug['metadata_fields']['description'] : __('Not set', 'rrze-multisite-manager')); ?></td></tr>
                    <?php foreach ((array)($attachment_debug['document_metadata'] ?? []) as $document_metadata_label => $document_metadata_value) { ?>
                        <tr><th><?php echo esc_html((string)$document_metadata_label); ?></th><td><?php echo esc_html((string)$document_metadata_value); ?></td></tr>
                    <?php } ?>
                <?php } ?>
                <?php foreach ((array)($attachment_debug['image_metadata'] ?? []) as $image_metadata_label => $image_metadata_value) { ?>
                    <tr><th><?php echo esc_html((string)$image_metadata_label); ?></th><td><?php echo esc_html((string)$image_metadata_value); ?></td></tr>
                <?php } ?>
                <tr><th><?php echo esc_html__('Attached file', 'rrze-multisite-manager'); ?></th><td><code><?php echo esc_html((string)($attachment_debug['attached_file'] ?? '')); ?></code></td></tr>
                <tr><th><?php echo esc_html__('Normalized path', 'rrze-multisite-manager'); ?></th><td><code><?php echo esc_html((string)($attachment_debug['normalized_path'] ?? '')); ?></code></td></tr>
                <tr><th><?php echo esc_html__('File URL', 'rrze-multisite-manager'); ?></th><td><?php if (!empty($attachment_debug['file_url'])) { ?><a href="<?php echo esc_url((string)$attachment_debug['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$attachment_debug['file_url']); ?></a><?php } ?></td></tr>
                <tr><th><?php echo esc_html__('File exists in uploads', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['file_exists']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('Attachment metadata available', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['has_attachment_metadata']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('Image size variants in metadata', 'rrze-multisite-manager'); ?></th><td class="rrze-msm-col-numeric"><?php echo esc_html((string)($attachment_debug['metadata_size_variants'] ?? 0)); ?></td></tr>
                <tr><th><?php echo esc_html__('In attachment candidate list', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_attachment_candidates']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('In attachment index', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_attachment_index']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('In cached used attachments', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_cached_used_attachments']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('In cached unused attachments', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_cached_unused_attachments']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                <tr><th><?php echo esc_html__('Cached orphan analysis state', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['cached_orphan_analysis_state'] ?? '')); ?></td></tr>
                <tr><th><?php echo esc_html__('Cached generated at', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['cached_generated_at'] ?? '')); ?></td></tr>
                <tr><th><?php echo esc_html__('Matches without code', 'rrze-multisite-manager'); ?></th><td class="rrze-msm-col-numeric"><?php echo esc_html((string)count((array)($attachment_debug['matches_without_code'] ?? []))); ?></td></tr>
                <tr><th><?php echo esc_html__('Matches with code', 'rrze-multisite-manager'); ?></th><td class="rrze-msm-col-numeric"><?php echo esc_html((string)count((array)($attachment_debug['matches_with_code'] ?? []))); ?></td></tr>
            </tbody>
        </table>
        <?php if (!empty($attachment_debug['matches_without_code'])) { ?>
            <h3><?php echo esc_html__('Detected references without code', 'rrze-multisite-manager'); ?></h3>
            <table class="widefat striped rrze-msm-table">
                <thead><tr><th><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></th><th><?php echo esc_html__('Title', 'rrze-multisite-manager'); ?></th><th><?php echo esc_html__('Matches', 'rrze-multisite-manager'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ((array)$attachment_debug['matches_without_code'] as $match_row) { ?>
                        <tr><td><?php echo esc_html((string)($match_row['post_type'] ?? '')); ?></td><td><?php if (!empty($match_row['edit_url'])) { ?><a href="<?php echo esc_url((string)$match_row['edit_url']); ?>"><?php echo esc_html((string)($match_row['title'] ?? '')); ?></a><?php } else { echo esc_html((string)($match_row['title'] ?? '')); } ?></td><td><?php echo esc_html((string)($match_row['matches_label'] ?? '')); ?></td></tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } else { ?>
            <p><?php echo esc_html__('No references were detected in posts, pages, or their meta fields.', 'rrze-multisite-manager'); ?></p>
        <?php } ?>
    <?php } ?>
</section>
