<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Umgebung', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Kuratierte Betriebs- und Umgebungsdaten des gesamten Multisite-Netzwerks.', 'rrze-multisite-manager'); ?></p>
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
                    <h2><?php echo esc_html__('Auffälligkeiten', 'rrze-multisite-manager'); ?></h2>
                </header>
                <div class="notice notice-warning inline">
                    <?php foreach ($environment_overview['warnings'] as $warning) { ?>
                        <p><?php echo esc_html((string)$warning); ?></p>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>

        <div class="rrze-msm-grid">
            <?php if (!empty($environment_overview['sections']) && is_array($environment_overview['sections'])) { ?>
                <?php foreach ($environment_overview['sections'] as $section) { ?>
                    <section class="rrze-msm-widget rrze-msm-widget-span-12">
                        <header class="rrze-msm-widget-header">
                            <h2><?php echo esc_html((string)($section['title'] ?? '')); ?></h2>
                        </header>
                        <div class="rrze-msm-environment-table-wrap">
                            <table class="widefat striped rrze-msm-table">
                                <tbody>
                                    <?php foreach ((array)($section['rows'] ?? []) as $row) { ?>
                                        <tr>
                                            <th><?php echo esc_html((string)($row['label'] ?? '')); ?></th>
                                            <td<?php echo !empty($row['numeric']) ? ' class="rrze-msm-col-numeric"' : ''; ?>>
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
