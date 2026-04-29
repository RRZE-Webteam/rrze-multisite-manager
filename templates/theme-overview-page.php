<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Theme-Übersicht', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Alle im Netzwerk vorhandenen Themes mit Status, Nutzung und direktem Einstieg in die Theme-Details.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <section class="rrze-msm-widget rrze-msm-widget-span-12">
            <header class="rrze-msm-widget-header">
                <h2><?php echo esc_html__('Themes', 'rrze-multisite-manager'); ?></h2>
            </header>
            <?php if (empty($themes)) { ?>
                <p><?php echo esc_html__('Keine Theme-Daten vorhanden.', 'rrze-multisite-manager'); ?></p>
            <?php } else { ?>
                <div class="rrze-msm-theme-card-list">
                    <?php foreach ($themes as $theme) { ?>
                        <?php echo $theme_widget->renderThemeCard((array)$theme); ?>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
    </div>
</div>
