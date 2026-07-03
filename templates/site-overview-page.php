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

        <?php if (!empty($metrics_refreshed)) { ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('Die Kennzahlen wurden neu aufgebaut.', 'rrze-multisite-manager'); ?></p>
            </div>
        <?php } ?>

        <?php if (!empty($metrics_notice_html)) { echo $metrics_notice_html; } ?>

        <?php if (!empty($metrics_has_data)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-overview-page-section">
                <nav class="rrze-msm-overview-tabs" aria-label="<?php echo esc_attr__('Statusfilter für Websites', 'rrze-multisite-manager'); ?>">
                    <?php foreach ($overview_tabs as $tab) { ?>
                        <a class="rrze-msm-overview-tab <?php echo esc_attr((string)$tab['class']); ?><?php echo $current_tab === (string)$tab['slug'] ? ' is-active' : ''; ?>" href="<?php echo esc_url((string)$tab['url']); ?>">
                            <span><?php echo esc_html((string)$tab['label']); ?></span>
                            <strong>(<?php echo esc_html(number_format_i18n((int)$tab['count'])); ?>)</strong>
                        </a>
                    <?php } ?>
                </nav>
                <?php echo $site_overview_table; ?>
            </section>
        <?php } ?>
    </div>
    <div class="rrze-msm-modal" id="rrze-msm-site-delete-modal" hidden>
        <div class="rrze-msm-modal-backdrop rrze-msm-close-site-delete-modal"></div>
        <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-site-delete-title">
            <h3 id="rrze-msm-site-delete-title"><?php echo esc_html__('Site endgültig löschen', 'rrze-multisite-manager'); ?></h3>
            <p class="rrze-msm-modal-text"><?php echo esc_html__('Diese Aktion löscht die Site endgültig über die normale Netzwerkfunktion von WordPress. Für große Websites solltest du das nicht im Browser tun. Halte den Browser offen, bis der Vorgang abgeschlossen ist.', 'rrze-multisite-manager'); ?></p>
            <p class="rrze-msm-modal-target">
                <strong><?php echo esc_html__('Ausgewählte Site:', 'rrze-multisite-manager'); ?></strong>
                <span id="rrze-msm-site-delete-target"></span>
            </p>
            <label class="rrze-msm-modal-checkbox">
                <input type="checkbox" id="rrze-msm-site-delete-confirm">
                <span><?php echo esc_html__('Ja, ich bin sicher. Diese Site soll endgültig gelöscht werden und ich lasse den Browser dafür offen.', 'rrze-multisite-manager'); ?></span>
            </label>
            <div class="rrze-msm-modal-actions">
                <button type="button" class="button button-secondary rrze-msm-close-site-delete-modal"><?php echo esc_html__('Abbrechen', 'rrze-multisite-manager'); ?></button>
                <a href="#" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-site-delete-submit" aria-disabled="true"><?php echo esc_html__('Zur endgültigen Löschung', 'rrze-multisite-manager'); ?></a>
            </div>
        </div>
    </div>
</div>
