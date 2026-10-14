<?php
defined('ABSPATH') || exit;
?>
<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Move widget up', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Move widget down', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
    </header>
    <div class="rrze-msm-cards">
        <?php foreach ($cards as $card) { ?>
            <div class="rrze-msm-card rrze-msm-card-status <?php echo esc_attr((string)($card['status_class'] ?? 'rrze-msm-status-automatic')); ?>">
                <span class="rrze-msm-card-label"><?php echo esc_html((string)($card['label'] ?? '')); ?></span>
                <strong class="rrze-msm-card-value"><?php echo esc_html(number_format_i18n((int)($card['value'] ?? 0))); ?></strong>
            </div>
        <?php } ?>
    </div>
</section>
