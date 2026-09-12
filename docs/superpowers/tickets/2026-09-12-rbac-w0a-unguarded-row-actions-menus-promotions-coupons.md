# Ticket — unguarded row actions on Menus / Promotions / Coupons pages after wave 0a gates (2026-09-12)

**Source:** `tenancy-authz-reviewer` gate r1 on `lane/rbac-w0a` (`docs/superpowers/reviews/2026-09-12-rbac-w0a-impl-gate-r1-tenancy.md`, finding M-2). **Owner:** RBAC wave 0b task 0b-15 page-guard slice, or wave 2a. **Not** a wave-0a change (that lane touches no `apps/web` file).

## What happens now

Wave 0a gates the backend routes with keys the `manager` role already holds, so managers see no change. Roles that hold the page's *view* key but not the *manage* key still see the buttons and now receive a 403 on click:

| Page | Route guard (held by) | Unguarded action | Backend gate now | File:line (dev `477c877a3`) |
|---|---|---|---|---|
| `/menus` | `composite-items.view` (cashier, viewer) | Delete menu item | `can:menus.manage` | `apps/web/src/features/menu/pages/MenuListPage.tsx:184-196`, `apps/web/src/routes/index.tsx:2612-2621` |
| `/pos/promotions` | `promotions.view` (viewer) | activate / pause / archive / delete | `can:promotions.manage` | `apps/web/src/features/promotions/pages/PromotionListPage.tsx:187,196,205,214`, `apps/web/src/routes/index.tsx:3023` |
| coupons list | `coupons.view` | revoke / reactivate / delete | `can:coupons.manage` | coupon list page (same pattern; probed only for `viewer` in the wave-0a handback) |

The denial is correct hardening. The defect is UI truthfulness: an action is offered that the caller cannot perform, and the 403 envelope is the only feedback.

## Required fix

Gate the row actions with `hasPermission(...)` from `usePermissions` (never a hand-rolled check): Menu delete on `menus.manage`, Promotion row actions on `promotions.manage`, Coupon row actions on `coupons.manage`. Hide or disable per the existing page convention (`docs/conventions/03-AUTHORIZATION.md`, frontend section). Add a Vitest per page rendering as `viewer` and asserting the action is absent, and a Playwright spec for `cashier` on `/menus`. `frontend-conventions-reviewer` gate required.

## Related

- m-4 from the same register: `POST api/v1/support-access/sessions/{session}/exit` has no `->whereUuid('session')`; a non-UUID segment 500s on PostgreSQL (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:60`, `SessionLifecycleService.php:146`). Pre-existing; one-line fix in the SupportAccess module, any lane.
