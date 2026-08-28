<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Website Overview', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Extended overview of sites, users, content, and storage usage across the entire network.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (!empty($status_updated)) { ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('The website status has been updated.', 'rrze-multisite-manager'); ?></p>
            </div>
        <?php } ?>

        <?php if (!empty($metrics_refreshed)) { ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('The metrics have been rebuilt.', 'rrze-multisite-manager'); ?></p>
            </div>
        <?php } ?>

        <?php if (!empty($metrics_notice_html)) { echo $metrics_notice_html; } ?>

        <?php if (!empty($metrics_has_data)) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-overview-page-section">
                <nav class="rrze-msm-overview-tabs" aria-label="<?php echo esc_attr__('Status filter for websites', 'rrze-multisite-manager'); ?>">
                    <?php foreach ($overview_tabs as $tab) { ?>
                        <a class="rrze-msm-overview-tab <?php echo esc_attr((string)$tab['class']); ?><?php echo $current_tab === (string)$tab['slug'] ? ' is-active' : ''; ?>" href="<?php echo esc_url((string)$tab['url']); ?>">
                            <span><?php echo esc_html((string)$tab['label']); ?></span>
                            <strong>(<?php echo esc_html(number_format_i18n((int)$tab['count'])); ?>)</strong>
                        </a>
                    <?php } ?>
                </nav>
                <?php echo $site_overview_table; ?>
                <?php if (!empty($inactive_status_labels) && is_array($inactive_status_labels)) { ?>
                    <p class="description">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: list of site status labels without matching websites. */
                                __('No websites are in status %s. Therefore, the filters for these statuses are not active.', 'rrze-multisite-manager'),
                                '"' . implode('", "', array_map('strval', $inactive_status_labels)) . '"'
                            )
                        );
                        ?>
                    </p>
                <?php } ?>
            </section>
        <?php } ?>
    </div>
    <div class="rrze-msm-modal" id="rrze-msm-site-delete-modal" hidden>
        <div class="rrze-msm-modal-backdrop rrze-msm-close-site-delete-modal"></div>
        <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-site-delete-title">
            <h3 id="rrze-msm-site-delete-title"><?php echo esc_html__('Permanently delete site', 'rrze-multisite-manager'); ?></h3>
            <p class="rrze-msm-modal-text"><?php echo esc_html__('This action permanently deletes the site using the normal WordPress network function. You should not do this in the browser for large websites. Keep the browser open until the process is complete.', 'rrze-multisite-manager'); ?></p>
            <p class="rrze-msm-modal-target">
                <strong><?php echo esc_html__('Selected site:', 'rrze-multisite-manager'); ?></strong>
                <span id="rrze-msm-site-delete-target"></span>
            </p>
            <label class="rrze-msm-modal-checkbox">
                <input type="checkbox" id="rrze-msm-site-delete-confirm">
                <span><?php echo esc_html__('Yes, I am sure. This site should be permanently deleted and I will keep the browser open for it.', 'rrze-multisite-manager'); ?></span>
            </label>
            <div class="rrze-msm-modal-actions">
                <button type="button" class="button button-secondary rrze-msm-close-site-delete-modal"><?php echo esc_html__('Cancel', 'rrze-multisite-manager'); ?></button>
                <a href="#" class="button button-secondary rrze-msm-button-danger" id="rrze-msm-site-delete-submit" aria-disabled="true"><?php echo esc_html__('Proceed to permanent deletion', 'rrze-multisite-manager'); ?></a>
            </div>
        </div>
    </div>
</div>
