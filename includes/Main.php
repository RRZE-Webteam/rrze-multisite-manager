<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Main {
    protected Plugin $plugin;
    protected Settings $settings;
    protected Config $config;
    protected Dashboard $dashboard;

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        $this->config = new Config();
    }

    public function onLoaded(): void {
        $settings = new Settings($this->plugin);
        $settings->onLoaded();
        $this->settings = $settings;

        $dashboard = new Dashboard($this->plugin, $settings);
        $dashboard->onLoaded();
        $this->dashboard = $dashboard;
    }
}
