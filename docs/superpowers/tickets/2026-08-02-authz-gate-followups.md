# Ticket: authz gate follow-ups (F2 scoped settings DTO, F3 company-create abuse, F4 uuid 500)

From the authz fix-lane gate (2026-08-02, APPROVE-WITH-FIXES —
docs/superpowers/reviews/2026-08-02-authz-fixlane-gate.md). F1 (manager FE affordances) + F5/F7/F9
are being applied in-lane under the orchestrator ruling: **writes stay admin-only
(`settings.update`) — the previously-ungated routes were the anomaly, not manager entitlement; FE
save affordances must reflect the holder's real permission.**

## F2 — P1: reservation-settings read leaks anti-fraud thresholds to cashiers

Live: cashier GET `/companies/{id}/reservation-settings` → 200 including
`manager_override_threshold_amount`, `goodwill_four_eyes_threshold`,
`customer_history_search_alert_thresholds` — the thresholds that police that exact role. Do NOT
blanket-gate: POS/voucher clients legitimately read parts of it as cashier (`voucherApi.ts:63`,
`useCompanySettings.ts:28`). Fix = scoped DTO: split the response into a low-privilege slice
(what POS needs) and a `settings.view`-gated full view. Enumerate consumers before shaping.

## F3 — P2: POST /companies fully ungated (intentional, but unbounded)

Not privesc (gate verified team-scoped roles + no self-assign), but any viewer can create
unlimited companies, each seeding COA + hash chains + tax configs (storage/fiscal-object
amplification). Route comment says intentional for onboarding. Needs a product ruling: gate
behind a permission, or keep + add a per-tenant company-count/plan limit.

## F4 — P1: PG uuid guard absent on companies routes — GET /companies/my → 500

`CompanyController.php:180/226/500` — the KNOWN repo pitfall (validate `Str::isUuid()` before
`where` on uuid columns or PG 500s). Live-reproduced. Sweep the controller (and route-model-less
lookups in the module) and add the guard → 404 not 500.

## Also carried

F6 (logo DELETE inline-only — no hole, consistency); F8 (loginAsRole vacuous-pass hardening in
campaign helpers — `apiRequest` never throws, permissions default `[]`; make the helper fail loud).

## m6 — P1: `InventorySettings.tsx` margin-save mutation targets a route that does not exist

From the FE-batch gate fix round (2026-08-02,
`docs/superpowers/reviews/2026-08-02-fe-batch-gate.md`, m6). Investigated per the review's fix
directive; NOT a one-line URL correction, so left un-fixed here per that directive.

`apps/web/src/features/settings/components/InventorySettings.tsx:104` (`GET /company`) and `:162`
(`PATCH /company`, the margin-defaults save mutation) both target a bare `/company` route. No such
route exists anywhere in `apps/api` — grep of every `routes.php` finds no `Route::get/patch('company'…)`,
and `curl http://localhost:8010/api/v1/company` returns `404` live. Both calls have been dead since
this screen shipped; `default_target_margin` / `default_minimum_margin` / `allow_below_cost_sales`
can never be loaded or saved through this leg. (The sibling `saveReservationMutation` in the same
file, hitting `PUT /companies/{id}/reservation-settings`, works fine — only the margin-defaults leg
is broken. Because the Save button's `hasChanges` is `hasInventoryChanges || hasReservationChanges`
and `hasInventoryChanges` is permanently falsy — `company` stays `undefined` forever — the button can
still be enabled by a reservation-field edit, silently no-op on the margin fields.)

**What it was supposed to hit:** `PUT /companies/{companyId}` (`CompanyController::update`,
`apps/api/app/Modules/Company/routes.php:29`, `can:settings.update` — see the FIXED entry above),
which reads `default_target_margin` / `default_minimum_margin` via
`UpdateCompanyRequest::rules()` (`apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:53-54`)
and returns them via `CompanyController::formatCompany()` (`CompanyController.php:589-596`) — so a
GET/PATCH → GET/PUT `companies/{id}` swap would restore `default_target_margin` and
`default_minimum_margin`.

**Why this is NOT a one-line fix:** `allow_below_cost_sales` is NOT in
`UpdateCompanyRequest::rules()` at all, and NOT in `formatCompany()`'s response shape, even though
it is a real `Company` model column (`fillable` + cast at `Domain/Company.php:270,317`). Pointing
the URL at `companies/{id}` would silently start working for the two margin-percent fields but keep
silently dropping `allow_below_cost_sales` on every save (the toggle would always read back as its
model default, never persisting a user's `false`→`true` change) — a partial fix that trades one
silent-failure mode for a narrower one. Needs backend work: add
`'allow_below_cost_sales' => ['sometimes', 'boolean']` to `UpdateCompanyRequest::rules()` and
`'allow_below_cost_sales' => $company->allow_below_cost_sales` to `formatCompany()`, THEN the FE
URL swap (`/company` → `/companies/${currentCompany.id}` for both GET and PATCH→PUT), with a
regression test asserting the full margin+toggle round-trip.

## m7 — tax-configuration CRUD has no FE affordance gate (different permission, out of F1 scope)

From the same fix round, m7. `TaxSettingsPage.tsx`'s Tax Configurations tab (add/edit/delete tax
rates, `useDeleteTaxConfiguration` at `TaxSettingsPage.tsx:117`) drives CRUD gated server-side by
`can:taxation.tax_configurations.manage` (`apps/api/app/Modules/Taxation/routes.php:20-32`) — a
distinct permission from `settings.update`, which only covers the company-settings form on the same
page (already gated per the F1 ruling above). The tax-config CRUD buttons (add/edit/delete rows)
currently carry no `hasPermission('taxation.tax_configurations.manage')` check, so a
`settings.update`-less-but-otherwise-privileged holder (or vice versa) can see live/enabled CRUD
affordances that 403 server-side. Same defect class as F1, different permission key — needs its own
`canManageTaxConfigs` gate + `common:permissions.readOnlyEditHint` treatment on that tab's
add/edit/delete controls, following the `GoodsReceiptListPage.tsx:478-497` disabled+title+visible-span
pattern. Not implemented in this lane (out of the F1/M1 scope, which was `settings.update` only).
