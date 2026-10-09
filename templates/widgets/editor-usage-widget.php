<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal widget markup.
?>
<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Move widget up', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Move widget down', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <?php if (!empty($network_block_editor_enabled)) { ?>
        <div class="notice notice-info inline">
            <p>
                <?php esc_html_e('The Block Editor is active on all websites because it is configured as the network default editor in RRZE Settings.', 'rrze-multisite-manager'); ?>
                <a href="<?php echo esc_url($rrze_settings_writing_url); ?>"><?php esc_html_e('Open RRZE Settings: Writing', 'rrze-multisite-manager'); ?></a>
            </p>
        </div>
    <?php } else { ?>
        <?php echo $this->renderPieChart($items, $empty_message, ['aggregate_small_items' => false]); ?>
    <?php } ?>
</section>
