<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Website-Details', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Detailansicht einer einzelnen Website mit Status-, Benutzer-, Inhalts- und Speicherinformationen.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-details-search">
            <header class="rrze-msm-widget-header">
                <h2><?php echo esc_html__('Website auswählen', 'rrze-multisite-manager'); ?></h2>
                <p><?php echo esc_html__('Suche nach Titel oder URL und öffne danach die gewünschte Detailansicht.', 'rrze-multisite-manager'); ?></p>
            </header>
            <label class="screen-reader-text" for="rrze-msm-site-search"><?php echo esc_html__('Website suchen', 'rrze-multisite-manager'); ?></label>
            <input id="rrze-msm-site-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($site_search_placeholder); ?>" autocomplete="off">
            <div class="rrze-msm-site-search-results" id="rrze-msm-site-search-results"></div>
        </section>

        <?php if (!empty($site_details)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-details-hero">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html((string)$site_details['name']); ?></h2>
                    <p><a href="<?php echo esc_url((string)$site_details['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$site_details['url']); ?></a></p>
                </header>
                <div class="rrze-msm-site-details-hero-grid">
                    <div class="rrze-msm-site-details-logo">
                        <?php if (!empty($site_details['branding']['url'])) { ?>
                            <img src="<?php echo esc_url((string)$site_details['branding']['url']); ?>" alt="<?php echo esc_attr((string)$site_details['name']); ?>">
                        <?php } else { ?>
                            <span class="rrze-msm-site-branding-empty"><?php echo esc_html__('Kein Logo', 'rrze-multisite-manager'); ?></span>
                        <?php } ?>
                    </div>
                    <div class="rrze-msm-site-details-meta">
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Status', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo $status_badges_html; ?></div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Admin-E-Mail', 'rrze-multisite-manager'); ?></strong>
                            <div><a href="mailto:<?php echo esc_attr((string)$site_details['admin_email']); ?>"><?php echo esc_html((string)$site_details['admin_email']); ?></a></div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Registriert', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo esc_html((string)$site_details['registered_label']); ?></div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Zuletzt aktualisiert', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo esc_html((string)$site_details['last_updated_label']); ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="rrze-msm-grid">
                <section class="rrze-msm-widget rrze-msm-widget-span-4">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Statusdaten', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <dl class="rrze-msm-site-details-list">
                        <dt><?php echo esc_html__('Archiviert seit', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html($archived_at_label); ?></dd>
                        <dt><?php echo esc_html__('Gesperrt seit', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html($blocked_at_label); ?></dd>
                        <dt><?php echo esc_html__('Geändert von', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html($status_user_label); ?></dd>
                        <dt><?php echo esc_html__('Notiz', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo nl2br(esc_html((string)($site_details['status_note'] ?? ''))); ?></dd>
                    </dl>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-4">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Benutzer', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <dl class="rrze-msm-site-details-list">
                        <dt><?php echo esc_html__('Admins', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n((int)($site_details['role_counts']['admins'] ?? 0))); ?></dd>
                        <dt><?php echo esc_html__('Redakteure', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n((int)($site_details['role_counts']['editors'] ?? 0))); ?></dd>
                        <dt><?php echo esc_html__('Weitere Rollen', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n((int)($site_details['role_counts']['others'] ?? 0))); ?></dd>
                    </dl>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-4">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Inhalte und Speicher', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <dl class="rrze-msm-site-details-list">
                        <dt><?php echo esc_html__('Seiten', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n((int)($site_details['content_counts']['pages'] ?? 0))); ?></dd>
                        <dt><?php echo esc_html__('Beiträge', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n((int)($site_details['content_counts']['posts'] ?? 0))); ?></dd>
                        <dt><?php echo esc_html__('Medien', 'rrze-multisite-manager'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n((int)($site_details['content_counts']['media'] ?? 0))); ?></dd>
                        <dt><?php echo esc_html__('Speicher', 'rrze-multisite-manager'); ?></dt>
                        <dd>
                            <strong><?php echo esc_html((string)($site_details['storage']['used_label'] ?? '')); ?></strong>
                            <?php if (!empty($site_details['storage']['max_label'])) { ?>
                                <br><?php echo esc_html(sprintf(__('von %s', 'rrze-multisite-manager'), (string)$site_details['storage']['max_label'])); ?>
                            <?php } ?>
                            <?php if (isset($site_details['storage']['percent']) && is_int($site_details['storage']['percent'])) { ?>
                                <br><?php echo esc_html(sprintf(__('%d%% belegt', 'rrze-multisite-manager'), (int)$site_details['storage']['percent'])); ?>
                            <?php } ?>
                        </dd>
                    </dl>
                </section>
            </div>
        <?php } ?>
    </div>
</div>
