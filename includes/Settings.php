<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Settings {
    protected Plugin $plugin;
    protected string $optionName = '';
    public array $options = [];
    public string $optionsPage = '';
    protected array $settingsMenu = [];
    protected array $settingsSections = [];
    protected array $settingsFields = [];
    protected array $allTabs = [];
    protected string $defaultTab = '';
    protected string $currentTab = '';
    protected string $settingsPrefix = '';
    protected Config $config;
    protected array $sectionDescriptions = [];

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        $this->config = new Config();
        $this->settingsPrefix = $this->plugin->getSlug() . '-';
    }

    public function onLoaded(): void {
        $this->setMenu();
        $this->setSections();
        $this->setFields();

        $this->optionName = $this->config->getOptionName();
        $this->options = $this->getOptions();

        if (is_admin()) {
            add_action('network_admin_menu', [$this, 'adminMenu']);
            add_action('network_admin_edit_' . $this->optionName, [$this, 'saveNetworkOptions']);
        }
    }

    protected function setMenu(): void {
        $this->settingsMenu = $this->config->getMenuSettings();
    }

    protected function setSections(): void {
        $this->settingsSections = $this->config->getSections();
    }

    protected function setFields(): void {
        $this->settingsFields = $this->config->getFields();
    }

    protected function defaultOptions(): array {
        $options = [];
        $sectionName = '';
        $fields = [];
        $option = [];
        $name = '';
        $default = '';

        foreach ($this->settingsFields as $sectionName => $fields) {
            foreach ($fields as $option) {
                $name = (string)$option['name'];
                $default = $option['default'] ?? '';
                $options[$sectionName . '_' . $name] = $default;
            }
        }

        return $options;
    }

    public function getOptions(): array {
        $defaults = $this->defaultOptions();
        $options = get_site_option($this->optionName, []);

        if (!is_array($options)) {
            $options = [];
        }

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return $this->normalizeOptions($options);
    }

    public function getOption(string $section, string $name, mixed $default = ''): mixed {
        $option = $section . '_' . $name;

        if (array_key_exists($option, $this->options)) {
            return $this->options[$option];
        }

        return $default;
    }

    public function sanitizeOptions(mixed $options): array {
        if (!is_array($options)) {
            return $this->options;
        }

        $merged = array_merge($this->defaultOptions(), $options);
        return $this->normalizeOptions($merged);
    }

    public function adminMenu(): void {
        $pageTitle = (string)($this->settingsMenu['page_title'] ?? __('RRZE Multisite Manager', 'rrze-multisite-manager'));
        $menuTitle = (string)($this->settingsMenu['menu_title'] ?? __('Multisite Manager', 'rrze-multisite-manager'));
        $capability = (string)($this->settingsMenu['capability'] ?? 'manage_network_options');
        $menuSlug = (string)($this->settingsMenu['menu_slug'] ?? 'rrze-multisite-manager');

        $this->optionsPage = add_submenu_page(
            'settings.php',
            $pageTitle,
            $menuTitle,
            $capability,
            $menuSlug,
            [$this, 'renderOptionsPage']
        );
    }

    public function saveNetworkOptions(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        check_admin_referer($this->optionName . '_save');

        $rawOptions = $_POST[$this->optionName] ?? [];
        $options = $this->sanitizeOptions($rawOptions);

        update_site_option($this->optionName, $options);

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settingsMenu['menu_slug'] ?? 'rrze-multisite-manager',
                'updated' => 'true',
            ],
            network_admin_url('settings.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function renderOptionsPage(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'rrze-multisite-manager'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('RRZE Multisite Manager', 'rrze-multisite-manager') . '</h1>';
        echo '<form method="post" action="' . esc_url(network_admin_url('edit.php?action=' . $this->optionName)) . '">';
        wp_nonce_field($this->optionName . '_save');
        $this->renderFields();
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    protected function renderFields(): void {
        $section = [];
        $sectionId = '';
        $sectionTitle = '';
        $field = [];
        $fieldName = '';
        $fieldId = '';
        $value = null;

        foreach ($this->settingsSections as $section) {
            $sectionId = (string)$section['id'];
            $sectionTitle = (string)$section['title'];
            echo '<h2>' . esc_html($sectionTitle) . '</h2>';

            if (!empty($section['description'])) {
                echo '<p>' . esc_html((string)$section['description']) . '</p>';
            }

            if (empty($this->settingsFields[$sectionId]) || !is_array($this->settingsFields[$sectionId])) {
                continue;
            }

            echo '<table class="form-table" role="presentation"><tbody>';

            foreach ($this->settingsFields[$sectionId] as $field) {
                $fieldName = $sectionId . '_' . (string)$field['name'];
                $fieldId = $this->settingsPrefix . $fieldName;
                $value = $this->options[$fieldName] ?? ($field['default'] ?? '');

                echo '<tr>';
                echo '<th scope="row"><label for="' . esc_attr($fieldId) . '">' . esc_html((string)$field['label']) . '</label></th>';
                echo '<td>';
                $this->renderFieldInput($fieldId, $fieldName, $field, $value);

                if (!empty($field['desc'])) {
                    echo '<p class="description">' . esc_html((string)$field['desc']) . '</p>';
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    protected function renderFieldInput(string $fieldId, string $fieldName, array $field, mixed $value): void {
        $type = (string)($field['type'] ?? 'text');
        $inputName = $this->optionName . '[' . $fieldName . ']';

        if ($type === 'checkbox') {
            echo '<label for="' . esc_attr($fieldId) . '">';
            echo '<input type="hidden" name="' . esc_attr($inputName) . '" value="0">';
            echo '<input type="checkbox" id="' . esc_attr($fieldId) . '" name="' . esc_attr($inputName) . '" value="1" ' . checked((bool)$value, true, false) . '>';
            echo ' ' . esc_html__('Aktiv', 'rrze-multisite-manager');
            echo '</label>';
            return;
        }

        echo '<input class="regular-text" type="text" id="' . esc_attr($fieldId) . '" name="' . esc_attr($inputName) . '" value="' . esc_attr((string)$value) . '">';
    }

    protected function normalizeOptions(array $options): array {
        $key = '';

        foreach ($options as $key => $value) {
            if ($this->isCheckboxOption($key)) {
                $options[$key] = $this->sanitizeBoolean($value);
            }
        }

        return $options;
    }

    protected function isCheckboxOption(string $key): bool {
        $sectionFields = [];
        $field = [];
        $compoundName = '';

        foreach ($this->settingsFields as $sectionName => $sectionFields) {
            foreach ($sectionFields as $field) {
                $compoundName = $sectionName . '_' . (string)$field['name'];

                if ($compoundName === $key) {
                    return (($field['type'] ?? '') === 'checkbox');
                }
            }
        }

        return false;
    }

    protected function sanitizeBoolean(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }
}
