<div class="wrap rrze-multisite-manager-admin rrze-msm-mode-light">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Ansichten verwalten', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Hier steuerst du, welche Widgets in den verschiedenen Dashboard-Ansichten sichtbar sind, und legst neue Ansichten an.', 'rrze-multisite-manager'); ?></p>
            </div>
        </div>

        <?php if ($updated) { ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Die Ansichten wurden gespeichert.', 'rrze-multisite-manager'); ?></p></div>
        <?php } ?>

        <form method="post" action="<?php echo esc_url($form_action); ?>" class="rrze-msm-views-form">
            <?php wp_nonce_field('rrze_multisite_manager_save_views'); ?>

            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Neue Ansicht anlegen', 'rrze-multisite-manager'); ?></h2>
                    <p><?php echo esc_html__('Neue Ansichten starten mit allen Widgets. Danach kannst du die Auswahl direkt darunter einschranken.', 'rrze-multisite-manager'); ?></p>
                </header>
                <input type="text" class="regular-text" name="new_view_name" value="" placeholder="<?php echo esc_attr__('Name der neuen Ansicht', 'rrze-multisite-manager'); ?>">
            </section>

            <?php foreach ($views as $view) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-view-editor">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html((string)$view['label']); ?></h2>
                        <p><code><?php echo esc_html((string)$view['slug']); ?></code></p>
                    </header>

                    <?php if (!empty($view['system'])) { ?>
                        <input type="hidden" name="views[<?php echo esc_attr((string)$view['slug']); ?>][label]" value="<?php echo esc_attr((string)$view['label']); ?>">
                        <?php if ((string)$view['slug'] === 'all_widgets') { ?>
                            <p class="description"><?php echo esc_html__('System-Ansicht: enthält immer alle verfügbaren Widgets und ist nicht bearbeitbar.', 'rrze-multisite-manager'); ?></p>
                        <?php } else { ?>
                            <p class="description"><?php echo esc_html__('System-Ansicht: Name fest, Widget-Zuordnung anpassbar.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } else { ?>
                        <p>
                            <label>
                                <span class="screen-reader-text"><?php echo esc_html__('Name', 'rrze-multisite-manager'); ?></span>
                                <input type="text" class="regular-text" name="views[<?php echo esc_attr((string)$view['slug']); ?>][label]" value="<?php echo esc_attr((string)$view['label']); ?>">
                            </label>
                            <label class="rrze-msm-delete-toggle">
                                <input type="checkbox" name="views[<?php echo esc_attr((string)$view['slug']); ?>][delete]" value="1">
                                <?php echo esc_html__('Ansicht löschen', 'rrze-multisite-manager'); ?>
                            </label>
                        </p>
                    <?php } ?>

                    <div class="rrze-msm-widget-selector">
                        <?php foreach ($widget_options as $widgetOption) { ?>
                            <label class="rrze-msm-widget-check">
                                <input
                                    type="checkbox"
                                    name="views[<?php echo esc_attr((string)$view['slug']); ?>][widgets][]"
                                    value="<?php echo esc_attr((string)$widgetOption['id']); ?>"
                                    <?php checked(in_array((string)$widgetOption['id'], $view['widgets'], true)); ?>
                                    <?php disabled((string)$view['slug'] === 'all_widgets'); ?>
                                >
                                <span><?php echo esc_html((string)$widgetOption['label']); ?></span>
                            </label>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>

            <?php submit_button(__('Ansichten speichern', 'rrze-multisite-manager')); ?>
        </form>
    </div>
</div>
