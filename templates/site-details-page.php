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
                            <strong><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></strong>
                            <div class="rrze-msm-site-details-actions"><?php echo $site_actions; ?></div>
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
                        <div class="rrze-msm-site-details-meta-item rrze-msm-site-details-storage-item">
                            <strong><?php echo esc_html__('Speicherbelegung', 'rrze-multisite-manager'); ?></strong>
                            <?php
                            $usedPercent = isset($site_details['storage']['percent']) && is_int($site_details['storage']['percent']) ? max(0, min(100, (int)$site_details['storage']['percent'])) : 100;
                            $freePercent = max(0, 100 - $usedPercent);
                            $storageGradient = 'conic-gradient(var(--rrze-msm-status-info-bg) 0% ' . $usedPercent . '%, var(--rrze-msm-surface-alt) ' . $usedPercent . '% 100%)';
                            ?>
                            <div class="rrze-msm-site-storage-visual rrze-msm-site-storage-visual-compact">
                                <div class="rrze-msm-site-storage-pie" style="background: <?php echo esc_attr($storageGradient); ?>;"></div>
                                <div class="rrze-msm-site-storage-text">
                                    <strong><?php echo esc_html((string)($site_details['storage']['used_label'] ?? '')); ?></strong>
                                    <?php if (!empty($site_details['storage']['max_label'])) { ?>
                                        <br><?php echo esc_html(sprintf(__('von %s', 'rrze-multisite-manager'), (string)$site_details['storage']['max_label'])); ?>
                                    <?php } ?>
                                    <?php if (isset($site_details['storage']['percent']) && is_int($site_details['storage']['percent'])) { ?>
                                        <br><?php echo esc_html(sprintf(__('%d%% belegt', 'rrze-multisite-manager'), (int)$site_details['storage']['percent'])); ?>
                                        <br><?php echo esc_html(sprintf(__('%d%% frei', 'rrze-multisite-manager'), $freePercent)); ?>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="rrze-msm-grid">
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Statusdaten', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <?php if (!empty($status_sections)) { ?>
                        <?php foreach ($status_sections as $status_section) { ?>
                            <div class="rrze-msm-site-status-section">
                                <h3><?php echo esc_html((string)$status_section['title']); ?></h3>
                                <dl class="rrze-msm-site-details-list">
                                    <dt><?php echo esc_html((string)$status_section['date_label']); ?></dt>
                                    <dd><?php echo esc_html((string)$status_section['date_value']); ?></dd>
                                    <dt><?php echo esc_html((string)$status_section['user_label']); ?></dt>
                                    <dd><?php echo esc_html((string)$status_section['user_value']); ?></dd>
                                    <dt><?php echo esc_html__('Notiz', 'rrze-multisite-manager'); ?></dt>
                                    <dd>
                                        <?php if (trim((string)$status_section['note']) !== '') { ?>
                                            <?php echo nl2br(esc_html((string)$status_section['note'])); ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('Keine Notiz', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </dd>
                                </dl>
                            </div>
                        <?php } ?>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Für diese Site sind derzeit keine Statusdetails zu Archivierung oder Sperrung vorhanden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Benutzer', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <p><a class="button button-secondary" href="<?php echo esc_url($site_users_url); ?>"><?php echo esc_html__('Zur Benutzerverwaltung der Site', 'rrze-multisite-manager'); ?></a></p>
                    <?php if (!empty($site_details['users']) && is_array($site_details['users'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Benutzername', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('E-Mail-Adresse', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Rolle', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Benutzerprofil', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($site_details['users'] as $user) { ?>
                                    <tr<?php echo (string)($user['role_key'] ?? '') === 'administrator' ? ' class="rrze-msm-detail-row-admin"' : ''; ?>>
                                        <td><?php echo esc_html((string)$user['username']); ?></td>
                                        <td><?php echo esc_html((string)$user['name']); ?></td>
                                        <td><a href="mailto:<?php echo esc_attr((string)$user['email']); ?>"><?php echo esc_html((string)$user['email']); ?></a></td>
                                        <td><?php echo esc_html((string)$user['role_label']); ?></td>
                                        <td><a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['user_id' => (int)$user['id']], $site_user_edit_base_url)); ?>"><?php echo esc_html__('Benutzerprofil', 'rrze-multisite-manager'); ?></a></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Für diese Website wurden keine Benutzer gefunden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                    <p><a class="button button-secondary" href="<?php echo esc_url($site_users_url); ?>"><?php echo esc_html__('Zur Benutzerverwaltung der Site', 'rrze-multisite-manager'); ?></a></p>
                </section>

                <section id="rrze-msm-site-content" class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Inhaltstypen', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <?php if (!empty($site_content_notice_messages)) { ?>
                        <div class="notice notice-success inline">
                            <?php foreach ($site_content_notice_messages as $site_content_notice_message) { ?>
                                <p><?php echo esc_html((string)$site_content_notice_message); ?></p>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php
                    $customPostTypes = is_array($site_details['custom_post_types'] ?? null) ? $site_details['custom_post_types'] : [];
                    $customPages = is_array($site_custom_pages ?? null) ? $site_custom_pages : [];
                    $blockTemplateTypes = is_array($site_details['block_template_types'] ?? null) ? $site_details['block_template_types'] : [];
                    ?>
                    <nav class="rrze-msm-subtabs" aria-label="<?php echo esc_attr__('Inhaltstypen', 'rrze-multisite-manager'); ?>">
                        <a class="rrze-msm-subtab<?php echo $site_content_current_tab === 'overview' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'content_tab' => 'overview'], $site_details_base_url) . '#rrze-msm-site-content'); ?>"><?php echo esc_html__('Übersicht', 'rrze-multisite-manager'); ?></a>
                        <a class="rrze-msm-subtab<?php echo $site_content_current_tab === 'custom-post-types' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'content_tab' => 'custom-post-types'], $site_details_base_url) . '#rrze-msm-site-content'); ?>"><?php echo esc_html__('Custom Post Types', 'rrze-multisite-manager'); ?></a>
                        <?php if (!empty($customPages)) { ?>
                            <a class="rrze-msm-subtab<?php echo $site_content_current_tab === 'custom-pages' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'content_tab' => 'custom-pages'], $site_details_base_url) . '#rrze-msm-site-content'); ?>"><?php echo esc_html__('Custom Pages', 'rrze-multisite-manager'); ?></a>
                        <?php } ?>
                        <?php if (!empty($blockTemplateTypes)) { ?>
                            <a class="rrze-msm-subtab<?php echo $site_content_current_tab === 'block-templates' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'content_tab' => 'block-templates'], $site_details_base_url) . '#rrze-msm-site-content'); ?>"><?php echo esc_html__('Block Vorlagen', 'rrze-multisite-manager'); ?></a>
                        <?php } ?>
                    </nav>

                    <?php if ($site_content_current_tab === 'overview') { ?>
                        <?php if (!empty($site_details['content_types']) && is_array($site_details['content_types'])) { ?>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Post-Typ', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                        <th class="rrze-msm-col-numeric"><?php echo esc_html__('Anzahl', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($site_details['content_types'] as $content_type) { ?>
                                        <tr<?php echo !empty($content_type['level']) ? ' class="rrze-msm-detail-row-child-type"' : ''; ?>>
                                            <td>
                                                <?php if (!empty($content_type['level'])) { ?>
                                                    <span class="rrze-msm-detail-child-prefix" aria-hidden="true">↳</span>
                                                <?php } ?>
                                                <?php echo esc_html((string)$content_type['label']); ?>
                                            </td>
                                            <td><code><?php echo esc_html((string)$content_type['slug']); ?></code></td>
                                            <td><?php echo esc_html(number_format_i18n((int)$content_type['count'])); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } else { ?>
                            <p><?php echo esc_html__('Für diese Website wurden keine Inhalte ermittelt.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } elseif ($site_content_current_tab === 'custom-post-types') { ?>
                        <?php if (!empty($customPostTypes)) { ?>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Bezeichnung', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Gruppe', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Im Request registriert', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Anzahl', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Aktion', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customPostTypes as $custom_post_type) { ?>
                                        <tr>
                                            <td><?php echo esc_html((string)$custom_post_type['label']); ?></td>
                                            <td><code><?php echo esc_html((string)$custom_post_type['slug']); ?></code></td>
                                            <td><?php echo esc_html((string)$custom_post_type['group']); ?></td>
                                            <td><?php echo esc_html(!empty($custom_post_type['registered']) ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')); ?></td>
                                            <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)$custom_post_type['count'])); ?></td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="button button-secondary rrze-msm-button-danger rrze-msm-open-delete-cpt-modal"
                                                    data-post-type="<?php echo esc_attr((string)$custom_post_type['slug']); ?>"
                                                    data-post-type-label="<?php echo esc_attr((string)$custom_post_type['label']); ?>">
                                                    <?php echo esc_html__('Löschen', 'rrze-multisite-manager'); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <div class="rrze-msm-modal" id="rrze-msm-delete-cpt-modal" hidden>
                                <div class="rrze-msm-modal-backdrop rrze-msm-close-modal"></div>
                                <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-delete-cpt-title">
                                    <h3 id="rrze-msm-delete-cpt-title"><?php echo esc_html__('Custom Post Type löschen', 'rrze-multisite-manager'); ?></h3>
                                    <p class="rrze-msm-modal-text">
                                        <?php echo esc_html__('Damit werden alle Einträge dieses Custom Post Types endgültig gelöscht. Die Registrierung des Typs im Plugin- oder Theme-Code bleibt davon unberührt.', 'rrze-multisite-manager'); ?>
                                    </p>
                                    <p class="rrze-msm-modal-target">
                                        <strong><?php echo esc_html__('Ausgewählter Typ:', 'rrze-multisite-manager'); ?></strong>
                                        <span id="rrze-msm-delete-cpt-target"></span>
                                    </p>
                                    <form method="post" action="<?php echo esc_url($site_post_type_delete_action); ?>">
                                        <?php wp_nonce_field('rrze_multisite_manager_delete_post_type_entries_' . (int)$site_id); ?>
                                        <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                        <input type="hidden" name="post_type" id="rrze-msm-delete-cpt-input" value="">
                                        <input type="hidden" name="confirm_delete" value="1">
                                        <label class="rrze-msm-modal-checkbox">
                                            <input type="checkbox" id="rrze-msm-delete-cpt-confirm">
                                            <span><?php echo esc_html__('Ja, ich bin sicher und möchte alle Einträge dieses Custom Post Types endgültig löschen.', 'rrze-multisite-manager'); ?></span>
                                        </label>
                                        <div class="rrze-msm-modal-actions">
                                            <button type="button" class="button button-secondary rrze-msm-close-modal"><?php echo esc_html__('Abbrechen', 'rrze-multisite-manager'); ?></button>
                                            <button type="submit" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-delete-cpt-submit" disabled><?php echo esc_html__('Custom Post Type und alle Einträge endgültig löschen', 'rrze-multisite-manager'); ?></button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php } else { ?>
                            <p><?php echo esc_html__('Für diese Website wurden keine Custom Post Types erkannt.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } elseif ($site_content_current_tab === 'custom-pages') { ?>
                        <?php if (!empty($customPages)) { ?>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Bezeichnung', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Im Request registriert', 'rrze-multisite-manager'); ?></th>
                                        <th class="rrze-msm-col-numeric"><?php echo esc_html__('Anzahl', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customPages as $custom_page) { ?>
                                        <tr>
                                            <td><?php echo esc_html((string)$custom_page['label']); ?></td>
                                            <td><code><?php echo esc_html((string)$custom_page['slug']); ?></code></td>
                                            <td><?php echo esc_html(!empty($custom_page['registered']) ? __('Ja', 'rrze-multisite-manager') : __('Nein', 'rrze-multisite-manager')); ?></td>
                                            <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)$custom_page['count'])); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } ?>
                    <?php } elseif ($site_content_current_tab === 'block-templates') { ?>
                        <?php if (!empty($blockTemplateTypes)) { ?>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Bezeichnung', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                        <th class="rrze-msm-col-numeric"><?php echo esc_html__('Anzahl', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blockTemplateTypes as $block_template_type) { ?>
                                        <tr>
                                            <td><?php echo esc_html((string)$block_template_type['label']); ?></td>
                                            <td><code><?php echo esc_html((string)$block_template_type['slug']); ?></code></td>
                                            <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)$block_template_type['count'])); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } ?>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Verwendetes Theme', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-site-theme-card">
                        <div class="rrze-msm-site-theme-screenshot">
                            <?php if (!empty($site_details['theme']['screenshot'])) { ?>
                                <img src="<?php echo esc_url((string)$site_details['theme']['screenshot']); ?>" alt="<?php echo esc_attr((string)($site_details['theme']['name'] ?? '')); ?>">
                            <?php } else { ?>
                                <span class="rrze-msm-site-branding-empty"><?php echo esc_html__('Kein Screenshot verfügbar', 'rrze-multisite-manager'); ?></span>
                            <?php } ?>
                        </div>
                        <div class="rrze-msm-site-theme-details">
                            <h3><a href="<?php echo esc_url($this->getThemeDetailsUrl((string)($site_details['theme']['stylesheet'] ?? ''))); ?>"><?php echo esc_html((string)($site_details['theme']['name'] ?? '')); ?></a></h3>
                            <?php if (!empty($site_details['theme']['version'])) { ?>
                                <p><strong><?php echo esc_html__('Version:', 'rrze-multisite-manager'); ?></strong> <?php echo esc_html((string)$site_details['theme']['version']); ?></p>
                            <?php } ?>
                            <?php if (!empty($site_details['theme']['description'])) { ?>
                                <p><?php echo esc_html((string)$site_details['theme']['description']); ?></p>
                            <?php } ?>
                            <div class="rrze-msm-site-theme-links">
                                <a class="button button-secondary" href="<?php echo esc_url($this->getThemeOverviewUrl()); ?>"><?php echo esc_html__('Theme-Übersicht', 'rrze-multisite-manager'); ?></a>
                                <a class="button button-secondary" href="<?php echo esc_url($site_themes_url); ?>"><?php echo esc_html__('Themes der Site', 'rrze-multisite-manager'); ?></a>
                                <a class="button button-secondary" href="<?php echo esc_url($site_customizer_url); ?>"><?php echo esc_html__('Customizer', 'rrze-multisite-manager'); ?></a>
                                <?php if (!empty($site_editor_url)) { ?>
                                    <a class="button button-secondary" href="<?php echo esc_url($site_editor_url); ?>"><?php echo esc_html__('Site-Editor', 'rrze-multisite-manager'); ?></a>
                                <?php } ?>
                                <a class="button button-secondary" href="<?php echo esc_url($site_menus_url); ?>"><?php echo esc_html__('Menüs', 'rrze-multisite-manager'); ?></a>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Aktive Plugins', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Netzwerkweit aktive Plugins stehen zuerst, danach folgen lokal aktivierte Plugins.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <p><a class="button button-secondary" href="<?php echo esc_url($site_plugins_url); ?>"><?php echo esc_html__('Zur Plugin-Verwaltung der Site', 'rrze-multisite-manager'); ?></a></p>
                    <?php if (!empty($site_details['plugins']) && is_array($site_details['plugins'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Pluginname', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-plugin-col-version"><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Autor', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Kurzbeschreibung', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Status', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($site_details['plugins'] as $plugin) { ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url($this->getPluginDetailsUrl((string)($plugin['file'] ?? ''))); ?>"><?php echo esc_html((string)$plugin['name']); ?></a></td>
                                        <td class="rrze-msm-plugin-col-version"><?php echo esc_html((string)$plugin['version']); ?></td>
                                        <td><?php echo esc_html((string)($plugin['author'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string)$plugin['description']); ?></td>
                                        <td>
                                            <?php if (!empty($plugin['network_active'])) { ?>
                                                <span class="rrze-msm-badge rrze-msm-badge-info"><?php echo esc_html__('Netzwerkweit aktiv', 'rrze-multisite-manager'); ?></span>
                                            <?php } elseif ((int)($plugin['site_count'] ?? 0) > 0) { ?>
                                                <span class="rrze-msm-badge rrze-msm-badge-active"><?php echo esc_html__('Auf Websites aktiv', 'rrze-multisite-manager'); ?></span>
                                            <?php } else { ?>
                                                <span class="rrze-msm-badge rrze-msm-badge-archive"><?php echo esc_html__('Nicht aktiviert', 'rrze-multisite-manager'); ?></span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <div class="rrze-msm-site-actions">
                                                <?php if (!empty($plugin['deactivate_url'])) { ?>
                                                    <a class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-site-action-text" href="<?php echo esc_url((string)$plugin['deactivate_url']); ?>" title="<?php echo esc_attr__('Auf dieser Site deaktivieren', 'rrze-multisite-manager'); ?>" aria-label="<?php echo esc_attr__('Auf dieser Site deaktivieren', 'rrze-multisite-manager'); ?>">
                                                        <span class="rrze-msm-site-action-label"><?php echo esc_html__('Auf dieser Site deaktivieren', 'rrze-multisite-manager'); ?></span>
                                                    </a>
                                                <?php } ?>
                                                <?php if (!empty($plugin['settings_url'])) { ?>
                                                    <a class="button button-small rrze-msm-site-action rrze-msm-site-action-text" href="<?php echo esc_url((string)$plugin['settings_url']); ?>" title="<?php echo esc_attr__('Einstellungen', 'rrze-multisite-manager'); ?>" aria-label="<?php echo esc_attr__('Einstellungen', 'rrze-multisite-manager'); ?>">
                                                        <span class="rrze-msm-site-action-label"><?php echo esc_html__('Einstellungen', 'rrze-multisite-manager'); ?></span>
                                                    </a>
                                                <?php } ?>
                                                <?php if (!empty($plugin['details_url'])) { ?>
                                                    <a class="button button-small rrze-msm-site-action rrze-msm-site-action-text rrze-msm-site-action-info" href="<?php echo esc_url((string)$plugin['details_url']); ?>" title="<?php echo esc_attr__('Details', 'rrze-multisite-manager'); ?>" aria-label="<?php echo esc_attr__('Details', 'rrze-multisite-manager'); ?>" target="_blank" rel="noopener noreferrer">
                                                        <span class="rrze-msm-site-action-label"><?php echo esc_html__('Details', 'rrze-multisite-manager'); ?></span>
                                                    </a>
                                                <?php } ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Für diese Website wurden keine aktiven Plugins ermittelt.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                    <p><a class="button button-secondary" href="<?php echo esc_url($site_plugins_url); ?>"><?php echo esc_html__('Zur Plugin-Verwaltung der Site', 'rrze-multisite-manager'); ?></a></p>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Registrierte Bildgrößen', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Hier sind die auf dieser Website derzeit registrierten Image-Sizes aus der tatsächlichen Laufzeitumgebung gelistet.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($site_details['image_sizes']) && is_array($site_details['image_sizes'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Breite', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Höhe', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Crop', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Quelle', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Verursacher', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($site_details['image_sizes'] as $image_size) { ?>
                                    <tr>
                                        <td><strong><?php echo esc_html((string)($image_size['label'] ?? '')); ?></strong></td>
                                        <td><code><?php echo esc_html((string)($image_size['slug'] ?? '')); ?></code></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($image_size['width'] ?? 0))); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($image_size['height'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string)($image_size['crop'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string)($image_size['provider_type'] ?? '')); ?></td>
                                        <td><?php echo !empty($image_size['providers']) ? esc_html(implode(', ', (array)$image_size['providers'])) : esc_html__('Keine direkte Zuordnung', 'rrze-multisite-manager'); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Für diese Website konnten keine registrierten Bildgrößen ermittelt werden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section id="rrze-msm-site-options" class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Optionen der Website', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Die Gruppen basieren auf dem Präfix des Optionsnamens. Das ist Absicht, weil WordPress keine saubere Plugin-Zuordnung für Optionen mitliefert.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($site_options_notice_messages)) { ?>
                        <div class="notice notice-success inline">
                            <?php foreach ($site_options_notice_messages as $site_options_notice_message) { ?>
                                <p><?php echo esc_html((string)$site_options_notice_message); ?></p>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php if (!empty($site_options_groups) && is_array($site_options_groups)) { ?>
                        <nav class="rrze-msm-option-tabs" aria-label="<?php echo esc_attr__('Options-Gruppen', 'rrze-multisite-manager'); ?>">
                            <?php foreach ($site_options_groups as $site_options_group) { ?>
                                <a class="rrze-msm-option-tab<?php echo (string)($site_options_group['slug'] ?? '') === (string)$site_options_current_tab ? ' is-active' : ''; ?><?php echo (string)($site_options_group['slug'] ?? '') === 'wordpress-core' ? ' rrze-msm-option-tab-core' : ''; ?><?php echo (string)($site_options_group['slug'] ?? '') === 'fau' ? ' rrze-msm-option-tab-fau' : ''; ?><?php echo (string)($site_options_group['slug'] ?? '') === 'rrze' ? ' rrze-msm-option-tab-rrze' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'options_tab' => (string)$site_options_group['slug']], $site_details_base_url) . '#rrze-msm-site-options'); ?>">
                                    <?php echo esc_html((string)$site_options_group['label']); ?>
                                    <span>(<?php echo esc_html(number_format_i18n((int)($site_options_group['count'] ?? 0))); ?>)</span>
                                </a>
                            <?php } ?>
                        </nav>
                        <?php if ($site_options_current_tab !== '') { ?>
                            <?php foreach ($site_options_groups as $site_options_group) { ?>
                                <?php if ((string)($site_options_group['slug'] ?? '') !== (string)$site_options_current_tab) { ?>
                                    <?php continue; ?>
                                <?php } ?>
                                <?php if ((string)($site_options_group['slug'] ?? '') !== 'all' && (string)($site_options_group['slug'] ?? '') !== 'wordpress-core') { ?>
                                    <form method="post" action="<?php echo esc_url($site_option_group_delete_action); ?>" class="rrze-msm-option-delete-form">
                                        <?php wp_nonce_field('rrze_multisite_manager_delete_site_option_group_' . (int)$site_id . '_' . (string)$site_options_group['slug']); ?>
                                        <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                        <input type="hidden" name="group_key" value="<?php echo esc_attr((string)$site_options_group['slug']); ?>">
                                        <button type="submit" class="button button-secondary rrze-msm-button-danger"><?php echo esc_html__('Gesamte Gruppe löschen', 'rrze-multisite-manager'); ?></button>
                                    </form>
                                <?php } ?>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                                            <th><?php echo esc_html__('Wert', 'rrze-multisite-manager'); ?></th>
                                            <th><?php echo esc_html__('Autoload', 'rrze-multisite-manager'); ?></th>
                                            <?php if ((string)($site_options_group['slug'] ?? '') !== 'wordpress-core') { ?>
                                                <th><?php echo esc_html__('Aktion', 'rrze-multisite-manager'); ?></th>
                                            <?php } ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)($site_options_group['options'] ?? []) as $site_option) { ?>
                                            <tr>
                                                <td><code><?php echo esc_html((string)$site_option['name']); ?></code></td>
                                                <td>
                                                    <details class="rrze-msm-option-value">
                                                        <summary><?php echo esc_html__('Wert anzeigen', 'rrze-multisite-manager'); ?></summary>
                                                        <pre><?php echo esc_html((string)$site_option['value']); ?></pre>
                                                    </details>
                                                </td>
                                                <td><?php echo esc_html((string)$site_option['autoload']); ?></td>
                                                <?php if ((string)($site_options_group['slug'] ?? '') !== 'wordpress-core') { ?>
                                                    <td>
                                                        <?php if (empty($site_option['is_core'])) { ?>
                                                            <form method="post" action="<?php echo esc_url($site_option_delete_action); ?>" class="rrze-msm-option-delete-form">
                                                                <?php wp_nonce_field('rrze_multisite_manager_delete_site_option_' . (int)$site_id . '_' . (string)$site_option['name']); ?>
                                                                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                                                <input type="hidden" name="options_tab" value="<?php echo esc_attr((string)$site_options_current_tab); ?>">
                                                                <input type="hidden" name="option_name" value="<?php echo esc_attr((string)$site_option['name']); ?>">
                                                                <button type="submit" class="button button-secondary rrze-msm-button-danger"><?php echo esc_html__('Löschen', 'rrze-multisite-manager'); ?></button>
                                                            </form>
                                                        <?php } ?>
                                                    </td>
                                                <?php } ?>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            <?php } ?>
                        <?php } else { ?>
                            <p><?php echo esc_html__('Wähle oben eine Gruppe aus, um die zugehörigen Optionen anzuzeigen.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Für diese Website wurden keine Optionen ermittelt.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section id="rrze-msm-site-process" class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Prozessdaten', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <nav class="rrze-msm-subtabs" aria-label="<?php echo esc_attr__('Prozessdaten', 'rrze-multisite-manager'); ?>">
                        <a class="rrze-msm-subtab<?php echo $site_process_current_tab === 'stats' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'process_tab' => 'stats'], $site_details_base_url) . '#rrze-msm-site-process'); ?>"><?php echo esc_html__('Stats', 'rrze-multisite-manager'); ?></a>
                        <a class="rrze-msm-subtab<?php echo $site_process_current_tab === 'transients' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'process_tab' => 'transients'], $site_details_base_url) . '#rrze-msm-site-process'); ?>"><?php echo esc_html__('Transients', 'rrze-multisite-manager'); ?></a>
                        <a class="rrze-msm-subtab<?php echo $site_process_current_tab === 'scheduler' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['site_id' => (int)$site_id, 'process_tab' => 'scheduler'], $site_details_base_url) . '#rrze-msm-site-process'); ?>"><?php echo esc_html__('Scheduler', 'rrze-multisite-manager'); ?></a>
                    </nav>
                    <?php if ($site_process_current_tab === 'stats') { ?>
                        <ul class="rrze-msm-inline-stats">
                            <li><strong><?php echo esc_html(number_format_i18n(count((array)($site_details['transients'] ?? [])))); ?></strong> <?php echo esc_html__('Transients', 'rrze-multisite-manager'); ?></li>
                            <li><strong><?php echo esc_html(number_format_i18n(count((array)($site_details['cron_events'] ?? [])))); ?></strong> <?php echo esc_html__('Scheduler-Einträge', 'rrze-multisite-manager'); ?></li>
                        </ul>
                    <?php } elseif ($site_process_current_tab === 'transients') { ?>
                        <?php if (!empty($site_details['transients']) && is_array($site_details['transients'])) { ?>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Ablaufzeit', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($site_details['transients'] as $transient) { ?>
                                        <tr>
                                            <td><code><?php echo esc_html((string)$transient['name']); ?></code></td>
                                            <td><?php echo esc_html((string)$transient['expires_at']); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } else { ?>
                            <p><?php echo esc_html__('Für diese Website wurden keine Transients gefunden.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } elseif ($site_process_current_tab === 'scheduler') { ?>
                        <?php if (!empty($site_details['cron_events']) && is_array($site_details['cron_events'])) { ?>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Hook', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Zeitplan', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Nächste Ausführung', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($site_details['cron_events'] as $cron_event) { ?>
                                        <tr>
                                            <td><code><?php echo esc_html((string)$cron_event['hook']); ?></code></td>
                                            <td><?php echo esc_html((string)$cron_event['schedule']); ?></td>
                                            <td><?php echo esc_html((string)$cron_event['next_run']); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } else { ?>
                            <p><?php echo esc_html__('Für diese Website wurden keine Scheduler Tasks gefunden.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } ?>
                </section>
            </div>
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
