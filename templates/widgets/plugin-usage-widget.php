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
        <p><?php echo esc_html__('Derzeit liegen keine aktiven Plugins im Netzwerk vor.', 'rrze-multisite-manager'); ?></p>
    <?php } else { ?>
        <?php
        echo $this->renderTable(
            $plugins,
            [
                'table_id' => 'plugin-usage',
                'default_per_page' => (int)$default_per_page,
                'sort_key' => 'active-sites',
                'sort_direction' => 'desc',
                'show_active_sites' => true,
                'show_active_site_list' => true,
                'show_network_button' => true,
                'highlight_network_plugins' => true,
                'network_plugins_url' => (string)$network_plugins_url,
            ]
        );
        ?>
        <div class="rrze-msm-modal" id="rrze-msm-plugin-deactivate-modal" hidden>
            <div class="rrze-msm-modal-backdrop rrze-msm-close-plugin-modal"></div>
            <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-plugin-deactivate-title">
                <h3 id="rrze-msm-plugin-deactivate-title"><?php echo esc_html__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager'); ?></h3>
                <p class="rrze-msm-modal-text"><?php echo esc_html__('Dieses Plugin wird netzwerkweit deaktiviert. Das betrifft alle Websites dieses Netzwerks.', 'rrze-multisite-manager'); ?></p>
                <p class="rrze-msm-modal-target">
                    <strong><?php echo esc_html__('Ausgewähltes Plugin:', 'rrze-multisite-manager'); ?></strong>
                    <span id="rrze-msm-plugin-deactivate-target"></span>
                </p>
                <label class="rrze-msm-modal-checkbox">
                    <input type="checkbox" id="rrze-msm-plugin-deactivate-confirm">
                    <span><?php echo esc_html__('Ja, ich bin sicher und möchte dieses Plugin netzwerkweit für alle Sites deaktivieren.', 'rrze-multisite-manager'); ?></span>
                </label>
                <div class="rrze-msm-modal-actions">
                    <button type="button" class="button button-secondary rrze-msm-close-plugin-modal"><?php echo esc_html__('Abbrechen', 'rrze-multisite-manager'); ?></button>
                    <a href="#" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-plugin-deactivate-submit" aria-disabled="true"><?php echo esc_html__('Plugin netzwerkweit deaktivieren', 'rrze-multisite-manager'); ?></a>
                </div>
            </div>
        </div>
    <?php } ?>
</section>
