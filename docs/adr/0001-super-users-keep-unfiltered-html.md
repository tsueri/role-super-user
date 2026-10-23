# ADR-0001: Super-Users keep `unfiltered_html` (Editor parity)

## Status

Accepted — 2026-10-23

## Context

The Blocklist keeps a Super-User's capability set at "the site's Administrator minus a fixed list". On single site, the Administrator role holds `unfiltered_html`, so a Super-User holds it too: they can publish raw HTML — `<script>`, `<iframe>`, `on*` handlers — in posts, pages, widgets, and the Custom HTML block.

That is a live stored-XSS route. A Super-User could publish script that runs in an Administrator's session when the admin previews or views the content while logged in, and script in an admin session can do anything an Administrator can (create another Administrator, activate plugins). The escalation guards shipped in v1.0.1 close the direct routes (`default_role`, `users_can_register`, `setup_network`, the All Settings screen); this route survives, because it is core's own trust model, not something this plugin adds.

WordPress already accepts exactly this gap elsewhere: the default **Editor** role holds `unfiltered_html` on single site (`populate_roles_160()`), and an Editor has the same ability to publish active content that executes in an admin's session. Blocking `unfiltered_html` for Super-Users would make them strictly *less* trusted than an Editor — a silent deviation from "Administrator minus the Blocklist" that operators would have to discover.

On multisite the question does not arise: core denies `unfiltered_html` to everyone who is not a network Super Admin (`map_meta_cap()`), so subsite Super-Users are already filtered, exactly like subsite Administrators.

## Decision

Keep `unfiltered_html` granted to Super-Users on single site. Do not add it to the Blocklist.

## Consequences

**Open gap (accepted, documented):** on single site, a Super-User — like any Editor today — can publish active content that executes in an Administrator's session. This plugin does not introduce the gap and does not close it.

**What this means in practice:**

- Do not grant the Super-User role to anyone you would not trust as an Editor today. The role is a delegation of trust, not a security boundary against malicious content authors.
- Sites that need the stricter boundary can add `unfiltered_html` to the Blocklist in a custom build. That makes Super-Users less trusted than Editors; the deviation must be documented per site if chosen.
- Content-scrubbing security plugins, or allowlist-based embed plugins, shrink the gap without changing the capability set.

**Not a fix target for this plugin:** closing it would either break Editor parity or require a per-user trust layer outside this plugin's scope.
