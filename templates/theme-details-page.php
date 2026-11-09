<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Theme Details', 'rrze-multisite-manager'); ?></h1>
            </div>
            <div class="rrze-msm-header-controls">
                <?php if (!empty($theme_details)) { ?>
                    <div class="rrze-msm-site-header-search">
                        <label class="screen-reader-text" for="rrze-msm-theme-search"><?php echo esc_html__('Search theme', 'rrze-multisite-manager'); ?></label>
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
                    <label class="screen-reader-text" for="rrze-msm-theme-search"><?php echo esc_html__('Search theme', 'rrze-multisite-manager'); ?></label>
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
                <?php echo $theme_widget->renderThemeCard((array)$theme_details, ['link_title' => false, 'show_sites' => false]); ?>
            </section>

            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Websites using the theme', 'rrze-multisite-manager'); ?></h2>
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d: number of websites using the theme. */
                        __('This theme is currently used on %d websites.', 'rrze-multisite-manager'),
                        (int)($theme_details['site_count'] ?? 0)
                    )); ?></p>
                </header>
                <?php if (empty($theme_details['usage_data_available'])) { ?>
                    <p><?php echo esc_html__('The central plugin and theme usage index has not run yet.', 'rrze-multisite-manager'); ?> <a href="<?php echo esc_url((string)($theme_details['usage_monitoring_url'] ?? '')); ?>"><?php echo esc_html__('Open monitoring and start or review the task.', 'rrze-multisite-manager'); ?></a></p>
                <?php } elseif (!empty($theme_details['active_sites']) && is_array($theme_details['active_sites'])) { ?>
                    <?php echo $site_overview_widget->renderSiteOverviewTable((array)$theme_details['active_sites'], ['table_id' => 'theme-details-sites', 'default_per_page' => 20, 'sort_key' => 'registered', 'sort_direction' => 'desc']); ?>
                <?php } else { ?>
                    <p><?php echo esc_html__('This theme is currently not used actively on any website.', 'rrze-multisite-manager'); ?></p>
                <?php } ?>
            </section>

            <?php if (!empty($theme_actions_html)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <div class="rrze-msm-site-details-actions"><?php echo $theme_actions_html; ?></div>
                </section>
            <?php } ?>

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
                                    <td><?php echo esc_html((string)($theme_details['version'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Author', 'rrze-multisite-manager'); ?></th>
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
                                    <th><?php echo esc_html__('Theme URL', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['theme_uri'])) { ?>
                                            <a href="<?php echo esc_url((string)$theme_details['theme_uri']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$theme_details['theme_uri']); ?></a>
                                        <?php } else { ?>
                                            <?php echo esc_html__('Not present', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Tags', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo !empty($theme_details['tags']) ? esc_html(implode(', ', (array)$theme_details['tags'])) : esc_html__('No information', 'rrze-multisite-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('License', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['license']['name'])) { ?>
                                            <?php if (!empty($theme_details['license']['url'])) { ?>
                                                <a href="<?php echo esc_url((string)$theme_details['license']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$theme_details['license']['name']); ?></a>
                                            <?php } else { ?>
                                                <?php echo esc_html((string)$theme_details['license']['name']); ?>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('No information', 'rrze-multisite-manager'); ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Date of latest version', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['last_release_date_label'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Installation date', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['installation_date_label'] ?? '')); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="rrze-msm-plugin-meta-table-wrap">
                        <h3><?php echo esc_html__('Technical data', 'rrze-multisite-manager'); ?></h3>
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
                                            <div><?php echo esc_html(sprintf(
                                                /* translators: %s: repository type. */
                                                __('Type: %s', 'rrze-multisite-manager'), (string)$theme_details['repository']['type'])); ?></div>
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
                                    <th><?php echo esc_html__('Compatibility', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['compatibility']['wp_requires'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('WP from: %s', 'rrze-multisite-manager'), (string)$theme_details['compatibility']['wp_requires'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['compatibility']['wp_tested_up_to'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('Tested up to: %s', 'rrze-multisite-manager'), (string)$theme_details['compatibility']['wp_tested_up_to'])); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($theme_details['compatibility']['php_requires'])) { ?>
                                            <div><?php echo esc_html(sprintf(__('PHP from: %s', 'rrze-multisite-manager'), (string)$theme_details['compatibility']['php_requires'])); ?></div>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Text domain', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo esc_html((string)($theme_details['text_domain'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Translations', 'rrze-multisite-manager'); ?></th>
                                    <td>
                                        <?php if (!empty($theme_details['translation_languages']) && is_array($theme_details['translation_languages'])) { ?>
                                            <?php foreach ($theme_details['translation_languages'] as $translation_language) { ?>
                                                <div><code><?php echo esc_html((string)$translation_language); ?></code></div>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo esc_html__('No translation files found', 'rrze-multisite-manager'); ?>
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
                                    <th><?php echo esc_html__('Metadata sources', 'rrze-multisite-manager'); ?></th>
                                    <td><?php echo !empty($theme_details['metadata_sources']) ? esc_html(implode(', ', (array)$theme_details['metadata_sources'])) : esc_html__('Theme header only', 'rrze-multisite-manager'); ?></td>
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
                            <button type="button" class="button-link rrze-msm-readme-toggle-button" data-readme-id="theme-readme" aria-expanded="false"><?php echo esc_html__('Show README markdown', 'rrze-multisite-manager'); ?></button>
                        </p>
                        <div class="rrze-msm-readme-toggle-content" hidden>
                            <p><button type="button" class="button-link rrze-msm-readme-toggle-button" data-readme-id="theme-readme" aria-expanded="true"><?php echo esc_html__('Hide README markdown', 'rrze-multisite-manager'); ?></button></p>
                            <div class="rrze-msm-readme-markdown"><?php echo $theme_readme_html; ?></div>
                        </div>
                    </div>
                </section>
            <?php } ?>

        <?php } ?>
    </div>
</div>
