# Ticket: /settings/setup renders with no RequirePermission / ModuleGuard — verify + gate (possible authz gap)

Flagged by the 2026-08-02 full-E2E campaign plan (F-6) while enumerating the route surface
(apps/web/src/routes/index.tsx): the `/settings/setup` route element carries no
`RequirePermission` moduleKey and no module gate, unlike sibling settings routes.

**ADJUDICATED 2026-08-02 (tenancy-authz-reviewer): P1, CHANGES-REQUESTED — not P0.** Page is a
read + router-push (zero mutations); all mutation destinations 403 for cashier (live-confirmed);
tenancy pinning correct. But rule 12 both-layers IS violated on the ONLY ungated child under
/settings, `GET onboarding/status` has no `can:` (cashier reads full config posture, live 200;
routes.php:21 comment falsely claims a permission), and the Dashboard onboarding banner renders
for users who can't action any step → dashboard→setup→dashboard bounce loop (launch-visible).

**Fix list (exact):**
1. FE: wrap `/settings/setup` in `<RequirePermission moduleKey="settings">` (routes/index.tsx:2352,
   pattern of :2216/:2226). `settings` is a permission-bundle moduleKey, NOT a vertical — no
   backend `module:` middleware warranted.
2. BE: `->middleware('can:settings.view')` on `GET onboarding/status` (Tenant/routes.php:28) and
   fix the stale comment at :21. No seeder/cache-reset needed (`settings.view` pre-exists on
   admin/manager/viewer; 403 degrades gracefully — api.ts:186 console-only, banner hides on []).
3. FE: gate Dashboard's onboarding query `enabled` with `hasPermission('settings.view')`
   (Dashboard.tsx:93) — kills the bounce loop.
4. Test: cashier deny-path 403 on onboarding/status (extend ModuleGatingTenantIsolationTest).
5. MINOR: CompanySettingsController auth placement inconsistency (FormRequest vs inline) — prefer
   route-level `can:settings.update` to survive refactors.
