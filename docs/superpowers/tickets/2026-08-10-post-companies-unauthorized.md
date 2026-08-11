# P1: `POST /companies` Is Available to Any Authenticated Tenant Actor

Raised by: tenancy-authz gate follow-up, 2026-08-10.

Status: **OPEN — launch blocker.**

## Finding

`POST /api/v1/companies` is registered at
`apps/api/app/Modules/Company/routes.php:25` without `can:` middleware, and
`CreateCompanyRequest::authorize()` returns `true`. Any authenticated actor in a tenant can
therefore create a company, choose its country/currency, trigger country-specific COA and tax
provisioning, and receive an owner membership in the new company.

This is an authorization asymmetry. The adjacent `PUT /api/v1/companies/{id}` route was gated by
`settings.update` in the 2026-08-02 P0 fix and is regression-covered by
`CompanyUpdateAuthorizationTest`; `POST /companies` was not gated in that fix.

The gap also keeps the country-authority escalation open: a caller can mint a throwaway TN
company, switch `X-Company-Id`, and use the trusted TN country state when writing tenant-global,
country-scoped tax configurations. See
`docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md`.

## Required disposition

Gate creation behind an owner-approved permission (the conservative existing-family choice is
`settings.update`, unless product introduces a dedicated `companies.create` permission). Add
path-level tests proving a zero-permission authenticated user receives 403 and cannot create a
company, location, membership, hash-chain genesis row, COA, expense category, or tax configuration;
also retain an authorized creation control case.

Do not mark the country-authority launch item closed merely because existing-company settings are
immutable. Both the creation gate and the separately deferred company scoping of authored
`tax_configurations` must receive an explicit disposition.
