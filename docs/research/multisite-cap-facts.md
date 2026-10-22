# Multisite & capability facts — subsite Administrator, per-site roles, boot hooks

Research for [Verify multisite & capability facts](https://github.com/tsueri/role-super-user/issues/2), feeding the
[Map: Super-User role (Administrator minus blocklist)](https://github.com/tsueri/role-super-user/issues/1).

**Source basis:** WordPress core tag [`7.1.3`](https://github.com/WordPress/WordPress/tree/7.1.3) (current release at
time of writing), cross-checked against [developer.wordpress.org](https://developer.wordpress.org), two
[make.wordpress.org/core](https://make.wordpress.org/core) dev notes, and the first-party
[Roles and Capabilities](https://wordpress.org/documentation/article/roles-and-capabilities/) handbook page.
Line numbers refer to the tag's files; permalinks use `blob/7.1.3/<path>#L<line>`.

## TL;DR

1. **A subsite Administrator is a full clone of the single-site Administrator.** Every new site runs the same
   `populate_roles()` schema, so the role stored in `wp_{blog_id}_user_roles` contains the complete default
   Administrator cap list — including the entire Blocklist except `add_users`.
2. **Correction to the ticket's premise:** no Blocklist cap is "absent from the cloned role anyway."
   `add_users` is the only listed cap not in the role (it's a meta cap aliased to `promote_users` since 4.4).
   On multisite the stored caps are instead made **inert at check time** by `map_meta_cap()` → `do_not_allow`.
3. `unfiltered_html` — present in the role, denied to non-super-admins on multisite by `map_meta_cap()`.
   `manage_options` — present and unrestricted; works per-site.
4. Roles and role APIs are strictly **per-site**. `add_role` / `get_role` / `remove_role` operate on the current
   site only; `switch_to_blog()` re-binds the global roles object.
5. An mu-plugin (and a network-activated plugin) is included **once per request, in the current site's context**,
   on every load of every site in the network. It never loops sites by itself.
6. `wp_loaded` fires at the end of every full WordPress boot — normal requests, AJAX, REST, and WP-CLI alike.

---

## 1. What the subsite Administrator role actually contains

### 1.1 New sites clone the full default role set

Site creation runs `wp_initialize_site()`, which switches to the new blog and calls `populate_roles()`:

- [`wp_initialize_site()` — wp-includes/ms-site.php L653, `populate_roles()` at L743](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/ms-site.php#L653):
  after `populate_options()`, it calls **`populate_roles()`**, re-instantiates `WP_Roles`, runs
  `wp_install_defaults()`, and assigns the creator via `add_user_to_blog( $site->id, $user_id, 'administrator' )`.
- `populate_roles()` ([wp-admin/includes/schema.php L719](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/includes/schema.php#L719))
  runs the same versioned builders used for a single-site install:
  `populate_roles_160()` (L750), `_210()` (L842), `_230()` (L890), `_250()` (L903), `_260()` (L916),
  `_270()` (L930), `_280()` (L944), `_300()` (L957).

So each subsite's Administrator starts with **exactly** the single-site default cap set.

### 1.2 The listed caps — presence in the role vs. effectiveness on multisite

| Capability | In default role (source) | Subsite admin on multisite |
|---|---|---|
| `activate_plugins` | `populate_roles_160` | **Conditional** — allowed, but only if the network enabled the Plugins menu item (site option `menu_items[plugins]`); otherwise the check additionally requires `manage_network_plugins` ([capabilities.php L654–666](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L654-L666)) |
| `install_plugins` | `populate_roles_270` (L930) | denied — `do_not_allow` ([L619–641](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L619-L641)) |
| `delete_plugins` | `populate_roles_260` (L916) | denied (same block) |
| `update_plugins` | `populate_roles_260` (L916) | denied (same block) |
| `edit_plugins` | `populate_roles_160` | denied — `do_not_allow` ([L605–617](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L605-L617)) |
| `switch_themes` | `populate_roles_160` | **allowed** — no multisite case exists in `map_meta_cap()`; the only other core reference grants `resume_themes` ([L1332](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L1332)) |
| `install_themes` | `populate_roles_280` (L944) | denied ([L619–641](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L619-L641)) |
| `update_themes` | `populate_roles_270` (L930) | denied (same block) |
| `delete_themes` | `populate_roles_300` (L957) | denied (same block) |
| `edit_themes` | `populate_roles_160` | denied ([L605–617](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L605-L617)) |
| `edit_files` | `populate_roles_160` | denied ([L605–617](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L605-L617)); handbook marks it "no longer used", but the meta-cap map still honors it |
| `edit_users` | `populate_roles_160` | **denied for editing others** — on multisite, meta `edit_user`/primitive `edit_users` requires `manage_network_users`, which no site role holds ([L61–79](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L61-L79), condition at L75); confirmed at screen level in [`wp-admin/user-edit.php` L99–104](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/user-edit.php#L99-L104). Editing your own profile needs no cap. |
| `create_users` | `populate_roles_210` (L842) | **denied unless** the network setting `add_new_users` is enabled ([L682–689](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L682-L689)) |
| `delete_users` | `populate_roles_210` (L842) | denied — "If multisite only super admins can delete users" ([L673–680](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L673-L680)) |
| `list_users` | `populate_roles_300` (L957) | **allowed** (per-site user list) |
| `promote_users` | `populate_roles_300` (L957) | **allowed** (role changes on the site) |
| `remove_users` | `populate_roles_300` (L957) | **allowed** for removing others from the site; removing **yourself** requires super admin (meta `remove_user`, [L49–55](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L49-L55)) |
| `add_users` | **not in any default role** | meta cap alias → `promote_users` ([L57–60](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L57-L60)); handbook: "Replaced in 4.4 with promote_users". Nothing to blocklist. |
| `update_core` | `populate_roles_300` (L957) | denied ([L619–641](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L619-L641)) |
| `unfiltered_html` | `populate_roles_160` | **denied** for non-super-admins on multisite ([L594–603](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L594-L603)); `DISALLOW_UNFILTERED_HTML` denies even super admins. `edit_css` shares this case. |
| `manage_options` | `populate_roles_160` | **present and unrestricted** — no multisite case touches it; site settings screens work per-site. (`setup_network` maps to `manage_options` only on single site, [L770–777](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L770-L777).) |

Related, for completeness: `unfiltered_upload` is in the role (`populate_roles_230`) but only grants with
`ALLOW_UNFILTERED_UPLOADS` **and** super admin on multisite ([L587–593](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L587-L593));
`install_languages`/`update_languages` are super-admin-only on multisite ([L644–652](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L644-L652)).

**Multisite no-op summary (non-super-admin):** `update_core`, `install/update/delete/upload_plugins`,
`install/update/delete/upload_themes`, `edit_plugins`, `edit_themes`, `edit_files`, `unfiltered_html`,
`unfiltered_upload`, `install/update_languages`, `delete_users`, `edit_users` (others),
`create_users` (unless `add_new_users`). **Conditionally live:** `activate_plugins`/`deactivate_plugins`
(network menu setting). **Live:** `switch_themes`, `list_users`, `promote_users`, `remove_users`
(except self-removal), `manage_options`.

### 1.3 Why they're inert: `map_meta_cap()`, not role absence

Every `current_user_can()` / `user_can()` call runs the required cap through
[`map_meta_cap()`](https://developer.wordpress.org/reference/functions/map_meta_cap/)
([wp-includes/capabilities.php L45](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L45)).
For the caps above, the multisite branch replaces the required primitive with `do_not_allow` unless
`is_super_admin( $user_id )` — membership in the `site_admins` network option
([`get_super_admins()` L1163](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L1163),
[`is_super_admin()` L1177](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L1177)).
`WP_User::has_cap()` then short-circuits: a multisite super admin has all caps except `do_not_allow`
([class-wp-user.php L796–801](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-user.php#L796-L801));
for everyone else `do_not_allow` is never satisfied (it is unset from `allcaps` before evaluation, L828).

The handbook states this as "In Multisite, only the Super Admin has these abilities" for
`update_core`, `update_plugins`, `update_themes`, `install_plugins`, `install_themes`, `delete_themes`,
`delete_plugins`, `edit_plugins`, `edit_themes`, `edit_files`, `edit_users`, `add_users`, `create_users`,
`delete_users`, `unfiltered_html`
([Roles and Capabilities → Additional Admin Capabilities](https://wordpress.org/documentation/article/roles-and-capabilities/#additional-admin-capabilities)).
The source shows the **mechanism**: the caps remain in the stored role; core denies them at check time.

### 1.4 Consequence for Super-User

- On **multisite**, stripping Blocklist caps from the Super-User role is role hygiene and single-site parity,
  not the enforcement mechanism — core already denies most of them. The **load-bearing** Blocklist entries on a
  vanilla multisite (caps a subsite Administrator can actually exercise) are `switch_themes`,
  `activate_plugins` (only when the network enables the Plugins menu), `promote_users`/`remove_users`/
  `list_users` (if blocklisted), and `manage_options`-family caps.
- On **single site**, stripping *is* the mechanism — nothing in core denies these caps to Administrators.
- Tests should assert **both** layers: stored role caps (`get_role()->capabilities`) and runtime
  `current_user_can()` behavior.

## 2. Per-site role storage and behavior

### 2.1 Storage is per site

`WP_Roles::for_site()` sets the option key per site:
[`$this->role_key = $wpdb->get_blog_prefix( $this->site_id ) . 'user_roles';`](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-roles.php#L333-L351)
(L342). Blog 1 stores `wp_user_roles`; blog N stores `wp_{N}_user_roles` in that site's own options table.
Reading roles for a non-current site goes through `get_blog_option()`
([`get_roles_data()` L373–391](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-roles.php#L373-L391)).

A user's *role assignment* is per site too: capability meta is stored under the blog-prefixed key
`wp_{blog_id}_capabilities` ([`WP_User::_init_caps()` L482–495](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-user.php#L482-L495)),
and `add_user_to_blog()` switches to the target blog before `$user->set_role()`
([ms-functions.php L162](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/ms-functions.php#L162)).

### 2.2 `add_role()` / `get_role()` / `remove_role()` operate on the current site only

All three are thin wrappers over `wp_roles()` — the single global `WP_Roles` instance
([capabilities.php L1081–1152](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/capabilities.php#L1081-L1152)).
That instance is bound to the current site at boot ([`$GLOBALS['wp_roles'] = new WP_Roles()` — wp-settings.php L698](https://github.com/WordPress/WordPress/blob/7.1.3/wp-settings.php#L698))
and re-bound when the site switches: the `switch_blog` action runs `wp_switch_roles_and_user()`
([ms-blogs.php L695–707](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/ms-blogs.php#L695-L707),
hooked at [ms-default-filters.php L37](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/ms-default-filters.php#L37)),
which calls `wp_roles()->for_site( $new_site_id )` and `wp_get_current_user()->for_site( $new_site_id )`
— but only after `init` has fired. Writing happens per site via `update_option( $this->role_key, … )`
([`WP_Roles::add_role()` L176–195](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-roles.php#L176-L195)).

So: on any given request these functions read and write **only the current site's** role set. Network-wide role
work requires a `switch_to_blog()` loop — exactly what `wp_initialize_site()` does when cloning roles.

**make.wordpress.org corroboration:** the 4.9 dev note
["Improvements for roles and capabilities in 4.9"](https://make.wordpress.org/core/2017/10/15/improvements-for-roles-and-capabilities-in-4-9)
documents `WP_Roles::for_site()`, `WP_User::for_site()` and `wp_switch_roles_and_user()` (site switching moved
out of `switch_to_blog()`/`restore_current_blog()`); the 6.1 note
["Multisite improvements in WordPress 6.1"](https://make.wordpress.org/core/2022/10/10/multisite-improvements-in-wordpress-6-1/)
states: "While site administrators cannot edit user details in multisite, they are able to modify a user's roles."

### 2.3 Hooks relevant to Capability sync

- `wp_roles_init` — fired by `WP_Roles::init_roles()` on **every** (re)initialization, including each
  `for_site()` re-bind during site switching ([class-wp-roles.php L302–322](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-roles.php#L302-L322)).
- `switch_blog` (roles re-bind at priority 1), `ms_loaded` (fired at the end of `ms-settings.php`).

## 3. mu-plugins and network-activated plugins

Terminology split worth keeping precise:

- **mu-plugins** (`wp-content/mu-plugins`) are included on **every** request, multisite or not —
  [wp-settings.php L506–520](https://github.com/WordPress/WordPress/blob/7.1.3/wp-settings.php#L506-L520),
  [`wp_get_mu_plugins()` — wp-includes/load.php L974](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/load.php#L974).
- **Network-activated plugins** are included only when multisite, sourced from the network option
  `active_sitewide_plugins` ([wp-settings.php L522–541](https://github.com/WordPress/WordPress/blob/7.1.3/wp-settings.php#L522-L541),
  [`wp_get_active_network_plugins()` — wp-includes/ms-load.php L37](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/ms-load.php#L37)).
- Both groups load **before** `muplugins_loaded` (L548); site-activated plugins load later (L582), and
  `wp_get_active_and_valid_plugins()` excludes files already loaded as network plugins
  ([load.php L1013](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/load.php#L1013)).

**Answer: yes, in each site's context, on every load.** The current site/network is resolved *before* any plugin
loads: [wp-settings.php L156–161](https://github.com/WordPress/WordPress/blob/7.1.3/wp-settings.php#L156-L161)
requires [`wp-includes/ms-settings.php`](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/ms-settings.php),
which populates `$current_site`/`$current_blog` via `ms_load_current_site_and_network()`, sets
`$wpdb->set_blog_id()`, and fires `ms_loaded`. So on a request to site N, the mu-plugin's file is included once
with site N's context (blog ID, per-site options, per-site roles, current user). One request = one site; the
plugin never iterates sites itself. Acting network-wide means looping `get_sites()` + `switch_to_blog()`
yourself (or driving it with WP-CLI).

## 4. `wp_loaded` across contexts

`do_action( 'wp_loaded' )` is the **final statement** of
[wp-settings.php L801](https://github.com/WordPress/WordPress/blob/7.1.3/wp-settings.php#L801) — it fires at the
end of every full WordPress boot, after `init` (L779) and the multisite `ms_site_check()` (L783).

- **AJAX:** [`wp-admin/admin-ajax.php`](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/admin-ajax.php)
  defines `DOING_AJAX` (L16) and then requires `wp-load.php` (L22) → full boot → `wp_loaded` fires.
- **REST:** REST requests boot through the normal front-end path —
  [`wp-blog-header.php`](https://github.com/WordPress/WordPress/blob/7.1.3/wp-blog-header.php) requires
  `wp-load.php`, then calls `wp()`. The REST server is dispatched by `rest_api_loaded()`, hooked on
  `parse_request` ([default-filters.php L549](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/default-filters.php#L549),
  [rest-api.php L448](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/rest-api.php#L448)), which
  runs inside `wp()` — i.e. **after** `wp-settings.php` has already fired `wp_loaded`.
- **WP-CLI:** [`WP_CLI\Runner::load_wordpress()`](https://github.com/wp-cli/wp-cli/blob/main/php/WP_CLI/Runner.php)
  evals `wp-config.php` and then `require ABSPATH . 'wp-settings.php';` (L2367 of Runner.php, `main` branch) —
  the same full bootstrap — so `wp_loaded` fires before the command runs, followed by WP-CLI's `after_wp_load`
  hook (L2402). Caveat: with `--skip-plugins=<this-plugin>` the plugin file is never included, so its own
  `wp_loaded` hook doesn't exist for that run; the action itself still fires.

Conclusion: a `wp_loaded` sync hook fires in normal, AJAX, REST, and WP-CLI contexts.

## 5. Caveats / not verified

- Screen-level UX beyond the capability checks (exact wording/behavior of user-list and editor screens for
  subsite admins) was not audited; cap-level facts above are the cited ones.
- `edit_files`: handbook says "no longer used"; `map_meta_cap()` still honors it (L605). Core screens check
  `edit_plugins`/`edit_themes` instead — no core screen was found checking `edit_files` directly.
- History claims kept to cited changelogs (`for_site`/`wp_switch_roles_and_user` since 4.9;
  `add_users` → `promote_users` alias since 4.4 per handbook). Older-version behavior not verified.
- Object-cache interactions with per-site role options (e.g. persistent cache groups per site) were not examined.

## Source index

- Core source at tag `7.1.3`: `wp-includes/capabilities.php`, `class-wp-roles.php`, `class-wp-user.php`,
  `ms-settings.php`, `ms-blogs.php`, `ms-default-filters.php`, `ms-load.php`, `ms-site.php`,
  `ms-functions.php`, `load.php`, `default-filters.php`, `rest-api.php`, `wp-settings.php`,
  `wp-blog-header.php`, `wp-load.php`, `wp-admin/admin-ajax.php`, `wp-admin/user-edit.php`,
  `wp-admin/includes/schema.php` (github.com/WordPress/WordPress).
- WP-CLI: `wp-cli/wp-cli` `php/WP_CLI/Runner.php` (`main`).
- developer.wordpress.org: [`map_meta_cap()`](https://developer.wordpress.org/reference/functions/map_meta_cap/),
  [`WP_Roles::for_site()`](https://developer.wordpress.org/reference/classes/wp_roles/for_site/),
  [`wp_switch_roles_and_user()`](https://developer.wordpress.org/reference/functions/wp_switch_roles_and_user/),
  [`wp_roles()`](https://developer.wordpress.org/reference/functions/wp_roles/),
  [`get_current_blog_id()`](https://developer.wordpress.org/reference/functions/get_current_blog_id/).
- make.wordpress.org/core: [Improvements for roles and capabilities in 4.9](https://make.wordpress.org/core/2017/10/15/improvements-for-roles-and-capabilities-in-4-9),
  [Multisite improvements in WordPress 6.1](https://make.wordpress.org/core/2022/10/10/multisite-improvements-in-wordpress-6-1/).
- Handbook: [Roles and Capabilities](https://wordpress.org/documentation/article/roles-and-capabilities/).
