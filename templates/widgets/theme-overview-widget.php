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
    <?php if (empty($themes)) { ?>
        <p><?php echo esc_html__('Keine Theme-Daten vorhanden.', 'rrze-multisite-manager'); ?></p>
    <?php } else { ?>
        <div class="rrze-msm-theme-card-list">
            <?php foreach ($themes as $theme) { ?>
                <?php echo wp_kses_post($this->renderThemeCard((array)$theme)); ?>
            <?php } ?>
        </div>
    <?php } ?>
</section>
