# Fiscal Follow-up: Branch Tax Identity Is Writable by the Inventory Manager Tier Without Audit

Raised by: tenancy-authz and fiscal-light gate follow-up, 2026-08-10.

Status: **OPEN.**

## Finding

`PATCH /api/v1/locations/{location}` is gated by `inventory.adjust`, which the seeded `manager`
tier holds. `UpdateLocationRequest` accepts `tax_id` and `vat_number`, and
`LocationController::update()` writes the validated payload directly. There is no fiscal-specific
permission check, confirmation, or old/new audit event for an actual branch tax-identity change.

These are not merely inventory labels. `TerminalResource.php:93-94` sends the location `tax_id`
and `vat_number` to the POS terminal; the branch-tax-identity flow uses those values for POS
seller identity and receipts. A manager-tier inventory permission can therefore silently change
fiscal output identity.

## Required disposition

Separate branch tax-identity mutation from general location/inventory edits. Require an
owner-approved fiscal permission for actual changes, record old/new values atomically with the
location update, and add manager-deny, authorized-change, idempotent, audit, and rollback tests.
The UI should explain the elevated requirement rather than ending in a 403.

Cross-reference the owning project and its value-source contract:

- `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md`
- `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md`

That project intentionally made location tax identity reach receipts; this follow-up supplies the
missing authorization and evidence boundary around subsequent edits.
