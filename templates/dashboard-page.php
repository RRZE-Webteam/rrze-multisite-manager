<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('RRZE Multisite Manager', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Local network metrics and operational overview for this WordPress multisite.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <form class="rrze-msm-view-form" method="get" action="<?php echo esc_url($dashboard_url); ?>">
                    <input type="hidden" name="page" value="rrze-multisite-manager-dashboard">
                    <label for="rrze-msm-view-select" class="screen-reader-text"><?php echo esc_html__('Select view', 'rrze-multisite-manager'); ?></label>
                    <select id="rrze-msm-view-select" name="view">
                        <?php foreach ($views as $view) { ?>
                            <option value="<?php echo esc_attr((string)$view['slug']); ?>" <?php selected($current_view_slug, (string)$view['slug']); ?>>
                                <?php echo esc_html((string)$view['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </form>
                <?php if (!empty($views_url)) { ?>
                    <a class="button button-secondary" href="<?php echo esc_url($views_url); ?>"><?php echo esc_html__('Manage views', 'rrze-multisite-manager'); ?></a>
                <?php } ?>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <div class="rrze-msm-view-caption">
            <strong><?php echo esc_html__('Active view:', 'rrze-multisite-manager'); ?></strong>
            <span><?php echo esc_html($current_view_label); ?></span>
        </div>

        <?php if (!empty($metrics_refreshed)) { ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('The metrics have been rebuilt.', 'rrze-multisite-manager'); ?></p>
            </div>
        <?php } ?>

        <?php if (!empty($metrics_notice_html)) { echo $metrics_notice_html; } ?>

        <?php if (!empty($metrics_has_data)) { ?>
            <div class="rrze-msm-grid rrze-msm-grid-primary" data-current-view="<?php echo esc_attr($current_view_slug); ?>">
                <?php foreach ($widget_markup as $markup) { echo $markup; } ?>
            </div>
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
