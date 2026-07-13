<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Theme-Details', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Detailansicht eines einzelnen Themes mit Metadaten, Nutzung und technischer Code-Auswertung.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <?php if (!empty($theme_details)) { ?>
                    <div class="rrze-msm-site-header-search">
                        <label class="screen-reader-text" for="rrze-msm-theme-search"><?php echo esc_html__('Theme suchen', 'rrze-multisite-manager'); ?></label>
                        <input id="rrze-msm-theme-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($theme_search_placeholder); ?>" autocomplete="off">
                        <div class="rrze-msm-site-search-results" id="rrze-msm-theme-search-results"></div>
                    </div>
                <?php } ?>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (empty($theme_details)) { ?>
            <section class="rrze-msm-detail-search-entry">
                <div class="rrze-msm-detail-search-entry-inner">
                    <label class="screen-reader-text" for="rrze-msm-theme-search"><?php echo esc_html__('Theme suchen', 'rrze-multisite-manager'); ?></label>
                    <input id="rrze-msm-theme-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($theme_search_placeholder); ?>" autocomplete="off">
                    <div class="rrze-msm-site-search-results" id="rrze-msm-theme-search-results"></div>
                </div>
            </section>
        <?php } ?>

        <?php if (!empty($theme_details)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Theme', 'rrze-multisite-manager'); ?></h2>
                </header>
                <?php echo $theme_widget->renderThemeCard((array)$theme_details, ['link_title' => false]); ?>
            </section>

            <?php if (!empty($theme_actions_html)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Aktionen', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-site-details-actions"><?php echo $theme_actions_html; ?></div>
                </section>
            <?php } ?>

            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Metadaten', 'rrze-multisite-manager'); ?></h2>
                </header>
                <div class="rrze-msm-plugin-meta-tables">
                    <div class="rrze-msm-plugin-meta-table-wrap">
                        <h3><?php echo esc_html__('Kerndaten', 'rrze-multisite-manager'); ?></h3>
                        <table class="widefat striped rrze-msm-table">
                            <tbody>
                                <tr>
                                    <th><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['version'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Autor', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['author_url'])) { ?>
                                            <a href="<?php echo esc_url((string)$theme_details['author_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($theme_details['author'] ?? '')); ?></a>
                                        <?php } else { ?>
                                            <?php echo esc_html((string)($theme_details['author'] ?? '')); ?>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['author_email'])) { ?>
                                            <br><a href="mailto:<?php echo esc_attr((string)$theme_details['author_email']); ?>"><?php echo esc_html((string)$theme_details['author_email']); ?></a>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Theme-URL', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['theme_uri'])) { ?>
                                            <a href="<?php echo esc_url((string)$theme_details['theme_uri']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$theme_details['theme_uri']); ?></a>
                                        <?php } else { ?>
                                            <?php echo esc_html__('Nicht vorhanden', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Tags', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo !empty($theme_details['tags']) ? esc_html(implode(', ', (array)$theme_details['tags'])) : esc_html__('Keine Angaben', 'rrze-multisite-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Lizenz', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['license']['name'])) { ?>
                                            <?php if (!empty($theme_details['license']['url'])) { ?>
                                                <a href="<?php echo esc_url((string)$theme_details['license']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$theme_details['license']['name']); ?></a>
                                            <?php } else { ?>
                                                <?php echo esc_html((string)$theme_details['license']['name']); ?>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('Keine Angaben', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Datum letzte Version', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['last_release_date_label'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Installationsdatum', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['installation_date_label'] ?? '')); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="rrze-msm-plugin-meta-table-wrap">
                        <h3><?php echo esc_html__('Technische Daten', 'rrze-multisite-manager'); ?></h3>
                        <table class="widefat striped rrze-msm-table">
                            <tbody>
                                <tr>
                                    <th><?php echo esc_html__('Stylesheet', 'rrze-multisite-manager'); ?></th>
                                    <td><code><?php echo esc_html((string)($theme_details['stylesheet'] ?? '')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Template', 'rrze-multisite-manager'); ?></th>
                                    <td><code><?php echo esc_html((string)($theme_details['template'] ?? '')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Repository', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['repository']['type'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('Typ: %s', 'rrze-multisite-manager'), (string)$theme_details['repository']['type'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['repository']['url'])) { ?>
                                            <div><a href="<?php echo esc_url((string)$theme_details['repository']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$theme_details['repository']['url']); ?></a></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['repository']['issues'])) { ?>
                                            <div><a href="<?php echo esc_url((string)$theme_details['repository']['issues']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Issues', 'rrze-multisite-manager'); ?></a></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['repository']['clone'])) { ?>
                                            <div><code><?php echo esc_html((string)$theme_details['repository']['clone']); ?></code></div>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Kompatibilität', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['compatibility']['wp_requires'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('WP ab: %s', 'rrze-multisite-manager'), (string)$theme_details['compatibility']['wp_requires'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['compatibility']['wp_tested_up_to'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('Getestet bis: %s', 'rrze-multisite-manager'), (string)$theme_details['compatibility']['wp_tested_up_to'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['compatibility']['php_requires'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('PHP ab: %s', 'rrze-multisite-manager'), (string)$theme_details['compatibility']['php_requires'])); ?></div>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Textdomain', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['text_domain'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Übersetzungen', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['translation_languages']) && is_array($theme_details['translation_languages'])) { ?>
                                            <?php foreach ($theme_details['translation_languages'] as $translation_language) { ?>
                                                <div><code><?php echo esc_html((string)$translation_language); ?></code></div>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('Keine Übersetzungsdateien gefunden', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php if (!empty($theme_details['supports'])) { ?>
                                    <tr>
                                        <th><?php echo esc_html__('Supports', 'rrze-multisite-manager'); ?></th>
                                        <td><?php echo esc_html(implode(', ', (array)$theme_details['supports'])); ?></td>
                                    </tr>
                                <?php } ?>
                                <tr>
                                    <th><?php echo esc_html__('Metadatenquellen', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo !empty($theme_details['metadata_sources']) ? esc_html(implode(', ', (array)$theme_details['metadata_sources'])) : esc_html__('Nur Theme-Header', 'rrze-multisite-manager'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <?php if (!empty($theme_readme_html)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('README', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-readme-toggle" data-readme-id="theme-readme">
                        <p class="rrze-msm-readme-toggle-collapsed">
                            <button type="button" class="button-link rrze-msm-readme-toggle-button" data-readme-id="theme-readme" aria-expanded="false"><?php echo esc_html__('README Markdown anzeigen', 'rrze-multisite-manager'); ?></button>
                        </p>
                        <div class="rrze-msm-readme-toggle-content" hidden>
                            <p><button type="button" class="button-link rrze-msm-readme-toggle-button" data-readme-id="theme-readme" aria-expanded="true"><?php echo esc_html__('README Markdown verbergen', 'rrze-multisite-manager'); ?></button></p>
                            <div class="rrze-msm-readme-markdown"><?php echo $theme_readme_html; ?></div>
                        </div>
                    </div>
                </section>
            <?php } ?>

            <div class="rrze-msm-grid">
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Shortcodes', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <?php if (!empty($theme_details['shortcodes']) && is_array($theme_details['shortcodes'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead><tr><th><?php echo esc_html__('Shortcode', 'rrze-multisite-manager'); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($theme_details['shortcodes'] as $theme_shortcode) { ?>
                                    <tr><td><code><?php echo esc_html((string)$theme_shortcode); ?></code></td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Es wurden keine statisch erkennbaren Shortcodes gefunden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Registrierte Bildgrößen', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Hier sind die im Theme-Code direkt erkennbaren Registrierungen über add_image_size() und set_post_thumbnail_size() gelistet.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($theme_details['image_sizes']) && is_array($theme_details['image_sizes'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Breite', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Höhe', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Crop', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($theme_details['image_sizes'] as $theme_image_size) { ?>
                                    <tr>
                                        <td><strong><?php echo esc_html((string)($theme_image_size['label'] ?? '')); ?></strong></td>
                                        <td><code><?php echo esc_html((string)($theme_image_size['slug'] ?? '')); ?></code></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($theme_image_size['width'] ?? 0))); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($theme_image_size['height'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string)($theme_image_size['crop'] ?? '')); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Es wurden keine direkt statisch erkennbaren Bildgrößen gefunden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Blöcke und Block Patterns', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-plugin-analysis-grid">
                        <div>
                            <h3><?php echo esc_html__('Blöcke', 'rrze-multisite-manager'); ?></h3>
                            <?php if (!empty($theme_details['blocks']) && is_array($theme_details['blocks'])) { ?>
                                <div class="rrze-msm-analysis-table-wrap">
                                    <table class="widefat striped rrze-msm-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo esc_html__('Titel', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Beschreibung', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Block', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Kategorie', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Keywords', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Quelle', 'rrze-multisite-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($theme_details['blocks'] as $theme_block) { ?>
                                                <tr>
                                                    <td>
                                                        <strong>
                                                            <?php if (!empty($theme_block['icon'])) { ?>
                                                                <span class="dashicons dashicons-<?php echo esc_attr((string)$theme_block['icon']); ?> rrze-msm-block-icon" aria-hidden="true"></span>
                                                            <?php } ?>
                                                            <?php echo esc_html((string)($theme_block['title'] ?? '')); ?>
                                                        </strong>
                                                    </td>
                                                    <td><?php echo esc_html((string)($theme_block['description'] ?? '')); ?></td>
                                                    <td><code><?php echo esc_html((string)($theme_block['name'] ?? '')); ?></code></td>
                                                    <td><?php echo esc_html((string)($theme_block['category'] ?? '')); ?></td>
                                                    <td><?php echo !empty($theme_block['keywords']) && is_array($theme_block['keywords']) ? esc_html(implode(', ', (array)$theme_block['keywords'])) : ''; ?></td>
                                                    <td><?php echo esc_html((string)($theme_block['source'] ?? '')); ?></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <p><?php echo esc_html__('Es wurden keine statisch erkennbaren Blöcke gefunden.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                        <div>
                            <h3><?php echo esc_html__('Block Patterns', 'rrze-multisite-manager'); ?></h3>
                            <?php if (!empty($theme_details['block_patterns']) && is_array($theme_details['block_patterns'])) { ?>
                                <div class="rrze-msm-analysis-table-wrap">
                                    <table class="widefat striped rrze-msm-table">
                                        <thead><tr><th><?php echo esc_html__('Pattern', 'rrze-multisite-manager'); ?></th></tr></thead>
                                        <tbody>
                                            <?php foreach ($theme_details['block_patterns'] as $theme_pattern) { ?>
                                                <tr><td><code><?php echo esc_html((string)$theme_pattern); ?></code></td></tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <p><?php echo esc_html__('Es wurden keine statisch erkennbaren Block Patterns gefunden.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                    </div>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Bereitgestellte Actions und Filter', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <?php if (!empty($theme_details['provided_hooks']) && is_array($theme_details['provided_hooks'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Hook', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Typ', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($theme_details['provided_hooks'] as $theme_hook) { ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string)($theme_hook['name'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string)($theme_hook['type'] ?? '')); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('Es wurden keine statisch erkennbaren Actions oder Filter gefunden.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>
            </div>
        <?php } ?>
    </div>
</div>
