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
        <div class="rrze-msm-site-table-wrap rrze-msm-plugin-table-wrap" data-table-id="plugin-usage" data-default-per-page="<?php echo esc_attr((string)$default_per_page); ?>" data-current-page="1" data-sort-key="active-sites" data-sort-direction="desc">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <a class="button" href="<?php echo esc_url($network_plugins_url); ?>"><?php echo esc_html__('Plugin-Verwaltung im Netzwerk öffnen', 'rrze-multisite-manager'); ?></a>
                    <label for="rrze-msm-plugin-per-page"><?php echo esc_html__('Anzeigen:', 'rrze-multisite-manager'); ?></label>
                    <select class="rrze-msm-site-table-per-page" id="rrze-msm-plugin-per-page">
                        <?php foreach ($this->getSiteTablePerPageOptions((int)$default_per_page) as $option) { ?>
                            <option value="<?php echo esc_attr((string)$option); ?>"<?php selected($option, (int)$default_per_page); ?>>
                                <?php if ($option === (int)$default_per_page) { ?>
                                    <?php echo esc_html(sprintf(__('Standard (%d)', 'rrze-multisite-manager'), $option)); ?>
                                <?php } else { ?>
                                    <?php echo esc_html((string)$option); ?>
                                <?php } ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <table class="widefat striped rrze-msm-table rrze-msm-plugin-table">
                <thead>
                    <tr>
                        <th><?php echo $this->renderSiteTableSortButton('name', __('Plugin', 'rrze-multisite-manager')); ?></th>
                        <th><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                        <th><?php echo $this->renderSiteTableSortButton('author', __('Autor', 'rrze-multisite-manager')); ?></th>
                        <th><?php echo $this->renderSiteTableSortButton('active-sites', __('Aktive Sites', 'rrze-multisite-manager')); ?></th>
                        <th><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $plugin) { ?>
                        <tr<?php echo !empty($plugin['network_active']) ? ' class="rrze-msm-detail-row-network-plugin"' : ''; ?> data-sort-name="<?php echo esc_attr(strtolower((string)($plugin['name'] ?? ''))); ?>" data-sort-author="<?php echo esc_attr(strtolower((string)($plugin['author'] ?? ''))); ?>" data-sort-active-sites="<?php echo esc_attr((string)((int)($plugin['site_count'] ?? 0))); ?>">
                            <td>
                                <strong><?php echo esc_html((string)($plugin['name'] ?? '')); ?></strong>
                                <?php if (!empty($plugin['description'])) { ?>
                                    <br><span class="description"><?php echo esc_html((string)$plugin['description']); ?></span>
                                <?php } ?>
                            </td>
                            <td><?php echo esc_html((string)($plugin['version'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($plugin['author'] ?? '')); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int)($plugin['site_count'] ?? 0))); ?></td>
                            <td>
                                <div class="rrze-msm-site-actions">
                                    <?php if (!empty($plugin['deactivate_url'])) { ?>
                                        <button type="button" class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-open-plugin-deactivate-modal" data-plugin-name="<?php echo esc_attr((string)($plugin['name'] ?? '')); ?>" data-deactivate-url="<?php echo esc_url((string)$plugin['deactivate_url']); ?>" title="<?php echo esc_attr__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager'); ?>" aria-label="<?php echo esc_attr__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager'); ?>">
                                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                            <span class="screen-reader-text"><?php echo esc_html__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager'); ?></span>
                                        </button>
                                    <?php } ?>
                                    <?php if (!empty($plugin['settings_url'])) { ?>
                                        <a class="button button-small rrze-msm-site-action" href="<?php echo esc_url((string)$plugin['settings_url']); ?>" title="<?php echo esc_attr__('Einstellungen', 'rrze-multisite-manager'); ?>" aria-label="<?php echo esc_attr__('Einstellungen', 'rrze-multisite-manager'); ?>">
                                            <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                                            <span class="screen-reader-text"><?php echo esc_html__('Einstellungen', 'rrze-multisite-manager'); ?></span>
                                        </a>
                                    <?php } ?>
                                    <?php if (!empty($plugin['details_url'])) { ?>
                                        <a class="button button-small rrze-msm-site-action rrze-msm-site-action-info" href="<?php echo esc_url((string)$plugin['details_url']); ?>" title="<?php echo esc_attr__('Details', 'rrze-multisite-manager'); ?>" aria-label="<?php echo esc_attr__('Details', 'rrze-multisite-manager'); ?>" target="_blank" rel="noopener noreferrer">
                                            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                            <span class="screen-reader-text"><?php echo esc_html__('Details', 'rrze-multisite-manager'); ?></span>
                                        </a>
                                    <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Seitennavigation', 'rrze-multisite-manager'); ?>"></div>
            </div>
        </div>
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
