<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('RRZE Multisite Manager', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Lokale Netzwerkkennzahlen und Betriebsübersicht für diese WordPress-Multisite.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <form class="rrze-msm-view-form" method="get" action="<?php echo esc_url(network_admin_url('admin.php')); ?>">
                    <input type="hidden" name="page" value="rrze-multisite-manager-dashboard">
                    <label for="rrze-msm-view-select" class="screen-reader-text"><?php echo esc_html__('Ansicht wählen', 'rrze-multisite-manager'); ?></label>
                    <select id="rrze-msm-view-select" name="view">
                        <?php foreach ($views as $view) { ?>
                            <option value="<?php echo esc_attr((string)$view['slug']); ?>" <?php selected($current_view_slug, (string)$view['slug']); ?>>
                                <?php echo esc_html((string)$view['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </form>
                <a class="button button-secondary" href="<?php echo esc_url($views_url); ?>"><?php echo esc_html__('Ansichten verwalten', 'rrze-multisite-manager'); ?></a>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <div class="rrze-msm-view-caption">
            <strong><?php echo esc_html__('Aktive Ansicht:', 'rrze-multisite-manager'); ?></strong>
            <span><?php echo esc_html($current_view_label); ?></span>
        </div>

        <div class="rrze-msm-grid rrze-msm-grid-primary" data-current-view="<?php echo esc_attr($current_view_slug); ?>">
            <?php foreach ($widget_markup as $markup) { echo $markup; } ?>
        </div>
    </div>
</div>
