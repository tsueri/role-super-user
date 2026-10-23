# Super-User Role

A WordPress **must-use (mu-)plugin**: on every site it is installed on, it maintains a **Super-User** role — a per-site role that mirrors that site's Administrator capabilities minus a fixed Blocklist, kept in sync automatically.

Super-Users are trusted powerusers on your own site: they can do everything a site Administrator can — except user management, plugin and theme management, code editing, and core updates. See `GLOSSARY.md` for the exact language: **Super-User**, **Blocklist**, **Capability sync**, **Abolish**.

> **Break-glass:** this role is a delegation of trust, not a security boundary. Always keep at least one real Administrator account, and keep its credentials somewhere a compromised site cannot reach.

## How it works

- The mu-plugin hooks `wp_loaded`, which fires at the end of every full WordPress boot — normal requests, AJAX, REST, and WP-CLI alike.
- On each boot it re-derives the expected capability set — the current site's Administrator capabilities minus the Blocklist — and rewrites the `super_user` role only when it has drifted. An in-sync boot writes nothing: the stored role option is left byte-identical.
- What WordPress or other plugins add to the Administrator role later reaches Super-Users on the next boot. Caps the Administrator loses are lost too. The Blocklist always wins: even if the Administrator holds a blocklisted capability, a Super-User never gets it.
- A Super-User is never granted beyond what the site's Administrator already has.

## The Blocklist

These capabilities are never granted to a Super-User, no matter what the site's Administrator holds:

```
activate_plugins   install_plugins    delete_plugins    update_plugins
edit_plugins       switch_themes      install_themes    update_themes
delete_themes      edit_themes        edit_files        edit_users
create_users       delete_users       list_users        promote_users
remove_users       add_users          update_core
```

Deliberate exceptions:

- **`edit_theme_options` is kept.** Menus, widgets, and the Customizer stay available; theme switching, installing, and file editing stay blocked.
- **`update_core` is stripped.** Core updates stay with admins and the network.
- **`unfiltered_html` is not blocklisted**, but it is only ever cloned from the Administrator: single-site Administrators normally hold it, so their Super-Users do too; on multisite, subsite Administrators are denied it by WordPress core (`map_meta_cap()`), and so are their Super-Users. This plugin never grants more than the site's Administrator already has. Keeping it is an Editor-parity trade-off with a documented open gap (ADR-0001).

### Escalation guards

`manage_options` is broader than it looks: core's Settings API trusts it for writes and screens that would otherwise bypass the Blocklist. The plugin closes the known routes from Super-User to Administrator or network Super Admin:

- **User-creation options.** `default_role` and `users_can_register` are guarded by `promote_users` and `create_users`. A crafted Settings POST cannot turn the new-user default role into Administrator or open registration with that default.
- **Network Setup.** `setup_network` maps to `manage_options` on single site, so it is denied to Super-Users. They cannot run Network Setup and name themselves network Super Admin.
- **All Settings.** `options.php` writes arbitrary options (`active_plugins`, `template`, `stylesheet`) when accessed directly. Super-Users are kept off that page; normal Settings screens still save because they post with their own `option_page`.

The guards apply only to Pure Super-Users (see `GLOSSARY.md`): Administrators keep full control, and so does anyone WordPress counts as a Super Admin (`is_super_admin()` — the network's super-admin list on multisite; on single site, holders of `delete_users`, a capability the Blocklist never grants through the role; ADR-0002). They are not a security boundary — see Caveats.

## Install

1. Create `wp-content/mu-plugins/` if it does not exist (WordPress only auto-loads top-level `.php` files there).
2. Upload `role-super-user.php` into it. Done — mu-plugins have no activation step and cannot be deactivated from the admin UI.

On **multisite**, one drop covers every site in the network: mu-plugins load network-wide on every request, in each site's context. There is never a per-site upload. Any file pipeline (SFTP, rsync, scp, CI) does the identical job.

The role itself is created lazily per site: each site's first request after installation runs the sync and creates its own `super_user` role. Nothing touches sites that are not visited.

## Assign the role

Per site:

```sh
wp user set-role <user> super_user --url=<site>   # multisite; omit --url on single site
```

`wp user set-role` takes exactly one user per invocation and, on multisite, also adds the user to the site if they are not yet a member. The same choice is available on the site's Users screen in the role dropdown.

## Verify

```sh
wp role list --url=<site>          # shows super_user once that site has booted
```

To sync (and verify) every site on a network in one go — rarely needed, since any visit syncs the site — touch them all:

```sh
wp site list --field=url --archived=0 --deleted=0 --spam=0 | xargs -n1 -I{} sh -c 'wp role list --url={}'
```

Note the filters: archived, spam, and deleted sites are excluded, and `--field=url` is built from each site's `home` option — a site whose `home` and `siteurl` disagree makes its URL unresolvable, and commands targeting it die with `Error: Site '<url>' not found. Verify --url=<url> matches an existing site.`

**Upgrade** = overwrite the file; the sync re-runs on the next request, per site.

**Freeze, not revoke:** removing the mu-plugin file stops syncing but revokes *nothing* — the role and its assignments persist with their last-synced capabilities. Complete removal is the Abolish procedure below.

## Abolish

Complete removal of the Super-User role from a site: sync stopped, role stripped from holders, role definition deleted. Ordering is forced: **the file comes out first**, or the lazy sync re-creates the role on the next boot.

1. **Remove the mu-plugin file.** This alone revokes nothing (see freeze-not-revoke).
2. **Strip holders, per site** — naming the role keeps holders' other roles and site membership:

   ```sh
   wp user list --role=super_user --field=ID --url=<site>
   wp user remove-role <user> super_user --url=<site>
   ```

   ⚠️ Never run bare `wp user remove-role <user>` on multisite: without a role argument it removes the user **from the site entirely**.
3. **Delete the role, per site:**

   ```sh
   # multisite
   wp site list --field=url --archived=0 --deleted=0 --spam=0 | xargs -n1 -I{} sh -c 'wp role delete super_user --url={}'
   # single site
   wp role delete super_user
   ```

   `wp role delete` touches no user meta — which is why step 2 exists.

**Reinstall-resurrection warning:** deleting the role without stripping holders leaves an inert stale `super_user` entry in users' capabilities meta. It grants nothing while the plugin is gone, but reinstalling the plugin lazily re-creates the role and instantly re-powers those users. Step 2 is the protection.

## Multisite notes

- **Super-User is deliberately subsite-level.** It is never the network owner (WordPress's "Super Admin") and there is no network-admin UI. Each site's role mirrors *that site's* Administrator.
- On multisite, most blocklisted capabilities are already denied to subsite Administrators by WordPress core at check time (`update_core`, plugin/theme install, `edit_users` on others, `unfiltered_html`, …), whether or not they sit in the stored role. Stripping them from Super-User is single-site parity and hygiene. The entries that change behavior on a vanilla network: `switch_themes`, `activate_plugins` (only when the network enables the Plugins menu item), and the user-management caps (`list_users`, `promote_users`, `remove_users`).
- The sync is lazy and per-site: each site is synced by the requests that load it. A subsite nobody visits stays as it was.

## Caveats

- **Plugins that check the role *name* `administrator`** instead of capabilities will not recognize Super-Users — they may hide menus or deny screens that a capability check would allow. This is documented, not worked around.
- mu-plugins cannot be deactivated from the admin UI; "uninstalling" is the Abolish procedure.
- `unfiltered_html` on multisite is core-denied for Super-Users the same as for subsite Administrators (see the Blocklist section).
- **Open gap: `unfiltered_html` on single site.** A Super-User can publish raw HTML/JS — scripts, iframes, event handlers — in posts, pages, and widgets. If an Administrator views or previews that content while logged in, the script runs in an admin session and could escalate (create an Administrator, activate plugins). This gap already exists in the default **Editor** role — Editors hold `unfiltered_html` on single site and have the same ability — so this plugin neither introduces it nor closes it; it is accepted and documented in ADR-0001. Blocking it would make Super-Users less trusted than Editors; do that only in a custom build if a site needs the stricter boundary. The plugin's escalation guards (default_role, users_can_register, setup_network, All Settings) do not change this.
- **The escalation guards cover core's single-site routes, not `manage_options` itself.** Any plugin that treats `manage_options` as "administrator" (file managers, SQL runners, plugin managers, arbitrary uploaders) can still expose dangerous functionality to a Super-User. Curate the plugin set, or block those plugin capabilities in a custom build.

## Tests

The automated suite runs the same spec against **single-site** and **multisite** wp-env environments (latest WordPress, PHP 8.5), locally — no CI involved.

One-time setup (Node + Docker required):

```sh
npm run setup
```

Then, from the repo root:

```sh
npm run test:all     # PHPUnit on both configs + a WP-CLI smoke test on both
npm run test         # single-site PHPUnit only
npm run test:multisite
npm run test:cli     # WP-CLI smoke test (single site)
npm run test:cli:multisite
```

The suite proves the synced capability set: the stored role equals the Administrator minus the Blocklist; blocklist caps never re-enter the role; runtime `user_can()` matches the stored role (own profile editable, others' not); `unfiltered_html` is stored on both configs but only effective where the site's Administrator is actually granted it (single site — subsites are denied it by core, and so are their Super-Users); idempotent boots write nothing; new Administrator capabilities propagate on the next boot while blocklisted ones never do; removed ones are removed; on multisite, untouched subsites get no role; a missing Administrator role fatals nothing and creates nothing; a WP-CLI boot leaves the role in sync. It also covers the escalation guards: a Super-User's write to `default_role` or `users_can_register` is a no-op while an Administrator's still succeeds, and a Super-User cannot satisfy `setup_network` or reach All Settings while an Administrator still can — and the applicability predicate restricts exactly Pure Super-Users, never an Administrator, a network Super Admin, or a logged-out visitor.

**Manual pre-rollout checklist** — run once on a real site with real plugins after assigning the role; the automated suite proves the cap set, this proves what a human sees:

- [ ] Dashboard and content screens (posts, pages, media) all work.
- [ ] Settings screens open and save.
- [ ] The Plugins menu is gone (or shows no install/update/delete actions).
- [ ] Themes: Customizer, widgets, and menus work; switching, installing, and the theme file editor do not.
- [ ] The Updates screen shows no core-update action.
- [ ] Users: own profile editable; other users' profiles are not; no user create/delete.
- [ ] Settings: General saves, but Membership and New User Default Role cannot be changed by a Super-User.
- [ ] `/wp-admin/options.php` (All Settings) is denied to a Super-User.