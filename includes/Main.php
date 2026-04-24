<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Main {
    protected Plugin $plugin;
    protected Settings $settings;
    protected Config $config;

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        $this->config = new Config();
    }

    public function onLoaded(): void {
        $settings = new Settings($this->plugin);
        $settings->onLoaded();
        $this->settings = $settings;
    }
}
