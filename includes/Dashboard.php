<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

use RRZE\MultisiteManager\Widgets\ArchivedSitesWidget;
use RRZE\MultisiteManager\Widgets\BlockedSitesWidget;
use RRZE\MultisiteManager\Widgets\DeletedSitesWidget;
use RRZE\MultisiteManager\Widgets\EditorUsageWidget;
use RRZE\MultisiteManager\Widgets\InactiveSitesWidget;
use RRZE\MultisiteManager\Widgets\InactivePluginsWidget;
use RRZE\MultisiteManager\Widgets\InactiveThemesWidget;
use RRZE\MultisiteManager\Widgets\NetworkStorageUsageWidget;
use RRZE\MultisiteManager\Widgets\NewMonitoringAlertsWidget;
use RRZE\MultisiteManager\Widgets\OperationalStatusWidget;
use RRZE\MultisiteManager\Widgets\PluginUsageWidget;
use RRZE\MultisiteManager\Widgets\ProblemSitesWidget;
use RRZE\MultisiteManager\Widgets\RecentSitesWidget;
use RRZE\MultisiteManager\Widgets\RecentlyUpdatedSitesWidget;
use RRZE\MultisiteManager\Widgets\SiteOverviewWidget;
use RRZE\MultisiteManager\Widgets\StatusWidget;
use RRZE\MultisiteManager\Widgets\SummaryWidget;
use RRZE\MultisiteManager\Widgets\ThemeOverviewWidget;
use RRZE\MultisiteManager\Widgets\ThemeUsageWidget;

class Dashboard {
    protected Plugin $plugin;
    protected Settings $settings;
    protected Config $config;
    protected MetricsService $metrics;
    protected Template $template;
    protected ViewManager $viewManager;
    protected array $pageHooks = [];
    protected const META_ARCHIVED_AT = 'rrze_msm_archived_at';
    protected const META_SPAM_AT = 'rrze_msm_spam_at';
    protected const META_STATUS_NOTE = 'rrze_msm_status_note';
    protected const META_STATUS_USER_ID = 'rrze_msm_status_user_id';
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_OPERATIONAL_STATUS_SOURCE = 'rrze_msm_operational_status_source';
    protected const META_PREVIOUS_OPERATIONAL_STATUS = 'rrze_msm_previous_operational_status';
    protected const META_OPERATIONAL_STATUS_CHANGED_AT = 'rrze_msm_operational_status_changed_at';
    protected const META_DNS_STATUS = 'rrze_msm_dns_status';
    protected const META_HTTP_STATUS = 'rrze_msm_http_status';
    protected const META_LAST_AVAILABILITY_CHECK = 'rrze_msm_last_availability_check';
    protected const META_LAST_DNS_OK_AT = 'rrze_msm_last_dns_ok_at';
    protected const META_LAST_HTTP_OK_AT = 'rrze_msm_last_http_ok_at';
    protected const META_MONITORING_NOTE = 'rrze_msm_monitoring_note';

    public function __construct(Plugin $plugin, Settings $settings) {
        $this->plugin = $plugin;
        $this->settings = $settings;
        $this->config = new Config();
        $this->metrics = new MetricsService($settings, $this->config);
        $this->template = new Template($this->config, $this->plugin->getPath('templates'));
        $this->viewManager = new ViewManager();
    }

    public function onLoaded(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'registerMenu'], 999);
        add_action('network_admin_menu', [$this, 'registerNetworkMenu'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_bar_menu', [$this, 'addAdminBarMenu'], 35);
        add_action('admin_head', [$this, 'printAdminBarStyles']);
        add_filter('user_has_cap', [$this, 'filterUserHasCap'], 20, 4);
        add_filter('admin_body_class', [$this, 'filterAdminBodyClass']);
        add_action('wp_ajax_rrze_msm_save_widget_order', [$this, 'ajaxSaveWidgetOrder']);
        add_action('wp_ajax_rrze_msm_search_sites', [$this, 'ajaxSearchSites']);
        add_action('wp_ajax_rrze_msm_search_plugins', [$this, 'ajaxSearchPlugins']);
        add_action('wp_ajax_rrze_msm_search_themes', [$this, 'ajaxSearchThemes']);
        add_action('admin_post_rrze_multisite_manager_save_views', [$this, 'saveViews']);
        add_action('admin_post_rrze_multisite_manager_site_status', [$this, 'handleSiteStatusAction']);
        add_action('admin_post_rrze_multisite_manager_update_site_monitoring_status', [$this, 'handleSiteMonitoringStatusUpdate']);
        add_action('admin_post_rrze_multisite_manager_delete_site_option', [$this, 'handleSiteOptionDelete']);
        add_action('admin_post_rrze_multisite_manager_delete_site_option_group', [$this, 'handleSiteOptionGroupDelete']);
        add_action('admin_post_rrze_multisite_manager_delete_orphan_file', [$this, 'handleOrphanFileDelete']);
        add_action('admin_post_rrze_multisite_manager_delete_post_type_entries', [$this, 'handlePostTypeDelete']);
        add_action('network_admin_edit_rrze_multisite_manager_save_views', [$this, 'saveViews']);
        add_action('network_admin_edit_rrze_multisite_manager_site_status', [$this, 'handleSiteStatusAction']);
        add_action('network_admin_edit_rrze_multisite_manager_update_site_monitoring_status', [$this, 'handleSiteMonitoringStatusUpdate']);
        add_action('network_admin_edit_rrze_multisite_manager_delete_site_option', [$this, 'handleSiteOptionDelete']);
        add_action('network_admin_edit_rrze_multisite_manager_delete_site_option_group', [$this, 'handleSiteOptionGroupDelete']);
        add_action('network_admin_edit_rrze_multisite_manager_delete_post_type_entries', [$this, 'handlePostTypeDelete']);
    }

    public function registerMenu(): void {
        $menuSettings = $this->config->getMenuSettings();
        $pageTitle = (string)($menuSettings['page_title'] ?? __('RRZE Multisite Manager', 'rrze-multisite-manager'));
        $menuTitle = (string)($menuSettings['menu_title'] ?? __('Multisite Manager', 'rrze-multisite-manager'));
        $capability = (string)($menuSettings['capability'] ?? 'manage_network_options');
        $parentSlug = (string)($menuSettings['parent_slug'] ?? 'rrze-multisite-manager-dashboard');
        $dashboardSlug = (string)($menuSettings['dashboard_slug'] ?? 'rrze-multisite-manager-dashboard');
        $environmentOverviewSlug = (string)($menuSettings['environment_overview_slug'] ?? 'rrze-multisite-manager-environment-overview');
        $siteOverviewSlug = (string)($menuSettings['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview');
        $pluginOverviewSlug = (string)($menuSettings['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview');
        $pluginDetailsSlug = (string)($menuSettings['plugin_details_slug'] ?? 'rrze-multisite-manager-plugin-details');
        $themeOverviewSlug = (string)($menuSettings['theme_overview_slug'] ?? 'rrze-multisite-manager-theme-overview');
        $themeDetailsSlug = (string)($menuSettings['theme_details_slug'] ?? 'rrze-multisite-manager-theme-details');
        $siteDetailsSlug = (string)($menuSettings['site_details_slug'] ?? 'rrze-multisite-manager-site-details');
        $siteStorageAnalysisSlug = (string)($menuSettings['site_storage_analysis_slug'] ?? 'rrze-multisite-manager-site-storage-analysis');
        $siteStatusSlug = (string)($menuSettings['site_status_slug'] ?? 'rrze-multisite-manager-site-status');
        $viewsSlug = (string)($menuSettings['views_slug'] ?? 'rrze-multisite-manager-views');
        $settingsSlug = $this->settings->getSettingsSlug();

        if (is_network_admin() || !$this->currentUserCanAccessManager()) {
            return;
        }

        $this->pageHooks[] = add_menu_page(
            $pageTitle,
            $menuTitle,
            $capability,
            $parentSlug,
            [$this, 'renderDashboardPage'],
            'dashicons-superhero',
            99999
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Dashboard', 'rrze-multisite-manager'),
            __('Dashboard', 'rrze-multisite-manager'),
            $capability,
            $dashboardSlug,
            [$this, 'renderDashboardPage']
        );

        if ($this->currentUserCanUseNetworkAdminFeatures()) {
            $this->pageHooks[] = add_submenu_page(
                $parentSlug,
                __('Umgebung', 'rrze-multisite-manager'),
                __('Umgebung', 'rrze-multisite-manager'),
                $capability,
                $environmentOverviewSlug,
                [$this, 'renderEnvironmentOverviewPage']
            );
        }

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Website-Übersicht', 'rrze-multisite-manager'),
            __('Website-Übersicht', 'rrze-multisite-manager'),
            $capability,
            $siteOverviewSlug,
            [$this, 'renderSiteOverviewPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Website-Details', 'rrze-multisite-manager'),
            __('Website-Details', 'rrze-multisite-manager'),
            $capability,
            $siteDetailsSlug,
            [$this, 'renderSiteDetailsPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Speicheranalyse', 'rrze-multisite-manager'),
            __('Speicheranalyse', 'rrze-multisite-manager'),
            $capability,
            $siteStorageAnalysisSlug,
            [$this, 'renderSiteStorageAnalysisPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Plugin-Übersicht', 'rrze-multisite-manager'),
            __('Plugin-Übersicht', 'rrze-multisite-manager'),
            $capability,
            $pluginOverviewSlug,
            [$this, 'renderPluginOverviewPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Plugin-Details', 'rrze-multisite-manager'),
            __('Plugin-Details', 'rrze-multisite-manager'),
            $capability,
            $pluginDetailsSlug,
            [$this, 'renderPluginDetailsPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Theme-Übersicht', 'rrze-multisite-manager'),
            __('Theme-Übersicht', 'rrze-multisite-manager'),
            $capability,
            $themeOverviewSlug,
            [$this, 'renderThemeOverviewPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Theme-Details', 'rrze-multisite-manager'),
            __('Theme-Details', 'rrze-multisite-manager'),
            $capability,
            $themeDetailsSlug,
            [$this, 'renderThemeDetailsPage']
        );

        $this->pageHooks[] = add_submenu_page(
            null,
            __('Site-Status ändern', 'rrze-multisite-manager'),
            __('Site-Status ändern', 'rrze-multisite-manager'),
            $capability,
            $siteStatusSlug,
            [$this, 'renderSiteStatusPage']
        );

        if ($this->currentUserCanUseNetworkAdminFeatures()) {
            $settingsPage = add_submenu_page(
                $parentSlug,
                __('Settings', 'rrze-multisite-manager'),
                __('Settings', 'rrze-multisite-manager'),
                $capability,
                $settingsSlug,
                [$this->settings, 'renderOptionsPage']
            );

            $this->pageHooks[] = $settingsPage;
            $this->settings->setOptionsPage((string)$settingsPage);
        }
    }

    public function registerNetworkMenu(): void {
        $menuSettings = $this->config->getMenuSettings();
        $pageTitle = (string)($menuSettings['page_title'] ?? __('RRZE Multisite Manager', 'rrze-multisite-manager'));
        $menuTitle = (string)($menuSettings['menu_title'] ?? __('Multisite Manager', 'rrze-multisite-manager'));
        $networkRedirectSlug = 'rrze-multisite-manager-network-redirect';

        if (!is_network_admin() || !$this->currentUserCanUseNetworkAdminFeatures()) {
            return;
        }

        $this->pageHooks[] = add_menu_page(
            $pageTitle,
            $menuTitle,
            'manage_network_options',
            $networkRedirectSlug,
            [$this, 'renderNetworkRedirectPage'],
            'dashicons-superhero',
            99999
        );
    }

    public function addAdminBarMenu(\WP_Admin_Bar $adminBar): void {
        $menuSettings = $this->config->getMenuSettings();
        $dashboardSlug = (string)($menuSettings['dashboard_slug'] ?? 'rrze-multisite-manager-dashboard');
        $menuTitle = (string)($menuSettings['menu_title'] ?? __('Multisite Manager', 'rrze-multisite-manager'));

        if (!is_multisite() || !is_admin_bar_showing() || !$this->currentUserCanAccessManager()) {
            return;
        }

        $adminBar->add_node([
            'id' => 'rrze-multisite-manager',
            'title' => '<span class="ab-icon dashicons dashicons-superhero" aria-hidden="true"></span><span class="ab-label">' . esc_html($menuTitle) . '</span>',
            'href' => add_query_arg(
                [
                    'page' => $dashboardSlug,
                ],
                $this->getAdminPageBaseUrl()
            ),
            'meta' => [
                'title' => $menuTitle,
                'class' => 'rrze-msm-admin-bar-link',
            ],
        ]);
    }

    public function enqueueAssets(string $hookSuffix): void {
        if (!in_array($hookSuffix, $this->pageHooks, true)) {
            return;
        }

        wp_enqueue_style(
            'rrze-multisite-manager-admin',
            $this->plugin->getUrl('assets/css/rrze-multisite-manager.css'),
            [],
            $this->plugin->getVersion()
        );

        wp_enqueue_script(
            'rrze-multisite-manager-admin',
            $this->plugin->getUrl('assets/js/rrze-multisite-manager.js'),
            ['jquery', 'jquery-ui-sortable'],
            $this->plugin->getVersion(),
            true
        );

        wp_localize_script(
            'rrze-multisite-manager-admin',
            'rrzeMultisiteManagerAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'orderNonce' => wp_create_nonce('rrze-msm-save-widget-order'),
                'siteSearchNonce' => wp_create_nonce('rrze-msm-search-sites'),
                'pluginSearchNonce' => wp_create_nonce('rrze-msm-search-plugins'),
                'currentView' => $this->getCurrentViewSlug(),
                'currentMode' => $this->getColorMode(),
                'lightModeLabel' => __('Light Mode', 'rrze-multisite-manager'),
                'darkModeLabel' => __('Dark Mode', 'rrze-multisite-manager'),
                'siteDetailsBaseUrl' => $this->getSiteDetailsUrl(),
                'siteSearchBaseUrl' => $this->getCurrentSiteSearchBaseUrl(),
                'pluginDetailsBaseUrl' => $this->getPluginDetailsUrl(),
                'themeDetailsBaseUrl' => $this->getThemeDetailsUrl(),
                'siteSearchMinLength' => 3,
                'siteSearchNoResults' => __('Keine Websites gefunden.', 'rrze-multisite-manager'),
                'pluginSearchMinLength' => 3,
                'pluginSearchNoResults' => __('Keine Plugins gefunden.', 'rrze-multisite-manager'),
                'themeSearchNonce' => wp_create_nonce('rrze-msm-search-themes'),
                'themeSearchMinLength' => 3,
                'themeSearchNoResults' => __('Keine Themes gefunden.', 'rrze-multisite-manager'),
            ]
        );
    }

    public function filterAdminBodyClass(string $classes): string {
        return trim($classes . ' rrze-msm-admin rrze-msm-mode-' . $this->getColorMode());
    }

    public function printAdminBarStyles(): void {
        if (!$this->currentUserCanAccessManager()) {
            return;
        }

        echo '<style id="rrze-msm-admin-bar-link">';
        echo '#wpadminbar #wp-admin-bar-rrze-multisite-manager > .ab-item { display: inline-flex; align-items: center; }';
        echo '#wpadminbar #wp-admin-bar-rrze-multisite-manager > .ab-item .ab-icon.dashicons { font: normal 20px/1 dashicons; width: 20px; height: 20px; margin-top: 0; display: inline-flex; align-items: center; justify-content: center; }';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard > a.menu-top { background: #b32d2e; color: #fff; }';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect > a.menu-top { background: #b32d2e; color: #fff; }';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect > a.menu-top .wp-menu-image:before { color: #fff; }';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard:hover > a.menu-top,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard.wp-has-current-submenu > a.menu-top,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard.current > a.menu-top,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect:hover > a.menu-top,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect.wp-has-current-submenu > a.menu-top,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect.current > a.menu-top { background: #8a2424; color: #fff; }';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard:hover > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard:hover > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard.wp-has-current-submenu > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard.wp-has-current-submenu > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard.current > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-dashboard.current > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect:hover > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect:hover > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect.wp-has-current-submenu > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect.wp-has-current-submenu > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect.current > a.menu-top .wp-menu-name,';
        echo '#adminmenu #toplevel_page_rrze-multisite-manager-network-redirect.current > a.menu-top .wp-menu-image:before { color: #fff; }';
        echo '</style>';
    }

    public function filterUserHasCap(array $allcaps, array $caps, array $args, \WP_User $user): array {
        unset($caps, $args);

        if ((int)$user->ID <= 0) {
            return $allcaps;
        }

        if (is_super_admin((int)$user->ID)
            || !empty($allcaps['rrze_websupport_read_multisite_manager'])
            || !empty($allcaps['rrze_multisite_manager_read'])
            || !empty($allcaps['rrze_websupport_site_admin'])
            || !empty($allcaps['websupport'])) {
            $allcaps['rrze_multisite_manager_access'] = true;
        }

        return $allcaps;
    }

    public function renderDashboardPage(): void {
        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $metricsStatus = $this->metrics->getDashboardDataStatus();
        $widgets = $this->getWidgetInstances();
        $views = $this->viewManager->getViews(array_keys($widgets));
        $currentView = $this->viewManager->getCurrentView($views, 'default');
        $selectedWidgets = $this->sortWidgetsForUser(
            $this->filterWidgetsForView($widgets, $currentView['widgets']),
            (string)$currentView['slug']
        );
        $widgetMarkup = [];
        $widget = null;

        if (!empty($metricsStatus['has_data'])) {
            foreach ($selectedWidgets as $widget) {
                $widgetMarkup[] = $widget->render($this->template, $dashboardData);
            }
        }

        echo $this->template->render(
            'dashboard-page',
            [
                'views' => $views,
                'current_view_slug' => (string)$currentView['slug'],
                'current_view_label' => (string)$currentView['label'],
                'dashboard_url' => $this->getDashboardUrl(),
                'views_url' => $this->currentUserCanUseNetworkAdminFeatures() ? $this->getSettingsUrl('views') : '',
                'widget_markup' => $widgetMarkup,
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'metrics_notice_html' => $this->renderMetricsStatusNoticeHtml($metricsStatus, $this->getDashboardUrl()),
                'metrics_has_data' => !empty($metricsStatus['has_data']),
                'metrics_refreshed' => !empty($_GET['metrics-refreshed']),
            ],
            $this
        );
    }

    public function renderNetworkRedirectPage(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $targetUrl = $this->getMainSiteDashboardUrl();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Weiterleitung zum Multisite Manager', 'rrze-multisite-manager') . '</h1>';
        echo '<p>' . esc_html__('Du wirst zur Hauptinstanz des Multisite Managers weitergeleitet.', 'rrze-multisite-manager') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($targetUrl) . '">' . esc_html__('Jetzt zum Multisite Manager wechseln', 'rrze-multisite-manager') . '</a></p>';
        echo '<meta http-equiv="refresh" content="0;url=' . esc_url($targetUrl) . '">';
        echo '<script>window.location.replace(' . wp_json_encode($targetUrl) . ');</script>';
        echo '</div>';
        exit;
    }

    public function renderViewsPage(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $widgets = $this->getWidgetInstances();
        $views = $this->viewManager->getViews(array_keys($widgets));
        $widgetOptions = $this->getWidgetOptions($widgets);

        echo $this->template->render(
            'views-page',
            [
                'views' => $views,
                'widget_options' => $widgetOptions,
                'form_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_save_views'),
                'updated' => !empty($_GET['updated']),
            ],
            $this
        );
    }

    public function renderSiteOverviewPage(): void {
        $dashboardData = [];
        $widget = null;
        $currentTab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'all';
        $summary = [];
        $tabs = [];
        $tabTables = [];
        $metricsStatus = [];
        $returnUrl = '';

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $metricsStatus = $this->metrics->getDashboardDataStatus();
        $widget = new SiteOverviewWidget($this->plugin, $this->config);
        $summary = is_array($dashboardData['summary'] ?? null) ? $dashboardData['summary'] : [];

        if (!in_array($currentTab, ['all', 'active', 'archived', 'blocked', 'deleted', 'provisioning', 'dns-missing', 'unreachable'], true)) {
            $currentTab = 'all';
        }

        $returnUrl = add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                'tab' => $currentTab,
            ],
            $this->getAdminPageBaseUrl()
        );

        $tabs = [
            [
                'slug' => 'all',
                'label' => __('Alle Websites', 'rrze-multisite-manager'),
                'count' => (int)($summary['total_sites'] ?? 0),
                'class' => 'rrze-msm-tab-all',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'all',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'active',
                'label' => __('Aktive Websites', 'rrze-multisite-manager'),
                'count' => (int)($summary['active_sites'] ?? 0),
                'class' => 'rrze-msm-tab-positive',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'active',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'archived',
                'label' => __('Archivierte Websites', 'rrze-multisite-manager'),
                'count' => (int)($summary['archived_sites'] ?? 0),
                'class' => 'rrze-msm-tab-warning',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'archived',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'blocked',
                'label' => __('Gesperrte Websites', 'rrze-multisite-manager'),
                'count' => (int)($summary['spam_sites'] ?? 0),
                'class' => 'rrze-msm-tab-gold',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'blocked',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'deleted',
                'label' => __('Zu löschende Sites', 'rrze-multisite-manager'),
                'count' => (int)($summary['deleted_sites'] ?? 0),
                'class' => 'rrze-msm-tab-danger',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'deleted',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'provisioning',
                'label' => __('Einrichtung läuft', 'rrze-multisite-manager'),
                'count' => count((array)($dashboardData['provisioning_sites'] ?? [])),
                'class' => 'rrze-msm-tab-info',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'provisioning',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'dns-missing',
                'label' => __('DNS fehlt', 'rrze-multisite-manager'),
                'count' => count((array)($dashboardData['dns_missing_sites'] ?? [])),
                'class' => 'rrze-msm-tab-danger',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'dns-missing',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'unreachable',
                'label' => __('Technisch nicht erreichbar', 'rrze-multisite-manager'),
                'count' => count((array)($dashboardData['unreachable_sites'] ?? [])),
                'class' => 'rrze-msm-tab-gold',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                        'tab' => 'unreachable',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
        ];

        $tabTables = [
            'all' => $widget->renderSiteOverviewTable(
                $dashboardData['site_overview'] ?? [],
                [
                    'table_id' => 'site-overview-page-all',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                    'action_mode' => 'text',
                ]
            ),
            'active' => $widget->renderSiteOverviewTable(
                array_values(
                    array_filter(
                        $dashboardData['site_overview'] ?? [],
                        static function (array $site): bool {
                            return empty($site['is_archived']) && empty($site['is_spam']) && empty($site['is_deleted']);
                        }
                    )
                ),
                [
                    'table_id' => 'site-overview-page-active',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                    'action_mode' => 'text',
                ]
            ),
            'archived' => $widget->renderSiteOverviewTable(
                $dashboardData['archived_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-archived',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                    'action_mode' => 'text',
                ]
            ),
            'blocked' => $widget->renderSiteOverviewTable(
                $dashboardData['blocked_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-blocked',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                    'action_mode' => 'text',
                ]
            ),
            'deleted' => $widget->renderSiteOverviewTable(
                $dashboardData['deleted_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-deleted',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                    'action_mode' => 'text',
                ]
            ),
            'provisioning' => $widget->renderOperationalStatusSiteTable(
                $dashboardData['provisioning_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-provisioning',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'name',
                    'sort_direction' => 'asc',
                    'action_mode' => 'text',
                ]
            ),
            'dns-missing' => $widget->renderOperationalStatusSiteTable(
                $dashboardData['dns_missing_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-dns-missing',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'name',
                    'sort_direction' => 'asc',
                    'action_mode' => 'text',
                ]
            ),
            'unreachable' => $widget->renderOperationalStatusSiteTable(
                $dashboardData['unreachable_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-unreachable',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'name',
                    'sort_direction' => 'asc',
                    'action_mode' => 'text',
                ]
            ),
        ];

        echo $this->template->render(
            'site-overview-page',
            [
                'site_overview_table' => !empty($metricsStatus['has_data']) ? ($tabTables[$currentTab] ?? $tabTables['all']) : '',
                'overview_tabs' => $tabs,
                'current_tab' => $currentTab,
                'status_updated' => !empty($_GET['status-updated']),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'metrics_notice_html' => $this->renderMetricsStatusNoticeHtml($metricsStatus, $returnUrl),
                'metrics_has_data' => !empty($metricsStatus['has_data']),
                'metrics_refreshed' => !empty($_GET['metrics-refreshed']),
            ],
            $this
        );
    }

    public function renderEnvironmentOverviewPage(): void {
        $environmentOverview = [];

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $environmentOverview = $this->metrics->getEnvironmentOverview();

        echo $this->template->render(
            'environment-overview-page',
            [
                'environment_overview' => $environmentOverview,
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
            ],
            $this
        );
    }

    public function renderSiteDetailsPage(): void {
        $siteId = isset($_GET['site_id']) ? absint($_GET['site_id']) : 0;
        $currentOptionsTab = isset($_GET['options_tab']) ? sanitize_key((string)$_GET['options_tab']) : '';
        $currentContentTab = isset($_GET['content_tab']) ? sanitize_key((string)$_GET['content_tab']) : 'overview';
        $currentProcessTab = isset($_GET['process_tab']) ? sanitize_key((string)$_GET['process_tab']) : 'stats';
        $siteDetails = $siteId > 0 ? $this->metrics->getSiteDetails(
            $siteId,
            [
                'content' => true,
                'options_summary' => true,
                'options_values_group' => $currentOptionsTab,
                'process_stats' => true,
                'transients' => $currentProcessTab === 'transients',
                'cron_events' => $currentProcessTab === 'scheduler',
            ]
        ) : [];
        $siteWidget = null;
        $statusSections = [];
        $optionsOverview = is_array($siteDetails['options_overview'] ?? null) ? $siteDetails['options_overview'] : ['groups' => []];
        $optionsGroups = is_array($optionsOverview['groups'] ?? null) ? $optionsOverview['groups'] : [];
        $selectedOptionsGroup = is_array($optionsOverview['selected_group'] ?? null) ? $optionsOverview['selected_group'] : [];
        $validOptionTabs = [];
        $optionsGroup = [];
        $optionNotices = [];
        $contentNotices = [];
        $hasCustomPages = false;
        $customPostType = [];
        $customPages = [];
        $hasBlockTemplateTypes = !empty($siteDetails['block_template_types']) && is_array($siteDetails['block_template_types']);

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $siteWidget = new SiteOverviewWidget($this->plugin, $this->config);

        foreach ($optionsGroups as $optionsGroup) {
            if (!is_array($optionsGroup) || empty($optionsGroup['slug'])) {
                continue;
            }

            $validOptionTabs[] = (string)$optionsGroup['slug'];
        }

        if (!in_array($currentOptionsTab, $validOptionTabs, true)) {
            $currentOptionsTab = '';
        }

        if ($currentOptionsTab !== '') {
            foreach ($optionsGroups as $index => $optionsGroup) {
                if ((string)($optionsGroup['slug'] ?? '') !== $currentOptionsTab) {
                    continue;
                }

                $optionsGroups[$index]['options'] = (array)($selectedOptionsGroup['options'] ?? []);
                break;
            }
        }

        foreach ((array)($siteDetails['custom_post_types'] ?? []) as $customPostType) {
            if ((string)($customPostType['group'] ?? '') === 'page') {
                $hasCustomPages = true;
                $customPages[] = $customPostType;
            }
        }

        if (!in_array($currentContentTab, ['overview', 'custom-post-types', 'custom-pages', 'block-templates'], true)) {
            $currentContentTab = 'overview';
        }

        if ($currentContentTab === 'custom-pages' && !$hasCustomPages) {
            $currentContentTab = 'overview';
        }

        if ($currentContentTab === 'block-templates' && !$hasBlockTemplateTypes) {
            $currentContentTab = 'overview';
        }

        if (!in_array($currentProcessTab, ['stats', 'transients', 'scheduler'], true)) {
            $currentProcessTab = 'stats';
        }

        if (!empty($siteDetails['is_archived'])) {
            $statusSections[] = [
                'title' => __('Archiviert', 'rrze-multisite-manager'),
                'date_label' => __('Archiviert seit', 'rrze-multisite-manager'),
                'date_value' => $this->formatStatusDate((string)($siteDetails['archived_at'] ?? '')),
                'user_label' => __('Archiviert von', 'rrze-multisite-manager'),
                'user_value' => $this->getStatusUserLabel($siteDetails),
                'note' => (string)($siteDetails['status_note'] ?? ''),
            ];
        }

        if (!empty($siteDetails['is_spam'])) {
            $statusSections[] = [
                'title' => __('Gesperrt', 'rrze-multisite-manager'),
                'date_label' => __('Gesperrt seit', 'rrze-multisite-manager'),
                'date_value' => $this->formatStatusDate((string)($siteDetails['spam_at'] ?? '')),
                'user_label' => __('Gesperrt von', 'rrze-multisite-manager'),
                'user_value' => $this->getStatusUserLabel($siteDetails),
                'note' => (string)($siteDetails['status_note'] ?? ''),
            ];
        }

        if (!empty($_GET['option_deleted'])) {
            $optionNotices[] = sprintf(
                __('Option "%s" wurde gelöscht.', 'rrze-multisite-manager'),
                sanitize_text_field((string)$_GET['option_deleted'])
            );
        }

        if (!empty($_GET['option_group_deleted'])) {
            $optionNotices[] = sprintf(
                __('%1$d Optionen aus der Gruppe "%2$s" wurden gelöscht.', 'rrze-multisite-manager'),
                absint($_GET['option_group_deleted_count'] ?? 0),
                sanitize_text_field((string)$_GET['option_group_deleted'])
            );
        }

        if (!empty($_GET['deleted_post_type'])) {
            $contentNotices[] = sprintf(
                __('%1$d Einträge des Custom Post Types "%2$s" wurden endgültig gelöscht.', 'rrze-multisite-manager'),
                absint($_GET['deleted_post_type_count'] ?? 0),
                sanitize_text_field((string)$_GET['deleted_post_type'])
            );
        }

        echo $this->template->render(
            'site-details-page',
            [
                'site_id' => $siteId,
                'site_details' => $siteDetails,
                'site_actions' => !empty($siteDetails) ? $siteWidget->renderActionsForSite($siteDetails, 'text') : '',
                'site_plugins_url' => $siteId > 0 ? get_admin_url($siteId, 'plugins.php') : '',
                'site_themes_url' => $siteId > 0 ? get_admin_url($siteId, 'themes.php') : '',
                'site_storage_analysis_url' => $siteId > 0 ? $this->getSiteStorageAnalysisUrl($siteId) : '',
                'site_customizer_url' => $siteId > 0 ? get_admin_url($siteId, 'customize.php') : '',
                'site_menus_url' => $siteId > 0 ? get_admin_url($siteId, 'nav-menus.php') : '',
                'site_editor_url' => $siteId > 0 && !empty($siteDetails['theme']['is_block_theme']) ? get_admin_url($siteId, 'site-editor.php') : '',
                'site_search_placeholder' => __('Website nach Titel oder URL suchen', 'rrze-multisite-manager'),
                'site_details_base_url' => $this->getSiteDetailsUrl(),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'status_badges_html' => !empty($siteDetails['status']) && is_array($siteDetails['status'])
                    ? $this->renderStatusBadgesHtml($siteDetails['status'])
                    : '',
                'status_user_label' => $this->getStatusUserLabel($siteDetails),
                'archived_at_label' => $this->formatStatusDate((string)($siteDetails['archived_at'] ?? '')),
                'blocked_at_label' => $this->formatStatusDate((string)($siteDetails['spam_at'] ?? '')),
                'status_sections' => $statusSections,
                'site_monitoring_rows' => [
                    [
                        'label' => __('Betriebsstatus', 'rrze-multisite-manager'),
                        'value' => (string)($siteDetails['operational_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager')),
                    ],
                    [
                        'label' => __('Quelle des Betriebsstatus', 'rrze-multisite-manager'),
                        'value' => !empty($siteDetails['operational_status_source']) && (string)$siteDetails['operational_status_source'] === 'manual'
                            ? __('Manuell gesetzt', 'rrze-multisite-manager')
                            : __('Automatisch / nicht gesetzt', 'rrze-multisite-manager'),
                    ],
                    [
                        'label' => __('Vorheriger Betriebsstatus', 'rrze-multisite-manager'),
                        'value' => trim((string)($siteDetails['previous_operational_status_label'] ?? '')) !== ''
                            ? (string)$siteDetails['previous_operational_status_label']
                            : __('Nicht gesetzt', 'rrze-multisite-manager'),
                    ],
                    [
                        'label' => __('Betriebsstatus geändert am', 'rrze-multisite-manager'),
                        'value' => $this->formatStatusDate((string)($siteDetails['operational_status_changed_at'] ?? '')),
                    ],
                    [
                        'label' => __('DNS-Status', 'rrze-multisite-manager'),
                        'value' => (string)($siteDetails['dns_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager')),
                    ],
                    [
                        'label' => __('HTTP-Status', 'rrze-multisite-manager'),
                        'value' => (string)($siteDetails['http_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager')),
                    ],
                    [
                        'label' => __('Letzte Verfügbarkeitsprüfung', 'rrze-multisite-manager'),
                        'value' => $this->formatStatusDate((string)($siteDetails['last_availability_check'] ?? '')),
                    ],
                    [
                        'label' => __('Zuletzt DNS erfolgreich', 'rrze-multisite-manager'),
                        'value' => $this->formatStatusDate((string)($siteDetails['last_dns_ok_at'] ?? '')),
                    ],
                    [
                        'label' => __('Zuletzt DNS fehlerhaft', 'rrze-multisite-manager'),
                        'value' => $this->formatStatusDate((string)($siteDetails['last_dns_error_at'] ?? '')),
                    ],
                    [
                        'label' => __('DNS-Fehlversuche in Folge', 'rrze-multisite-manager'),
                        'value' => (string)number_format_i18n((int)($siteDetails['dns_failure_count'] ?? 0)),
                    ],
                    [
                        'label' => __('Zuletzt HTTP erfolgreich', 'rrze-multisite-manager'),
                        'value' => $this->formatStatusDate((string)($siteDetails['last_http_ok_at'] ?? '')),
                    ],
                    [
                        'label' => __('Zuletzt HTTP fehlerhaft', 'rrze-multisite-manager'),
                        'value' => $this->formatStatusDate((string)($siteDetails['last_http_error_at'] ?? '')),
                    ],
                    [
                        'label' => __('HTTP-Fehlversuche in Folge', 'rrze-multisite-manager'),
                        'value' => (string)number_format_i18n((int)($siteDetails['http_failure_count'] ?? 0)),
                    ],
                    [
                        'label' => __('Monitoring-Notiz', 'rrze-multisite-manager'),
                        'value' => trim((string)($siteDetails['monitoring_note'] ?? '')) !== ''
                            ? (string)$siteDetails['monitoring_note']
                            : __('Keine Notiz', 'rrze-multisite-manager'),
                    ],
                ],
                'can_manage_network_actions' => $this->currentUserCanUseNetworkAdminFeatures(),
                'site_monitoring_update_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_update_site_monitoring_status'),
                'site_monitoring_operational_options' => $this->getOperationalStatusOptions(),
                'site_monitoring_notice_message' => !empty($_GET['monitoring-status-updated'])
                    ? __('Der Betriebsstatus wurde aktualisiert.', 'rrze-multisite-manager')
                    : '',
                'site_monitoring_history' => is_array($siteDetails['monitoring_history'] ?? null) ? $siteDetails['monitoring_history'] : [],
                'site_detail_section_limit' => 250,
                'site_users_url' => $siteId > 0 ? get_admin_url($siteId, 'users.php') : '',
                'site_user_edit_base_url' => $siteId > 0 ? get_admin_url($siteId, 'user-edit.php') : '',
                'site_options_groups' => $optionsGroups,
                'site_options_current_tab' => $currentOptionsTab,
                'site_option_delete_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_delete_site_option'),
                'site_option_group_delete_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_delete_site_option_group'),
                'site_options_notice_messages' => $optionNotices,
                'site_content_current_tab' => $currentContentTab,
                'site_process_current_tab' => $currentProcessTab,
                'site_custom_pages' => $customPages,
                'site_content_notice_messages' => $contentNotices,
                'site_post_type_delete_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_delete_post_type_entries'),
            ],
            $this
        );
    }

    public function renderSiteStorageAnalysisPage(): void {
        $siteId = isset($_GET['site_id']) ? absint($_GET['site_id']) : 0;
        $siteSummary = $siteId > 0 ? $this->metrics->getSiteStorageAnalysisSite($siteId) : [];
        $storageAnalysis = $siteId > 0 ? $this->metrics->getSiteStorageAnalysis($siteId) : [];

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        echo $this->template->render(
            'site-storage-analysis-page',
            [
                'site_id' => $siteId,
                'site_summary' => $siteSummary,
                'storage_analysis' => $storageAnalysis,
                'orphan_file_delete_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_delete_orphan_file'),
                'site_search_placeholder' => __('Website nach Titel oder URL suchen', 'rrze-multisite-manager'),
                'site_storage_analysis_base_url' => $this->getSiteStorageAnalysisUrl(),
                'site_details_url' => $siteId > 0 ? $this->getSiteDetailsUrl($siteId) : '',
                'site_media_library_url' => $siteId > 0 ? get_admin_url($siteId, 'upload.php') : '',
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'orphan_file_deleted' => isset($_GET['orphan_file_deleted']) ? sanitize_text_field((string)wp_unslash($_GET['orphan_file_deleted'])) : '',
                'orphan_file_deleted_count' => isset($_GET['orphan_file_deleted_count']) ? absint($_GET['orphan_file_deleted_count']) : 0,
                'orphan_file_error' => isset($_GET['orphan_file_error']) ? sanitize_text_field((string)wp_unslash($_GET['orphan_file_error'])) : '',
            ],
            $this
        );
    }

    public function renderPluginOverviewPage(): void {
        $dashboardData = [];
        $widget = null;
        $pluginUsage = [];
        $allPlugins = [];
        $currentTab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'all';
        $tabs = [];
        $tables = [];
        $networkPlugins = [];
        $activePlugins = [];
        $inactivePlugins = [];
        $missingPlugins = [];
        $metricsStatus = [];
        $returnUrl = '';

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $metricsStatus = $this->metrics->getDashboardDataStatus();
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];
        $allPlugins = is_array($pluginUsage['plugins'] ?? null) ? $pluginUsage['plugins'] : [];
        $missingPlugins = is_array($pluginUsage['missing_plugins'] ?? null) ? $pluginUsage['missing_plugins'] : [];
        $widget = new PluginUsageWidget($this->plugin, $this->config);
        $networkPlugins = array_values(
            array_filter(
                $allPlugins,
                [$this, 'isNetworkPlugin']
            )
        );
        $activePlugins = array_values(
            array_filter(
                $allPlugins,
                [$this, 'isLocallyActivePlugin']
            )
        );
        $inactivePlugins = array_values(
            array_filter(
                $allPlugins,
                [$this, 'isInactivePlugin']
            )
        );

        if (!in_array($currentTab, ['all', 'network', 'active', 'inactive'], true)) {
            $currentTab = 'all';
        }

        $returnUrl = add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview'),
                'tab' => $currentTab,
            ],
            $this->getAdminPageBaseUrl()
        );

        $tabs = [
            [
                'slug' => 'all',
                'label' => __('Alle Plugins', 'rrze-multisite-manager'),
                'count' => count($allPlugins),
                'class' => 'rrze-msm-tab-all',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview'),
                        'tab' => 'all',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'network',
                'label' => __('Netzwerkplugins', 'rrze-multisite-manager'),
                'count' => count($networkPlugins),
                'class' => 'rrze-msm-tab-info',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview'),
                        'tab' => 'network',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'active',
                'label' => __('Aktivierte Plugins', 'rrze-multisite-manager'),
                'count' => count($activePlugins),
                'class' => 'rrze-msm-tab-positive',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview'),
                        'tab' => 'active',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
            [
                'slug' => 'inactive',
                'label' => __('Nicht aktivierte Plugins', 'rrze-multisite-manager'),
                'count' => count($inactivePlugins),
                'class' => 'rrze-msm-tab-gold',
                'url' => add_query_arg(
                    [
                        'page' => (string)($this->config->getMenuSettings()['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview'),
                        'tab' => 'inactive',
                    ],
                    $this->getAdminPageBaseUrl()
                ),
            ],
        ];

        $tables = [
            'all' => $widget->renderTable(
                $allPlugins,
                [
                    'table_id' => 'plugin-overview-all',
                    'default_per_page' => 30,
                    'sort_key' => 'active-sites',
                    'sort_direction' => 'desc',
                    'show_active_sites' => true,
                    'show_active_site_list' => true,
                    'show_network_button' => true,
                    'highlight_network_plugins' => true,
                    'action_mode' => 'text',
                ]
            ),
            'network' => $widget->renderTable(
                $networkPlugins,
                [
                    'table_id' => 'plugin-overview-network',
                    'default_per_page' => 30,
                    'sort_key' => 'active-sites',
                    'sort_direction' => 'desc',
                    'show_active_sites' => true,
                    'show_active_site_list' => false,
                    'show_network_button' => true,
                    'highlight_network_plugins' => false,
                    'action_mode' => 'text',
                ]
            ),
            'active' => $widget->renderTable(
                $activePlugins,
                [
                    'table_id' => 'plugin-overview-active',
                    'default_per_page' => 30,
                    'sort_key' => 'active-sites',
                    'sort_direction' => 'desc',
                    'show_active_sites' => true,
                    'show_active_site_list' => true,
                    'show_network_button' => true,
                    'highlight_network_plugins' => false,
                    'action_mode' => 'text',
                ]
            ),
            'inactive' => $widget->renderTable(
                $inactivePlugins,
                [
                    'table_id' => 'plugin-overview-inactive',
                    'default_per_page' => 30,
                    'sort_key' => 'name',
                    'sort_direction' => 'asc',
                    'show_active_sites' => false,
                    'show_active_site_list' => false,
                    'show_network_button' => true,
                    'highlight_network_plugins' => false,
                    'action_mode' => 'text',
                ]
            ),
        ];

        echo $this->template->render(
            'plugin-overview-page',
            [
                'plugin_overview_tabs' => $tabs,
                'current_tab' => $currentTab,
                'plugin_overview_table' => !empty($metricsStatus['has_data']) ? ($tables[$currentTab] ?? $tables['all']) : '',
                'missing_plugin_count' => count($missingPlugins),
                'missing_plugin_table' => !empty($metricsStatus['has_data']) && !empty($missingPlugins)
                    ? $widget->renderMissingPluginTable(
                        $missingPlugins,
                        [
                            'table_id' => 'plugin-overview-missing',
                            'default_per_page' => 20,
                        ]
                    )
                    : '',
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'metrics_notice_html' => $this->renderMetricsStatusNoticeHtml($metricsStatus, $returnUrl),
                'metrics_has_data' => !empty($metricsStatus['has_data']),
                'metrics_refreshed' => !empty($_GET['metrics-refreshed']),
            ],
            $this
        );
    }

    public function renderPluginDetailsPage(): void {
        $pluginFile = isset($_GET['plugin']) ? sanitize_text_field((string)wp_unslash($_GET['plugin'])) : '';
        $pluginDetails = $pluginFile !== '' ? $this->metrics->getPluginDetails($pluginFile) : [];

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        echo $this->template->render(
            'plugin-details-page',
            [
                'plugin_file' => $pluginFile,
                'plugin_details' => $pluginDetails,
                'plugin_search_placeholder' => __('Plugin nach Name suchen', 'rrze-multisite-manager'),
                'plugin_details_base_url' => $this->getPluginDetailsUrl(),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'plugin_status_badges_html' => !empty($pluginDetails['status']) && is_array($pluginDetails['status'])
                    ? $this->renderStatusBadgesHtml($pluginDetails['status'])
                    : '',
                'plugin_status_update_html' => !empty($pluginDetails) ? $this->renderPluginStatusUpdateHtml($pluginDetails) : '',
                'plugin_actions_html' => !empty($pluginDetails) ? $this->renderPluginActionsHtml($pluginDetails) : '',
                'plugin_readme_html' => !empty($pluginDetails['readme_markdown'])
                    ? $this->renderSimpleMarkdown((string)$pluginDetails['readme_markdown'])
                    : '',
            ],
            $this
        );
    }

    public function renderThemeOverviewPage(): void {
        $dashboardData = [];
        $themeWidget = null;
        $themes = [];
        $metricsStatus = [];
        $returnUrl = '';

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $metricsStatus = $this->metrics->getDashboardDataStatus();
        $themeWidget = new ThemeOverviewWidget($this->plugin, $this->config);
        $themes = is_array($dashboardData['themes'] ?? null) ? $dashboardData['themes'] : [];
        $returnUrl = add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['theme_overview_slug'] ?? 'rrze-multisite-manager-theme-overview'),
            ],
            $this->getAdminPageBaseUrl()
        );

        echo $this->template->render(
            'theme-overview-page',
            [
                'themes' => !empty($metricsStatus['has_data']) ? $themes : [],
                'theme_widget' => $themeWidget,
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'metrics_notice_html' => $this->renderMetricsStatusNoticeHtml($metricsStatus, $returnUrl),
                'metrics_has_data' => !empty($metricsStatus['has_data']),
                'metrics_refreshed' => !empty($_GET['metrics-refreshed']),
            ],
            $this
        );
    }

    public function renderThemeDetailsPage(): void {
        $stylesheet = isset($_GET['theme']) ? sanitize_text_field((string)wp_unslash($_GET['theme'])) : '';
        $themeDetails = $stylesheet !== '' ? $this->metrics->getThemeDetails($stylesheet) : [];
        $themeWidget = new ThemeOverviewWidget($this->plugin, $this->config);

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        echo $this->template->render(
            'theme-details-page',
            [
                'theme_stylesheet' => $stylesheet,
                'theme_details' => $themeDetails,
                'theme_widget' => $themeWidget,
                'theme_search_placeholder' => __('Theme nach Name suchen', 'rrze-multisite-manager'),
                'theme_details_base_url' => $this->getThemeDetailsUrl(),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'theme_actions_html' => !empty($themeDetails) ? $this->renderThemeActionsHtml($themeDetails) : '',
                'theme_readme_html' => !empty($themeDetails['readme_markdown'])
                    ? $this->renderSimpleMarkdown((string)$themeDetails['readme_markdown'])
                    : '',
            ],
            $this
        );
    }

    protected function isNetworkPlugin(array $plugin): bool {
        return !empty($plugin['network_active']);
    }

    protected function isLocallyActivePlugin(array $plugin): bool {
        return (int)($plugin['site_count'] ?? 0) > 0 && empty($plugin['network_active']);
    }

    protected function isInactivePlugin(array $plugin): bool {
        return (int)($plugin['site_count'] ?? 0) <= 0;
    }

    public function renderSiteStatusPage(): void {
        $siteId = isset($_GET['site_id']) ? absint($_GET['site_id']) : 0;
        $statusAction = isset($_GET['status_action']) ? sanitize_key((string)$_GET['status_action']) : '';
        $site = $siteId > 0 ? get_site($siteId) : null;
        $siteName = '';
        $currentNote = '';
        $allowedActions = ['archive', 'spam'];

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        if (!$site instanceof \WP_Site || !in_array($statusAction, $allowedActions, true)) {
            wp_die(esc_html__('Ungültige Status-Aktion.', 'rrze-multisite-manager'));
        }

        if (is_main_site($siteId)) {
            wp_die(esc_html__('Die Hauptsite kann nicht auf diese Weise geändert werden.', 'rrze-multisite-manager'));
        }

        $siteName = $this->getSiteName($site);
        $currentNote = (string)get_site_meta($siteId, self::META_STATUS_NOTE, true);

        echo $this->template->render(
            'site-status-page',
            [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'site_url' => get_home_url($siteId, '/'),
                'status_action' => $statusAction,
                'status_action_label' => $statusAction === 'archive'
                    ? __('Archivieren', 'rrze-multisite-manager')
                    : __('Sperren', 'rrze-multisite-manager'),
                'status_action_description' => $statusAction === 'archive'
                    ? __('Setzt den Site-Status auf archiviert.', 'rrze-multisite-manager')
                    : __('Setzt den Site-Status auf gesperrt.', 'rrze-multisite-manager'),
                'current_note' => $currentNote,
                'form_action' => $this->getAdminPostActionUrl('rrze_multisite_manager_site_status'),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'redirect_url' => $this->getSiteOverviewUrl(),
            ],
            $this
        );
    }

    public function saveViews(): void {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage these views.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_save_views');

        $widgets = $this->getWidgetInstances();
        $submittedViews = isset($_POST['views']) ? wp_unslash($_POST['views']) : [];
        $newViewName = isset($_POST['new_view_name']) ? sanitize_text_field((string)wp_unslash($_POST['new_view_name'])) : '';

        $this->viewManager->saveViews($submittedViews, array_keys($widgets), $newViewName);

        $redirectUrl = add_query_arg(
            [
                'page' => $this->settings->getSettingsSlug(),
                'tab' => isset($_POST['settings_tab']) ? sanitize_key((string)wp_unslash($_POST['settings_tab'])) : 'views',
                'views-updated' => 'true',
            ],
            $this->getAdminPageBaseUrl()
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function ajaxSaveWidgetOrder(): void {
        if (!$this->currentUserCanAccessManager()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('rrze-msm-save-widget-order', 'nonce');

        $view = isset($_POST['view']) ? sanitize_key((string)wp_unslash($_POST['view'])) : 'default';
        $order = isset($_POST['order']) && is_array($_POST['order']) ? wp_unslash($_POST['order']) : [];
        $widgets = $this->getWidgetInstances();
        $allowedIds = array_keys($widgets);
        $sanitizedOrder = [];
        $widgetId = '';
        $storedOrders = get_user_meta(get_current_user_id(), 'rrze_msm_widget_orders', true);

        foreach ($order as $widgetId) {
            $widgetId = sanitize_key((string)$widgetId);

            if (in_array($widgetId, $allowedIds, true) && !in_array($widgetId, $sanitizedOrder, true)) {
                $sanitizedOrder[] = $widgetId;
            }
        }

        if (!is_array($storedOrders)) {
            $storedOrders = [];
        }

        $storedOrders[$view] = $sanitizedOrder;
        update_user_meta(get_current_user_id(), 'rrze_msm_widget_orders', $storedOrders);

        wp_send_json_success();
    }

    public function ajaxSearchSites(): void {
        $searchTerm = isset($_GET['q']) ? sanitize_text_field((string)wp_unslash($_GET['q'])) : '';

        if (!$this->currentUserCanAccessManager()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('rrze-msm-search-sites', 'nonce');

        wp_send_json_success(
            [
                'results' => $this->metrics->searchSites($searchTerm, 20),
            ]
        );
    }

    public function ajaxSearchPlugins(): void {
        $searchTerm = isset($_GET['q']) ? sanitize_text_field((string)wp_unslash($_GET['q'])) : '';

        if (!$this->currentUserCanAccessManager()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('rrze-msm-search-plugins', 'nonce');

        wp_send_json_success(
            [
                'results' => $this->metrics->searchPlugins($searchTerm, 20),
            ]
        );
    }

    public function ajaxSearchThemes(): void {
        $searchTerm = isset($_GET['q']) ? sanitize_text_field((string)wp_unslash($_GET['q'])) : '';

        if (!$this->currentUserCanAccessManager()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('rrze-msm-search-themes', 'nonce');

        wp_send_json_success(
            [
                'results' => $this->metrics->searchThemes($searchTerm, 20),
            ]
        );
    }

    public function handleSiteStatusAction(): void {
        $siteId = isset($_REQUEST['site_id']) ? absint(wp_unslash($_REQUEST['site_id'])) : 0;
        $statusAction = isset($_REQUEST['status_action']) ? sanitize_key((string)wp_unslash($_REQUEST['status_action'])) : '';
        $note = isset($_POST['status_note']) ? sanitize_textarea_field((string)wp_unslash($_POST['status_note'])) : '';
        $site = $siteId > 0 ? get_site($siteId) : null;
        $isArchived = false;
        $isSpam = false;
        $isDeleted = false;
        $redirectUrl = $this->getSiteOverviewUrl();

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to manage site statuses.', 'rrze-multisite-manager'));
        }

        if (!$site instanceof \WP_Site) {
            wp_die(esc_html__('Ungültige Site.', 'rrze-multisite-manager'));
        }

        $isArchived = ((int)$site->archived === 1);
        $isSpam = ((int)$site->spam === 1);
        $isDeleted = ((int)$site->deleted === 1);

        if (is_main_site($siteId) && in_array($statusAction, ['archive', 'spam', 'delete'], true)) {
            wp_die(esc_html__('Die Hauptsite kann nicht auf diese Weise geändert werden.', 'rrze-multisite-manager'));
        }

        if (in_array($statusAction, ['archive', 'spam'], true)) {
            if ($isArchived || $isSpam || (int)$site->deleted === 1) {
                wp_die(esc_html__('Diese Site kann in ihrem aktuellen Status nicht so geändert werden.', 'rrze-multisite-manager'));
            }

            check_admin_referer('rrze_multisite_manager_site_status_' . $statusAction . '_' . $siteId);
            $this->applySiteStatus($siteId, $statusAction, $note);
        } elseif ($statusAction === 'restore') {
            if (!$isArchived && !$isSpam && !$isDeleted) {
                wp_die(esc_html__('Nur archivierte, gesperrte oder zum Löschen markierte Sites können wiederhergestellt werden.', 'rrze-multisite-manager'));
            }

            check_admin_referer('rrze_multisite_manager_site_status_restore_' . $siteId);
            $this->restoreSiteStatus($siteId);
        } elseif ($statusAction === 'delete') {
            if (!$isArchived && !$isSpam) {
                wp_die(esc_html__('Nur archivierte oder gesperrte Sites können zum Löschen markiert werden.', 'rrze-multisite-manager'));
            }

            check_admin_referer('rrze_multisite_manager_site_status_delete_' . $siteId);
            update_blog_status($siteId, 'deleted', 1);
        } else {
            wp_die(esc_html__('Ungültige Status-Aktion.', 'rrze-multisite-manager'));
        }

        $this->metrics->clearCache();
        $redirectUrl = add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
                'status-updated' => 'true',
            ],
            $this->getAdminPageBaseUrl()
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function handleSiteOptionDelete(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $optionName = isset($_POST['option_name']) ? sanitize_text_field((string)wp_unslash($_POST['option_name'])) : '';
        $optionsTab = isset($_POST['options_tab']) ? sanitize_key((string)wp_unslash($_POST['options_tab'])) : 'all';
        $redirectUrl = $this->getSiteDetailsUrl($siteId);

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to delete site options.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_delete_site_option_' . $siteId . '_' . $optionName);

        if ($siteId <= 0 || $optionName === '') {
            wp_die(esc_html__('Ungültige Option.', 'rrze-multisite-manager'));
        }

        if ($this->metrics->isWordPressCoreOptionName($optionName)) {
            wp_die(esc_html__('WordPress-Core-Optionen können hier nicht gelöscht werden.', 'rrze-multisite-manager'));
        }

        $this->metrics->deleteSiteOption($siteId, $optionName);
        $this->metrics->clearCache();

        $redirectUrl = add_query_arg(
            [
                'site_id' => $siteId,
                'options_tab' => $optionsTab,
                'option_deleted' => $optionName,
            ],
            $this->getSiteDetailsUrl()
        ) . '#rrze-msm-site-options';

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function handleSiteMonitoringStatusUpdate(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $operationalStatus = isset($_POST['operational_status']) ? sanitize_key((string)wp_unslash($_POST['operational_status'])) : '';
        $monitoringNote = isset($_POST['monitoring_note']) ? sanitize_textarea_field((string)wp_unslash($_POST['monitoring_note'])) : '';
        $redirectUrl = $this->getSiteDetailsUrl($siteId);
        $allowedStatuses = array_keys($this->getOperationalStatusOptions());
        $currentStatus = '';
        $timestamp = current_time('mysql', true);

        if (!$this->currentUserCanAccessManager()) {
            wp_die(esc_html__('You are not allowed to update monitoring data.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_update_site_monitoring_status_' . $siteId);

        if ($siteId <= 0 || !get_site($siteId) instanceof \WP_Site) {
            wp_die(esc_html__('Ungültige Site.', 'rrze-multisite-manager'));
        }

        if (!in_array($operationalStatus, $allowedStatuses, true)) {
            wp_die(esc_html__('Ungültiger Betriebsstatus.', 'rrze-multisite-manager'));
        }

        $currentStatus = (string)get_site_meta($siteId, self::META_OPERATIONAL_STATUS, true);

        if ($operationalStatus === '') {
            if ($currentStatus !== '') {
                update_site_meta($siteId, self::META_PREVIOUS_OPERATIONAL_STATUS, $currentStatus);
                update_site_meta($siteId, self::META_OPERATIONAL_STATUS_CHANGED_AT, $timestamp);
            }

            delete_site_meta($siteId, self::META_OPERATIONAL_STATUS);
            delete_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE);
        } else {
            if ($currentStatus !== $operationalStatus) {
                update_site_meta($siteId, self::META_PREVIOUS_OPERATIONAL_STATUS, $currentStatus);
                update_site_meta($siteId, self::META_OPERATIONAL_STATUS_CHANGED_AT, $timestamp);
            }

            update_site_meta($siteId, self::META_OPERATIONAL_STATUS, $operationalStatus);
            update_site_meta($siteId, self::META_OPERATIONAL_STATUS_SOURCE, 'manual');
        }

        if ($monitoringNote === '') {
            delete_site_meta($siteId, self::META_MONITORING_NOTE);
        } else {
            update_site_meta($siteId, self::META_MONITORING_NOTE, $monitoringNote);
        }

        $this->metrics->clearCache();

        $redirectUrl = add_query_arg(
            [
                'site_id' => $siteId,
                'monitoring-status-updated' => 'true',
            ],
            $this->getSiteDetailsUrl()
        );

        wp_safe_redirect($redirectUrl . '#rrze-msm-site-monitoring');
        exit;
    }

    public function handleOrphanFileDelete(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $relativePath = isset($_POST['relative_path']) ? sanitize_text_field((string)wp_unslash($_POST['relative_path'])) : '';
        $relativePaths = isset($_POST['relative_paths']) && is_array($_POST['relative_paths'])
            ? array_values(array_filter(array_map('sanitize_text_field', wp_unslash((array)$_POST['relative_paths']))))
            : [];
        $redirectUrl = $this->getSiteStorageAnalysisUrl($siteId);
        $result = [];
        $deletedCount = 0;
        $deletedPaths = [];
        $errors = [];
        $path = '';

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to delete upload files.', 'rrze-multisite-manager'));
        }

        if (!empty($relativePaths)) {
            check_admin_referer('rrze_multisite_manager_delete_orphan_files_' . $siteId);
        } else {
            check_admin_referer('rrze_multisite_manager_delete_orphan_file_' . $siteId . '_' . $relativePath);
        }

        if ($siteId <= 0 || ($relativePath === '' && empty($relativePaths))) {
            wp_die(esc_html__('Ungültige Datei.', 'rrze-multisite-manager'));
        }

        if (!empty($relativePaths)) {
            foreach ($relativePaths as $path) {
                if (!is_string($path) || trim($path) === '') {
                    continue;
                }

                $result = $this->metrics->deleteSiteOrphanFile($siteId, $path);

                if (!empty($result['deleted'])) {
                    $deletedCount++;
                    $deletedPaths[] = $path;
                    continue;
                }

                $errors[] = sprintf(
                    '%1$s: %2$s',
                    $path,
                    (string)($result['message'] ?? __('Die Datei konnte nicht gelöscht werden.', 'rrze-multisite-manager'))
                );
            }
        } else {
            $result = $this->metrics->deleteSiteOrphanFile($siteId, $relativePath);

            if (!empty($result['deleted'])) {
                $deletedCount = 1;
                $deletedPaths[] = $relativePath;
            } else {
                $errors[] = (string)($result['message'] ?? __('Die Datei konnte nicht gelöscht werden.', 'rrze-multisite-manager'));
            }
        }

        $this->metrics->clearCache();

        if ($deletedCount > 0 && empty($errors)) {
            $redirectUrl = add_query_arg(
                [
                    'site_id' => $siteId,
                    'orphan_file_deleted' => $deletedCount === 1 && !empty($deletedPaths[0]) ? rawurlencode((string)$deletedPaths[0]) : '',
                    'orphan_file_deleted_count' => $deletedCount,
                ],
                $this->getSiteStorageAnalysisUrl()
            );
        } else {
            $redirectUrl = add_query_arg(
                [
                    'site_id' => $siteId,
                    'orphan_file_deleted_count' => $deletedCount,
                    'orphan_file_error' => rawurlencode(implode(' | ', array_slice($errors, 0, 5))),
                ],
                $this->getSiteStorageAnalysisUrl()
            );
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function handleSiteOptionGroupDelete(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $groupKey = isset($_POST['group_key']) ? sanitize_key((string)wp_unslash($_POST['group_key'])) : '';
        $redirectUrl = $this->getSiteDetailsUrl($siteId);
        $deletedCount = 0;

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to delete site options.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_delete_site_option_group_' . $siteId . '_' . $groupKey);

        if ($siteId <= 0 || $groupKey === '') {
            wp_die(esc_html__('Ungültige Options-Gruppe.', 'rrze-multisite-manager'));
        }

        if ($this->metrics->isWordPressCoreOptionGroup($groupKey)) {
            wp_die(esc_html__('Die WordPress-Core-Gruppe kann hier nicht gelöscht werden.', 'rrze-multisite-manager'));
        }

        $deletedCount = $this->metrics->deleteSiteOptionGroup($siteId, $groupKey);
        $this->metrics->clearCache();

        $redirectUrl = add_query_arg(
            [
                'site_id' => $siteId,
                'options_tab' => $groupKey,
                'option_group_deleted' => $groupKey,
                'option_group_deleted_count' => $deletedCount,
            ],
            $this->getSiteDetailsUrl()
        ) . '#rrze-msm-site-options';

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function handlePostTypeDelete(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $postType = isset($_POST['post_type']) ? sanitize_key((string)wp_unslash($_POST['post_type'])) : '';
        $confirmDelete = isset($_POST['confirm_delete']) ? sanitize_text_field((string)wp_unslash($_POST['confirm_delete'])) : '';
        $deletedCount = 0;
        $redirectUrl = $this->getSiteDetailsUrl($siteId);

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            wp_die(esc_html__('You are not allowed to delete post type entries.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_delete_post_type_entries_' . $siteId);

        if ($siteId <= 0 || $postType === '') {
            wp_die(esc_html__('Ungültiger Custom Post Type.', 'rrze-multisite-manager'));
        }

        if ($confirmDelete !== '1') {
            wp_die(esc_html__('Die Sicherheitsbestätigung fehlt.', 'rrze-multisite-manager'));
        }

        $deletedCount = $this->metrics->deletePostTypeEntries($siteId, $postType);
        $this->metrics->clearCache();

        $redirectUrl = add_query_arg(
            [
                'site_id' => $siteId,
                'content_tab' => 'custom-post-types',
                'deleted_post_type' => $postType,
                'deleted_post_type_count' => $deletedCount,
            ],
            $this->getSiteDetailsUrl()
        ) . '#rrze-msm-site-content';

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function maybeRefreshMetricsCache(): bool {
        $nonce = isset($_POST['rrze_msm_refresh_nonce']) ? sanitize_text_field((string)wp_unslash($_POST['rrze_msm_refresh_nonce'])) : '';
        $refresh = isset($_POST['rrze_msm_refresh']) ? sanitize_text_field((string)wp_unslash($_POST['rrze_msm_refresh'])) : '';

        if ($refresh !== '1') {
            return false;
        }

        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            return false;
        }

        if (!wp_verify_nonce($nonce, 'rrze-msm-refresh')) {
            return false;
        }

        $this->metrics->clearCache();
        return true;
    }

    protected function currentUserCanAccessManager(): bool {
        return current_user_can((string)($this->config->getMenuSettings()['capability'] ?? 'rrze_multisite_manager_access'));
    }

    protected function currentUserCanUseNetworkAdminFeatures(): bool {
        return is_super_admin();
    }

    protected function getAdminPageBaseUrl(): string {
        return admin_url('admin.php');
    }

    protected function getAdminPostActionUrl(string $action): string {
        return add_query_arg(
            [
                'action' => $action,
            ],
            admin_url('admin-post.php')
        );
    }

    protected function getDashboardUrl(): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['dashboard_slug'] ?? 'rrze-multisite-manager-dashboard'),
            ],
            $this->getAdminPageBaseUrl()
        );
    }

    protected function getMainSiteDashboardUrl(): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['dashboard_slug'] ?? 'rrze-multisite-manager-dashboard'),
            ],
            get_admin_url(get_main_site_id(), 'admin.php')
        );
    }

    protected function getSettingsUrl(string $tab = 'general'): string {
        return add_query_arg(
            [
                'page' => $this->settings->getSettingsSlug(),
                'tab' => $tab,
            ],
            $this->getAdminPageBaseUrl()
        );
    }

    protected function isNetworkAdminUrl(string $url): bool {
        return str_contains($url, '/wp-admin/network/');
    }

    public function getCurrentViewSlug(): string {
        $view = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : 'default';
        return $view === '' ? 'default' : $view;
    }

    public function getColorMode(): string {
        $mode = isset($_COOKIE['rrze_msm_color_mode']) ? sanitize_key((string)wp_unslash($_COOKIE['rrze_msm_color_mode'])) : 'light';
        return $mode === 'dark' ? 'dark' : 'light';
    }

    public function getModeToggleLabel(): string {
        return $this->getColorMode() === 'dark'
            ? __('Light Mode', 'rrze-multisite-manager')
            : __('Dark Mode', 'rrze-multisite-manager');
    }

    public function getStatusActionPageUrl(int $siteId, string $statusAction): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_status_slug'] ?? 'rrze-multisite-manager-site-status'),
                'site_id' => $siteId,
                'status_action' => $statusAction,
            ],
            $this->getAdminPageBaseUrl()
        );
    }

    public function getStatusActionSubmitUrl(int $siteId, string $statusAction): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'site_id' => $siteId,
                    'status_action' => $statusAction,
                ],
                $this->getAdminPostActionUrl('rrze_multisite_manager_site_status')
            ),
            'rrze_multisite_manager_site_status_' . $statusAction . '_' . $siteId
        );
    }

    public function getSiteOverviewUrl(): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
            ],
            $this->getAdminPageBaseUrl()
        );
    }

    public function getEnvironmentOverviewUrl(): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['environment_overview_slug'] ?? 'rrze-multisite-manager-environment-overview'),
            ],
            $this->getAdminPageBaseUrl()
        );
    }

    public function getSiteDetailsUrl(int $siteId = 0): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['site_details_slug'] ?? 'rrze-multisite-manager-site-details'),
        ];

        if ($siteId > 0) {
            $args['site_id'] = $siteId;
        }

        return add_query_arg($args, $this->getAdminPageBaseUrl());
    }

    public function getSiteStorageAnalysisUrl(int $siteId = 0): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['site_storage_analysis_slug'] ?? 'rrze-multisite-manager-site-storage-analysis'),
        ];

        if ($siteId > 0) {
            $args['site_id'] = $siteId;
        }

        return add_query_arg($args, $this->getAdminPageBaseUrl());
    }

    public function getPluginDetailsUrl(string $pluginFile = ''): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['plugin_details_slug'] ?? 'rrze-multisite-manager-plugin-details'),
        ];

        if (trim($pluginFile) !== '') {
            $args['plugin'] = $pluginFile;
        }

        return add_query_arg($args, $this->getAdminPageBaseUrl());
    }

    public function getThemeOverviewUrl(): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['theme_overview_slug'] ?? 'rrze-multisite-manager-theme-overview'),
            ],
            $this->getAdminPageBaseUrl()
        );
    }

    public function getThemeDetailsUrl(string $stylesheet = ''): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['theme_details_slug'] ?? 'rrze-multisite-manager-theme-details'),
        ];

        if (trim($stylesheet) !== '') {
            $args['theme'] = $stylesheet;
        }

        return add_query_arg($args, $this->getAdminPageBaseUrl());
    }

    protected function getCurrentSiteSearchBaseUrl(): string {
        $page = isset($_GET['page']) ? sanitize_key((string)$_GET['page']) : '';
        $storageAnalysisSlug = (string)($this->config->getMenuSettings()['site_storage_analysis_slug'] ?? 'rrze-multisite-manager-site-storage-analysis');

        if ($page === $storageAnalysisSlug) {
            return $this->getSiteStorageAnalysisUrl();
        }

        return $this->getSiteDetailsUrl();
    }

    protected function getWidgetInstances(): array {
        return [
            'summary' => new SummaryWidget($this->plugin, $this->config),
            'status' => new StatusWidget($this->plugin, $this->config),
            'operational_status' => new OperationalStatusWidget($this->plugin, $this->config),
            'network_storage_usage' => new NetworkStorageUsageWidget($this->plugin, $this->config),
            'new_monitoring_alerts' => new NewMonitoringAlertsWidget($this->plugin, $this->config),
            'problem_sites' => new ProblemSitesWidget($this->plugin, $this->config),
            'theme_usage' => new ThemeUsageWidget($this->plugin, $this->config),
            'editor_usage' => new EditorUsageWidget($this->plugin, $this->config),
            'recent_sites' => new RecentSitesWidget($this->plugin, $this->config),
            'recently_updated_sites' => new RecentlyUpdatedSitesWidget($this->plugin, $this->config),
            'inactive_sites' => new InactiveSitesWidget($this->plugin, $this->config),
            'site_overview' => new SiteOverviewWidget($this->plugin, $this->config),
            'archived_sites' => new ArchivedSitesWidget($this->plugin, $this->config),
            'blocked_sites' => new BlockedSitesWidget($this->plugin, $this->config),
            'deleted_sites' => new DeletedSitesWidget($this->plugin, $this->config),
            'theme_overview' => new ThemeOverviewWidget($this->plugin, $this->config),
            'plugin_usage' => new PluginUsageWidget($this->plugin, $this->config),
            'inactive_plugins' => new InactivePluginsWidget($this->plugin, $this->config),
            'inactive_themes' => new InactiveThemesWidget($this->plugin, $this->config),
        ];
    }

    protected function filterWidgetsForView(array $widgets, array $widgetIds): array {
        $selected = [];
        $widgetId = '';

        foreach ($widgetIds as $widgetId) {
            if (isset($widgets[$widgetId])) {
                $selected[$widgetId] = $widgets[$widgetId];
            }
        }

        return $selected;
    }

    protected function sortWidgetsForUser(array $widgets, string $viewSlug): array {
        $storedOrders = get_user_meta(get_current_user_id(), 'rrze_msm_widget_orders', true);
        $order = is_array($storedOrders) && !empty($storedOrders[$viewSlug]) && is_array($storedOrders[$viewSlug])
            ? $storedOrders[$viewSlug]
            : [];
        $sorted = [];
        $widgetId = '';

        foreach ($order as $widgetId) {
            if (isset($widgets[$widgetId])) {
                $sorted[$widgetId] = $widgets[$widgetId];
            }
        }

        foreach ($widgets as $widgetId => $widget) {
            if (!isset($sorted[$widgetId])) {
                $sorted[$widgetId] = $widget;
            }
        }

        return $sorted;
    }

    protected function getWidgetOptions(array $widgets): array {
        $options = [];
        $widgetId = '';

        foreach ($widgets as $widgetId => $widget) {
            $options[] = [
                'id' => $widgetId,
                'label' => $widget->getTitle(),
            ];
        }

        return $options;
    }

    protected function applySiteStatus(int $siteId, string $statusAction, string $note): void {
        $timestamp = current_time('mysql', true);
        $userId = get_current_user_id();

        if ($statusAction === 'archive') {
            update_blog_status($siteId, 'archived', 1);
            update_blog_status($siteId, 'spam', 0);
            update_blog_status($siteId, 'deleted', 0);
            delete_site_meta($siteId, self::META_SPAM_AT);
            update_site_meta($siteId, self::META_ARCHIVED_AT, $timestamp);
        } elseif ($statusAction === 'spam') {
            update_blog_status($siteId, 'spam', 1);
            update_blog_status($siteId, 'archived', 0);
            update_blog_status($siteId, 'deleted', 0);
            delete_site_meta($siteId, self::META_ARCHIVED_AT);
            update_site_meta($siteId, self::META_SPAM_AT, $timestamp);
        }

        update_site_meta($siteId, self::META_STATUS_NOTE, $note);
        update_site_meta($siteId, self::META_STATUS_USER_ID, $userId);
    }

    protected function restoreSiteStatus(int $siteId): void {
        update_blog_status($siteId, 'archived', 0);
        update_blog_status($siteId, 'spam', 0);
        update_blog_status($siteId, 'deleted', 0);
        delete_site_meta($siteId, self::META_ARCHIVED_AT);
        delete_site_meta($siteId, self::META_SPAM_AT);
        delete_site_meta($siteId, self::META_STATUS_NOTE);
        delete_site_meta($siteId, self::META_STATUS_USER_ID);
    }

    protected function getSiteName(\WP_Site $site): string {
        $blogName = get_blog_option((int)$site->blog_id, 'blogname', '');

        if (is_string($blogName) && trim($blogName) !== '') {
            return $blogName;
        }

        return untrailingslashit($site->domain . $site->path);
    }

    protected function renderStatusBadgesHtml(array $statusItems): string {
        $html = '';
        $statusItem = [];

        foreach ($statusItems as $statusItem) {
            $html .= '<span class="rrze-msm-badge rrze-msm-badge-' . esc_attr((string)$statusItem['accent']) . '">' . esc_html((string)$statusItem['label']) . '</span> ';
        }

        return trim($html);
    }

    protected function renderPluginActionsHtml(array $pluginDetails): string {
        $html = '<div class="rrze-msm-site-actions">';
        $pluginCheckUrl = $this->getPluginCheckUrl($pluginDetails);
        $canUseNetworkAdminFeatures = $this->currentUserCanUseNetworkAdminFeatures();

        if (!empty($pluginDetails['deactivate_url']) && ($canUseNetworkAdminFeatures || !$this->isNetworkAdminUrl((string)$pluginDetails['deactivate_url']))) {
            if (!empty($pluginDetails['network_active'])) {
                $html .= '<button type="button" class="button button-small rrze-msm-site-action rrze-msm-site-action-warning rrze-msm-site-action-text rrze-msm-open-plugin-deactivate-modal" data-plugin-name="' . esc_attr((string)($pluginDetails['name'] ?? '')) . '" data-deactivate-url="' . esc_url((string)$pluginDetails['deactivate_url']) . '" title="' . esc_attr__('Netzwerkweit deaktivieren', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Netzwerkweit deaktivieren', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Netzwerkweit deaktivieren', 'rrze-multisite-manager') . '</span></button>';
            } else {
                $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-site-action-text" href="' . esc_url((string)$pluginDetails['deactivate_url']) . '" title="' . esc_attr__('Deaktivieren', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Deaktivieren', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Deaktivieren', 'rrze-multisite-manager') . '</span></a>';
            }
        }

        if (!empty($pluginDetails['settings_url']) && ($canUseNetworkAdminFeatures || !$this->isNetworkAdminUrl((string)$pluginDetails['settings_url']))) {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-text" href="' . esc_url((string)$pluginDetails['settings_url']) . '" title="' . esc_attr__('Einstellungen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Einstellungen', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Einstellungen', 'rrze-multisite-manager') . '</span></a>';
        }

        if ($pluginCheckUrl !== '') {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-text" href="' . esc_url($pluginCheckUrl) . '" title="' . esc_attr__('Plugin prüfen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Plugin prüfen', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Plugin prüfen', 'rrze-multisite-manager') . '</span></a>';
        }

        if (!empty($pluginDetails['update_url']) && ($canUseNetworkAdminFeatures || !$this->isNetworkAdminUrl((string)$pluginDetails['update_url']))) {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-text" href="' . esc_url((string)$pluginDetails['update_url']) . '" title="' . esc_attr__('Aktualisieren', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Aktualisieren', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Aktualisieren', 'rrze-multisite-manager') . '</span></a>';
        }

        if ($canUseNetworkAdminFeatures && !empty($pluginDetails['delete_url'])) {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-site-action-text" href="' . esc_url((string)$pluginDetails['delete_url']) . '" title="' . esc_attr__('Löschen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Löschen', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Löschen', 'rrze-multisite-manager') . '</span></a>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function renderPluginStatusUpdateHtml(array $pluginDetails): string {
        $html = '';
        $canUseNetworkAdminFeatures = $this->currentUserCanUseNetworkAdminFeatures();

        if (empty($pluginDetails['update_available']) || empty($pluginDetails['update_version'])) {
            return '';
        }

        $html .= '<p class="rrze-msm-plugin-status-update">';
        $html .= '<strong>' . esc_html(sprintf(__('Neue Version %s verfügbar.', 'rrze-multisite-manager'), (string)$pluginDetails['update_version'])) . '</strong>';

        if (!empty($pluginDetails['update_details_url']) || !empty($pluginDetails['update_url'])) {
            $html .= ' ';
        }

        if (!empty($pluginDetails['update_details_url'])) {
            $html .= '<a href="' . esc_url((string)$pluginDetails['update_details_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Details', 'rrze-multisite-manager') . '</a>';
        }

        if (!empty($pluginDetails['update_details_url']) && !empty($pluginDetails['update_url'])) {
            $html .= ' | ';
        }

        if (!empty($pluginDetails['update_url']) && ($canUseNetworkAdminFeatures || !$this->isNetworkAdminUrl((string)$pluginDetails['update_url']))) {
            $html .= '<a href="' . esc_url((string)$pluginDetails['update_url']) . '">' . esc_html__('Aktualisieren', 'rrze-multisite-manager') . '</a>';
        }

        $html .= '</p>';

        return $html;
    }

    protected function renderThemeActionsHtml(array $themeDetails): string {
        if (!$this->currentUserCanUseNetworkAdminFeatures()) {
            return '';
        }

        $stylesheet = (string)($themeDetails['stylesheet'] ?? '');
        $html = '<div class="rrze-msm-site-actions">';
        $networkThemesUrl = network_admin_url('themes.php');
        $siteCount = (int)($themeDetails['site_count'] ?? 0);

        $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-text" href="' . esc_url($networkThemesUrl) . '" title="' . esc_attr__('Theme-Verwaltung im Netzwerk öffnen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Theme-Verwaltung im Netzwerk öffnen', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Theme-Verwaltung im Netzwerk öffnen', 'rrze-multisite-manager') . '</span></a>';

        if ($stylesheet === '') {
            $html .= '</div>';
            return $html;
        }

        if (!empty($themeDetails['network_enabled'])) {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-warning rrze-msm-site-action-text" href="' . esc_url($this->getThemeNetworkDisableUrl($stylesheet)) . '" title="' . esc_attr__('Netzwerkfreigabe entziehen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Netzwerkfreigabe entziehen', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Netzwerkfreigabe entziehen', 'rrze-multisite-manager') . '</span></a>';
        } else {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-positive rrze-msm-site-action-text" href="' . esc_url($this->getThemeNetworkEnableUrl($stylesheet)) . '" title="' . esc_attr__('Netzwerkweit freigeben', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Netzwerkweit freigeben', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Netzwerkweit freigeben', 'rrze-multisite-manager') . '</span></a>';
        }

        if (empty($themeDetails['network_enabled']) && $siteCount === 0) {
            $html .= '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-site-action-text" href="' . esc_url($this->getThemeDeleteUrl($stylesheet)) . '" title="' . esc_attr__('Theme löschen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Theme löschen', 'rrze-multisite-manager') . '"><span class="rrze-msm-site-action-label">' . esc_html__('Theme löschen', 'rrze-multisite-manager') . '</span></a>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function getThemeNetworkEnableUrl(string $stylesheet): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'enable',
                    'theme' => $stylesheet,
                ],
                network_admin_url('themes.php')
            ),
            'enable-theme_' . $stylesheet
        );
    }

    protected function getThemeNetworkDisableUrl(string $stylesheet): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'disable',
                    'theme' => $stylesheet,
                ],
                network_admin_url('themes.php')
            ),
            'disable-theme_' . $stylesheet
        );
    }

    protected function getThemeDeleteUrl(string $stylesheet): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'delete-selected',
                    'checked[]' => $stylesheet,
                    'theme_status' => 'all',
                ],
                network_admin_url('themes.php')
            ),
            'bulk-themes'
        );
    }

    protected function getPluginCheckUrl(array $pluginDetails): string {
        $pluginFile = (string)($pluginDetails['file'] ?? '');
        $activeSites = is_array($pluginDetails['active_sites'] ?? null) ? $pluginDetails['active_sites'] : [];
        $firstSite = [];
        $siteId = 0;

        if ($pluginFile === '' || !$this->isPluginCheckAvailable() || empty($activeSites)) {
            return '';
        }

        $firstSite = (array)reset($activeSites);
        $siteId = (int)($firstSite['id'] ?? 0);

        if ($siteId <= 0) {
            return '';
        }

        return (string)add_query_arg(
            [
                'page' => 'plugin-check',
                'plugin' => $pluginFile,
            ],
            get_admin_url($siteId, 'tools.php')
        );
    }

    protected function isPluginCheckAvailable(): bool {
        return defined('WP_PLUGIN_DIR') && is_file(WP_PLUGIN_DIR . '/plugin-check/plugin.php');
    }

    protected function getStatusUserLabel(array $siteDetails): string {
        $statusUser = (array)($siteDetails['status_user'] ?? []);
        $displayName = trim((string)($statusUser['display_name'] ?? ''));
        $email = trim((string)($statusUser['email'] ?? ''));
        $userId = (int)($statusUser['id'] ?? 0);

        if ($displayName !== '' && $email !== '') {
            return $displayName . ' (' . $email . ')';
        }

        if ($displayName !== '') {
            return $displayName;
        }

        if ($email !== '') {
            return $email;
        }

        if ($userId > 0) {
            return sprintf(__('User-ID %d', 'rrze-multisite-manager'), $userId);
        }

        return __('Unbekannt', 'rrze-multisite-manager');
    }

    protected function formatStatusDate(string $dateValue): string {
        if ($dateValue === '' || $dateValue === '0000-00-00 00:00:00') {
            return __('Nicht gesetzt', 'rrze-multisite-manager');
        }

        return get_date_from_gmt($dateValue, get_option('date_format') . ' ' . get_option('time_format'));
    }

    protected function renderMetricsStatusNoticeHtml(array $status, string $returnUrl): string {
        $lastRunLabel = !empty($status['last_run_timestamp'])
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int)$status['last_run_timestamp'])
            : __('Noch nie', 'rrze-multisite-manager');
        $nextRunLabel = !empty($status['next_run_timestamp'])
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int)$status['next_run_timestamp'])
            : __('Noch nicht geplant', 'rrze-multisite-manager');
        $noticeClass = !empty($status['has_data']) ? 'notice-info' : 'notice-warning';
        $message = '';
        $html = '';

        if (empty($status['has_data'])) {
            $message = __('Für diese Ansicht liegen noch keine vorberechneten Kennzahlen vor. Die Daten werden erst im nächsten Metrics-Lauf des Schedulers erzeugt.', 'rrze-multisite-manager');
        } elseif (!empty($status['is_running'])) {
            $message = __('Die Kennzahlen werden gerade im Hintergrund aktualisiert. Bis zum Abschluss werden noch die zuletzt verfügbaren Daten angezeigt.', 'rrze-multisite-manager');
        } elseif (!empty($status['needs_refresh'])) {
            $message = __('Die angezeigten Kennzahlen sind veraltet. Eine Aktualisierung wurde für den nächsten Metrics-Lauf eingeplant.', 'rrze-multisite-manager');
        }

        if ($message === '') {
            return '';
        }

        $html .= '<div class="notice ' . esc_attr($noticeClass) . ' inline">';
        $html .= '<p>' . esc_html($message) . '</p>';
        $html .= '<p><strong>' . esc_html__('Letzter Metrics-Lauf:', 'rrze-multisite-manager') . '</strong> ' . esc_html($lastRunLabel) . '<br>';
        $html .= '<strong>' . esc_html__('Nächster Metrics-Lauf:', 'rrze-multisite-manager') . '</strong> ' . esc_html($nextRunLabel);

        if (!empty($status['batch_total'])) {
            $html .= '<br><strong>' . esc_html__('Batch-Fortschritt:', 'rrze-multisite-manager') . '</strong> '
                . esc_html(
                    sprintf(
                        __('%1$s von %2$s Websites', 'rrze-multisite-manager'),
                        number_format_i18n((int)($status['batch_offset'] ?? 0)),
                        number_format_i18n((int)($status['batch_total'] ?? 0))
                    )
                );
        }

        $html .= '</p>';

        if ($this->currentUserCanUseNetworkAdminFeatures()) {
            $html .= '<form method="post" action="' . esc_url($this->getAdminPostActionUrl('rrze_multisite_manager_refresh_metrics')) . '">';
            $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($returnUrl) . '">';
            $html .= wp_nonce_field('rrze_multisite_manager_refresh_metrics', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-secondary">' . esc_html__('Jetzt trotzdem aktualisieren', 'rrze-multisite-manager') . '</button>';
            $html .= '</form>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function getOperationalStatusOptions(): array {
        return [
            '' => __('Nicht gesetzt / automatisch', 'rrze-multisite-manager'),
            'provisioning' => __('Einrichtung läuft', 'rrze-multisite-manager'),
            'healthy' => __('Technisch erreichbar', 'rrze-multisite-manager'),
            'dns_missing' => __('DNS fehlt', 'rrze-multisite-manager'),
            'unreachable' => __('Technisch nicht erreichbar', 'rrze-multisite-manager'),
            'retired' => __('Außer Betrieb', 'rrze-multisite-manager'),
        ];
    }

    protected function renderSimpleMarkdown(string $markdown): string {
        $lines = explode("\n", str_replace("\r", '', $markdown));
        $html = '';
        $paragraph = [];
        $listItems = [];
        $inCodeBlock = false;
        $codeLines = [];
        $line = '';
        $trimmed = '';
        $ordered = false;
        $headingLevel = 0;
        $headingText = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                if ($inCodeBlock) {
                    $html .= '<pre class="rrze-msm-readme-code"><code>' . esc_html(implode("\n", $codeLines)) . '</code></pre>';
                    $codeLines = [];
                    $inCodeBlock = false;
                } else {
                    $html .= $this->flushMarkdownParagraph($paragraph);
                    $html .= $this->flushMarkdownList($listItems, $ordered);
                    $inCodeBlock = true;
                }

                continue;
            }

            if ($inCodeBlock) {
                $codeLines[] = $line;
                continue;
            }

            if ($trimmed === '') {
                $html .= $this->flushMarkdownParagraph($paragraph);
                $html .= $this->flushMarkdownList($listItems, $ordered);
                continue;
            }

            $headingLevel = $this->getMarkdownHeadingLevel($trimmed);

            if ($headingLevel > 0) {
                $html .= $this->flushMarkdownParagraph($paragraph);
                $html .= $this->flushMarkdownList($listItems, $ordered);
                $headingText = trim(substr($trimmed, $headingLevel));
                $html .= '<h' . $headingLevel . '>' . $this->formatInlineMarkdown($headingText) . '</h' . $headingLevel . '>';
                continue;
            }

            if ($this->isMarkdownListItem($trimmed, $ordered)) {
                $html .= $this->flushMarkdownParagraph($paragraph);
                $listItems[] = $this->stripMarkdownListMarker($trimmed);
                continue;
            }

            $html .= $this->flushMarkdownList($listItems, $ordered);
            $paragraph[] = $trimmed;
        }

        if ($inCodeBlock) {
            $html .= '<pre class="rrze-msm-readme-code"><code>' . esc_html(implode("\n", $codeLines)) . '</code></pre>';
        }

        $html .= $this->flushMarkdownParagraph($paragraph);
        $html .= $this->flushMarkdownList($listItems, $ordered);

        return $html;
    }

    protected function flushMarkdownParagraph(array &$paragraph): string {
        $content = trim(implode(' ', $paragraph));

        $paragraph = [];

        if ($content === '') {
            return '';
        }

        return '<p>' . $this->formatInlineMarkdown($content) . '</p>';
    }

    protected function flushMarkdownList(array &$listItems, bool &$ordered): string {
        $html = '';
        $item = '';

        if (empty($listItems)) {
            $ordered = false;
            return '';
        }

        $html .= $ordered ? '<ol>' : '<ul>';

        foreach ($listItems as $item) {
            $html .= '<li>' . $this->formatInlineMarkdown($item) . '</li>';
        }

        $html .= $ordered ? '</ol>' : '</ul>';
        $listItems = [];
        $ordered = false;

        return $html;
    }

    protected function getMarkdownHeadingLevel(string $line): int {
        $level = 0;

        if (!preg_match('/^(#{1,6})\s+.+$/', $line, $matches)) {
            return 0;
        }

        $level = strlen((string)($matches[1] ?? ''));

        return max(1, min(6, $level));
    }

    protected function isMarkdownListItem(string $line, bool &$ordered): bool {
        if (preg_match('/^[-*+]\s+.+$/', $line)) {
            $ordered = false;
            return true;
        }

        if (preg_match('/^\d+\.\s+.+$/', $line)) {
            $ordered = true;
            return true;
        }

        return false;
    }

    protected function stripMarkdownListMarker(string $line): string {
        if (preg_match('/^[-*+]\s+(.+)$/', $line, $matches)) {
            return trim((string)($matches[1] ?? ''));
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
            return trim((string)($matches[1] ?? ''));
        }

        return $line;
    }

    protected function formatInlineMarkdown(string $text): string {
        $text = esc_html($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);

        return (string)$text;
    }
}
