# M3 evidence — 3C tail controls

M3 is implemented by `f6e14340c`. It completes T18, T19, T19b, detector
checks D-a/D-b/D-e/D-g, D-f's POS and goods-receipt arms, the ruled work-order
no-op, and R-5's scoped SQL counterpart. C-5 was pulled forward into the
atomic M2 cutover; M3 re-verifies that live composite root.

## T18 — movement-keyed test surface

`GeneralLedgerService::createCOGSEntry()` is deleted and has zero remaining
production or test references. The accounting integration tests now create
and assert `inventory_exit` / `inventory_entry` entries keyed to stock
movements. The no-`CompanyContext` chain-sequence case was moved, not lost: it
now calls `createInventoryMovementEntry()` and proves the same sequence chain
without a bound company context. `CompleteSalesCycleWithReturnTest` exercises
the real PostgreSQL root and asserts the delivery exit and return entry at the
movement seam; invoice-time `cogs` remains absent.

## T19 — deploy artifact and release note

The blocking, unattended PostgreSQL artifact is
`scripts/preflight-wave3-inventory-gl-cutover.sql`. It fails closed on:

1. any legacy invoice-keyed `source_type = 'cogs'` entry;
2. any duplicate `(source_type, source_id)` across all five values in
   `InventoryGlSourceTypes::ALL`;
3. any pre-T4 delivery movement attached to a non-physical product line.

It must be run separately on every real deploy-target tenant database. The
local run against `autoerp_test` completed all three `DO` blocks and returned:

```text
legacy_invoice_cogs_count: 0
duplicate groups: 0 rows
non-physical delivery movement groups: 0 rows
```

That local database is not deployment evidence. In particular, M0's local
R-11 population was empty, so neither it nor this syntax/probe run discharges
the hard per-tenant pre-promotion checks.

The seven-section release note is
`docs/follow-ups/2026-08-10-dpa-wave3-cogs-at-exit-release-note.md`. It records
the forward-only/no-backfill posture, watermark behavior, POS cutover, failure
behavior 4a/4b/4c, D-20/D-21/section-0.14 follow-ups, the known false-tamper
cross-reference, and 3E delivery-first behavior. The required tickets are:

- `docs/superpowers/tickets/2026-08-18-stock-adjustment-document-gl-leg.md`
- `docs/superpowers/tickets/2026-08-18-scrap-inventory-gl-source-unification.md`
- `docs/superpowers/tickets/2026-08-18-reverse-document-gl-advisory.md`
- `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`

## T19b — forward-only compensating command

`accounting:reverse-inventory-movement-entries` requires an explicit `--from`
timestamp and `--confirm`. It selects only Posted `inventory_exit` and
`inventory_entry` entries, keeps each original immutable, writes a new Posted
`inventory_movement_reversal` entry with every debit/credit swapped exactly,
and keys idempotency to the original journal-entry id. Tests prove both source
types, exact line inversion, zero net balance by account, a second-run no-op,
and the lower timestamp bound. The command is registered for operator use and
has no normal application-path caller.

## Detector contracts

- D-a reports an above-watermark, costed COGS movement only after its two-hour
  grace and stays silent for pre-watermark, grace-period, historical, and
  already-covered rows.
- D-b reports null/zero COGS cost and excludes `stock_adjustment`.
- D-e reports non-COGS `requiresGLEntry()` movements without an entry and
  carries the same explicit `stock_adjustment` exclusion.
- D-g reports only above-watermark return-basis records whose source is
  `current_cost`.
- D-f POS is a line-level anti-join on `pos_receipt` / receipt / product and has
  no grace window. D-f goods receipt uses the persisted line movement link and
  the established `Document` / purchase-order / product tuple. Both restrict
  candidates to tenant/company-scoped physical products and emit `amount=null`.
- The work-order arm is a named, ticket-citing no-op. Its negative fixture
  proves work-order product lines do not appear in D-f while the parts goods
  lane remains deferred.

R-5 is documented in `PhysicalLinePredicate` as the exact tenant/company/
physical-product SQL counterpart used by both document scanners. Its red-first
cross-tenant fixture originally made `InvoicedBeforeDeliveryScanner` report a
forged sibling-tenant line; after adding the tenant predicate the test passed
with four assertions.

## Red-first and revert-replay

The initial detector run failed six new positive checks because D-a, D-b, D-e,
D-g, POS D-f, and goods-receipt D-f did not exist. The reversal tests initially
failed with `CommandNotFoundException`. The D-g watermark case was separately
red (`expected exit 0, got 1`) until its cutover predicate was added. The real
complete-sales-cycle test first exposed the wrapper transaction, then exposed
the missing authoritative `source_delivery_note_ids` payload; running the real
root with that linkage passed all 55 assertions.

After committing `f6e14340c`, `git revert --no-commit f6e14340c` was applied
and the new detector/reversal tests were restored from the commit. The replay
produced the intended failures:

```text
10 failed, 12 passed (27 assertions)
D-a/D-b/D-e/D-g/POS-D-f/GR-D-f: expected exit 1, received 0
R-5: forged cross-tenant invoice was reported
T19b: CommandNotFoundException in all three reversal tests
```

`git revert --abort` restored the exact committed tree and a clean worktree.

## PostgreSQL by-path verification

All behavioral acceptance runs used local PostgreSQL on port 5432. Tests were
run by path only:

```text
CheckCogsCoverageCommandTest: 19 passed (42 assertions)
Reverse command + JournalCode + InventoryGlSourceTypes: 7 passed (43 assertions)
Accounting/document movement regression group: 96 passed (332 assertions)
CompleteSalesCycleWithReturnTest real root: 1 passed (55 assertions)
InventoryGlCompositeRootTest C-5 filter: 1 passed (3 assertions)
ParapharmacySeederLocaleHooksTest: 1 passed (2 assertions)
```

The first isolated C-5 attempt encountered a PostgreSQL migration DDL deadlock
before application code. A process/backend check found no surviving contender;
the immediate isolated retry passed as recorded above.

Static and repository checks:

```text
Pint (all touched PHP): pass
PHPStan level 8 (all touched production/seeder PHP): [OK] No errors
git diff --check: pass
createCOGSEntry references under app/ and tests/: 0
deptrac: 127 current, 127 at accepted M2, 116 at M1, 99 checked-in baseline
.github/workflows/** changes: 0
```

The deptrac ratchet still exits non-zero against the stale checked-in baseline,
but M3 adds no violation over the accepted M2 aggregate. No baseline or
workflow file was changed.
