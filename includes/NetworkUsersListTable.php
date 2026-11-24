<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

/**
 * Extends the WordPress network user table with a view of users without
 * website membership and a view of orphaned accounts.
 *
 * Orphaned accounts have no website membership, were registered before a
 * cut-off date and have neither a Wordfence login record nor an activity
 * record of the former rrze-netzwerk-audit plugin after a second cut-off
 * date. Users without any recorded login or activity count as orphaned once
 * they are old enough. The view is offered while Wordfence is active or
 * legacy activity records exist.
 */
class NetworkUsersListTable extends \WP_MS_Users_List_Table {
    public const FILTER_ALL = 'all';
    public const FILTER_WITHOUT_MEMBERSHIP = 'without-site-membership';
    public const FILTER_ORPHANED = 'orphaned';
    public const PER_PAGE_PARAMETER = 'rrze_msm_users_per_page';
    public const DEFAULT_PER_PAGE = 10;

    /**
     * User meta keys in which Wordfence Login Security may store the last
     * login. Both spellings are accepted; the most recent value counts.
     */
    public const LAST_LOGIN_META_KEYS = ['wfls_last_login', 'wfls-last-login'];

    /**
     * User meta key of the activity log of the former rrze-netzwerk-audit
     * plugin (removed there in 2026). The value is a serialized array whose
     * "timestamp" element holds the Unix time of the last admin screen view
     * or logout. Recording ended when that feature was removed, so the value
     * can only prove activity up to that point.
     */
    public const LEGACY_ACTIVITY_META_KEY = 'rrze_users_activity';

    /** Serialized prefix of the "timestamp" element inside the legacy value. */
    protected const LEGACY_ACTIVITY_TIMESTAMP_TOKEN = 's:9:"timestamp";i:';

    private string $filter;
    private int $loginCutoff;
    private string $registeredCutoff;
    private bool $membershipFilterApplied = false;
    private int $perPage;
    private int $defaultPerPage;

    /**
     * @param int $loginCutoff Unix timestamp; a login at or after it keeps an account active
     * @param string $registeredCutoff UTC date (Y-m-d H:i:s); only accounts registered before it can be orphaned
     */
    public function __construct(string $filter = self::FILTER_ALL, int $loginCutoff = 0, string $registeredCutoff = '', int $defaultPerPage = self::DEFAULT_PER_PAGE) {
        $this->filter = in_array($filter, [self::FILTER_WITHOUT_MEMBERSHIP, self::FILTER_ORPHANED], true) ? $filter : self::FILTER_ALL;
        $this->loginCutoff = $loginCutoff;
        $this->registeredCutoff = $registeredCutoff;
        $this->defaultPerPage = max(1, $defaultPerPage);
        $this->perPage = $this->getRequestedPerPage();

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        parent::__construct([
            'screen' => $screen instanceof \WP_Screen ? $screen : 'users-network',
        ]);
    }

    public function getPerPage(): int {
        return $this->perPage;
    }

    public function get_items_per_page($option, $default = 20) {
        if ($option === 'users_network_per_page') {
            return $this->perPage;
        }

        return parent::get_items_per_page($option, $default);
    }

    protected function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }

        echo '<div class="alignright actions rrze-msm-network-users-per-page">';
        echo '<label class="screen-reader-text" for="rrze-msm-network-users-per-page">' . esc_html__('Show:', 'rrze-multisite-manager') . '</label>';
        echo '<select class="rrze-msm-network-users-per-page" id="rrze-msm-network-users-per-page" name="' . esc_attr(self::PER_PAGE_PARAMETER) . '">';

        foreach ($this->getPerPageOptions() as $option) {
            echo '<option value="' . esc_attr((string)$option) . '"' . selected($option, $this->perPage, false) . '>';
            echo $option === $this->defaultPerPage
                ? esc_html(sprintf(__('Default (%d)', 'rrze-multisite-manager'), $option))
                : esc_html((string)$option);
            echo '</option>';
        }

        echo '</select>';
        echo '<button type="button" class="button rrze-msm-network-users-per-page-apply">' . esc_html__('Apply', 'rrze-multisite-manager') . '</button>';
        echo '</div>';
    }

    /** The list/grid switch does not apply to the network user table. */
    public function view_switcher($currentMode) {
        unset($currentMode);
    }

    private function getRequestedPerPage(): int {
        $perPage = isset($_GET[self::PER_PAGE_PARAMETER])
            ? absint(wp_unslash($_GET[self::PER_PAGE_PARAMETER]))
            : $this->defaultPerPage;

        return in_array($perPage, $this->getPerPageOptions(), true) ? $perPage : $this->defaultPerPage;
    }

    /** @return int[] */
    private function getPerPageOptions(): array {
        return array_values(array_unique([$this->defaultPerPage, 10, 30, 50, 100]));
    }

    public function prepare_items() {
        add_action('pre_user_query', [$this, 'sortUserQuery'], PHP_INT_MAX);

        if ($this->filter !== self::FILTER_ALL) {
            add_action('pre_user_query', [$this, 'restrictUserQuery']);
        }

        try {
            parent::prepare_items();
        } finally {
            remove_action('pre_user_query', [$this, 'sortUserQuery'], PHP_INT_MAX);

            if ($this->filter !== self::FILTER_ALL) {
                remove_action('pre_user_query', [$this, 'restrictUserQuery']);
            }
        }

        $this->setVisibleColumnHeaders();
    }

    /** Sorts MSM user-table columns that differ from the Core query defaults. */
    public function sortUserQuery(\WP_User_Query $query): void {
        global $wpdb;

        $orderby = isset($_GET['orderby'])
            ? sanitize_key((string)wp_unslash($_GET['orderby']))
            : (string)$query->get('orderby');
        $requestedOrder = isset($_GET['order']) ? sanitize_key((string)wp_unslash($_GET['order'])) : '';
        $order = $requestedOrder === 'desc' ? 'DESC' : 'ASC';

        if (in_array($orderby, ['name', 'display_name'], true)) {
            if (!str_contains($query->query_from, 'rrze_msm_first_name_meta')) {
                $query->query_from .= $wpdb->prepare(
                    " LEFT JOIN {$wpdb->usermeta} AS rrze_msm_first_name_meta ON (rrze_msm_first_name_meta.user_id = {$wpdb->users}.ID AND rrze_msm_first_name_meta.meta_key = %s) LEFT JOIN {$wpdb->usermeta} AS rrze_msm_last_name_meta ON (rrze_msm_last_name_meta.user_id = {$wpdb->users}.ID AND rrze_msm_last_name_meta.meta_key = %s)",
                    'first_name',
                    'last_name'
                );
            }

            $nameSortValue = "CASE WHEN rrze_msm_first_name_meta.meta_value <> '' THEN rrze_msm_first_name_meta.meta_value WHEN rrze_msm_last_name_meta.meta_value <> '' THEN rrze_msm_last_name_meta.meta_value ELSE '-' END";
            $query->query_orderby = "ORDER BY {$nameSortValue} {$order}, COALESCE(rrze_msm_last_name_meta.meta_value, '') {$order}, {$wpdb->users}.user_login ASC";
            return;
        }

        if ($orderby !== 'rrze_last_login') {
            return;
        }

        $alias = str_contains($query->query_from, 'rrze_last_login_meta')
            ? 'rrze_last_login_meta'
            : 'rrze_msm_last_login_meta';

        if (!str_contains($query->query_from, $alias)) {
            $query->query_from .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->usermeta} AS {$alias} ON ({$alias}.user_id = {$wpdb->users}.ID AND {$alias}.meta_key = %s)",
                'rrze_last_login'
            );
        }

        if (!str_contains($query->query_from, 'rrze_msm_legacy_login_meta')) {
            $query->query_from .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->usermeta} AS rrze_msm_legacy_login_meta ON (rrze_msm_legacy_login_meta.user_id = {$wpdb->users}.ID AND rrze_msm_legacy_login_meta.meta_key = %s) LEFT JOIN {$wpdb->usermeta} AS rrze_msm_wordfence_login_meta ON (rrze_msm_wordfence_login_meta.user_id = {$wpdb->users}.ID AND rrze_msm_wordfence_login_meta.meta_key = %s) LEFT JOIN {$wpdb->usermeta} AS rrze_msm_wordfence_hyphen_login_meta ON (rrze_msm_wordfence_hyphen_login_meta.user_id = {$wpdb->users}.ID AND rrze_msm_wordfence_hyphen_login_meta.meta_key = %s) LEFT JOIN {$wpdb->usermeta} AS rrze_msm_wordfence_legacy_login_meta ON (rrze_msm_wordfence_legacy_login_meta.user_id = {$wpdb->users}.ID AND rrze_msm_wordfence_legacy_login_meta.meta_key = %s)",
                self::LEGACY_ACTIVITY_META_KEY,
                'wfls_last_login',
                'wfls-last-login',
                'wfls_lastlogin'
            );
        }

        $wordfenceTimestamp = "CASE WHEN %1\$s.meta_value REGEXP '^[0-9]+$' THEN CAST(%1\$s.meta_value AS UNSIGNED) ELSE COALESCE(UNIX_TIMESTAMP(%1\$s.meta_value), 0) END";
        $legacyTimestampToken = self::LEGACY_ACTIVITY_TIMESTAMP_TOKEN;
        $loginTimestamp = "COALESCE(NULLIF(CAST({$alias}.meta_value AS UNSIGNED), 0), NULLIF(GREATEST(COALESCE(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(rrze_msm_legacy_login_meta.meta_value, '{$legacyTimestampToken}', -1), ';', 1) AS UNSIGNED), 0), "
            . sprintf($wordfenceTimestamp, 'rrze_msm_wordfence_login_meta') . ', '
            . sprintf($wordfenceTimestamp, 'rrze_msm_wordfence_hyphen_login_meta') . ', '
            . sprintf($wordfenceTimestamp, 'rrze_msm_wordfence_legacy_login_meta')
            . '), 0), 0)';
        $query->query_orderby = "ORDER BY {$loginTimestamp} {$order}, {$wpdb->users}.user_login ASC";
    }

    /** Ignore obsolete per-user column visibility settings for this table. */
    private function setVisibleColumnHeaders(): void {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'username'];
    }

    /**
     * Limits the Core user query to users without a role on any website and,
     * for orphaned accounts, additionally by registration date and last
     * login. Usermeta is indexed by user ID, so every condition remains a
     * bounded lookup per user instead of iterating over every website.
     */
    public function restrictUserQuery(\WP_User_Query $query): void {
        global $wpdb;

        if ($this->membershipFilterApplied) {
            return;
        }

        $this->membershipFilterApplied = true;
        $query->query_where .= ' AND ' . self::getWithoutMembershipCondition("{$wpdb->users}.ID", "{$wpdb->users}.user_login");

        if ($this->filter === self::FILTER_ORPHANED) {
            $query->query_where .= ' AND ' . self::getOrphanedCondition("{$wpdb->users}.ID", "{$wpdb->users}.user_registered", $this->loginCutoff, $this->registeredCutoff);
        }
    }

    /** Returns the current number of users without a role on any website. */
    public static function getUsersWithoutWebsiteMembershipCount(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The condition is built with $wpdb->prepare(); table names are trusted identifiers.
        $count = $wpdb->get_var(
            "SELECT COUNT(1) FROM {$wpdb->users} AS rrze_msm_users WHERE " . self::getWithoutMembershipCondition('rrze_msm_users.ID', 'rrze_msm_users.user_login')
        );

        return absint($count);
    }

    /** Returns the current number of orphaned accounts. */
    public static function getOrphanedUsersCount(int $loginCutoff, string $registeredCutoff): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The conditions are built with $wpdb->prepare(); table names are trusted identifiers.
        $count = $wpdb->get_var(
            "SELECT COUNT(1) FROM {$wpdb->users} AS rrze_msm_users WHERE "
            . self::getWithoutMembershipCondition('rrze_msm_users.ID', 'rrze_msm_users.user_login')
            . ' AND ' . self::getOrphanedCondition('rrze_msm_users.ID', 'rrze_msm_users.user_registered', $loginCutoff, $registeredCutoff)
        );

        return absint($count);
    }

    /**
     * Number of users with a legacy rrze-netzwerk-audit activity record and
     * the newest recorded timestamp. Both are 0 when no such data exists,
     * e.g. after the legacy cleanup of rrze-netzwerk-audit has run.
     *
     * @return array{count: int, latest: int}
     */
    public static function getLegacyActivityInfo(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is a trusted identifier; the caller caches the result.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(1) AS records, MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(meta_value, %s, -1), ';', 1) AS UNSIGNED)) AS latest FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
                self::LEGACY_ACTIVITY_TIMESTAMP_TOKEN,
                self::LEGACY_ACTIVITY_META_KEY,
                '%' . $wpdb->esc_like(self::LEGACY_ACTIVITY_TIMESTAMP_TOKEN) . '%'
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return ['count' => 0, 'latest' => 0];
        }

        return [
            'count' => absint($row['records'] ?? 0),
            'latest' => absint($row['latest'] ?? 0),
        ];
    }

    /**
     * SQL condition: the user has no capabilities entry on any website and
     * is not a super admin. Super admins manage the network without a
     * website role and must not appear as unassigned or orphaned.
     */
    protected static function getWithoutMembershipCondition(string $userIdColumn, string $loginColumn): string {
        global $wpdb;
        $superAdmins = function_exists('get_super_admins') ? array_values(array_filter(array_map('strval', (array)get_super_admins()))) : [];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column and table names are trusted identifiers.
        $condition = $wpdb->prepare(
            "NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_site_membership WHERE rrze_msm_site_membership.user_id = {$userIdColumn} AND rrze_msm_site_membership.meta_key LIKE %s)",
            $wpdb->esc_like($wpdb->base_prefix) . '%capabilities'
        );

        if (!empty($superAdmins)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Column name is a trusted identifier; one placeholder per super admin login.
            $condition .= $wpdb->prepare(
                " AND {$loginColumn} NOT IN (" . implode(', ', array_fill(0, count($superAdmins), '%s')) . ')',
                $superAdmins
            );
        }

        return $condition;
    }

    /**
     * SQL condition: registered before the registration cut-off, no recorded
     * Wordfence login and no legacy rrze-netzwerk-audit activity at or after
     * the login cut-off. A Wordfence value is read as a Unix timestamp when
     * it is numeric and as a date string otherwise; the legacy value is a
     * serialized array whose "timestamp" element is extracted in SQL. Both
     * sources are always checked: each can only keep an account active, so
     * an additional source never adds accounts to the orphaned list.
     */
    protected static function getOrphanedCondition(string $userIdColumn, string $registeredColumn, int $loginCutoff, string $registeredCutoff): string {
        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count(self::LAST_LOGIN_META_KEYS), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Column and table names are trusted identifiers; the placeholder list matches LAST_LOGIN_META_KEYS.
        $condition = $wpdb->prepare(
            "{$registeredColumn} < %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_last_login WHERE rrze_msm_last_login.user_id = {$userIdColumn} AND rrze_msm_last_login.meta_key IN ({$placeholders}) AND ((rrze_msm_last_login.meta_value REGEXP '^[0-9]+$' AND CAST(rrze_msm_last_login.meta_value AS UNSIGNED) >= %d) OR (rrze_msm_last_login.meta_value NOT REGEXP '^[0-9]+$' AND rrze_msm_last_login.meta_value >= %s)))",
            array_merge(
                [$registeredCutoff],
                self::LAST_LOGIN_META_KEYS,
                [$loginCutoff, gmdate('Y-m-d H:i:s', $loginCutoff)]
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column and table names are trusted identifiers.
        $condition .= $wpdb->prepare(
            " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_legacy_activity WHERE rrze_msm_legacy_activity.user_id = {$userIdColumn} AND rrze_msm_legacy_activity.meta_key = %s AND rrze_msm_legacy_activity.meta_value LIKE %s AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(rrze_msm_legacy_activity.meta_value, %s, -1), ';', 1) AS UNSIGNED) >= %d)",
            self::LEGACY_ACTIVITY_META_KEY,
            '%' . $wpdb->esc_like(self::LEGACY_ACTIVITY_TIMESTAMP_TOKEN) . '%',
            self::LEGACY_ACTIVITY_TIMESTAMP_TOKEN,
            $loginCutoff
        );

        return $condition;
    }

    /** Adds the MSM table class so light and dark mode colours apply. */
    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'rrze-msm-table';

        return $classes;
    }

    /** Uses the Core network-user deletion workflow and confirmation page. */
    protected function get_bulk_actions() {
        if (!current_user_can('delete_users')) {
            return [];
        }

        return [
            'delete' => __('Delete', 'rrze-multisite-manager'),
        ];
    }

    /**
     * Uses a namespaced field name so admin-post.php can reliably dispatch to
     * the MSM handler without colliding with WordPress's Core action field.
     */
    protected function bulk_actions($which = '') {
        if (!current_user_can('delete_users')) {
            return;
        }

        $suffix = $which === 'bottom' ? '2' : '';
        $fieldName = 'rrze_msm_bulk_action' . $suffix;

        echo '<label for="rrze-msm-bulk-action-selector-' . esc_attr($which) . '" class="screen-reader-text">';
        echo esc_html__('Select bulk action', 'rrze-multisite-manager');
        echo '</label>';
        echo '<select name="' . esc_attr($fieldName) . '" id="rrze-msm-bulk-action-selector-' . esc_attr($which) . '">';
        echo '<option value="-1">' . esc_html__('Bulk actions', 'rrze-multisite-manager') . '</option>';
        echo '<option value="delete">' . esc_html__('Delete', 'rrze-multisite-manager') . '</option>';
        echo '</select>';
        submit_button(__('Apply', 'rrze-multisite-manager'), 'action compact', 'rrze_msm_bulk_apply', false, [
            'id' => 'rrze-msm-doaction' . $suffix,
        ]);
    }

    public function get_columns() {
        $columns = parent::get_columns();

        // Network Admin Core applies wpmu_users_columns. Plugins can add
        // columns to either the regular or the network-specific screen.
        $columns = apply_filters('manage_users_columns', $columns);
        $columns = apply_filters('manage_users-network_columns', $columns);

        // This is an MSM-specific overview, not a copy of Network Admin's
        // user table. Keep only the Core columns needed here and the single
        // consolidated last-login column supplied by RRZE Settings.
        $columns = array_intersect_key(
            $columns,
            array_flip(['cb', 'username', 'name', 'email', 'registered', 'rrze_last_login', 'blogs'])
        );

        if (isset($columns['registered'], $columns['rrze_last_login'])) {
            $lastLoginColumn = $columns['rrze_last_login'];
            unset($columns['rrze_last_login']);
            $orderedColumns = [];

            foreach ($columns as $columnKey => $columnLabel) {
                $orderedColumns[$columnKey] = $columnLabel;

                if ($columnKey === 'registered') {
                    $orderedColumns['rrze_last_login'] = $lastLoginColumn;
                }
            }

            $columns = $orderedColumns;
        }

        if (!current_user_can('delete_users')) {
            unset($columns['cb']);
        }

        return $columns;
    }

    public function get_sortable_columns() {
        $columns = parent::get_sortable_columns();
        $visibleColumns = $this->get_columns();

        if (isset($visibleColumns['rrze_last_login'])) {
            $columns['rrze_last_login'] = ['rrze_last_login', true];
        }

        return $columns;
    }

    /** Renders the registration date in the compact MSM table format. */
    public function column_registered($user) {
        $registered = is_object($user) && isset($user->user_registered) ? (string)$user->user_registered : '';

        if ($registered === '') {
            echo '&mdash;';
            return;
        }

        echo esc_html(mysql2date('d.m.Y', $registered, true));
    }

    /** Renders the consolidated RRZE Settings login timestamp without relative time. */
    public function column_rrze_last_login($user) {
        $userId = is_object($user) && isset($user->ID) ? (int)$user->ID : 0;
        $timestamp = class_exists('\\RRZE\\Settings\\Users\\LastLogin')
            ? \RRZE\Settings\Users\LastLogin::get($userId)
            : 0;

        if ($timestamp <= 0) {
            echo '&mdash;';
            return;
        }

        echo esc_html(wp_date('d.m.Y', $timestamp));
    }

    /** Adds the user's WordPress role directly to each website assignment. */
    public function column_blogs($user) {
        $blogs = get_blogs_of_user((int)$user->ID, true);

        if (!is_array($blogs)) {
            return;
        }

        foreach ($blogs as $site) {
            if (!can_edit_network((int)$site->site_id)) {
                continue;
            }

            $siteId = (int)$site->userblog_id;
            $path = $site->path === '/' ? '' : $site->path;
            $siteClasses = ['site-' . (int)$site->site_id];
            $siteClasses = apply_filters('ms_user_list_site_class', $siteClasses, $siteId, (int)$site->site_id, $user);
            $siteClasses = is_array($siteClasses) && !empty($siteClasses)
                ? array_map('sanitize_html_class', array_unique($siteClasses))
                : [];
            $siteLabel = str_replace('.' . get_network()->domain, '', $site->domain . $path);
            $roleLabels = $this->getSiteRoleLabels((int)$user->ID, $siteId);
            $actions = [
                'edit' => '<a href="' . esc_url(network_admin_url('site-info.php?id=' . $siteId)) . '">' . esc_html__('Edit', 'rrze-multisite-manager') . '</a>',
                'view' => '<a href="' . esc_url(get_home_url($siteId)) . '">' . esc_html__('View', 'rrze-multisite-manager') . '</a>',
            ];

            $actions = apply_filters('ms_user_list_site_actions', $actions, $siteId);

            echo '<span' . (!empty($siteClasses) ? ' class="' . esc_attr(implode(' ', $siteClasses)) . '"' : '') . '>';
            echo '<a href="' . esc_url(network_admin_url('site-info.php?id=' . $siteId)) . '">' . esc_html($siteLabel) . '</a>';

            if (!empty($roleLabels)) {
                echo ' <span class="rrze-msm-network-user-site-roles">' . esc_html(implode(', ', $roleLabels)) . '</span>';
            }

            echo ' <small class="row-actions">';
            $actionCount = count($actions);
            $index = 0;

            foreach ($actions as $action => $link) {
                $index++;
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered Core action markup.
                echo '<span class="' . esc_attr((string)$action) . '">' . $link . ($index < $actionCount ? ' | ' : '') . '</span>';
            }

            echo '</small></span><br>';
        }
    }

    /** @return array<int, string> */
    protected function getSiteRoleLabels(int $userId, int $siteId): array {
        global $wpdb;

        if ($userId <= 0 || $siteId <= 0) {
            return [];
        }

        $capabilities = get_user_meta($userId, $wpdb->get_blog_prefix($siteId) . 'capabilities', true);

        if (!is_array($capabilities)) {
            return [];
        }

        $roleNames = wp_roles()->get_names();
        $labels = [];

        foreach ($capabilities as $role => $granted) {
            $role = sanitize_key((string)$role);

            if ($role === '' || empty($granted)) {
                continue;
            }

            $label = $roleNames[$role] ?? $role;
            $labels[] = translate_user_role((string)$label);
        }

        return array_values(array_unique($labels));
    }
}
