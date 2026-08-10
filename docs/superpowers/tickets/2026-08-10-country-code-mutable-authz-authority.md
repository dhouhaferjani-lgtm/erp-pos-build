# OWNER-VISIBLE: `company.country_code` Is a Mutable Field Now Acting as an Authorization Authority

Raised by: tenancy-authz-reviewer gate verdict (item G, 2026-08-10), finding F-1.
Status: GUARD LANE IMPLEMENTED on local branch `fix/company-identity-guards` (2026-08-10).
The owner selected post-provisioning immutability for company country/currency, plus a separate
`settings.fiscal.update` permission and dedicated old/new audit events for mutable tax identity.
The remaining `tax_configurations.company_id` scoping work is explicitly deferred to its own
reviewed follow-up lane and remains open.

## Why this needs a decision, not just a fix

Item G made `company.country_code` the authority for a security-relevant question: *may this company create a stamp-duty tax configuration?* (`CountryTaxConfigurationRegistry::supportsStampDuty()`, consumed at `TaxConfigurationController::validateDocumentTotalPolicy()`).

The guard itself is sound — country is read from trusted server state (`CompanyContext::requireCompany()->country_code`), a client-supplied `country_code` is discarded, and the merged-state evaluation on update blocks every smuggle attempt the reviewer tried.

The problem is the field it trusts. `country_code` is **runtime-mutable by the very actor the guard constrains**: `UpdateCompanySettingsRequest.php:41` accepts it and `CompanySettingsController.php:113,:150,:153` writes it, gated only on `settings.update` — a permission a company admin normally holds.

## The escalation, concretely

`tax_configurations` has **no `company_id`** (migration `2025_12_30_100000`); rows are country-scoped global reference data keyed on `country_code`. In a multi-company tenant with both an FR and a TN company:

1. FR company admin edits their own company's settings, flipping `country_code` FR → TN.
2. The capability check now answers "supported", so they create a TN stamp-duty configuration.
3. They flip `country_code` back to FR.
4. The stamp-duty row remains, live and country-scoped to TN — and it now applies to the **sibling TN company's** invoices, its `stamp_duty_amount`, its GL postings, and its signed fiscal payloads.

No step requires a permission the actor did not already have. The mutation path is pre-existing, but item G is what promoted `country_code` into an authorization decision, so the exposure is newly meaningful.

## Options (owner to choose)

1. **Immutability after creation.** `country_code` becomes set-once; changing it requires super-admin (or is impossible, with a "create a new company" answer). Cleanest, and matches how much downstream fiscal behaviour keys on it — chart of accounts, VAT strategy, stamp duty, tax seeders, precision scale.
2. **Super-admin gate.** Keep it mutable but move it behind a super-admin-only endpoint, out of tenant `settings.update`.
3. **Company-scope `tax_configurations`.** Add `company_id` (nullable for shared reference rows, set for company-authored ones) so a row created by company A can never apply to company B. Largest change; also fixes the blast radius rather than only the entry path.

Options 1 and 3 are complementary, not alternatives.

## Guard-lane disposition

The tenant settings path can no longer change `company.country_code` or `company.currency` after
provisioning, including through the nested `address.country` alias. Idempotent resubmissions are
accepted so the existing full-form settings save remains usable. Genuine corrections remain a
super-admin support-access procedure; support impersonation deliberately reaches the same guarded
tenant controller in v1, so the correction itself must be handled through the operational
super-admin escalation rather than the tenant settings form.

This closes the self-service authority escalation described above for the guard lane. It does not
close the sibling-company blast radius inherent in globally country-scoped `tax_configurations`;
that model should still not be described as company-isolated until the follow-up scoping lane is
implemented and reviewed.
