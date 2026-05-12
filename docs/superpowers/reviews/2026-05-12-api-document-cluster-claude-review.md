# api.document Cluster — Claude Review

Cluster: `api.document`
Cluster aggregate status (inventory): `fixed`
Verdict: **CONDITIONAL APPROVE** (pending non-TanStack closure plan execution for 3 open callsites)

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Document domain (quotes, sales orders, invoices, credit notes, delivery notes, return notes, purchase orders, draft persistence). Pre-sweep, several controllers and services chained partner/document/product reads without combining tenant + company predicates. Document is the high-traffic core of the sales/purchase flow and the input to fiscal hash chains.

Inventory callsite total: **45 callsites — 42 fixed, 3 pending** (`api.document.043, .044, .045` — `DraftPersistenceService` rows).

## Implementation summary

Top fix commits: `5174e756` (19 callsites), `8bd2b13a` (15), `dd56691b` (8).

Top files: `CreditNoteController`, `DocumentData`, `CreditNoteService`, `AgedReceivablesService`, `DocumentPostingService`.

The 3 open `DraftPersistenceService` rows are manual scanner stubs whose code already carries tenant+company predicates (`DraftPersistenceService.php:207-219, 433-444`); the rows are inventory-state-behind-code — the closure plan's Task 5 Step 1 will verify and lock them.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Document/DocumentTenantIsolationTest.php`. The closure plan also adds `RefundResidualTenantIsolationTest.php` for the DraftPersistenceService rows.

## Per-round review trail

Three implementation rounds + two Opus rounds:

- `2026-05-05-api-document-cluster-codex-review.md`
- `2026-05-05-api-document-cluster-codex-round2-review.md`
- `2026-05-05-api-document-cluster-codex-round3-review-{batch1,batch2,batch3}.md`
- `2026-05-05-api-document-cluster-opus-review.md`
- `2026-05-05-api-document-cluster-opus-round2-review.md`

## Gates evaluated

1. **Cross-document linkage scoping**: credit-note → invoice, delivery-note → order, return-note → invoice/delivery-note all carry both predicates.
2. **Fiscal-chain non-interference**: cluster does not modify hash chains; only the read/write paths. Hash chain re-tests remain green per fiscal-chain CI.
3. **Draft persistence batch-load scope**: products/services batch lookups in `DraftPersistenceService::batchLoad*` scoped by `$document->tenant_id` + `$companyId` (verified directly).

## Open work (handled by closure plan)

| Row | File | Code state | Plan task |
|---|---|---|---|
| api.document.043 | DraftPersistenceService.php:422 | Tenant+company scoped (verified) | Plan Task 5 Step 1: lock |
| api.document.044 | DraftPersistenceService.php:425 | Tenant+company scoped (verified) | Plan Task 5 Step 1: lock |
| api.document.045 | DraftPersistenceService.php:239 | Tenant+company scoped (verified) | Plan Task 5 Step 1: lock |

## Disposition

CONDITIONAL APPROVE. Upgrades to APPROVE once the closure plan executes Task 5 Step 1 and the 3 manual stub rows transition to `status=fixed`.

## Cross-references

- Closure plan: `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md` Task 5 Step 1
- Cluster-level test: `apps/api/tests/Feature/Document/DocumentTenantIsolationTest.php`
- New regression test: `apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php`
