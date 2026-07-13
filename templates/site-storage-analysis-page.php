<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Speicheranalyse', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Diagnose der Speicherbelegung einer einzelnen Website auf Basis ihres Upload-Verzeichnisses.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <?php if (!empty($site_summary)) { ?>
                    <div class="rrze-msm-site-header-search">
                        <label class="screen-reader-text" for="rrze-msm-site-search"><?php echo esc_html__('Website suchen', 'rrze-multisite-manager'); ?></label>
                        <input id="rrze-msm-site-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($site_search_placeholder); ?>" autocomplete="off">
                        <div class="rrze-msm-site-search-results" id="rrze-msm-site-search-results"></div>
                    </div>
                <?php } ?>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (empty($site_summary)) { ?>
            <section class="rrze-msm-detail-search-entry">
                <div class="rrze-msm-detail-search-entry-inner">
                    <label class="screen-reader-text" for="rrze-msm-site-search"><?php echo esc_html__('Website suchen', 'rrze-multisite-manager'); ?></label>
                    <input id="rrze-msm-site-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($site_search_placeholder); ?>" autocomplete="off">
                    <div class="rrze-msm-site-search-results" id="rrze-msm-site-search-results"></div>
                </div>
            </section>
        <?php } ?>

        <?php if (!empty($site_summary)) { ?>
            <?php if (!empty($orphan_file_deleted_count) || !empty($orphan_file_deleted)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <div class="notice notice-success inline">
                        <p>
                            <?php
                            if (!empty($orphan_file_deleted_count) && (int)$orphan_file_deleted_count > 1) {
                                echo esc_html(
                                    sprintf(
                                        _n('%d Datei gelöscht.', '%d Dateien gelöscht.', (int)$orphan_file_deleted_count, 'rrze-multisite-manager'),
                                        (int)$orphan_file_deleted_count
                                    )
                                );
                            } else {
                                echo esc_html(sprintf(__('Datei gelöscht: %s', 'rrze-multisite-manager'), rawurldecode((string)$orphan_file_deleted)));
                            }
                            ?>
                        </p>
                    </div>
                </section>
            <?php } ?>

            <?php if (!empty($orphan_file_error)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <div class="notice notice-error inline">
                        <p><?php echo esc_html(rawurldecode((string)$orphan_file_error)); ?></p>
                    </div>
                </section>
            <?php } ?>

            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-details-hero">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html((string)($site_summary['name'] ?? '')); ?></h2>
                    <p><a href="<?php echo esc_url((string)($site_summary['url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($site_summary['url'] ?? '')); ?></a></p>
                </header>
                <div class="rrze-msm-site-details-hero-grid">
                    <div class="rrze-msm-site-details-logo">
                        <?php if (!empty($site_summary['branding']['url'])) { ?>
                            <img src="<?php echo esc_url((string)$site_summary['branding']['url']); ?>" alt="<?php echo esc_attr((string)($site_summary['name'] ?? '')); ?>">
                        <?php } else { ?>
                            <span class="rrze-msm-site-branding-empty"><?php echo esc_html__('Kein Logo', 'rrze-multisite-manager'); ?></span>
                        <?php } ?>
                    </div>
                    <div class="rrze-msm-site-details-meta">
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Website-Details', 'rrze-multisite-manager'); ?></strong>
                            <div class="rrze-msm-site-actions">
                                <a class="button button-secondary" href="<?php echo esc_url($site_details_url); ?>"><?php echo esc_html__('Zurück zu Website-Details', 'rrze-multisite-manager'); ?></a>
                                <?php if (!empty($site_media_library_url)) { ?>
                                    <a class="button button-secondary" href="<?php echo esc_url($site_media_library_url); ?>"><?php echo esc_html__('Mediathek der Website', 'rrze-multisite-manager'); ?></a>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('WordPress-Speicherwert', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo esc_html((string)($site_summary['storage']['used_label'] ?? '')); ?></div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Upload-Verzeichnis', 'rrze-multisite-manager'); ?></strong>
                            <div><code><?php echo esc_html((string)($storage_analysis['upload_basedir'] ?? '')); ?></code></div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Gefundene Größe', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo esc_html((string)($storage_analysis['actual_label'] ?? '')); ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (!empty($storage_analysis['error'])) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <div class="notice notice-error inline">
                        <p><?php echo esc_html((string)$storage_analysis['error']); ?></p>
                    </div>
                </section>
            <?php } else { ?>
                <?php if (!empty($storage_analysis['warnings'])) { ?>
                    <section class="rrze-msm-widget rrze-msm-widget-span-12">
                        <?php foreach ((array)$storage_analysis['warnings'] as $warning_row) { ?>
                            <div class="notice <?php echo esc_attr((string)($warning_row['type'] ?? '') === 'info' ? 'notice-info' : 'notice-warning'); ?> inline">
                                <p><?php echo esc_html((string)($warning_row['message'] ?? '')); ?></p>
                            </div>
                        <?php } ?>
                    </section>
                <?php } ?>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Überblick', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Vergleicht den von WordPress gemeldeten Speicherwert mit dem tatsächlich gescannten Upload-Verzeichnis.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <table class="widefat striped rrze-msm-table">
                        <tbody>
                            <?php foreach ((array)($storage_analysis['summary_rows'] ?? []) as $summary_row) { ?>
                                <tr>
                                    <th><?php echo esc_html((string)($summary_row['label'] ?? '')); ?></th>
                                    <td><?php echo esc_html((string)($summary_row['value'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <th><?php echo esc_html__('Upload-URL', 'rrze-multisite-manager'); ?></th>
                                <td><code><?php echo esc_html((string)($storage_analysis['upload_baseurl'] ?? '')); ?></code></td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Top-Level-Ordner im Upload-Verzeichnis', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Zeigt, welche Hauptordner im Upload-Bereich wie viel Speicher verbrauchen.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($storage_analysis['top_level_directories'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Ordner', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Dateien', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Größe', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Anteil', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ((array)$storage_analysis['top_level_directories'] as $directory_row) { ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string)($directory_row['path'] ?? '')); ?></code></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($directory_row['file_count'] ?? 0))); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($directory_row['size_label'] ?? '')); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(sprintf(__('%d%%', 'rrze-multisite-manager'), (int)($directory_row['percent'] ?? 0))); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Im Upload-Verzeichnis wurden keine verwertbaren Ordnerdaten gefunden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Top 10 Speicherplatzverbraucher', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Zeigt die zehn größten Speicherplatzverbraucher im Upload-Verzeichnis sowie bei Bedarf einen zusätzlichen Anteil „Sonstige“.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php echo $top_consumers_pie_chart_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal trusted pie chart renderer output. ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Größte Dateien', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Dateien im Uploads-Ordner mit dem größten Speicherplatzverbrauch.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($storage_analysis['largest_files'])) { ?>
                        <div class="rrze-msm-site-table-wrap" data-table-id="largest-files" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                            <div class="tablenav top">
                                <div class="alignleft actions">
                                    <label for="rrze-msm-largest-files-per-page"><?php echo esc_html__('Anzeigen:', 'rrze-multisite-manager'); ?></label>
                                    <select class="rrze-msm-site-table-per-page" id="rrze-msm-largest-files-per-page">
                                        <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                        <option value="10">10</option>
                                        <option value="30">30</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                </div>
                            </div>
                            <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('Datei', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Dokumententyp', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Größe', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Zuletzt geändert', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['largest_files'] as $file_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($file_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($file_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($file_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($file_row['modified_timestamp'] ?? 0)); ?>">
                                            <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                <?php if (!empty($file_row['media_edit_url'])) { ?>
                                                    <a href="<?php echo esc_url((string)$file_row['media_edit_url']); ?>"><code><?php echo esc_html((string)($file_row['path'] ?? '')); ?></code></a>
                                                <?php } else { ?>
                                                    <code><?php echo esc_html((string)($file_row['path'] ?? '')); ?></code>
                                                <?php } ?>
                                            </td>
                                            <td><?php echo esc_html((string)($file_row['type_label'] ?? '')); ?></td>
                                            <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($file_row['size_label'] ?? '')); ?></td>
                                            <td><?php echo esc_html((string)($file_row['modified_label'] ?? '')); ?></td>
                                            <td>
                                                <div class="rrze-msm-site-actions">
                                                    <?php if (!empty($file_row['media_edit_url'])) { ?>
                                                        <a class="button button-secondary" href="<?php echo esc_url((string)$file_row['media_edit_url']); ?>"><?php echo esc_html__('Mediathek', 'rrze-multisite-manager'); ?></a>
                                                    <?php } elseif (!empty($file_row['file_url'])) { ?>
                                                        <a class="button button-secondary" href="<?php echo esc_url((string)$file_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Datei aufrufen', 'rrze-multisite-manager'); ?></a>
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
                    <?php } else { ?>
                        <p><?php echo esc_html__('Es wurden keine Dateien gefunden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Potenziell verwaiste Dateien', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Das sind Dateien im Uploads-Ordner, die aktuell nicht über Attachment-Metadaten referenziert werden.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <p>
                        <strong><?php echo esc_html(number_format_i18n((int)($storage_analysis['orphan_file_count'] ?? 0))); ?></strong>
                        <?php echo esc_html__('Dateien', 'rrze-multisite-manager'); ?>
                        ,
                        <strong><?php echo esc_html((string)($storage_analysis['orphan_total_label'] ?? '')); ?></strong>
                    </p>
                    <?php if (!empty($storage_analysis['orphan_files_truncated'])) { ?>
                        <div class="notice notice-warning inline">
                            <p><?php echo esc_html__('Für die Detailtabellen wurde die Analyse aus Performance-Gründen auf einen größeren, aber dennoch begrenzten Ausschnitt potenziell verwaister Dateien beschränkt.', 'rrze-multisite-manager'); ?></p>
                        </div>
                    <?php } ?>
                    <?php if (!empty($storage_analysis['largest_orphan_files'])) { ?>
                        <?php if (!empty($storage_analysis['orphan_files_found_in_content'])) { ?>
                            <h3><?php echo esc_html__('Noch über Referenzen oder Code-Registrierung gefunden', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('Diese Dateien sind keine Attachments, werden aber noch in Posts, Pages, deren Metafeldern oder über Register-/Enqueue-Aufrufe in aktiven Plugins, MU-Plugins oder dem aktiven Theme referenziert.', 'rrze-multisite-manager'); ?></p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="orphan-files-referenced" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-orphan-files-referenced-per-page"><?php echo esc_html__('Anzeigen:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-orphan-files-referenced-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('Datei', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Dokumententyp', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Größe', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><?php echo esc_html__('Referenzen', 'rrze-multisite-manager'); ?></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['orphan_files_found_in_content'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>">
                                                <td><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['content_usage_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Datei aufrufen', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Bei Google suchen', 'rrze-multisite-manager'); ?></a>
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
                        <?php } else { ?>
                            <h3><?php echo esc_html__('Noch über Referenzen oder Code-Registrierung gefunden', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('Für die analysierten potenziell verwaisten Dateien wurden keine Referenzen in Posts, Pages, deren Metafeldern oder über Register-/Enqueue-Aufrufe im aktiven Code gefunden.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>

                        <?php if (!empty($storage_analysis['orphan_files_without_content_matches'])) { ?>
                            <h3><?php echo esc_html__('Nirgends in Referenzen oder aktiver Code-Registrierung gefunden', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('Diese Dateien sind keine Attachments und es wurden weder direkte Referenzen in Posts, Pages oder deren Metafeldern noch Register-/Enqueue-Aufrufe im aktiven Code gefunden.', 'rrze-multisite-manager'); ?></p>
                            <form method="post" action="<?php echo esc_url((string)$orphan_file_delete_action); ?>">
                                <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                <?php wp_nonce_field('rrze_multisite_manager_delete_orphan_files_' . $site_id); ?>
                                <p class="rrze-msm-site-actions">
                                    <button
                                        type="button"
                                        class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                        data-select-label="<?php echo esc_attr__('Alle auswählen', 'rrze-multisite-manager'); ?>"
                                        data-unselect-label="<?php echo esc_attr__('Auswahl aufheben', 'rrze-multisite-manager'); ?>">
                                        <?php echo esc_html__('Alle auswählen', 'rrze-multisite-manager'); ?>
                                    </button>
                                    <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Selektierte Dateien löschen', 'rrze-multisite-manager'); ?></button>
                                </p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="orphan-files-unreferenced" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-orphan-files-unreferenced-per-page"><?php echo esc_html__('Anzeigen:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-orphan-files-unreferenced-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo esc_html__('Auswahl', 'rrze-multisite-manager'); ?></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('Datei', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Dokumententyp', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Größe', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Zuletzt geändert', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['orphan_files_without_content_matches'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($orphan_row['modified_timestamp'] ?? 0)); ?>">
                                                <td><input type="checkbox" name="relative_paths[]" value="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"></td>
                                                <td><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['modified_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Datei aufrufen', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Bei Google suchen', 'rrze-multisite-manager'); ?></a>
                                                            <button
                                                                type="button"
                                                                class="button button-primary rrze-msm-open-orphan-file-delete-modal"
                                                                data-site-id="<?php echo esc_attr((string)$site_id); ?>"
                                                                data-file-path="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"
                                                                data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_file_' . $site_id . '_' . (string)($orphan_row['path'] ?? ''))); ?>">
                                                                <?php echo esc_html__('Datei löschen', 'rrze-multisite-manager'); ?>
                                                            </button>
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
                            <p class="rrze-msm-site-actions">
                                <button
                                    type="button"
                                    class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                    data-select-label="<?php echo esc_attr__('Alle auswählen', 'rrze-multisite-manager'); ?>"
                                    data-unselect-label="<?php echo esc_attr__('Auswahl aufheben', 'rrze-multisite-manager'); ?>">
                                    <?php echo esc_html__('Alle auswählen', 'rrze-multisite-manager'); ?>
                                </button>
                                <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Selektierte Dateien löschen', 'rrze-multisite-manager'); ?></button>
                            </p>
                            </form>
                        <?php } ?>

                        <?php if (empty($storage_analysis['orphan_files_found_in_content']) && empty($storage_analysis['orphan_files_without_content_matches'])) { ?>
                            <form method="post" action="<?php echo esc_url((string)$orphan_file_delete_action); ?>">
                                <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                <?php wp_nonce_field('rrze_multisite_manager_delete_orphan_files_' . $site_id); ?>
                                <p class="rrze-msm-site-actions">
                                    <button
                                        type="button"
                                        class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                        data-select-label="<?php echo esc_attr__('Alle auswählen', 'rrze-multisite-manager'); ?>"
                                        data-unselect-label="<?php echo esc_attr__('Auswahl aufheben', 'rrze-multisite-manager'); ?>">
                                        <?php echo esc_html__('Alle auswählen', 'rrze-multisite-manager'); ?>
                                    </button>
                                    <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Selektierte Dateien löschen', 'rrze-multisite-manager'); ?></button>
                                </p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="orphan-files-fallback" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-orphan-files-fallback-per-page"><?php echo esc_html__('Anzeigen:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-orphan-files-fallback-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo esc_html__('Auswahl', 'rrze-multisite-manager'); ?></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('Datei', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Dokumententyp', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Größe', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Zuletzt geändert', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['largest_orphan_files'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($orphan_row['modified_timestamp'] ?? 0)); ?>">
                                                <td><input type="checkbox" name="relative_paths[]" value="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"></td>
                                                <td><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['modified_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Datei aufrufen', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Bei Google suchen', 'rrze-multisite-manager'); ?></a>
                                                            <button
                                                                type="button"
                                                                class="button button-primary rrze-msm-open-orphan-file-delete-modal"
                                                                data-site-id="<?php echo esc_attr((string)$site_id); ?>"
                                                                data-file-path="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"
                                                                data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_file_' . $site_id . '_' . (string)($orphan_row['path'] ?? ''))); ?>">
                                                                <?php echo esc_html__('Datei löschen', 'rrze-multisite-manager'); ?>
                                                            </button>
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
                            <p class="rrze-msm-site-actions">
                                <button
                                    type="button"
                                    class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                    data-select-label="<?php echo esc_attr__('Alle auswählen', 'rrze-multisite-manager'); ?>"
                                    data-unselect-label="<?php echo esc_attr__('Auswahl aufheben', 'rrze-multisite-manager'); ?>">
                                    <?php echo esc_html__('Alle auswählen', 'rrze-multisite-manager'); ?>
                                </button>
                                <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Selektierte Dateien löschen', 'rrze-multisite-manager'); ?></button>
                            </p>
                            </form>
                        <?php } ?>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Es wurden keine potenziell verwaisten Dateien erkannt.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <div class="rrze-msm-modal" id="rrze-msm-orphan-file-delete-modal" hidden>
                    <div class="rrze-msm-modal-backdrop rrze-msm-close-orphan-file-delete-modal"></div>
                    <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-orphan-file-delete-title">
                        <h3 id="rrze-msm-orphan-file-delete-title"><?php echo esc_html__('Datei endgültig löschen', 'rrze-multisite-manager'); ?></h3>
                        <p class="rrze-msm-modal-text"><?php echo esc_html__('Diese Datei wird direkt aus dem Uploads-Ordner entfernt. Das kann nicht rückgängig gemacht werden.', 'rrze-multisite-manager'); ?></p>
                        <p class="rrze-msm-modal-target">
                            <strong><?php echo esc_html__('Datei:', 'rrze-multisite-manager'); ?></strong>
                            <span id="rrze-msm-orphan-file-delete-target"></span>
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                            <input type="hidden" name="site_id" id="rrze-msm-orphan-file-delete-site-id" value="">
                            <input type="hidden" name="relative_path" id="rrze-msm-orphan-file-delete-path" value="">
                            <input type="hidden" name="_wpnonce" id="rrze-msm-orphan-file-delete-nonce" value="">
                            <div id="rrze-msm-orphan-file-delete-paths"></div>
                            <label class="rrze-msm-modal-checkbox">
                                <input type="checkbox" id="rrze-msm-orphan-file-delete-confirm">
                                <span><?php echo esc_html__('Ich verstehe, dass die Datei endgültig gelöscht wird.', 'rrze-multisite-manager'); ?></span>
                            </label>
                            <div class="rrze-msm-modal-actions">
                                <button type="button" class="button button-secondary rrze-msm-close-orphan-file-delete-modal"><?php echo esc_html__('Abbrechen', 'rrze-multisite-manager'); ?></button>
                                <button type="submit" class="button button-primary" id="rrze-msm-orphan-file-delete-submit"><?php echo esc_html__('Datei endgültig löschen', 'rrze-multisite-manager'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function rrzeMsmGetOrphanSelectionInputs(form) {
                    var inputs = [];

                    if (!form) {
                        return inputs;
                    }

                    inputs = form.querySelectorAll('input[name="relative_paths[]"]');

                    return Array.prototype.slice.call(inputs);
                }

                function rrzeMsmUpdateOrphanSelectionButtons(form) {
                    var buttons = null;
                    var inputs = [];
                    var allSelected = false;
                    var index = 0;
                    var selectLabel = '';
                    var unselectLabel = '';

                    if (!form) {
                        return;
                    }

                    buttons = form.querySelectorAll('.rrze-msm-toggle-orphan-file-selection');
                    inputs = rrzeMsmGetOrphanSelectionInputs(form);
                    allSelected = inputs.length > 0;

                    for (index = 0; index < inputs.length; index++) {
                        if (!inputs[index].checked) {
                            allSelected = false;
                            break;
                        }
                    }

                    for (index = 0; index < buttons.length; index++) {
                        selectLabel = buttons[index].getAttribute('data-select-label') || '';
                        unselectLabel = buttons[index].getAttribute('data-unselect-label') || '';
                        buttons[index].textContent = allSelected ? unselectLabel : selectLabel;
                        buttons[index].setAttribute('aria-pressed', allSelected ? 'true' : 'false');
                    }
                }

                function rrzeMsmToggleOrphanSelection(event) {
                    var button = event.currentTarget;
                    var form = null;
                    var inputs = [];
                    var shouldSelectAll = false;
                    var index = 0;

                    if (!button) {
                        return;
                    }

                    form = button.closest('form');

                    if (!form) {
                        return;
                    }

                    inputs = rrzeMsmGetOrphanSelectionInputs(form);

                    for (index = 0; index < inputs.length; index++) {
                        if (!inputs[index].checked) {
                            shouldSelectAll = true;
                            break;
                        }
                    }

                    for (index = 0; index < inputs.length; index++) {
                        inputs[index].checked = shouldSelectAll;
                    }

                    rrzeMsmUpdateOrphanSelectionButtons(form);
                }

                function rrzeMsmHandleOrphanSelectionChange(event) {
                    var input = event.currentTarget;
                    var form = null;

                    if (!input) {
                        return;
                    }

                    form = input.closest('form');

                    if (!form) {
                        return;
                    }

                    rrzeMsmUpdateOrphanSelectionButtons(form);
                }

                function rrzeMsmInitOrphanSelectionToggle() {
                    var buttons = document.querySelectorAll('.rrze-msm-toggle-orphan-file-selection');
                    var inputs = document.querySelectorAll('input[name="relative_paths[]"]');
                    var index = 0;
                    var form = null;

                    for (index = 0; index < buttons.length; index++) {
                        buttons[index].addEventListener('click', rrzeMsmToggleOrphanSelection);
                        form = buttons[index].closest('form');

                        if (form) {
                            rrzeMsmUpdateOrphanSelectionButtons(form);
                        }
                    }

                    for (index = 0; index < inputs.length; index++) {
                        inputs[index].addEventListener('change', rrzeMsmHandleOrphanSelectionChange);
                    }
                }

                document.addEventListener('DOMContentLoaded', rrzeMsmInitOrphanSelectionToggle);
                </script>

            <?php } ?>
        <?php } ?>
    </div>
</div>
