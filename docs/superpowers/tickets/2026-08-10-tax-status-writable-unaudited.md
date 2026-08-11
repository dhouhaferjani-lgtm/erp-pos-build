# Fiscal Follow-up: Company `tax_status` Is Writable Under Plain Settings Permission and Unaudited

Raised by: tenancy-authz gate follow-up, 2026-08-10.

Status: **OPEN — owner disposition required.**

## Finding

`UpdateCompanyRequest` accepts `tax_status` alongside tax identity fields
(`apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:47-50`). The route is
gated only by `settings.update`. `CompanyController::update()` applies the posted-document fiscal
lock, but a permitted pre-posting status change is not classified as a fiscal-identity change and
does not produce the dedicated old/new fiscal audit event.

That differs from `vat_number` on the same endpoint: an actual VAT-number change is included in
`CompanyFiscalIdentityService`, requires `settings.fiscal.update`, and is audited inside the update
transaction. `tax_status` changes tax treatment and recoverability but currently remain writable
under the plain settings permission and are silent at this controller boundary.

## Required disposition

Choose and record one of these outcomes:

1. Treat `tax_status` as fiscal identity: require `settings.fiscal.update`, include old/new status
   in a dedicated audit event, and keep update plus audit atomic.
2. Keep it under `settings.update`, but document the product/compliance rationale for excluding it
   from the fiscal split and identify the authoritative audit trail that makes the change visible.

Whichever outcome is chosen needs path-level authorization, audit, and rollback coverage. The
existing posted-document immutability lock is complementary; it does not resolve who may change
the status before the first fiscal posting or how that change is evidenced.
