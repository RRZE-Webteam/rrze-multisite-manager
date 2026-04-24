<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Widget nach oben verschieben', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Widget nach unten verschieben', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <div class="rrze-msm-plugin-summary">
        <div><span><?php echo esc_html__('Installierte Plugins', 'rrze-multisite-manager'); ?></span><strong><?php echo esc_html(number_format_i18n((int)($summary['available_plugins'] ?? 0))); ?></strong></div>
        <div><span><?php echo esc_html__('Netzwerkweit aktiv', 'rrze-multisite-manager'); ?></span><strong><?php echo esc_html(number_format_i18n((int)($summary['network_active_plugins'] ?? 0))); ?></strong></div>
        <div><span><?php echo esc_html__('Lokal genutzt', 'rrze-multisite-manager'); ?></span><strong><?php echo esc_html(number_format_i18n((int)($summary['locally_used_plugins'] ?? 0))); ?></strong></div>
    </div>

    <?php if (empty($plugins)) { ?>
        <p><?php echo esc_html__('Derzeit liegen keine Plugin-Nutzungsdaten vor.', 'rrze-multisite-manager'); ?></p>
    <?php } else { ?>
        <table class="widefat striped rrze-msm-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Plugin', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Aktive Sites', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Netzwerkweit aktiv', 'rrze-multisite-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plugins as $plugin) { ?>
                    <tr>
                        <td><strong><?php echo esc_html((string)$plugin['name']); ?></strong><br><code><?php echo esc_html((string)$plugin['file']); ?></code></td>
                        <td><?php echo esc_html((string)$plugin['version']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int)$plugin['site_count'])); ?></td>
                        <td><?php echo esc_html(!empty($plugin['network_active']) ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</section>
