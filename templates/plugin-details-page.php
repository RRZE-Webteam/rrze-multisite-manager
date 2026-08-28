<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Plugin Details', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Detailed view of a single plugin with metadata, usage, and technical code analysis.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <?php if (!empty($plugin_details)) { ?>
                    <div class="rrze-msm-site-header-search">
                        <label class="screen-reader-text" for="rrze-msm-plugin-search"><?php echo esc_html__('Search plugin', 'rrze-multisite-manager'); ?></label>
                        <input id="rrze-msm-plugin-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($plugin_search_placeholder); ?>" autocomplete="off">
                        <div class="rrze-msm-site-search-results" id="rrze-msm-plugin-search-results"></div>
                    </div>
                <?php } ?>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (empty($plugin_details)) { ?>
            <section class="rrze-msm-detail-search-entry">
                <div class="rrze-msm-detail-search-entry-inner">
                    <label class="screen-reader-text" for="rrze-msm-plugin-search"><?php echo esc_html__('Search plugin', 'rrze-multisite-manager'); ?></label>
                    <input id="rrze-msm-plugin-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($plugin_search_placeholder); ?>" autocomplete="off">
                    <div class="rrze-msm-site-search-results" id="rrze-msm-plugin-search-results"></div>
                </div>
            </section>
        <?php } ?>

        <?php if (!empty($plugin_details)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-details-hero">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html((string)$plugin_details['name']); ?></h2>
                    <?php if (!empty($plugin_details['description'])) { ?>
                        <p><?php echo esc_html((string)$plugin_details['description']); ?></p>
                    <?php } ?>
                </header>
            </section>

            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Status and actions', 'rrze-multisite-manager'); ?></h2>
                </header>
                <div class="rrze-msm-plugin-status-actions">
                    <div class="rrze-msm-plugin-status-actions-item">
                        <strong><?php echo esc_html__('Status', 'rrze-multisite-manager'); ?></strong>
                        <div><?php echo $plugin_status_badges_html; ?></div>
                        <?php if (!empty($plugin_status_update_html)) { ?>
                            <?php echo $plugin_status_update_html; ?>
                        <?php } ?>
                    </div>
                    <?php if (!empty($plugin_actions_html)) { ?>
                        <div class="rrze-msm-plugin-status-actions-item">
                            <strong><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></strong>
                            <div class="rrze-msm-site-details-actions"><?php echo $plugin_actions_html; ?></div>
                        </div>
                    <?php } ?>
                </div>
            </section>

            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Metadata', 'rrze-multisite-manager'); ?></h2>
                </header>
                <div class="rrze-msm-plugin-meta-tables">
                    <div class="rrze-msm-plugin-meta-table-wrap">
                        <h3><?php echo esc_html__('Core data', 'rrze-multisite-manager'); ?></h3>
                        <table class="widefat striped rrze-msm-table">
                            <tbody>
                                <tr>
                                    <th><?php echo esc_html__('Version', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($plugin_details['version'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Author', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($plugin_details['author_url'])) { ?>
                                            <a href="<?php echo esc_url((string)$plugin_details['author_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($plugin_details['author'] ?? '')); ?></a>
                                        <?php } else { ?>
                                            <?php echo esc_html((string)($plugin_details['author'] ?? '')); ?>
                                        <?php } ?>
                                        <?php if (!empty($plugin_details['author_email'])) { ?>
                                            <br><a href="mailto:<?php echo esc_attr((string)$plugin_details['author_email']); ?>"><?php echo esc_html((string)$plugin_details['author_email']); ?></a>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Plugin URL', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($plugin_details['details_url'])) { ?>
                                            <a href="<?php echo esc_url((string)$plugin_details['details_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$plugin_details['details_url']); ?></a>
                                        <?php } else { ?>
                                            <?php echo esc_html__('Not present', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Tags', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo !empty($plugin_details['tags']) ? esc_html(implode(', ', (array)$plugin_details['tags'])) : esc_html__('No information', 'rrze-multisite-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('License', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($plugin_details['license']['name'])) { ?>
                                            <?php if (!empty($plugin_details['license']['url'])) { ?>
                                                <a href="<?php echo esc_url((string)$plugin_details['license']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$plugin_details['license']['name']); ?></a>
                                            <?php } else { ?>
                                                <?php echo esc_html((string)$plugin_details['license']['name']); ?>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('No information', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Date of latest version', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($plugin_details['last_release_date_label'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Installation date', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($plugin_details['installation_date_label'] ?? '')); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="rrze-msm-plugin-meta-table-wrap">
                        <h3><?php echo esc_html__('Technical data', 'rrze-multisite-manager'); ?></h3>
                        <table class="widefat striped rrze-msm-table">
                            <tbody>
                                <tr>
                                    <th><?php echo esc_html__('Plugin file', 'rrze-multisite-manager'); ?></th>
                                    <td><code><?php echo esc_html((string)($plugin_details['file'] ?? '')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Repository', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($plugin_details['repository']['type'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('Type: %s', 'rrze-multisite-manager'), (string)$plugin_details['repository']['type'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($plugin_details['repository']['url'])) { ?>
                                            <div><a href="<?php echo esc_url((string)$plugin_details['repository']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$plugin_details['repository']['url']); ?></a></div>
                                        <?php } ?>
                                        <?php if (!empty($plugin_details['repository']['issues'])) { ?>
                                            <div><a href="<?php echo esc_url((string)$plugin_details['repository']['issues']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Issues', 'rrze-multisite-manager'); ?></a></div>
                                        <?php } ?>
                                        <?php if (!empty($plugin_details['repository']['clone'])) { ?>
                                            <div><code><?php echo esc_html((string)$plugin_details['repository']['clone']); ?></code></div>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Compatibility', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($plugin_details['compatibility']['wp_requires'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('WP from: %s', 'rrze-multisite-manager'), (string)$plugin_details['compatibility']['wp_requires'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($plugin_details['compatibility']['wp_tested_up_to'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('Tested up to: %s', 'rrze-multisite-manager'), (string)$plugin_details['compatibility']['wp_tested_up_to'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($plugin_details['compatibility']['php_requires'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('PHP from: %s', 'rrze-multisite-manager'), (string)$plugin_details['compatibility']['php_requires'])); ?></div>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Text domain', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($plugin_details['text_domain'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Translations', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($plugin_details['translation_languages']) && is_array($plugin_details['translation_languages'])) { ?>
                                            <?php foreach ($plugin_details['translation_languages'] as $translation_language) { ?>
                                                <div><code><?php echo esc_html((string)$translation_language); ?></code></div>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('No translation files found', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php if (!empty($plugin_details['supports'])) { ?>
                                    <tr>
                                        <th><?php echo esc_html__('Supports', 'rrze-multisite-manager'); ?></th>
                                        <td><?php echo esc_html(implode(', ', (array)$plugin_details['supports'])); ?></td>
                                    </tr>
                                <?php } ?>
                                <tr>
                                    <th><?php echo esc_html__('Metadata sources', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo !empty($plugin_details['metadata_sources']) ? esc_html(implode(', ', (array)$plugin_details['metadata_sources'])) : esc_html__('Plugin header only', 'rrze-multisite-manager'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <?php if (!empty($plugin_readme_html)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('README', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-readme-toggle" data-readme-id="plugin-readme">
                        <p class="rrze-msm-readme-toggle-collapsed">
                            <button type="button" class="button-link rrze-msm-readme-toggle-button" data-readme-id="plugin-readme" aria-expanded="false"><?php echo esc_html__('Show README markdown', 'rrze-multisite-manager'); ?></button>
                        </p>
                        <div class="rrze-msm-readme-toggle-content" hidden>
                            <p><button type="button" class="button-link rrze-msm-readme-toggle-button" data-readme-id="plugin-readme" aria-expanded="true"><?php echo esc_html__('Hide README markdown', 'rrze-multisite-manager'); ?></button></p>
                            <div class="rrze-msm-readme-markdown"><?php echo $plugin_readme_html; ?></div>
                        </div>
                    </div>
                </section>
            <?php } ?>

            <div class="rrze-msm-grid">
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Websites using the plugin', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html(sprintf(__('This plugin is currently used on %d websites.', 'rrze-multisite-manager'), (int)($plugin_details['site_count'] ?? 0))); ?></p>
                    </header>
                    <?php if (!empty($plugin_details['active_sites']) && is_array($plugin_details['active_sites'])) { ?>
                        <div class="rrze-msm-site-table-wrap" data-table-id="plugin-details-sites" data-default-per-page="20" data-current-page="1" data-sort-key="name" data-sort-direction="asc">
                            <div class="tablenav top">
                                <div class="alignleft actions">
                                    <label for="rrze-msm-plugin-details-sites-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                    <select class="rrze-msm-site-table-per-page" id="rrze-msm-plugin-details-sites-per-page">
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
                                        <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('Website', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                        <th><?php echo esc_html__('URL', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Action', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plugin_details['active_sites'] as $plugin_site) { ?>
                                        <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($plugin_site['name'] ?? ''))); ?>">
                                            <td><strong><?php echo esc_html((string)($plugin_site['name'] ?? '')); ?></strong></td>
                                            <td><a href="<?php echo esc_url((string)($plugin_site['url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($plugin_site['url'] ?? '')); ?></a></td>
                                            <td><a class="button button-small" href="<?php echo esc_url($this->getSiteDetailsUrl((int)($plugin_site['id'] ?? 0))); ?>"><?php echo esc_html__('Website Details', 'rrze-multisite-manager'); ?></a></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <div class="tablenav bottom">
                                <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                            </div>
                        </div>
                    <?php } else { ?>
                        <p><?php echo esc_html__('This plugin is currently not used actively on any website.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Shortcodes', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <?php if (!empty($plugin_details['shortcodes']) && is_array($plugin_details['shortcodes'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Shortcode', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugin_details['shortcodes'] as $plugin_shortcode) { ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string)$plugin_shortcode); ?></code></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('No statically detectable shortcodes were found.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Custom post types and taxonomies', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('The registrations visible in the plugin code via register_post_type() and register_taxonomy() are listed here. If slugs are built dynamically, this is indicated.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <div class="rrze-msm-plugin-analysis-grid">
                        <div>
                            <h3><?php echo esc_html__('Custom post types', 'rrze-multisite-manager'); ?></h3>
                            <?php if (!empty($plugin_details['custom_post_types']) && is_array($plugin_details['custom_post_types'])) { ?>
                                <div class="rrze-msm-analysis-table-wrap">
                                    <table class="widefat striped rrze-msm-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo esc_html__('Label', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($plugin_details['custom_post_types'] as $plugin_post_type) { ?>
                                                <tr>
                                                    <td><?php echo esc_html((string)($plugin_post_type['label'] ?? '')); ?></td>
                                                    <td>
                                                        <?php if (!empty($plugin_post_type['resolved'])) { ?>
                                                            <code><?php echo esc_html((string)($plugin_post_type['slug'] ?? '')); ?></code>
                                                        <?php } else { ?>
                                                            <span><?php echo esc_html((string)($plugin_post_type['slug'] ?? '')); ?></span>
                                                        <?php } ?>
                                                    </td>
                                                    <td><?php echo esc_html((string)($plugin_post_type['type'] ?? '')); ?></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <p><?php echo esc_html__('No statically detectable custom post types were found.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                        <div>
                            <h3><?php echo esc_html__('Taxonomies', 'rrze-multisite-manager'); ?></h3>
                            <?php if (!empty($plugin_details['taxonomies']) && is_array($plugin_details['taxonomies'])) { ?>
                                <div class="rrze-msm-analysis-table-wrap">
                                    <table class="widefat striped rrze-msm-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo esc_html__('Label', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Object type', 'rrze-multisite-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($plugin_details['taxonomies'] as $plugin_taxonomy) { ?>
                                                <tr>
                                                    <td><?php echo esc_html((string)($plugin_taxonomy['label'] ?? '')); ?></td>
                                                    <td>
                                                        <?php if (!empty($plugin_taxonomy['resolved'])) { ?>
                                                            <code><?php echo esc_html((string)($plugin_taxonomy['slug'] ?? '')); ?></code>
                                                        <?php } else { ?>
                                                            <span><?php echo esc_html((string)($plugin_taxonomy['slug'] ?? '')); ?></span>
                                                        <?php } ?>
                                                    </td>
                                                    <td><code><?php echo esc_html((string)($plugin_taxonomy['object_type'] ?? '')); ?></code></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <p><?php echo esc_html__('No statically detectable taxonomies were found.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                    </div>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Registered image sizes', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('The registrations directly visible in the plugin code via add_image_size() and set_post_thumbnail_size() are listed here.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($plugin_details['image_sizes']) && is_array($plugin_details['image_sizes'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Slug', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Width', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Height', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Crop', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugin_details['image_sizes'] as $plugin_image_size) { ?>
                                    <tr>
                                        <td><strong><?php echo esc_html((string)($plugin_image_size['label'] ?? '')); ?></strong></td>
                                        <td><code><?php echo esc_html((string)($plugin_image_size['slug'] ?? '')); ?></code></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($plugin_image_size['width'] ?? 0))); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($plugin_image_size['height'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string)($plugin_image_size['crop'] ?? '')); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('No directly statically detectable image sizes were found.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Blocks and block patterns', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-plugin-analysis-grid">
                        <div>
                            <h3><?php echo esc_html__('Blocks', 'rrze-multisite-manager'); ?></h3>
                            <?php if (!empty($plugin_details['blocks']) && is_array($plugin_details['blocks'])) { ?>
                                <div class="rrze-msm-analysis-table-wrap">
                                    <table class="widefat striped rrze-msm-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo esc_html__('Title', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Description', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Block', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Category', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Keywords', 'rrze-multisite-manager'); ?></th>
                                                <th><?php echo esc_html__('Source', 'rrze-multisite-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($plugin_details['blocks'] as $plugin_block) { ?>
                                                <tr>
                                                    <td>
                                                        <strong>
                                                            <?php if (!empty($plugin_block['icon'])) { ?>
                                                                <span class="dashicons dashicons-<?php echo esc_attr((string)$plugin_block['icon']); ?> rrze-msm-block-icon" aria-hidden="true"></span>
                                                            <?php } ?>
                                                            <?php echo esc_html((string)($plugin_block['title'] ?? '')); ?>
                                                        </strong>
                                                    </td>
                                                    <td><?php echo esc_html((string)($plugin_block['description'] ?? '')); ?></td>
                                                    <td><code><?php echo esc_html((string)($plugin_block['name'] ?? '')); ?></code></td>
                                                    <td><?php echo esc_html((string)($plugin_block['category'] ?? '')); ?></td>
                                                    <td><?php echo !empty($plugin_block['keywords']) && is_array($plugin_block['keywords']) ? esc_html(implode(', ', (array)$plugin_block['keywords'])) : ''; ?></td>
                                                    <td><?php echo esc_html((string)($plugin_block['source'] ?? '')); ?></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <p><?php echo esc_html__('No statically detectable blocks were found.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                        <div>
                            <h3><?php echo esc_html__('Block patterns', 'rrze-multisite-manager'); ?></h3>
                            <?php if (!empty($plugin_details['block_patterns']) && is_array($plugin_details['block_patterns'])) { ?>
                                <div class="rrze-msm-analysis-table-wrap">
                                    <table class="widefat striped rrze-msm-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo esc_html__('Pattern', 'rrze-multisite-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($plugin_details['block_patterns'] as $plugin_pattern) { ?>
                                                <tr>
                                                    <td><code><?php echo esc_html((string)$plugin_pattern); ?></code></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <p><?php echo esc_html__('No statically detectable block patterns were found.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                    </div>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Provided actions and filters', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('The hook calls found in the plugin code that can be used by other themes or plugins are listed here.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($plugin_details['provided_hooks']) && is_array($plugin_details['provided_hooks'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Hook', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugin_details['provided_hooks'] as $plugin_hook) { ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string)($plugin_hook['name'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string)($plugin_hook['type'] ?? '')); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('No statically detectable provided hooks were found.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>
            </div>
        <?php } elseif ($plugin_file !== '') { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <p><?php echo esc_html__('No details could be loaded for this plugin.', 'rrze-multisite-manager'); ?></p>
            </section>
        <?php } ?>
    </div>
    <div class="rrze-msm-modal" id="rrze-msm-plugin-deactivate-modal" hidden>
        <div class="rrze-msm-modal-backdrop rrze-msm-close-plugin-modal"></div>
        <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-plugin-deactivate-title">
            <h3 id="rrze-msm-plugin-deactivate-title"><?php echo esc_html__('Deactivate for all sites network-wide', 'rrze-multisite-manager'); ?></h3>
            <p class="rrze-msm-modal-text"><?php echo esc_html__('This plugin will be deactivated network-wide. This affects all websites in this network.', 'rrze-multisite-manager'); ?></p>
            <p class="rrze-msm-modal-target">
                <strong><?php echo esc_html__('Selected plugin:', 'rrze-multisite-manager'); ?></strong>
                <span id="rrze-msm-plugin-deactivate-target"></span>
            </p>
            <label class="rrze-msm-modal-checkbox">
                <input type="checkbox" id="rrze-msm-plugin-deactivate-confirm">
                <span><?php echo esc_html__('Yes, I am sure and want to deactivate this plugin network-wide for all sites.', 'rrze-multisite-manager'); ?></span>
            </label>
            <div class="rrze-msm-modal-actions">
                <button type="button" class="button button-secondary rrze-msm-close-plugin-modal"><?php echo esc_html__('Cancel', 'rrze-multisite-manager'); ?></button>
                <a href="#" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-plugin-deactivate-submit" aria-disabled="true"><?php echo esc_html__('Deactivate plugin network-wide', 'rrze-multisite-manager'); ?></a>
            </div>
        </div>
    </div>
</div>
