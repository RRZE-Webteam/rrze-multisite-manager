<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Move widget up', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Move widget down', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <?php echo wp_kses_post($this->renderPieChart($items, $empty_message)); ?>
    <?php if (!empty($status_explanations) && is_array($status_explanations)) { ?>
        <details class="rrze-msm-status-explanations">
            <summary><?php echo esc_html__('Quick explanation', 'rrze-multisite-manager'); ?></summary>
            <ul>
                <?php foreach ($status_explanations as $status_key => $status_explanation) { ?>
                    <li>
                        <span class="rrze-msm-status-explanation-swatch <?php echo esc_attr((string) $status_explanation['css_class']); ?>" aria-hidden="true"></span>
                        <strong><?php echo esc_html((string)($status_explanation['label'] ?? '')); ?>:</strong>
                        <?php echo esc_html((string)($status_explanation['text'] ?? '')); ?>
                    </li>
                <?php } ?>
            </ul>
        </details>
    <?php } ?>
</section>
