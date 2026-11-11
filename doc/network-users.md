# Users

## Purpose

The **Users** page is a network-wide user list based on the WordPress network administration. It adds filters for users without any website assignment and, with Wordfence, for orphaned accounts, so that such accounts can be found and removed. It is visible only to super admins.

## Layout

The page follows the website and plugin overviews: the title and the **Stand** line (time of the cached count of users without website assignment) sit on the page background, the filter buttons, the search field and the table sit in a content panel (white in light mode, dark in dark mode). The Core list table carries the class `rrze-msm-table`, so the MSM table colours apply in both modes.

## Filters and search

| Button | Content | Shown |
| --- | --- | --- |
| All users | Every user of the installation (`get_user_count()`) | Always |
| Super administrators | Users returned by `get_super_admins()` (`role=super`) | Always |
| Without website assignment | Users without a `<base_prefix><n>_capabilities` meta entry on any website (`rrze_msm_user_filter=without-site-membership`) | Always |
| Orphaned accounts | Users without website assignment who registered before the minimum account age and whose last login recorded by Wordfence is older than the inactivity period, or was never recorded (`rrze_msm_user_filter=orphaned`) | Only while Wordfence (`wordfence/wordfence.php`) is active network-wide or on the main site |

Both periods are set under **Settings > General > Users**: `users_orphaned_minimum_age_months` (registration, default 12) and `users_orphaned_inactive_months` (last login, default 12). The last login is read from the user meta keys `wfls_last_login` and `wfls-last-login` (both spellings are accepted because the key could not be verified against the Wordfence source; the most recent value counts). Numeric values are read as Unix timestamps, other values as date strings.

The search field and pagination are those of the WordPress network user table. The count of users without website assignment is one SQL query on `usermeta` and is cached for one hour in the site transient `rrze_msm_users_without_membership_count`, together with the time shown as **Stand**. The cache is cleared when a user is registered or deleted and when a `<base_prefix>…capabilities` meta entry is added or removed (`user_register`, `wpmu_delete_user`, `deleted_user`, `added_user_meta`, `deleted_user_meta`). The count of orphaned accounts is cached for one hour in `rrze_msm_users_orphaned_count`; it is cleared by the same hooks and recalculated when a setting changes or the day changes. Logins do not clear it, so the count can be up to one hour old; the table itself is always queried live.

## Columns

The visible columns follow **Settings > General > Users** (`users_default_visible_columns`). The selection offers all columns that WordPress and active plugins currently register for the network user table. It applies to users who have not saved their own **Screen Options** selection. The checkbox column for bulk actions is always shown.

## Bulk deletion

Selected users can be deleted with the bulk action. The handler `admin_post_rrze_multisite_manager_network_users_bulk` checks `delete_users`, the super-admin capability and the nonce `rrze_multisite_manager_network_users_bulk`, stores the selection in a five-minute site transient bound to the current user and redirects to the hidden confirmation page `rrze-multisite-manager-users-confirm-delete`. That page renders the WordPress core confirmation (content attribution), so deletion uses the core workflow. After deletion, the `wp_redirect` filter returns to the Multisite Manager user list instead of the core screen, but only for requests that carry the nonce `rrze_msm_network_users_delete_return` and a user with `manage_network_users` and `delete_users`.

## Implementation

| Component | Role |
| --- | --- |
| `Dashboard::renderNetworkUsersPage()` | Page, views, search form, notices |
| `Dashboard::configureNetworkUsersScreen()` | Registered on `load-<page hook>`; adds the column filters `manage_<screen>_columns` and `default_hidden_columns` |
| `NetworkUsersListTable` | Extends `WP_MS_Users_List_Table`; for the views without website assignment and orphaned accounts it adds `NOT EXISTS` conditions via `pre_user_query` only while preparing its items; the same conditions are used for the counts |
| `Dashboard::handleNetworkUsersBulkAction()`, `renderNetworkUsersDeleteConfirmationPage()`, `redirectNetworkUsersDeleteToMsm()` | Bulk deletion workflow |
