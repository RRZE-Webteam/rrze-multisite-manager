# Users

## Purpose

The **Users** page is a network-wide user list based on the WordPress network administration. It adds a view of users without any website assignment, so that orphaned accounts can be found and removed. It is visible only to super admins. The page is a first step; further functions for orphaned users are planned (see [Todo](Todo.md)).

## Views and search

| View | Content |
| --- | --- |
| All users | Every user of the installation (`get_user_count()`) |
| Super administrators | Users returned by `get_super_admins()` (`role=super`) |
| Users without website assignment | Users without a `<base_prefix><n>_capabilities` meta entry on any website (`rrze_msm_user_filter=without-site-membership`) |

The search field and pagination are those of the WordPress network user table. The counts in the view links are calculated on each page load; the count of users without website assignment is one SQL query on `usermeta`.

## Columns

The visible columns follow **Settings > General > Users** (`users_default_visible_columns`). The selection offers all columns that WordPress and active plugins currently register for the network user table. It applies to users who have not saved their own **Screen Options** selection. The checkbox column for bulk actions is always shown.

## Bulk deletion

Selected users can be deleted with the bulk action. The handler `admin_post_rrze_multisite_manager_network_users_bulk` checks `delete_users`, the super-admin capability and the nonce `rrze_multisite_manager_network_users_bulk`, stores the selection in a five-minute site transient bound to the current user and redirects to the hidden confirmation page `rrze-multisite-manager-users-confirm-delete`. That page renders the WordPress core confirmation (content attribution), so deletion uses the core workflow. After deletion, the `wp_redirect` filter returns to the Multisite Manager user list instead of the core screen, but only for requests that carry the nonce `rrze_msm_network_users_delete_return` and a user with `manage_network_users` and `delete_users`.

## Implementation

| Component | Role |
| --- | --- |
| `Dashboard::renderNetworkUsersPage()` | Page, views, search form, notices |
| `Dashboard::configureNetworkUsersScreen()` | Registered on `load-<page hook>`; adds the column filters `manage_<screen>_columns` and `default_hidden_columns` |
| `NetworkUsersListTable` | Extends `WP_MS_Users_List_Table`; for the view without website assignment it adds a `NOT EXISTS` condition via `pre_user_query` only while preparing its items |
| `Dashboard::handleNetworkUsersBulkAction()`, `renderNetworkUsersDeleteConfirmationPage()`, `redirectNetworkUsersDeleteToMsm()` | Bulk deletion workflow |
