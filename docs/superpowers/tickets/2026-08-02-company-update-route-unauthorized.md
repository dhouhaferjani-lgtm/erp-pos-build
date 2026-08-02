# Ticket: `PUT /companies/{id}` has NO permission gate at all — any authenticated tenant user can mutate fiscal/discount config

From the W-2 money-campaign leg (2026-08-02, `MTP-CFG-13`,
`apps/web/e2e/money-campaign/config-pricing.spec.ts`). Live-proven against demo-pharmacy-tn.

## Finding — P0

`UpdateCompanyRequest::authorize()` (`apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:19-21`)
returns `true` unconditionally, and the route itself carries no `can:` middleware:

```
Route::put('companies/{companyId}', [CompanyController::class, 'update'])->name('companies.update');
```

(`apps/api/app/Modules/Company/routes.php:29`). `CompanyController::update()`
(`Company/Presentation/Controllers/CompanyController.php:197-218`) performs no authorization check
of its own either — it only scopes the lookup by `tenant_id`.

**Consequence:** any authenticated user belonging to the tenant — including the lowest-privilege
seeded role (`viewer`, read-only by design, holds `settings.view` and nothing else) — can `PUT`
arbitrary changes onto **any field `UpdateCompanyRequest::rules()` accepts**, including
money-relevant company-wide policy:

- `discount_floor_mode` (Advisory vs Block — governs whether `DiscountPolicyDocumentValidator`
  actually enforces the discount/margin floor for every document in the company)
- `price_entry_mode`
- `default_target_margin`, `default_minimum_margin`, `default_max_discount_percent`
- `tax_status`, `default_tax_configuration_id` (fiscal registration status)

Live repro: logged in as `viewer`, `PUT /companies/{id}` with
`{"default_max_discount_percent": "<before>+1"}` returned **200** and the value changed.

**Contrast:** the conceptually-equivalent `PATCH /settings/company`
(`CompanySettingsController`) — which edits overlapping money-relevant fields — IS correctly
gated on `settings.update`. `PUT /companies/{id}` is a distinct, older/parallel route to the same
underlying `companies` row that was apparently never given the same treatment.

## Fix direction

Add `->middleware('can:settings.update')` (or a dedicated `companies.update` permission if the
two routes are meant to diverge in scope) to the route, and/or an explicit authorization check
inside `UpdateCompanyRequest::authorize()`. Needs an orchestrator/product ruling on whether this
route should be retired in favour of `/settings/company`, or kept and properly gated — two routes
writing the same money-relevant company fields with different (one currently ZERO) authorization
requirements is itself a latent risk regardless of which one is fixed.

## Disposition

Not fixed here (money-campaign agent scope is spec-only, no product-code edits). P0 — pre-launch
authorization gap on tenant-wide fiscal/discount configuration; recommend triage before any
external tenant onboarding. Live evidence pinned in `MTP-CFG-13`.

## FIXED — 2026-08-02 (commit `b9c0bd37a`)

`PUT /companies/{companyId}` now carries `->middleware('can:settings.update')`
(`apps/api/app/Modules/Company/routes.php`), matching `PATCH /settings/company`. The same audit
gated the two sibling ungated mutations found in the same route file: `PUT
.../reservation-settings` and `PUT .../receipt-settings`. TDD coverage:
`apps/api/tests/Feature/Company/CompanyUpdateAuthorizationTest.php` (viewer/cashier 403,
admin 200, across all three routes). `MTP-CFG-13` flipped from tripwire to regression assertion.

Gate review `docs/superpowers/reviews/2026-08-02-authz-fixlane-gate.md` (tenancy-authz-reviewer,
APPROVE-WITH-FIXES) raised F9: this ticket's "Fix direction" asked for an explicit
orchestrator/product ruling on the two-routes-same-fields question, which the fix commit did not
record. Ruling recorded here per that review, plus the F1 ORCHESTRATOR RULING it depends on:

- **F1 ruling (writes admin-only):** company-wide config writes across all of `PUT
  /companies/{id}`, `PUT .../reservation-settings`, `PUT .../receipt-settings`, `PATCH
  /settings/company`, and `POST /settings/company/logo` are **admin-only** via `settings.update`.
  The pre-fix state (any authenticated tenant user could write) was the anomaly, not an implied
  `manager` entitlement — `manager` holds `settings.view`/`settings.manage` but was never granted
  `settings.update` in `RolesAndPermissionsSeeder.php`. `settings.view`/`settings.manage` holders
  (manager, viewer) keep read access to every affected screen; only the mutation affordance is
  restricted. FE reflects this: the Save/mutation controls on `TaxSettingsPage`,
  `ReceiptSettingsTab` (in `CompanyPage`), `InventorySettings`, and `PosRefundPoliciesPage` are
  now disabled (with a `common:permissions.readOnlyEditHint` tooltip) for any caller without
  `settings.update`, while the pages themselves stay reachable for `settings.view` holders — see
  commit following `10ad37743` (gate-review fixlane, F1/F5/F7/F9).
- **F9 ruling (two routes, not retired):** `PUT /companies/{id}` and `PATCH /settings/company`
  are kept as two distinct routes, NOT collapsed into one. They are not equivalent: only `PUT
  /companies/{id}` accepts `tax_status`/margin fields and runs
  `CompanyTaxStatusValidationService` (`CompanyController.php:207-211`); `PATCH
  /settings/company` does not accept those fields at all
  (`UpdateCompanySettingsRequest.php:27-48`). There is no validation-bypass today because both
  routes now carry the identical `can:settings.update` gate. Retiring either route is deferred —
  no product driver for the consolidation, and the fields-accepted difference means it is not a
  pure duplication cleanup. Revisit if a third write path to `companies` is ever proposed.

Follow-up findings from the same gate review (F2 read-side scoped DTO, F3 `POST /companies`
gating, F4 PG-uuid-guard 500s, F6 inline-vs-route-level convention, F8 `MTP-PMT-05` vacuity
hygiene, F10 rule-19 precision ceiling on `updateReservationSettings`) are tracked as follow-up
tickets, not fixed in this lane.
