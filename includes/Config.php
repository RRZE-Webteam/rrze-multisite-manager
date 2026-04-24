<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Config {
    private array $config = [];

    public function __construct() {
        $this->config = [
            'option_name' => 'rrze-multisite-manager',
            'constants' => [
                'plugin_name' => __('RRZE Multisite Manager', 'rrze-multisite-manager'),
                'textdomain' => 'rrze-multisite-manager',
                'version' => '0.1.0',
            ],
            'menu_settings' => [
                'page_title' => __('RRZE Multisite Manager', 'rrze-multisite-manager'),
                'menu_title' => __('Multisite Manager', 'rrze-multisite-manager'),
                'capability' => 'manage_network_options',
                'menu_slug' => 'rrze-multisite-manager',
            ],
            'settings_sections' => [
                [
                    'id' => 'general',
                    'title' => __('General', 'rrze-multisite-manager'),
                    'description' => __('Grundkonfiguration fuer den RRZE Multisite Manager.', 'rrze-multisite-manager'),
                ],
            ],
            'settings_fields' => [
                'general' => [
                    [
                        'name' => 'enabled',
                        'label' => __('Plugin aktivieren', 'rrze-multisite-manager'),
                        'desc' => __('Aktiviert die Grundfunktionalitaet des Plugins.', 'rrze-multisite-manager'),
                        'type' => 'checkbox',
                        'default' => true,
                    ],
                ],
            ],
        ];
    }

    public function getOptionName(): string {
        return $this->config['option_name'];
    }

    public function getConstants(): array {
        return $this->config['constants'];
    }

    public function getMenuSettings(): array {
        return $this->config['menu_settings'];
    }

    public function getSections(): array {
        return $this->config['settings_sections'];
    }

    public function getFields(): array {
        return $this->config['settings_fields'];
    }
}
