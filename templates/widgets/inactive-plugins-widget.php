<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Widget nach oben verschieben', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Widget nach unten verschieben', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <?php if (empty($items)) { ?>
        <p><?php echo esc_html__('Alle installierten Plugins werden im Netzwerk genutzt.', 'rrze-multisite-manager'); ?></p>
    <?php } else { ?>
        <table class="widefat striped rrze-msm-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Kurzbeschreibung', 'rrze-multisite-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) { ?>
                    <tr>
                        <td><?php echo esc_html((string)($item['name'] ?? '')); ?></td>
                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($item['version'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($item['description'] ?? '')); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</section>
