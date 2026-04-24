<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html($status_action_label); ?></h1>
                <p><?php echo esc_html($status_action_description); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <a href="<?php echo esc_url($redirect_url); ?>" class="button button-secondary">
                    <?php echo esc_html__('Zur Website-Übersicht', 'rrze-multisite-manager'); ?>
                </a>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-overview-page-section">
            <header class="rrze-msm-widget-header">
                <h2><?php echo esc_html($site_name); ?></h2>
                <p>
                    <a href="<?php echo esc_url($site_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($site_url); ?>
                    </a>
                </p>
            </header>

            <form method="post" action="<?php echo esc_url($form_action); ?>">
                <?php wp_nonce_field('rrze_multisite_manager_site_status_' . $status_action . '_' . $site_id); ?>
                <input type="hidden" name="action" value="rrze_multisite_manager_site_status">
                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                <input type="hidden" name="status_action" value="<?php echo esc_attr($status_action); ?>">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="rrze-msm-status-note"><?php echo esc_html__('Notiz für Superadmins', 'rrze-multisite-manager'); ?></label>
                            </th>
                            <td>
                                <textarea id="rrze-msm-status-note" name="status_note" rows="6" class="large-text"><?php echo esc_textarea((string)$current_note); ?></textarea>
                                <p class="description"><?php echo esc_html__('Optionale interne Notiz zum Statuswechsel. Diese Notiz wird beim Wiederherstellen wieder geleert.', 'rrze-multisite-manager'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button($status_action_label); ?>
            </form>
        </section>
    </div>
</div>
