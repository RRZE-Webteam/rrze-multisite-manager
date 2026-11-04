<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

/** Extends the WordPress network user table with a membership-free view. */
class NetworkUsersListTable extends \WP_MS_Users_List_Table {
    private bool $withoutWebsiteMembership;
    private bool $membershipFilterApplied = false;

    public function __construct(bool $withoutWebsiteMembership = false) {
        $this->withoutWebsiteMembership = $withoutWebsiteMembership;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        parent::__construct([
            'screen' => $screen instanceof \WP_Screen ? $screen : 'users-network',
        ]);
    }

    public function prepare_items() {
        if (!$this->withoutWebsiteMembership) {
            parent::prepare_items();
            return;
        }

        add_action('pre_user_query', [$this, 'excludeUsersWithWebsiteMembership']);

        try {
            parent::prepare_items();
        } finally {
            remove_action('pre_user_query', [$this, 'excludeUsersWithWebsiteMembership']);
        }
    }

    /**
     * Limits the Core user query to users without a role on any website in
     * this multisite installation. Usermeta is indexed by user ID, so this
     * remains a bounded membership lookup per user instead of iterating over
     * every website.
     */
    public function excludeUsersWithWebsiteMembership(\WP_User_Query $query): void {
        global $wpdb;

        if ($this->membershipFilterApplied) {
            return;
        }

        $this->membershipFilterApplied = true;
        $capabilityMetaKeyPattern = $wpdb->esc_like($wpdb->base_prefix) . '%capabilities';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress table names are trusted identifiers.
        $query->query_where .= $wpdb->prepare(
            " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_site_membership WHERE rrze_msm_site_membership.user_id = {$wpdb->users}.ID AND rrze_msm_site_membership.meta_key LIKE %s)",
            $capabilityMetaKeyPattern
        );
    }

    /** Returns the current number of users without a role on any website. */
    public static function getUsersWithoutWebsiteMembershipCount(): int {
        global $wpdb;

        $capabilityMetaKeyPattern = $wpdb->esc_like($wpdb->base_prefix) . '%capabilities';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress table names are trusted identifiers.
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->users} AS rrze_msm_users WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS rrze_msm_site_membership WHERE rrze_msm_site_membership.user_id = rrze_msm_users.ID AND rrze_msm_site_membership.meta_key LIKE %s)",
            $capabilityMetaKeyPattern
        ));

        return absint($count);
    }

    /** Uses the Core network-user deletion workflow and confirmation page. */
    protected function get_bulk_actions() {
        if (!current_user_can('delete_users')) {
            return [];
        }

        return [
            'delete' => __('Delete', 'default'),
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
        echo esc_html__('Select bulk action', 'default');
        echo '</label>';
        echo '<select name="' . esc_attr($fieldName) . '" id="rrze-msm-bulk-action-selector-' . esc_attr($which) . '">';
        echo '<option value="-1">' . esc_html__('Bulk actions', 'default') . '</option>';
        echo '<option value="delete">' . esc_html__('Delete', 'default') . '</option>';
        echo '</select>';
        submit_button(__('Apply', 'default'), 'action compact', 'rrze_msm_bulk_apply', false, [
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
                'edit' => '<a href="' . esc_url(network_admin_url('site-info.php?id=' . $siteId)) . '">' . esc_html__('Edit', 'default') . '</a>',
                'view' => '<a href="' . esc_url(get_home_url($siteId)) . '">' . esc_html__('View', 'default') . '</a>',
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
