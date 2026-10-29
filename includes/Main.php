<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Metrics\StorageAnalysisService;

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
        $analysisLifecycle = new Analysis\AnalysisLifecycle();
        try {
            $analysisLifecycle->register();
        } catch (\Throwable $error) {
            add_action('admin_notices', [$analysisLifecycle, 'unavailableNotice']);
            add_action('network_admin_notices', [$analysisLifecycle, 'unavailableNotice']);
            do_action('rrze.log.error', 'RRZE-MSM: Analysis storage initialization failed', ['message' => $error->getMessage()]);
            return;
        }
        $settings = new Settings($this->plugin);
        $settings->onLoaded();
        $this->settings = $settings;

        $metrics = new MetricsService($settings, $this->config);
        $metrics->onLoaded();
        $this->metrics = $metrics;

        $storageAnalysisScheduler = new StorageAnalysisSchedulerService(new StorageAnalysisService($metrics), $this->config);
        $storageAnalysisScheduler->onLoaded();
        $this->storageAnalysisScheduler = $storageAnalysisScheduler;

        $shortcodeBlockAnalysisScheduler = new ShortcodeBlockAnalysisSchedulerService($this->config);
        $shortcodeBlockAnalysisScheduler->onLoaded();
        $this->shortcodeBlockAnalysisScheduler = $shortcodeBlockAnalysisScheduler;

        $dashboard = new Dashboard($this->plugin, $settings, $metrics, $this->config);
        $dashboard->onLoaded();
        $this->dashboard = $dashboard;

        $monitoring = new MonitoringService($this->plugin, $this->config);
        $monitoring->onLoaded();
        $this->monitoring = $monitoring;
    }
}
