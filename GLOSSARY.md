# Super-User Role

A WordPress capability plugin: a per-site role for trusted powerusers that mirrors the site's Administrator minus a fixed set of infrastructure capabilities.

## Language

**Super-User**:
A per-site WordPress role holding that site's Administrator capabilities minus the Blocklist. A site-level role — never the network owner.
_Avoid_: Super Admin (that term already means the network owner in WordPress), admin-plus, poweradmin

**Pure Super-User**:
A user holding a site's Super-User role without also holding that site's Administrator role and without being a network Super Admin. The escalation guards apply to exactly this class — never to an Administrator or network Super Admin who also holds the role.
_Avoid_: restricted user, guard target, plain Super-User

**Blocklist**:
The fixed set of capabilities never granted to a Super-User, covering user management, plugin and theme management, code editing, core updates, and credentials.
_Avoid_: denylist, exclusion list, stripped caps

**Capability sync**:
The invariant that a site's Super-User role continuously mirrors that site's Administrator role minus the Blocklist, so capabilities WordPress or plugins add later reach Super-Users as well.
_Avoid_: role snapshot, cloning, mirroring

**Abolish**:
The complete removal of the Super-User role from a site: sync stopped, the role stripped from its holders, the role definition deleted. Mere file removal is not abolish — it stops sync but revokes nothing.
_Avoid_: uninstall, deactivate (mu-plugins have neither)

**Editor parity**:
The trust floor for Super-Users: never restrict a Super-User below what a default Editor can do on the same site. `unfiltered_html` is kept on single site because Editors hold it there too; its stored-XSS risk is an accepted open gap (ADR-0001), not a flaw this plugin introduces.
_Avoid_: capability parity, admin parity
