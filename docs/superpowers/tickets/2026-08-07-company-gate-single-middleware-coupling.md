# Ticket: the company gate rides entirely on the `api` middleware group

**Filed:** 2026-08-07, by the R2-K-prev deposit-preflight lane (out-of-lane finding).
**Severity:** MEDIUM — no known exploit today; it is a structural trap that makes a
future omission silent and total.
**Status:** OPEN.

## The coupling

Permission checks and company checks are carried by two unrelated mechanisms, and only
one of them is company-aware:

| Gate | Carrier | Scope |
|---|---|---|
| `can:payments.create` etc. | per-route middleware | Spatie team = **`tenant_id`** (`SetPermissionsTeam.php:28`) — company-BLIND |
| company membership | `CompanyContextMiddleware`, appended to the **`api` group** (`bootstrap/app.php:104-110`) | `userHasAccessToCompany()`, Active-only (`CompanyContext.php:146-152`) |

So a `can:*` middleware never asks which company the user is acting in. The only thing
that does is `CompanyContextMiddleware`, and it is attached at exactly **one** place: the
`api` group.

## The failure mode

A route group registered without `'api'` in its middleware stack keeps working — auth
still passes, `can:*` still passes, the controller still runs — but every company check
silently disappears. Nothing fails loudly; the route simply becomes cross-company.

This is a close cousin of the already-documented "missing `'api'` middleware causes 401"
trap in CLAUDE.md rule 12, except the failure is *quieter*: a missing `'api'` breaks auth
visibly (401), but a route group that includes `auth:sanctum` and
`SetPermissionsTeam` while omitting the `api` group would authenticate, authorize and
serve — with no company scoping at all.

Confirmed reachable-by-mistake, not currently reached: every module `routes.php` audited
during this lane does use the `['api', 'auth:sanctum', SetPermissionsTeam::class, …]`
shape. There is no defect in the current route table. This ticket is about making the
next omission impossible rather than merely unlikely.

## Wanted

1. **A line in `docs/conventions/03-AUTHORIZATION.md`** stating explicitly that the
   `api` group is the sole carrier of the company gate, that `can:*` is tenant-scoped
   and company-blind, and that a route group without `'api'` loses company scoping while
   still passing permission checks. Rule 12 currently explains the 401 consequence of
   omitting `'api'` but not this one.

2. **A CI test** asserting every registered `api/v1/*` route (minus the
   `api/v1/admin` exemption `CompanyContextMiddleware::isAdminRoute()` already encodes)
   has `CompanyContextMiddleware` in its **gathered** middleware stack — gathered, not
   declared, so group expansion is what gets checked. Shape:

   ```php
   foreach (Route::getRoutes() as $route) {
       if (! str_starts_with($route->uri(), 'api/v1/')) continue;
       if (str_starts_with($route->uri(), 'api/v1/admin')) continue;
       $this->assertContains(
           CompanyContextMiddleware::class,
           app(Router::class)->gatherRouteMiddleware($route),
           "Route {$route->uri()} is not company-gated.",
       );
   }
   ```

   This is the same ratchet shape as the existing `HorizonQueueCoverageTest` (which
   catches the structurally identical "named queue with no consumer" trap).

## Related

- `2026-08-07-company-context-header-uuid-pg-500.md` — same middleware, crash-level bug.
- `docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md`
  §V2 — the reachability question whose answer ("`CompanyContextMiddleware` is what
  actually closes the non-member path") is what exposed how much weight that single
  middleware carries.
