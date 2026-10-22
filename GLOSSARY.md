# Super-User Role

A WordPress capability plugin: a per-site role for trusted powerusers that mirrors the site's Administrator minus a fixed set of infrastructure capabilities.

## Language

**Super-User**:
A per-site WordPress role holding that site's Administrator capabilities minus the Blocklist. A site-level role — never the network owner.
_Avoid_: Super Admin (that term already means the network owner in WordPress), admin-plus, poweradmin

**Blocklist**:
The fixed set of capabilities never granted to a Super-User, covering user management, plugin and theme management, code editing, and core updates.
_Avoid_: denylist, exclusion list, stripped caps

**Capability sync**:
The invariant that a site's Super-User role continuously mirrors that site's Administrator role minus the Blocklist, so capabilities WordPress or plugins add later reach Super-Users as well.
_Avoid_: role snapshot, cloning, mirroring
