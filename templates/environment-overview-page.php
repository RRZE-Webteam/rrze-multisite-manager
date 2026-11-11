<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Environment', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Curated operational and environment data for the entire multisite network.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (!empty($environment_overview['warnings']) && is_array($environment_overview['warnings'])) { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Issues', 'rrze-multisite-manager'); ?></h2>
                </header>
                <div class="notice notice-warning inline">
                    <?php foreach ($environment_overview['warnings'] as $warning) { ?>
                        <p><?php echo esc_html((string)$warning); ?></p>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>

        <section id="rrze-msm-response-sampling" class="rrze-msm-widget rrze-msm-widget-span-12">
            <header class="rrze-msm-widget-header">
                <h2><?php echo esc_html__('Measurement during operation', 'rrze-multisite-manager'); ?></h2>
                <p><?php echo esc_html__('Records the server-side response times of opening the editor, saving, autosaving and media uploads for posts and pages, each with the information whether a background analysis was running at the same time, the PHP-FPM pool state and the number of running database threads. Sampling ends automatically after the selected period. The download contains all values of this page as JSON.', 'rrze-multisite-manager'); ?></p>
            </header>
            <?php if (($response_sampling_notice ?? '') === 'start') { ?>
                <div class="notice notice-success inline"><p><?php echo esc_html__('Sampling has been started.', 'rrze-multisite-manager'); ?></p></div>
            <?php } elseif (($response_sampling_notice ?? '') === 'stop') { ?>
                <div class="notice notice-success inline"><p><?php echo esc_html__('Sampling has been stopped.', 'rrze-multisite-manager'); ?></p></div>
            <?php } elseif (($response_sampling_notice ?? '') === 'reset') { ?>
                <div class="notice notice-success inline"><p><?php echo esc_html__('The recorded response times have been deleted.', 'rrze-multisite-manager'); ?></p></div>
            <?php } ?>
            <div class="rrze-msm-site-theme-links">
                <form method="post" action="<?php echo esc_url($response_sampling_action); ?>">
                    <input type="hidden" name="action" value="rrze_multisite_manager_response_sampling">
                    <?php wp_nonce_field('rrze_multisite_manager_response_sampling'); ?>
                    <?php if (!empty($response_sampling['active'])) { ?>
                        <input type="hidden" name="sampling_command" value="stop">
                        <button type="submit" class="button button-secondary"><?php echo esc_html__('Stop sampling', 'rrze-multisite-manager'); ?></button>
                    <?php } else { ?>
                        <input type="hidden" name="sampling_command" value="start">
                        <label for="rrze-msm-sampling-days"><?php echo esc_html__('Duration', 'rrze-multisite-manager'); ?></label>
                        <select id="rrze-msm-sampling-days" name="sampling_days">
                            <?php foreach ((array)$response_sampling_durations as $samplingDays) { ?>
                                <option value="<?php echo esc_attr((string)$samplingDays); ?>"><?php echo esc_html(sprintf(
                                    /* translators: %d: number of days. */
                                    _n('%d day', '%d days', (int)$samplingDays, 'rrze-multisite-manager'),
                                    (int)$samplingDays
                                )); ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Start sampling', 'rrze-multisite-manager'); ?></button>
                    <?php } ?>
                </form>
                <form method="post" action="<?php echo esc_url($response_sampling_action); ?>">
                    <input type="hidden" name="action" value="rrze_multisite_manager_response_sampling">
                    <input type="hidden" name="sampling_command" value="reset">
                    <?php wp_nonce_field('rrze_multisite_manager_response_sampling'); ?>
                    <button type="submit" class="button button-secondary"><?php echo esc_html__('Delete recorded response times', 'rrze-multisite-manager'); ?></button>
                </form>
                <a class="button button-secondary" href="<?php echo esc_url($environment_export_url); ?>"><?php echo esc_html__('Download all values (JSON)', 'rrze-multisite-manager'); ?></a>
            </div>
            <div id="rrze-msm-database-latency">
                <h3><?php echo esc_html__('Database round trip', 'rrze-multisite-manager'); ?></h3>
                <p class="description"><?php echo esc_html(sprintf(
                    /* translators: %d: number of queries. */
                    __('Sends %d times "SELECT 1" through the WordPress database connection from the web node that answers this request and records the time of each query. The statement reads no table, so the result is essentially the round trip between web node and database server. Within one data centre a round trip usually stays well below 1 ms; repeat the measurement a few times, because the load balancer may send each click to another web node. The result appears in the section "Database round trip (SELECT 1)".', 'rrze-multisite-manager'),
                    (int)$database_latency_queries
                )); ?></p>
                <?php if (($database_latency_notice ?? '') === 'run') { ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html__('The database round trip has been measured.', 'rrze-multisite-manager'); ?></p></div>
                <?php } elseif (($database_latency_notice ?? '') === 'reset') { ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html__('The round-trip measurements have been deleted.', 'rrze-multisite-manager'); ?></p></div>
                <?php } ?>
                <div class="rrze-msm-site-theme-links">
                    <form method="post" action="<?php echo esc_url($response_sampling_action); ?>">
                        <input type="hidden" name="action" value="rrze_multisite_manager_database_latency">
                        <input type="hidden" name="latency_command" value="run">
                        <?php wp_nonce_field('rrze_multisite_manager_database_latency'); ?>
                        <button type="submit" class="button button-primary"><?php echo esc_html(sprintf(
                            /* translators: %d: number of queries. */
                            __('Measure database round trip (%d × SELECT 1)', 'rrze-multisite-manager'),
                            (int)$database_latency_queries
                        )); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url($response_sampling_action); ?>">
                        <input type="hidden" name="action" value="rrze_multisite_manager_database_latency">
                        <input type="hidden" name="latency_command" value="reset">
                        <?php wp_nonce_field('rrze_multisite_manager_database_latency'); ?>
                        <button type="submit" class="button button-secondary"><?php echo esc_html__('Delete round-trip measurements', 'rrze-multisite-manager'); ?></button>
                    </form>
                </div>
            </div>
        </section>

        <div class="rrze-msm-grid">
            <?php if (!empty($environment_overview['sections']) && is_array($environment_overview['sections'])) { ?>
                <?php foreach ($environment_overview['sections'] as $section) { ?>
                    <section class="rrze-msm-widget rrze-msm-widget-span-12">
                        <header class="rrze-msm-widget-header">
                            <h2><?php echo esc_html((string)($section['title'] ?? '')); ?></h2>
                            <?php if (!empty($section['site_health_links']) && is_array($section['site_health_links'])) { ?>
                                <p class="rrze-msm-environment-site-health">
                                    <?php echo esc_html__('More in Site Health of the main site:', 'rrze-multisite-manager'); ?>
                                    <?php foreach (array_values($section['site_health_links']) as $linkIndex => $siteHealthLink) { ?>
                                        <?php echo $linkIndex > 0 ? ' &middot; ' : ' '; ?><a href="<?php echo esc_url((string)($siteHealthLink['url'] ?? '')); ?>"><?php echo esc_html((string)($siteHealthLink['label'] ?? '')); ?></a>
                                    <?php } ?>
                                </p>
                            <?php } ?>
                        </header>
                        <div class="rrze-msm-environment-table-wrap">
                            <table class="striped rrze-msm-datatable">
                                <tbody>
                                    <?php foreach ((array)($section['rows'] ?? []) as $row) { ?>
                                        <tr>
                                            <th><?php echo esc_html((string)($row['label'] ?? '')); ?></th>
                                            <td>
                                                <?php if (!empty($row['code'])) { ?>
                                                    <code><?php echo esc_html((string)($row['value'] ?? '')); ?></code>
                                                <?php } else { ?>
                                                    <?php echo esc_html((string)($row['value'] ?? '')); ?>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php } ?>
            <?php } ?>
        </div>
    </div>
</div>
