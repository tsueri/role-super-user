# ADR-0002: The escalation guards exempt WordPress Super Admins (`is_super_admin()`)

## Status

Accepted — 2026-10-23

## Context

The escalation guards (guarded option writes, `setup_network`, the All Settings screen) restrict exactly the Pure Super-Users (GLOSSARY.md) — the class `super_user_role_is_restricted()` decides. Until that predicate was unified, each guard derived "who is exempt" on its own, and they disagreed in one corner:

- `setup_network` and both All Settings layers exempted anyone WordPress's `is_super_admin()` returns true for.
- The option-write guard (`default_role`, `users_can_register`) exempted only via `current_user_can()` on the guarded capability.

On single site, `is_super_admin()` is true for anyone holding `delete_users` (`wp-includes/capabilities.php`), not `manage_network`. The Blocklist never grants `delete_users` through the role, so that corner is reached only by a per-user grant (`wp user add-cap <user> delete_users`) or a plugin's `add_cap()`. For such a user, the old code exempted them from `setup_network` and All Settings but still denied the guarded option writes — two rules silently disagreeing.

The alternative — exempting only real Administrators plus Super Admins on multisite (`is_super_admin()` && `is_multisite()`) — would have changed the three guards that already used `is_super_admin()`: the delete_users edge would become restricted, and the predicate would be config-dependent.

## Decision

One predicate, one rule: the guards exempt whoever WordPress counts as a Super Admin — `is_super_admin()` unconditionally — plus Administrators via the role check. Core's single-site `delete_users` proxy is part of that rule, not a deviation from it.

## Consequences

- A Super-User with a per-user `delete_users` grant is exempt from all guards, not some. It looks like a hole; it is deliberate. Core itself treats that user as a Super Admin, and granting a Blocklisted capability per-user moves the user outside the "Administrator minus the Blocklist" model the guards police.
- Auditors and future contributors: treat `is_super_admin()` as the single source of truth for the exemption, and do not re-derive it per guard — that regression is what this ADR prevents.
- Operators: do not expect the guards to keep applying after granting Blocklisted capabilities per-user. The Capability sync is unaffected — the role itself still never carries `delete_users`.
