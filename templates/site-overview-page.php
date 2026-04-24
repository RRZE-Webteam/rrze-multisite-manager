<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Website-Übersicht', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Erweiterte Übersicht über Sites, Benutzer, Inhalte und Speicherverbrauch im gesamten Netzwerk.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (!empty($status_updated)) { ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('Der Website-Status wurde aktualisiert.', 'rrze-multisite-manager'); ?></p>
            </div>
        <?php } ?>

        <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-overview-page-section">
            <header class="rrze-msm-widget-header">
                <h2><?php echo esc_html__('Alle Websites', 'rrze-multisite-manager'); ?></h2>
                <p><?php echo esc_html__('Die Tabelle zeigt pro Site die wichtigsten Betriebsdaten und Verwaltungsaktionen.', 'rrze-multisite-manager'); ?></p>
            </header>
            <?php echo $site_overview_table; ?>
        </section>
    </div>
</div>
