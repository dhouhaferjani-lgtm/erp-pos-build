# Inventory movement-entry idempotency lookup lacks company scope

**Source:** Wave 3C treasury merge review F-7, recorded by the 2026-08-19 M4 STOP-B ruling

**Severity:** P3 follow-up / future accounting-integrity slice

**Status:** OPEN

**Owner:** Accounting + tenancy/authz

## Defect

`GeneralLedgerService::createInventoryMovementEntry()` performs both its outer fast-path lookup and its in-transaction recheck using only `(source_type, source_id)`. It does not include the method's `companyId` argument. The adjacent inventory-entry reversal lookup correctly scopes by `company_id` as well as source coordinates.

Current anchors at `48cebf0f2`:

- unscoped fast path: `GeneralLedgerService.php:4572-4576`;
- unscoped in-transaction recheck: `GeneralLedgerService.php:4601-4605`;
- scoped reversal precedent: `GeneralLedgerService.php:4679-4684`.

A colliding source UUID in another company can therefore be returned as the idempotent result before either account lookup establishes the requested company scope. The current partial unique index also uses `(source_type, source_id)`, so changing only the Eloquent predicates would leave the persistence constraint and runtime identity model inconsistent.

## Future-slice acceptance

1. Define inventory movement-entry identity consistently as `(company_id, source_type, source_id)` in both lookup sites and the matching partial unique index/migration posture.
2. Add a two-company regression with the same source id: company B must create/resolve only company B's entry and lines; it must never return or post company A's entry.
3. Preserve same-company retry idempotency and synchronous Draft repair.
4. Reconcile reversal and batch-write-off lookup/index shapes in the same review, without expanding this ticket into unrelated GL idempotency families.
5. Run on PostgreSQL and prove the database constraint agrees with the application lookup.

## Scope ruling

No Wave 3D code change is authorized for F-7. This ticket is the complete M4 deliverable for the finding.
