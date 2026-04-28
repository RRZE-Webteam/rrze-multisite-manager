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
        $siteOverviewSlug = (string)($menuSettings['site_overview_slug'] ?? 'rrze-multisite-manager-site-overview');
        $pluginOverviewSlug = (string)($menuSettings['plugin_overview_slug'] ?? 'rrze-multisite-manager-plugin-overview');
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
            __('Plugin-Übersicht', 'rrze-multisite-manager'),
            __('Plugin-Übersicht', 'rrze-multisite-manager'),
            $capability,
            $pluginOverviewSlug,
            [$this, 'renderPluginOverviewPage']
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
        $currentTab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'all';
        $summary = [];
        $tabs = [];
        $tabTables = [];

        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $widget = new SiteOverviewWidget($this->plugin, $this->config);
        $summary = is_array($dashboardData['summary'] ?? null) ? $dashboardData['summary'] : [];

        if (!in_array($currentTab, ['all', 'active', 'archived', 'blocked', 'deleted'], true)) {
            $currentTab = 'all';
        }

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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                ]
            ),
            'archived' => $widget->renderSiteOverviewTable(
                $dashboardData['archived_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-archived',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                ]
            ),
            'blocked' => $widget->renderSiteOverviewTable(
                $dashboardData['blocked_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-blocked',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                ]
            ),
            'deleted' => $widget->renderSiteOverviewTable(
                $dashboardData['deleted_sites'] ?? [],
                [
                    'table_id' => 'site-overview-page-deleted',
                    'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                    'sort_key' => 'registered',
                    'sort_direction' => 'desc',
                ]
            ),
        ];

        echo $this->template->render(
            'site-overview-page',
            [
                'site_overview_table' => $tabTables[$currentTab] ?? $tabTables['all'],
                'overview_tabs' => $tabs,
                'current_tab' => $currentTab,
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
        $siteWidget = null;
        $statusSections = [];
        $optionsOverview = is_array($siteDetails['options_overview'] ?? null) ? $siteDetails['options_overview'] : ['groups' => []];
        $optionsGroups = is_array($optionsOverview['groups'] ?? null) ? $optionsOverview['groups'] : [];
        $currentOptionsTab = isset($_GET['options_tab']) ? sanitize_key((string)$_GET['options_tab']) : '';
        $currentContentTab = isset($_GET['content_tab']) ? sanitize_key((string)$_GET['content_tab']) : 'overview';
        $currentProcessTab = isset($_GET['process_tab']) ? sanitize_key((string)$_GET['process_tab']) : 'stats';
        $validOptionTabs = [];
        $optionsGroup = [];
        $optionNotices = [];
        $contentNotices = [];
        $hasCustomPages = false;
        $customPostType = [];
        $customPages = [];
        $hasBlockTemplateTypes = !empty($siteDetails['block_template_types']) && is_array($siteDetails['block_template_types']);

        if (!current_user_can('manage_network_options')) {
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
                'site_actions' => !empty($siteDetails) ? $siteWidget->renderActionsForSite($siteDetails) : '',
                'site_plugins_url' => $siteId > 0 ? get_admin_url($siteId, 'plugins.php') : '',
                'site_themes_url' => $siteId > 0 ? get_admin_url($siteId, 'themes.php') : '',
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
                'site_users_url' => $siteId > 0 ? get_admin_url($siteId, 'users.php') : '',
                'site_user_edit_base_url' => $siteId > 0 ? get_admin_url($siteId, 'user-edit.php') : '',
                'site_options_groups' => $optionsGroups,
                'site_options_current_tab' => $currentOptionsTab,
                'site_option_delete_action' => network_admin_url('edit.php?action=rrze_multisite_manager_delete_site_option'),
                'site_option_group_delete_action' => network_admin_url('edit.php?action=rrze_multisite_manager_delete_site_option_group'),
                'site_options_notice_messages' => $optionNotices,
                'site_content_current_tab' => $currentContentTab,
                'site_process_current_tab' => $currentProcessTab,
                'site_custom_pages' => $customPages,
                'site_content_notice_messages' => $contentNotices,
                'site_post_type_delete_action' => network_admin_url('edit.php?action=rrze_multisite_manager_delete_post_type_entries'),
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

        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'rrze-multisite-manager'));
        }

        $dashboardData = $this->metrics->getDashboardData();
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];
        $allPlugins = is_array($pluginUsage['plugins'] ?? null) ? $pluginUsage['plugins'] : [];
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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                    network_admin_url('admin.php')
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
                ]
            ),
        ];

        echo $this->template->render(
            'plugin-overview-page',
            [
                'plugin_overview_tabs' => $tabs,
                'current_tab' => $currentTab,
                'plugin_overview_table' => $tables[$currentTab] ?? $tables['all'],
                'mode_class' => 'rrze-msm-mode-' . $this->getColorMode(),
                'mode_toggle_label' => $this->getModeToggleLabel(),
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
                    ? __('Archivieren', 'rrze-multisite-manager')
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
        $isDeleted = false;
        $redirectUrl = $this->getSiteOverviewUrl();

        if (!current_user_can('manage_network_options')) {
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
            network_admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function handleSiteOptionDelete(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $optionName = isset($_POST['option_name']) ? sanitize_text_field((string)wp_unslash($_POST['option_name'])) : '';
        $optionsTab = isset($_POST['options_tab']) ? sanitize_key((string)wp_unslash($_POST['options_tab'])) : 'all';
        $redirectUrl = $this->getSiteDetailsUrl($siteId);

        if (!current_user_can('manage_network_options')) {
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

    public function handleSiteOptionGroupDelete(): void {
        $siteId = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $groupKey = isset($_POST['group_key']) ? sanitize_key((string)wp_unslash($_POST['group_key'])) : '';
        $redirectUrl = $this->getSiteDetailsUrl($siteId);
        $deletedCount = 0;

        if (!current_user_can('manage_network_options')) {
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

        if (!current_user_can('manage_network_options')) {
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
