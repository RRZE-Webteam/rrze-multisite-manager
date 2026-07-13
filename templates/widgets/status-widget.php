<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Widget nach oben verschieben', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Widget nach unten verschieben', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <?php echo wp_kses_post($this->renderPieChart($items, $empty_message)); ?>
    <?php if (!empty($status_explanations) && is_array($status_explanations)) { ?>
        <div class="rrze-msm-status-explanations">
            <h3><?php echo esc_html__('Kurz erklärt', 'rrze-multisite-manager'); ?></h3>
            <ul>
                <?php foreach ($status_explanations as $status_explanation) { ?>
                    <li>
                        <strong><?php echo esc_html((string)($status_explanation['label'] ?? '')); ?>:</strong>
                        <?php echo esc_html((string)($status_explanation['text'] ?? '')); ?>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>
</section>
