# Ticket: /settings/setup renders with no RequirePermission / ModuleGuard — verify + gate (possible authz gap)

Flagged by the 2026-08-02 full-E2E campaign plan (F-6) while enumerating the route surface
(apps/web/src/routes/index.tsx): the `/settings/setup` route element carries no
`RequirePermission` moduleKey and no module gate, unlike sibling settings routes.

**Needed:** (1) verify what the page can actually do for a low-privilege user (cashier) — FE
render alone may be harmless if every mutation behind it 403s server-side, but rule 12 requires
BOTH layers gated; (2) check the backend routes it calls for permission middleware; (3) add the FE
gate (and backend if missing). Candidate for the tenancy-authz-reviewer to adjudicate severity.
Until verified, treat as P2-investigate; escalate to P0 if any mutation is reachable.
