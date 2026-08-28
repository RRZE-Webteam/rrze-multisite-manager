<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Plugin Overview', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Central overview of installed, network-active, active, and inactive plugins in the network.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (!empty($metrics_refreshed)) { ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('The metrics have been rebuilt.', 'rrze-multisite-manager'); ?></p>
            </div>
        <?php } ?>

        <?php if (!empty($metrics_notice_html)) { echo $metrics_notice_html; } ?>

        <?php if (!empty($metrics_has_data)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-overview-page-section">
                <nav class="rrze-msm-overview-tabs" aria-label="<?php echo esc_attr__('Filter for plugins', 'rrze-multisite-manager'); ?>">
                    <?php foreach ($plugin_overview_tabs as $tab) { ?>
                        <a class="rrze-msm-overview-tab <?php echo esc_attr((string)($tab['class'] ?? '')); ?><?php echo $current_tab === (string)$tab['slug'] ? ' is-active' : ''; ?>" href="<?php echo esc_url((string)$tab['url']); ?>">
                            <span><?php echo esc_html((string)$tab['label']); ?></span>
                            <strong>(<?php echo esc_html(number_format_i18n((int)$tab['count'])); ?>)</strong>
                        </a>
                    <?php } ?>
                </nav>
                <?php echo $plugin_overview_table; ?>
            </section>

            <?php if (!empty($missing_plugin_table)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-overview-page-section">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Plugins no longer present in active database entries', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('These plugin files are still marked as active on individual websites but no longer exist in the plugin directory.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php echo $missing_plugin_table; ?>
                </section>
            <?php } ?>
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
