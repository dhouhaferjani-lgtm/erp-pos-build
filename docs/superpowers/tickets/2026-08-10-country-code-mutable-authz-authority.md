# OWNER-VISIBLE: `company.country_code` Is an Authorization Authority with an Open Creation Bypass

Raised by: tenancy-authz-reviewer gate verdict (item G, 2026-08-10), finding F-1.

Status: **PARTIALLY CLOSED** on local branch `fix/company-identity-guards` (2026-08-10).

Launch readiness: **OPEN — do not inherit “closed” from the settings-mutation fix.**

The owner selected post-provisioning immutability for company country/currency, plus a separate
`settings.fiscal.update` permission and dedicated old/new audit events for mutable tax identity.
That closes the `PATCH /api/v1/settings/company` mutation path. It does not close the
un-permissioned `POST /api/v1/companies` creation path or the deferred lack of company scoping on
`tax_configurations`.

## Why this remains an authorization issue

Item G made `company.country_code` the authority for a security-relevant question: *may this
company create a stamp-duty tax configuration?* (`CountryTaxConfigurationRegistry::supportsStampDuty()`,
consumed at `TaxConfigurationController::validateDocumentTotalPolicy()`).

The settings guard itself is sound. Country is read from trusted server state, client-supplied
country is discarded by the tax-configuration write, and an existing company's `country_code` and
`currency` can no longer be changed through tenant settings, including through
`address.country`. Idempotent same-value submissions remain allowed.

However, an authenticated actor can still choose that trusted server state by creating another
company:

- `apps/api/app/Modules/Company/routes.php:25` registers `POST /api/v1/companies` without `can:`
  middleware.
- `CreateCompanyRequest::authorize()` returns `true`.
- `CompanyController::store()` accepts the submitted country and gives the creator an owner
  membership in the new company.
- `tax_configurations` has no `company_id`; authored rows are tenant-global reference data keyed
  only by `country_code`.

## The still-reachable escalation

In a tenant that already has a legitimate TN company:

1. An authenticated actor with no company-creation or settings permission calls
   `POST /api/v1/companies` and mints a throwaway TN company.
2. The actor switches `X-Company-Id` to the throwaway company. The server now derives TN from
   trusted company state.
3. An actor holding `taxation.tax_configurations.manage` writes a TN stamp-duty configuration.
4. Because the row is country-scoped and tenant-global, that configuration also applies to the
   sibling legitimate TN company's invoices, stamp-duty amount, GL postings, and signed fiscal
   payloads.

The creation authorization gap is tracked in
`docs/superpowers/tickets/2026-08-10-post-companies-unauthorized.md`. The larger blast-radius fix —
company-scoping authored `tax_configurations` — remains a separate reviewed lane.

## Closed versus open

**Closed:** post-provisioning country/currency mutation through tenant settings. Neither direct
fields nor the nested country alias can change the authorization authority after creation.

**Open:** arbitrary authenticated company creation can still mint the desired country authority;
country-scoped `tax_configurations` remain tenant-global and can affect sibling companies. Launch
readiness must remain open until the creation path is gated and the owner either lands company
scoping for authored tax configurations or explicitly accepts that deferred cross-company risk.

## Correction-path note

There is no in-product correction path for a genuinely mis-provisioned country/currency. Support
impersonation reaches the same unconditional immutability guard. The required owner acknowledgement
and missing support-operations procedure are tracked in
`docs/superpowers/tickets/2026-08-10-no-fiscal-identity-correction-path.md`.
