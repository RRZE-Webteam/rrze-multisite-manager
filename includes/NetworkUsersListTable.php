<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

/**
 * Extends the WordPress network user table with a view of users without
 * website membership and a view of orphaned accounts.
 *
 * Orphaned accounts (only offered while Wordfence is active) have no website
 * membership, were registered before a cut-off date and have no Wordfence
 * login record after a second cut-off date. Users without any recorded login
 * count as orphaned once they are old enough.
 */
class NetworkUsersListTable extends \WP_MS_Users_List_Table {
    public const FILTER_ALL = 'all';
    public const FILTER_WITHOUT_MEMBERSHIP = 'without-site-membership';
    public const FILTER_ORPHANED = 'orphaned';

    /**
     * User meta keys in which Wordfence Login Security may store the last
     * login. Both spellings are accepted; the most recent value counts.
     */
    public const LAST_LOGIN_META_KEYS = ['wfls_last_login', 'wfls-last-login'];

    private string $filter;
    private int $loginCutoff;
    private string $registeredCutoff;
    private bool $membershipFilterApplied = false;

    /**
     * @param int $loginCutoff Unix timestamp; a login at or after it keeps an account active
     * @param string $registeredCutoff UTC date (Y-m-d H:i:s); only accounts registered before it can be orphaned
     */
    public function __construct(string $filter = self::FILTER_ALL, int $loginCutoff = 0, string $registeredCutoff = '') {
        $this->filter = in_array($filter, [self::FILTER_WITHOUT_MEMBERSHIP, self::FILTER_ORPHANED], true) ? $filter : self::FILTER_ALL;
        $this->loginCutoff = $loginCutoff;
        $this->registeredCutoff = $registeredCutoff;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        parent::__construct([
            'screen' => $screen instanceof \WP_Screen ? $screen : 'users-network',
        ]);
    }

    public function prepare_items() {
        if ($this->filter === self::FILTER_ALL) {
            parent::prepare_items();
            return;
        }

        add_action('pre_user_query', [$this, 'restrictUserQuery']);

        try {
            parent::prepare_items();
        } finally {
            remove_action('pre_user_query', [$this, 'restrictUserQuery']);
        }
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
        $query->query_where .= ' AND ' . self::getWithoutMembershipCondition("{$wpdb->users}.ID");

        if ($this->filter === self::FILTER_ORPHANED) {
            $query->query_where .= ' AND ' . self::getOrphanedCondition("{$wpdb->users}.ID", "{$wpdb->users}.user_registered", $this->loginCutoff, $this->registeredCutoff);
        }
    }

    /** Returns the current number of users without a role on any website. */
    public static function getUsersWithoutWebsiteMembershipCount(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The condition is built with $wpdb->prepare(); table names are trusted identifiers.
        $count = $wpdb->get_var(
            "SELECT COUNT(1) FROM {$wpdb->users} AS rrze_msm_users WHERE " . self::getWithoutMembershipCondition('rrze_msm_users.ID')
        );

        return absint($count);
    }

    /** Returns the current number of orphaned accounts. */
    public static function getOrphanedUsersCount(int $loginCutoff, string $registeredCutoff): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The conditions are built with $wpdb->prepare(); table names are trusted identifiers.
        $count = $wpdb->get_var(
            "SELECT COUNT(1) FROM {$wpdb->users} AS rrze_msm_users WHERE "
            . self::getWithoutMembershipCondition('rrze_msm_users.ID')
            . ' AND ' . self::getOrphanedCondition('rrze_msm_users.ID', 'rrze_msm_users.user_registered', $loginCutoff, $registeredCutoff)
        );

        return absint($count);
    }

    /** SQL condition: the user has no capabilities entry on any website. */
    protected static function getWithoutMembershipCondition(string $userIdColumn): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column and table names are trusted identifiers.
        return $wpdb->prepare(
            "NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_site_membership WHERE rrze_msm_site_membership.user_id = {$userIdColumn} AND rrze_msm_site_membership.meta_key LIKE %s)",
            $wpdb->esc_like($wpdb->base_prefix) . '%capabilities'
        );
    }

    /**
     * SQL condition: registered before the registration cut-off and no
     * recorded login at or after the login cut-off. A login value is read as
     * a Unix timestamp when it is numeric and as a date string otherwise.
     */
    protected static function getOrphanedCondition(string $userIdColumn, string $registeredColumn, int $loginCutoff, string $registeredCutoff): string {
        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count(self::LAST_LOGIN_META_KEYS), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Column and table names are trusted identifiers; the placeholder list matches LAST_LOGIN_META_KEYS.
        return $wpdb->prepare(
            "{$registeredColumn} < %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_last_login WHERE rrze_msm_last_login.user_id = {$userIdColumn} AND rrze_msm_last_login.meta_key IN ({$placeholders}) AND ((rrze_msm_last_login.meta_value REGEXP '^[0-9]+$' AND CAST(rrze_msm_last_login.meta_value AS UNSIGNED) >= %d) OR (rrze_msm_last_login.meta_value NOT REGEXP '^[0-9]+$' AND rrze_msm_last_login.meta_value >= %s)))",
            array_merge(
                [$registeredCutoff],
                self::LAST_LOGIN_META_KEYS,
                [$loginCutoff, gmdate('Y-m-d H:i:s', $loginCutoff)]
            )
        );
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

        if (!current_user_can('delete_users')) {
            unset($columns['cb']);
        }

        return $columns;
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
