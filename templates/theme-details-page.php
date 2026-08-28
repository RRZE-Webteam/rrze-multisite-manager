<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Theme Details', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Detailed view of a single theme with metadata, usage, and technical code analysis.', 'rrze-multisite-manager'); ?></p>
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
                <?php echo $theme_widget->renderThemeCard((array)$theme_details, ['link_title' => false]); ?>
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
                                            <div><?php echo esc_html(sprintf(__('Type: %s', 'rrze-multisite-manager'), (string)$theme_details['repository']['type'])); ?></div>
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
                        <p><?php echo esc_html__('No statically detectable shortcodes were found.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Registered image sizes', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('The registrations directly visible in the theme code via add_image_size() and set_post_thumbnail_size() are listed here.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($theme_details['image_sizes']) && is_array($theme_details['image_sizes'])) { ?>
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
                            <?php if (!empty($theme_details['blocks']) && is_array($theme_details['blocks'])) { ?>
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
                                <p><?php echo esc_html__('No statically detectable blocks were found.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                        <div>
                            <h3><?php echo esc_html__('Block patterns', 'rrze-multisite-manager'); ?></h3>
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
                                <p><?php echo esc_html__('No statically detectable block patterns were found.', 'rrze-multisite-manager'); ?></p>
                            <?php } ?>
                        </div>
                    </div>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Provided actions and filters', 'rrze-multisite-manager'); ?></h2>
                    </header>
                    <?php if (!empty($theme_details['provided_hooks']) && is_array($theme_details['provided_hooks'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Hook', 'rrze-multisite-manager'); ?></th>
                                    <th><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></th>
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
                        <p><?php echo esc_html__('No statically detectable actions or filters were found.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>
            </div>
        <?php } ?>
    </div>
</div>
