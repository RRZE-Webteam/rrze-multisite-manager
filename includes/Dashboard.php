<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

use RRZE\MultisiteManager\Widgets\ArchivedSitesWidget;
use RRZE\MultisiteManager\Widgets\BlockedSitesWidget;
use RRZE\MultisiteManager\Widgets\DeletedSitesWidget;
use RRZE\MultisiteManager\Widgets\EditorUsageWidget;
use RRZE\MultisiteManager\Widgets\GrowthWidget;
use RRZE\MultisiteManager\Widgets\InactiveSitesWidget;
use RRZE\MultisiteManager\Widgets\PluginUsageWidget;
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

        add_action('network_admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('admin_body_class', [$this, 'filterAdminBodyClass']);
        add_action('wp_ajax_rrze_msm_save_widget_order', [$this, 'ajaxSaveWidgetOrder']);
        add_action('wp_ajax_rrze_msm_search_sites', [$this, 'ajaxSearchSites']);
        add_action('network_admin_edit_rrze_multisite_manager_save_views', [$this, 'saveViews']);
        add_action('network_admin_edit_rrze_multisite_manager_site_status', [$this, 'handleSiteStatusAction']);
    }

    public function registerMenu(): void {
        $menuSettings = $this->config->getMenuSettings();
        $pageTitle = (string)($menuSettings['page_title'] ?? __('RRZE Multisite Manager', 'rrze-multisite-manager'));
        $menuTitle = (string)($menuSettings['menu_title'] ?? __('Multisite Manager', 'rrze-multisite-manager'));
        $capability = (string)($menuSettings['capability'] ?? 'manage_network_options');
        $parentSlug = (string)($menuSettings['parent_slug'] ?? 'rrze-multisite-manager-dashboard');
        $dashboardSlug = (string)($menuSettings['dashboard_slug'] ?? 'rrze-multisite-manager-dashboard');
        $siteOverviewSlug = (string)($menuSettings['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview');
        $siteDetailsSlug = (string)($menuSettings['site_details_slug'] ?? 'rrze-multisite-manager-site-details');
        $siteStatusSlug = (string)($menuSettings['site_status_slug'] ?? 'rrze-multisite-manager-site-status');
        $viewsSlug = (string)($menuSettings['views_slug'] ?? 'rrze-multisite-manager-views');
        $settingsSlug = $this->settings->getSettingsSlug();

        $this->pageHooks[] = add_menu_page(
            $pageTitle,
            $menuTitle,
            $capability,
            $parentSlug,
            [$this, 'renderDashboardPage'],
            'dashicons-chart-area',
            3
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Dashboard', 'rrze-multisite-manager'),
            __('Dashboard', 'rrze-multisite-manager'),
            $capability,
            $dashboardSlug,
            [$this, 'renderDashboardPage']
        );

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
            null,
            __('Site-Status ändern', 'rrze-multisite-manager'),
            __('Site-Status ändern', 'rrze-multisite-manager'),
            $capability,
            $siteStatusSlug,
            [$this, 'renderSiteStatusPage']
        );

        $this->pageHooks[] = add_submenu_page(
            $parentSlug,
            __('Ansichten', 'rrze-multisite-manager'),
            __('Ansichten', 'rrze-multisite-manager'),
            $capability,
            $viewsSlug,
            [$this, 'renderViewsPage']
        );

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
                'currentView' => $this->getCurrentViewSlug(),
                'currentMode' => $this->getColorMode(),
                'lightModeLabel' => __('Light Mode', 'rrze-multisite-manager'),
                'darkModeLabel' => __('Dark Mode', 'rrze-multisite-manager'),
                'siteDetailsBaseUrl' => $this->getSiteDetailsUrl(),
                'siteSearchMinLength' => 2,
                'siteSearchNoResults' => __('Keine Websites gefunden.', 'rrze-multisite-manager'),
            ]
        );
    }

    public function filterAdminBodyClass(string $classes): string {
        return trim($classes . ' rrze-msm-admin rrze-msm-mode-' . $this->getColorMode());
    }

    public function renderDashboardPage(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $widgets = $this->getWidgetInstances();
        $views = $this->viewManager->getViews(array_keys($widgets));
        $currentView = $this->viewManager->getCurrentView($views, 'default');
        $selectedWidgets = $this->sortWidgetsForUser(
            $this->filterWidgetsForView($widgets, $currentView['widgets']),
            (string)$currentView['slug']
        );
        $widgetMarkup = [];
        $widget = null;

        foreach ($selectedWidgets as $widget) {
            $widgetMarkup[] = $widget->render($this->template, $dashboardData);
        }

        echo $this->template->render(
            'dashboard-page',
            [
                'views' => $views,
                'current_view_slug' => (string)$currentView['slug'],
                'current_view_label' => (string)$currentView['label'],
                'views_url' => network_admin_url('admin.php?page=' . $this->viewManager->getViewsSlug()),
                'widget_markup' => $widgetMarkup,
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
            ],
            $this
        );
    }

    public function renderViewsPage(): void {
        if (!current_user_can('manage_network_options')) {
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
                'form_action' => network_admin_url('edit.php?action=rrze_multisite_manager_save_views'),
                'updated' => !empty($_GET['updated']),
            ],
            $this
        );
    }

    public function renderSiteOverviewPage(): void {
        $dashboardData = [];
        $widget = null;

        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $widget = new SiteOverviewWidget($this->plugin, $this->config);

        echo $this->template->render(
            'site-overview-page',
            [
                'site_overview_table' => $widget->renderTable($dashboardData),
                'status_updated' => !empty($_GET['status-updated']),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
            ],
            $this
        );
    }

    public function renderSiteDetailsPage(): void {
        $siteId = isset($_GET['site_id']) ? absint($_GET['site_id']) : 0;
        $siteDetails = $siteId > 0 ? $this->metrics->getSiteDetails($siteId) : [];

        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        echo $this->template->render(
            'site-details-page',
            [
                'site_id' => $siteId,
                'site_details' => $siteDetails,
                'site_search_placeholder' => __('Website nach Titel oder URL suchen', 'rrze-multisite-manager'),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'status_badges_html' => !empty($siteDetails['status']) && is_array($siteDetails['status'])
                    ? $this->renderStatusBadgesHtml($siteDetails['status'])
                    : '',
                'status_user_label' => $this->getStatusUserLabel($siteDetails),
                'archived_at_label' => $this->formatStatusDate((string)($siteDetails['archived_at'] ?? '')),
                'blocked_at_label' => $this->formatStatusDate((string)($siteDetails['spam_at'] ?? '')),
            ],
            $this
        );
    }

    public function renderSiteStatusPage(): void {
        $siteId = isset($_GET['site_id']) ? absint($_GET['site_id']) : 0;
        $statusAction = isset($_GET['status_action']) ? sanitize_key((string)$_GET['status_action']) : '';
        $site = $siteId > 0 ? get_site($siteId) : null;
        $siteName = '';
        $currentNote = '';
        $allowedActions = ['archive', 'spam'];

        if (!current_user_can('manage_network_options')) {
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
                    ? __('Deaktivieren', 'rrze-multisite-manager')
                    : __('Sperren', 'rrze-multisite-manager'),
                'status_action_description' => $statusAction === 'archive'
                    ? __('Setzt den Site-Status auf archiviert.', 'rrze-multisite-manager')
                    : __('Setzt den Site-Status auf gesperrt.', 'rrze-multisite-manager'),
                'current_note' => $currentNote,
                'form_action' => network_admin_url('edit.php?action=rrze_multisite_manager_site_status'),
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
                'redirect_url' => $this->getSiteOverviewUrl(),
            ],
            $this
        );
    }

    public function saveViews(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage these views.', 'rrze-multisite-manager'));
        }

        check_admin_referer('rrze_multisite_manager_save_views');

        $widgets = $this->getWidgetInstances();
        $submittedViews = $_POST['views'] ?? [];
        $newViewName = isset($_POST['new_view_name']) ? sanitize_text_field((string)$_POST['new_view_name']) : '';

        $this->viewManager->saveViews($submittedViews, array_keys($widgets), $newViewName);

        $redirectUrl = add_query_arg(
            [
                'page' => $this->viewManager->getViewsSlug(),
                'updated' => 'true',
            ],
            network_admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function ajaxSaveWidgetOrder(): void {
        if (!current_user_can('manage_network_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('rrze-msm-save-widget-order', 'nonce');

        $view = isset($_POST['view']) ? sanitize_key((string)$_POST['view']) : 'default';
        $order = isset($_POST['order']) && is_array($_POST['order']) ? $_POST['order'] : [];
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

        if (!current_user_can('manage_network_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('rrze-msm-search-sites', 'nonce');

        wp_send_json_success(
            [
                'results' => $this->metrics->searchSites($searchTerm, 20),
            ]
        );
    }

    public function handleSiteStatusAction(): void {
        $siteId = isset($_REQUEST['site_id']) ? absint($_REQUEST['site_id']) : 0;
        $statusAction = isset($_REQUEST['status_action']) ? sanitize_key((string)$_REQUEST['status_action']) : '';
        $note = isset($_POST['status_note']) ? sanitize_textarea_field((string)wp_unslash($_POST['status_note'])) : '';
        $site = $siteId > 0 ? get_site($siteId) : null;
        $isArchived = false;
        $isSpam = false;
        $redirectUrl = $this->getSiteOverviewUrl();

        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage site statuses.', 'rrze-multisite-manager'));
        }

        if (!$site instanceof \WP_Site) {
            wp_die(esc_html__('Ungültige Site.', 'rrze-multisite-manager'));
        }

        $isArchived = ((int)$site->archived === 1);
        $isSpam = ((int)$site->spam === 1);

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
            if (!$isArchived && !$isSpam) {
                wp_die(esc_html__('Nur archivierte oder gesperrte Sites können wiederhergestellt werden.', 'rrze-multisite-manager'));
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
            network_admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function maybeRefreshMetricsCache(): bool {
        $nonce = isset($_POST['rrze_msm_refresh_nonce']) ? sanitize_text_field((string)wp_unslash($_POST['rrze_msm_refresh_nonce'])) : '';
        $refresh = isset($_POST['rrze_msm_refresh']) ? sanitize_text_field((string)wp_unslash($_POST['rrze_msm_refresh'])) : '';

        if ($refresh !== '1') {
            return false;
        }

        if (!wp_verify_nonce($nonce, 'rrze-msm-refresh')) {
            return false;
        }

        $this->metrics->clearCache();
        return true;
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
            network_admin_url('admin.php')
        );
    }

    public function getStatusActionSubmitUrl(int $siteId, string $statusAction): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'rrze_multisite_manager_site_status',
                    'site_id' => $siteId,
                    'status_action' => $statusAction,
                ],
                network_admin_url('edit.php')
            ),
            'rrze_multisite_manager_site_status_' . $statusAction . '_' . $siteId
        );
    }

    public function getSiteOverviewUrl(): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview'),
            ],
            network_admin_url('admin.php')
        );
    }

    public function getSiteDetailsUrl(int $siteId = 0): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['site_details_slug'] ?? 'rrze-multisite-manager-site-details'),
        ];

        if ($siteId > 0) {
            $args['site_id'] = $siteId;
        }

        return add_query_arg($args, network_admin_url('admin.php'));
    }

    protected function getWidgetInstances(): array {
        return [
            'summary' => new SummaryWidget($this->plugin, $this->config),
            'status' => new StatusWidget($this->plugin, $this->config),
            'growth' => new GrowthWidget($this->plugin, $this->config),
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
}
