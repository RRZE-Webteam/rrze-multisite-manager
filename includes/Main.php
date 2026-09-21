<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Main {
    protected Plugin $plugin;
    protected Settings $settings;
    protected Config $config;
    protected MetricsService $metrics;
    protected Dashboard $dashboard;
    protected MonitoringService $monitoring;
    protected StorageAnalysisSchedulerService $storageAnalysisScheduler;
    protected ShortcodeBlockAnalysisSchedulerService $shortcodeBlockAnalysisScheduler;

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        $this->config = new Config();
    }

    public function onLoaded(): void {
        $settings = new Settings($this->plugin);
        $settings->onLoaded();
        $this->settings = $settings;

        $metrics = new MetricsService($settings, $this->config);
        $metrics->onLoaded();
        $this->metrics = $metrics;

        $storageAnalysisScheduler = new StorageAnalysisSchedulerService($metrics, $this->config);
        $storageAnalysisScheduler->onLoaded();
        $this->storageAnalysisScheduler = $storageAnalysisScheduler;

        $shortcodeBlockAnalysisScheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $shortcodeBlockAnalysisScheduler->onLoaded();
        $this->shortcodeBlockAnalysisScheduler = $shortcodeBlockAnalysisScheduler;

        $dashboard = new Dashboard($this->plugin, $settings);
        $dashboard->onLoaded();
        $this->dashboard = $dashboard;

        $monitoring = new MonitoringService($this->plugin, $this->config);
        $monitoring->onLoaded();
        $this->monitoring = $monitoring;
    }
}
