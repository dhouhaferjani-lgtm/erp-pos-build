# Gate review — authz fix lane (b9c0bd37a + 10ad37743), local `dev`, NOT pushed

Reviewer: tenancy-authz-reviewer (adversarial). Date: 2026-08-02.
Spec = ADJUDICATED sections of
`docs/superpowers/tickets/2026-08-02-company-update-route-unauthorized.md` and
`docs/superpowers/tickets/2026-08-02-settings-setup-route-ungated.md`.

**VERDICT: spec ✅ — quality APPROVE-WITH-FIXES.**
Both P0/P1 holes are genuinely closed and live-verified. No new cross-tenant or auth-bypass
defect introduced. Findings below are one launch-visible capability regression (F1), three
gaps the commits' own stated audit should have caught (F2–F4), and test/doc hygiene.

## What was verified (evidence)

Static:
- `apps/api/app/Modules/Company/routes.php:29-31, 37-39, 46-48` — `can:settings.update` present on
  the three PUTs.
- `apps/api/app/Modules/Tenant/routes.php:26-31, 34-36` — `can:settings.update` on PATCH
  `/settings/company` + POST logo; `can:settings.view` on GET `onboarding/status`; stale comment fixed.
- `apps/web/src/routes/index.tsx:2351-2359` — `/settings/setup` wrapped in
  `<RequirePermission moduleKey="settings">`; `moduleKey` → `['settings.view']`
  (`apps/web/src/hooks/usePermissions.ts:40`, `:115-119`).
- `apps/web/src/features/dashboard/Dashboard.tsx:92-104` — onboarding query `enabled` now includes
  `hasPermission('settings.view')`.
- Middleware ORDER confirmed live, not assumed: `php artisan route:list --path=companies -v` prints
  `api → Authenticate:sanctum → SetPermissionsTeam → EnforceTokenTenantClaim →
  Authorize:settings.update` — `Authorize` runs AFTER `SetPermissionsTeam`, so Spatie team context
  (`SetPermissionsTeam.php:28`, `setPermissionsTeamId($user->tenant_id)`) is set before the gate
  resolves. Same for `onboarding.status` (`Authorize:settings.view` last).
- No new permission introduced. `settings.view`/`settings.update` are pre-existing
  (`RolesAndPermissionsSeeder.php:458-459`) → **no seeder re-sync and no `permission:cache-reset`
  required for these two commits.** (Verified as a deploy-safety property, not assumed.)

Tests run (by path, not the full suite):
- `tests/Feature/Company/CompanyUpdateAuthorizationTest.php` — 7/7 OK.
- `tests/Feature/Company/CompanyTaxStatusChangeTest.php` — 10/10 OK.
- `tests/Feature/Progression/ModuleGatingTenantIsolationTest.php` — 21/21 OK (the new cashier
  deny-path uses `makeTargetUser()` at `:483-497`, which really does `assignRole('cashier')`).
- Full affected-route regression set: `ReceiptSettingsTest`, `RefundPolicySettingsTest`,
  `ReservationSettingsPrecisionTest`, `Tenant/CompanySettingsTest` — 66/66 OK. All allow-path
  suites already seed `admin`, so the new gates did not silently turn existing coverage into
  vacuous passes.
- Web: `Dashboard.tenantScope.test.tsx`, `src/routes/routes.test.tsx`,
  `settings/__tests__/TailTenantScope.test.tsx` — 16/16 OK.

Live probes (api `:8010`, tenant `demo-pharmacy-tn`, db-per-tenant; mutations fired ONLY as
deny-paths):
| caller | PUT /companies/{id} | PUT reservation-settings | PUT receipt-settings | PATCH /settings/company | GET onboarding/status | GET /companies/{id} |
|---|---|---|---|---|---|---|
| viewer | **403** | **403** | **403** | 403 | 200 | 200 |
| cashier | **403** | **403** | — | 403 | **403** (was 200) | 200 |
| manager | **403** | **403** | — | 403 | 200 | 200 |
`owner@pharmabio.tn` resolves to Spatie role `admin` and holds `settings.update` (`/auth/me`), so
the campaign's allow-paths (MTP-CFG-15/16, the CFG-13 restore leg) still function.

## Findings

### [Important] F1 — `manager` (and any custom role with settings.view/manage but not
settings.update) silently loses write access to 4 settings screens; FE still shows the forms.
`RolesAndPermissionsSeeder.php:549` grants manager `settings.view, settings.manage` — **not**
`settings.update` (`apps/web/src/hooks/permissionsMap.generated.ts:242`: `'settings.update': ['admin']`).
Before b9c0bd37a, manager could write all three Company PUTs. Live-confirmed now 403 (table above).
The FE routes that call them are gated only on `moduleKey="settings"` (= `settings.view`):
`routes/index.tsx:2224` (tax → `TaxSettingsPage.tsx:116` `apiPut /companies/{id}`), `:2254`
(inventory → `InventorySettings.tsx:169` reservation-settings), `:2264` (pos-refund-policies →
`settings/api/posRefundPoliciesApi.ts:13`), plus the receipt tab
(`ReceiptSettingsTab.tsx:184`). So manager/viewer still see an enabled Save button and get a generic
toast on failure (`InventorySettings.tsx:174-176`, `TaxSettingsPage.tsx:131-133`) — a 403 dead-end,
and rule-12 "both layers" is not satisfied for the *mutation* affordance.
POS refund-policy configuration is launch-relevant (Lane C), so this needs an explicit product
ruling, not an implicit one.
*Fix:* either (a) record the ruling "company-wide config = admin-only" and gate the FE save
affordances/forms on `hasPermission('settings.update')` (read-only mode for view-only roles), or
(b) grant `settings.update` to manager in the seeder — which then DOES require a seeder re-sync +
`permission:cache-reset` on every existing tenant.

### [Important] F2 — read side left ungated, contradicting the rationale used to gate
`onboarding/status`.
Ticket 2 gated GET `onboarding/status` because it "reveals full config posture". But
`Company/routes.php:28` (GET company), `:34-35` (GET reservation-settings) and `:42-43`
(GET pos-settings) carry no gate at all, while `GET /settings/company` does
(`CompanySettingsController.php:52`). Live: **cashier** reads
`/companies/{id}/reservation-settings` → 200 with
`manager_override_threshold_amount: "50.00"`, `manager_override_threshold_percent: "10.00"`,
`daily_refund_cap_override_allowed`, `goodwill_four_eyes_threshold: "250.00"`,
`customer_history_search_alert_thresholds.{rejected_specificity_per_hour, same_partner_per_day}` —
i.e. the anti-fraud thresholds designed to police that exact role, plus the alerting tripwires.
Cashier also reads `/companies/{id}` → 200 (tax_id, vat_number, margin defaults,
`discount_floor_mode`, `tax_status`).
Not introduced by this lane (pre-existing), but it is inside the file the commit says it audited,
and it makes the P1 gate largely cosmetic. *Do not "fix" with a blanket `can:settings.view`*: the
POS/voucher client legitimately reads this endpoint (`features/vouchers/api/voucherApi.ts:63`,
`features/pos/hooks/useCompanySettings.ts:28`) as a cashier. The correct fix is a scoped read DTO
(operational fields for cashier; fraud thresholds behind `settings.view`/`fraud-settings.view`).

### [Important] F3 — `POST /companies` remains completely ungated and the exemption is undocumented.
`Company/routes.php:25` + `CreateCompanyRequest.php:11-13` (`authorize(): return true`). Any
authenticated tenant member — viewer, cashier, technician — can create companies. Per
`CompanyController.php:70-165` each create writes a Company + default Location + an `owner`
`UserCompanyMembership` + a genesis row per `HashChainType` + a full chart of accounts + country tax
provisioning, inside one transaction, with no plan/quota check.
*Not* a privilege escalation (verified: Spatie roles are tenant-team-scoped so the creator keeps
`viewer`; the only `isOwner()` authorization branch is
`UserController.php:900-913`, reachable only with `users.manage_location_access`, which viewers lack;
role self-assignment is gated at `AssignRoleRequest.php:20-23`). It IS unbounded write
amplification + company-switcher pollution by an unprivileged user. Not probed live (would create
data) — reasoned from code.
*Fix:* gate on a `companies.create`-class permission (new permission ⇒ seeder + cache-reset), or at
minimum record in the route comment why creation is intentionally open (the current comment at
`:17-18` explains only the missing company context, not the missing authorization).

### [Important] F4 — PG uuid guard still missing on the same routes (500 instead of 404).
Live: `GET /api/v1/companies/my` → **500** `SQLSTATE[22P02]: invalid input syntax for type uuid: "my"
(Connection: tenant)`. Source: `CompanyController.php:180-190` (`show`), `:226-233`
(`getReservationSettings`), `:500-507` (`getPOSSettings`), `:197-206` (`update`) all bind the raw
`{companyId}` path segment into a uuid column with no `Str::isUuid()` guard. The new `can:` gate now
shields the PUTs from unprivileged callers, but an admin hitting a malformed id still 500s, and the
GETs are 500-able by any authenticated user. Known repo-wide pitfall; this file was in scope of the
audit and no guard was added.

### [Minor] F5 — `MTP-CFG-14` is now stale and asserts the opposite of commit 10ad37743.
`apps/web/e2e/money-campaign/config-pricing.spec.ts:92-96`: "onboarding status has no permission gate
of its own (read-only progress view)" and the follow-on comment "proving the missing
RequirePermission on /settings/setup itself is benign". Both statements are false after this lane.
The case still passes only because `viewer` happens to hold `settings.view`. Commit 1 correctly
flipped its tripwire (CFG-13); commit 2 did not do the same for CFG-14.
*Fix:* re-point CFG-14 at cashier → expect 403 on `onboarding/status`, and assert
`/settings/setup` redirects cashier to `/dashboard`.

### [Minor] F6 — the commit's own "route-level over inline" principle applied unevenly.
`Tenant/routes.php:32` `DELETE settings/company/logo` still relies solely on the inline check at
`CompanySettingsController.php:228` (live-probed: viewer → 403, so no hole) while its two siblings
got route-level gates. Symmetrically, on the Company side the route gates were added but
`UpdateCompanyRequest.php:19-21` and `UpdateReceiptSettingsRequest.php:11-13` still
`return true` — the opposite belt-and-braces choice from the one argued in commit 2 item 5.
Pick one convention and state it.

### [Minor] F7 — FE deny-path coverage missing for the thing that was actually fixed.
`Dashboard.tenantScope.test.tsx` was changed `roles: []` → `roles: ['admin']` to keep the query
firing; nothing asserts the query is SKIPPED for a user without `settings.view` (the fix), and no
test covers `RequirePermission` on `/settings/setup`. Both are cheap:
one `roles: ['cashier']` case asserting no `/onboarding/status` fetch, one render test asserting the
redirect. (Backend deny-path coverage IS present and real — `CompanyUpdateAuthorizationTest`
viewer+cashier 403 with a `assertDatabaseMissing` follow-up at `:84-87`.)

### [Minor] F8 — the `MTP-PMT-05` flake claim: plausible, and the test is vacuity-prone.
`config-pricing.spec.ts:206-219` logs in as owner, GETs `/auth/me`, and asserts a permissions array
does **not** contain two strings. `apiRequest` (`helpers.ts:104-140`) returns `{status, body}` and
never throws, and the test defaults `permissions` to `[]` — so a 401/403/500 from `/auth/me` makes
the case pass **vacuously**. Its only real failure mode is a Playwright timeout inside
`loginAsRole` (`helpers.ts:55-57`, two 15 s `expect`s against a shared dev server) — i.e. exactly
the infra flake claimed. It touches no surface either commit changed (only `/auth/me`), so it
cannot be masking an authz regression from this lane. *Fix (hygiene):* assert
`me.status === 200 && permissions.length > 0` before the negative assertions.

### [Minor] F9 — ticket 1's explicit ask was not answered.
The ticket asks for "an orchestrator/product ruling on whether this route should be retired in
favour of `/settings/company`". The commit gates and closes the ticket without recording a ruling.
Two routes still write the same `companies` row on the same permission; note that they are NOT
equivalent — only `PUT /companies/{id}` accepts `tax_status`/margins and runs
`CompanyTaxStatusValidationService` (`CompanyController.php:207-211`); `PATCH /settings/company`
does not accept those fields at all (`UpdateCompanySettingsRequest.php:27-48`), so there is no
validation-bypass today. Record that, or the duplication will be re-litigated.

### [Observation, out of diff] F10 — rule-19 drift in the newly gated handler.
`CompanyController::updateReservationSettings` validates money fields
(`manager_override_threshold_amount`, `goodwill_named_customer_threshold`,
`goodwill_four_eyes_threshold`, `daily_refund_cap_per_cashier`, `high_value_alert_threshold`) as bare
`numeric` with no `/^-?\d+(\.\d{1,3})?$/` ceiling (`CompanyController.php:316-352`). Values are
`bcformatStrict`-ed on write, so no float corruption — a 4th decimal is silently truncated instead
of 422'ing. Pre-existing; flag for the precision backlog, not this gate.

## Not defects (checked, cleared)
- No queued job, console command, or server-side HTTP client calls any of these routes
  (`grep Http::put|Http::patch` in `app/` → none; no `CompanyController` reference outside the
  Company module). The POS/Tauri client never calls `/companies/*` or `/settings/company`. So the
  "system token / device sync" regression hypothesis is unfounded.
- No FE bounce loop: `/dashboard` (`routes/index.tsx:502-508`) is NOT wrapped in
  `RequirePermission`, so `RequirePermission`'s `Navigate to="/dashboard" replace`
  (`RequirePermission.tsx:73-76`) always lands. Cashier deep-link to `/settings/setup` bounces once,
  cleanly, and the Dashboard banner no longer renders (query disabled).
- `SetupChecklist.tsx:22` fires the same query without its own permission `enabled`, but it is only
  mounted under the now-gated `/settings/setup` route (sole consumer: `SetupChecklistPage.tsx:11`).
- `CompanyTaxStatusChangeTest` setUp change (seed + `assignRole('admin')`) does not mask a real
  behaviour change: that suite tests tax-status transition rules, and every other suite touching
  these routes already acted as admin. The behaviour change it does mask is F1 (manager), which is
  covered above.
- Tenancy: all four probes ran against the live db-per-tenant stack; no central-vs-tenant connection
  question is raised by either commit (no model/connection changes).

## One line before merge
Get a product ruling on F1 (manager vs `settings.update`) and reflect it in the FE save
affordances; F2/F3/F4 can ship as follow-up tickets, F5–F9 are hygiene.
