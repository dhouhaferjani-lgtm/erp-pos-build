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

## Adversarial round 1 remediation

Round 1 returned `CHANGES-REQUIRED` with four close-before-merge detector
defects. The scoped fix is `f9ca0bfe8`:

- POS D-f now filters on the immutable `pos_receipt_lines.stock_movement_expected`
  projection outcome. The additive, idempotent migration defaults existing
  pre-watermark rows to true. Projected and interactive writers set false for
  `not_received`; the projector also sets false for a restock disposition when
  the effective policy at projection time is `Never`. The same captured array
  drives the stock branch, so a concurrent catalogue-policy edit cannot split
  the stored decision from the action. The detector test changes the product
  policy afterward and remains silent, proving it does not re-evaluate history.
- D-e temporarily excludes `inventory_counting` until M5/T21 wires the real
  count writer; T21 must remove the exclusion with that wiring. Its M3 positive
  uses a synthetic `SupplierReturn`, while a live-shaped `CountCorrection` is
  the negative. D-a now shares the ruled stock-adjustment exclusion, and D-b
  excludes deliberate historical NULL-cost movements.
- The GR negative now carries the production shape (`reason = NULL`) and no
  fabricated `inventory_entry`. The separate reason/GR-IR ownership gap is
  recorded in
  `docs/superpowers/tickets/2026-08-18-goods-receipt-movement-reason-detector-gap.md`.
- All arity-vacuous `Log::shouldNotHaveReceived` checks were removed; exit 0
  carries the real signal. D-f's unavoidable live `is_physical` classification
  is documented at both new arms and in
  `docs/superpowers/tickets/2026-08-18-df-immutable-physical-snapshot.md`.
  The Company watermark type now matches its NOT NULL schema. The seeder keeps
  an explicit `is_physical = true`; only its second duplicate array key was
  removed so the touched file remains PHPStan-clean.

Red-first produced four detector failures (`expected exit 0, received 1`) and
NULL projected disposition/expectation assertions. Reverting `f9ca0bfe8` while
retaining its covering tests reproduced:

```text
Detector scoped replay: 4 failed (10 assertions)
Projected not_received replay: 1 failed (3 assertions)
Interactive disposition replay: 2 failed (4 assertions)
```

`git revert --abort` restored the committed tree. Fresh PostgreSQL results:

```text
CheckCogsCoverageCommandTest: 20 passed (41 assertions)
PosCoreReceiptProjectionRefundDispositionStockTest: 14 passed (69 assertions)
StoreReturnRequestDispositionTest: 5 passed (19 assertions)
ReceiptReturnFlowTest: 21 passed (119 assertions)
CogsRelocationCharacterisationTest: 15 passed (54 assertions)
Pint: pass
PHPStan (all round-1 touched production/migration/seeder files): [OK] No errors
deptrac: 127 (unchanged from accepted M2)
git diff --check: pass
```

One first-attempt PostgreSQL schema reset hit the local server's
`max_locks_per_transaction` limit before application code. No stale test
process or backend remained; the isolated retry passed all 14 projected-refund
tests and is the result recorded above.

## Adversarial round 2 remediation

Round 2 identified two further intentional no-movement populations in POS
D-f, an unscoped anti-join, and four evidence/hardening gaps. The scoped fix is
`a59263411c00a009bff6a3ca615cb928f42125fe`:

- Projected POS lines now capture whether the exact
  company/location/product/variant stock grain exists. Sales without that
  grain record `stock_movement_expected = false`; the same captured decision
  skips their stock branch. Scrap refunds additionally require the product to
  remain active, so the established archived-product/no-movement behavior is
  represented rather than reported forever. Positive scrap remains expected
  and is covered with its movement.
- The POS anti-join now scopes matching movements by tenant and company. A
  forged same-receipt movement in another company no longer silences the real
  finding.
- Projection writes normalize the observability-only disposition with
  `ReturnLineDisposition::tryFrom(...)?->value`, preserving the rule that a
  projector does not reject a sealed event through an enum cast.
- The inert interactive-return marker write and claims were removed: those
  lines have negative quantities and are outside D-f's positive-quantity sale
  population.
- Removal of D-e's temporary `inventory_counting` exclusion is pinned to T21
  in
  `docs/superpowers/tickets/2026-08-18-remove-counting-detector-exclusion-with-t21.md`
  and the release note.

Red-first reproduced the two behavioral defects: the no-stock-grain sale
stored `true` instead of `false`, and a cross-company movement made D-f exit 0
instead of 1. The archived-product scrap case was then pinned at the writer
boundary. Fresh PostgreSQL results are:

```text
PosCoreReceiptProjectionRefundDispositionStockTest: 15 passed (72 assertions)
CheckCogsCoverageCommandTest: 22 passed (43 assertions)
CogsRelocationCharacterisationTest: 15 passed (54 assertions)
Pint (round-2 touched PHP): pass
PHPStan level 8 (round-2 touched production PHP): [OK] No errors
deptrac: 127 (unchanged from accepted M2 and M3 round 1)
git diff --check: pass
.github/workflows/** changes: 0
```

Reverting `a59263411c00a009bff6a3ca615cb928f42125fe` while retaining the
three covering tests reproduced each intended failure:

```text
sale without stock grain: true is false (1 failed)
archived-product scrap: true is false (1 failed)
cross-company movement: expected exit 1, received 0 (1 failed)
```

`git revert --abort` restored the exact commit and a clean worktree.

## Adversarial round 3 remediation

Round 3 found that the product-level no-grain outcome had been applied too
broadly to variant lines and had also bypassed the sale writer's locked lookup.
The scoped fix is `0f99bde9e12558d8baa51a9ebfa890526f60ddef`:

- A product-level line without an exact location grain retains the established
  `stock_movement_expected = false` classification. A variant line without its
  exact variant grain remains `true`: the locked writer runs, preserves the
  no-fallback invariant, emits its variant-scoped warning, and D-f continues to
  report the missing movement.
- The unlocked snapshot no longer gates the sale decrement call. The writer's
  locked lookup remains authoritative, so a concurrently-created grain is not
  skipped.
- Routine `not_received`, missing product-level grain, and archived-product
  refund outcomes are silent. The regulated `RestockPolicy::Never` case again
  emits its distinct warning, pinned by an argument-sensitive assertion.
- The temporary product-level location-grain approximation has an owner and
  removal trigger in
  `docs/superpowers/tickets/2026-08-18-pos-location-stock-tracking-classification.md`.

Red-first produced `false is true` for the missing-variant line's detector flag
and no matching never-restock warning. Every dedicated projection test file was
then run individually on PostgreSQL to avoid cross-file database pollution:

```text
PosCoreReceiptProjection*Test.php (14 files): 84 passed (323 assertions)
CheckCogsCoverageCommandTest: 22 passed (43 assertions)
CogsRelocationCharacterisationTest: 15 passed (54 assertions)
Pint (round-3 touched PHP): pass
PHPStan level 8 (round-3 touched production PHP): [OK] No errors
deptrac: 127 (unchanged)
git diff --check: pass
.github/workflows/** changes: 0
```

Reverting `0f99bde9e12558d8baa51a9ebfa890526f60ddef` while retaining the two
covering assertions reproduced both failures: the variant line persisted
`stock_movement_expected = false`, and the regulated refund emitted no warning
matching `never-restock`. `git revert --abort` restored a clean tree.

## Adversarial round 4 tool error

The round-4 bridge produced no review register or parseable `VERDICT:` line and
exited with `claude invocation failed`. Per the self-review harness this is
recorded fail-closed as `CHANGES-REQUIRED`; it is not a code finding and no
production change was made. The retry consumes the fourth fix-round slot and
uses review round 5.
