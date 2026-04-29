<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Widget nach oben verschieben', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Widget nach unten verschieben', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <?php if ($summary_label !== '') { ?>
        <p><strong><?php echo esc_html($summary_label); ?></strong></p>
    <?php } ?>
    <?php if ($mode_note !== '') { ?>
        <p><?php echo esc_html($mode_note); ?></p>
    <?php } ?>
    <?php echo $this->renderPieChart($items, $empty_message, ['center_title' => $center_title ?? '', 'center_value' => $center_value ?? '']); ?>
</section>
