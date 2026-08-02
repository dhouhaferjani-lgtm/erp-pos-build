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
