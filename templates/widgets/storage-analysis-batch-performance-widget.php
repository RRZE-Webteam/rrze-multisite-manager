<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes every dynamic cell value.

defined('ABSPATH') || exit;
?>
<section class="rrze-msm-widget <?php echo esc_attr($widget_classes); ?>" data-widget-id="<?php echo esc_attr($widget_id); ?>">
    <div class="rrze-msm-widget-controls">
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-up" data-direction="up" aria-label="<?php echo esc_attr__('Move widget up', 'rrze-multisite-manager'); ?>">&#9650;</button>
        <button type="button" class="rrze-msm-widget-move rrze-msm-widget-move-down" data-direction="down" aria-label="<?php echo esc_attr__('Move widget down', 'rrze-multisite-manager'); ?>">&#9660;</button>
    </div>
    <header class="rrze-msm-widget-header">
        <h2><?php echo esc_html($widget_title); ?></h2>
        <p><?php echo esc_html($widget_description); ?></p>
    </header>
    <div class="rrze-msm-table-wrap">
        <table class="widefat striped rrze-msm-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Title', 'rrze-multisite-manager'); ?></th>
                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Websites', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Latest runtimes', 'rrze-multisite-manager'); ?></th>
                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Average runtime per website', 'rrze-multisite-manager'); ?></th>
                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Fastest runtime', 'rrze-multisite-manager'); ?></th>
                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Slowest runtime', 'rrze-multisite-manager'); ?></th>
                    <th><?php echo esc_html__('Last execution', 'rrze-multisite-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <th scope="row"><?php echo esc_html((string)$row['title']); ?></th>
                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)$row['site_count']); ?></td>
                        <td><?php echo esc_html((string)$row['latest_durations']); ?></td>
                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)$row['average_duration']); ?></td>
                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)$row['fastest_duration']); ?></td>
                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)$row['slowest_duration']); ?></td>
                        <td><?php echo esc_html((string)$row['last_execution']); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</section>
