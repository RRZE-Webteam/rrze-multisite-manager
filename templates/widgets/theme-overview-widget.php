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
        <table class="widefat striped rrze-msm-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Theme', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Verwendende Sites', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Netzwerkweit erlaubt', 'rrze-multisite-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($themes as $theme) { ?>
                    <tr>
                        <td><strong><?php echo esc_html((string)$theme['name']); ?></strong><br><code><?php echo esc_html((string)$theme['stylesheet']); ?></code></td>
                        <td><?php echo esc_html((string)$theme['version']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int)$theme['site_count'])); ?></td>
                        <td><?php echo esc_html(!empty($theme['network_enabled']) ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</section>
