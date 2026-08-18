# Codex DPA Wave 3C/3D execution report

## Run identity

- Dispatch: `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md`
- Harness: `docs/handoff/SELF-REVIEW-HARNESS.md`
- Base SHA: `26b63f0ff29be6353015ca1cd2c5b362aa6bfc18`
- 3C branch: `codex/dpa-wave3-3c`
- PostgreSQL: local port 5432; tests by path only

## M0 — preflight

Files touched:

- `scripts/wave3-citation-inventory.php`
- `docs/handoff/reviews/wave3-3c-3d/M0-citation-inventory.csv`
- `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`
- `docs/handoff/progress/wave3-3c-3d.progress.yaml`
- this report

Evidence and actual outputs are recorded in `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`. The run started with `HEAD == BASE_SHA`; after adversarial fix round 1, `N_extracted=256`, `N_mapped=256`, `unresolved=0`, including 13 extensionless citations and zero file-scope fallbacks. The regression test's red state was `Missing extensionless citation: SalesOrderToInvoiceConverter:334`; its green state is `wave3 citation inventory regression: PASS (256 rows)`. R-11's local result is `0` on a zero-denominator sample and is not treated as deploy evidence; both Workshop tickets are present; D-19 reconciles to 18 rows; GR movements use `Document` / purchase-order id and receipt-line identity is carried by unique `movement_id` / `free_movement_id` links.

Decision: POS refund re-entry will use the original POS sale movement cost at the same product grain (R-1 option a).

Fix-round revert/replay: revert `3fabdaa34` reduced the inventory to 243 citations and the covering check exited 1 for the missing `SalesOrderToInvoiceConverter:334` citation; restore `d2b5765e0` returned the regression to 256 passing rows.

Adversarial round 2 found four comment/punctuation mappings and the unrecorded bare R3-2 continuation. The round-2 test was red at the stale `DeliveredQuantityResolver:399-402` comment mapping. That statement citation now records its executable guard, while genuine rationale-block citations retain `anchor_kind=comment`; the report records current POS ordering through closure line 477 and all remaining bare-continuation coverage.

Round-2 revert/replay: revert `2d6723f9d` made the semantic covering probe exit 1 for the stale comment-backed resolver mapping; restore `78933f51e` returned the executable relocation and 256-row regression to green.

Adversarial round 3 exposed that the executable-only rule had displaced a legitimate inv-I1 comment target and that nearby-line assertions did not update `new_line`. The inventory now reports the actual assertion address, resolves docblocks to the method they document, emits its own second metrics line, and pins every manual override. Current result: `N_extracted=256 N_mapped=256 relocated=6 unresolved=0`; 34 explicit comment anchors are retained and classified.

Round-3 revert/replay: revert `ac89b8c88` made the V10 comment-target probe exit 1; restore `850b62b46` returned the inv-I1 block at `217-226` and the full corpus to green.

Adversarial round 4 found cross-file construct moves that line-diff mapping could not detect. The inventory now fails closed on reference/current semantic drift and explicitly relocates the deleted delivery-compliance cluster, D-19 twin B, and five other integrated semantic successors. Both V10 comment citation forms map to `217-226`, and the write-off `source_id` precedent maps to line 4708. Current result: `N_extracted=256 N_mapped=256 relocated=23 unresolved=0`, with 16 fully pinned relocation keys.

Round-4 revert/replay: revert `b48e88974` made the deleted-delivery-predicate successor probe exit 1; restore `06ebab27d` returned the cross-file relocation and semantic drift gate to green.

Adversarial round 5 found ten unextracted timestamp/lowercase citations, the T18 seeder comment annotation, and stale-line exposure in manual relocations. The inventory now contains 266 rows, consumes required comment/docblock annotations, records the ambiguous treasury migration rule, and validates each relocation by symbol and semantic text. Current result: `N_extracted=266 N_mapped=266 relocated=24 unresolved=0`.

Round-5 revert/replay: revert `e45248fdf` dropped the corpus to 256 and made the timestamp-citation probe exit 1; restore `f0a74d332` returned all 266 citations to green.

M0 gate: round 6 independently reproduced the 266-row corpus, exercised relocation-pin mutations, re-derived C-2/C-3 depths, and returned `ACCEPT`. M0 is passed; M1 begins from the accepted evidence register.

Deviation discovered and resolved in the execution model: `RefundService` currently calls `ReturnNoteService::confirmWithin()` at transaction depth 1, while D-28 states depth 2. M2 will add the implied inner savepoint at that call before the writer-tail flush and retain C-2's root-tail flush. This aligns runtime depth with the settled architecture without changing the domain transition or lock set.

## M1 — implementation resumed under sequencing ruling

The first implementation slice is preserved at `abb3018efd9b582ad788f7162271c940192a0f62`. It contains the movement-keyed GL DTO/buffer/service, scoped lifecycle and rollback reset, `absoluteDeltaForRow`, source-type constant and partial unique migration, explicit journal mappings, static buffer-only/I-2 rules, original-exit return-cost resolver and payload recording, and V-10's location carry plus typed FEFO refusal.

T16c audit result: **negative branch inapplicable**. The interactive return loop routes `Scrap` to `applyScrapPair` (`ReceiptReturnService.php:435-449`). That method opens one savepoint and calls `restoreStock` followed by `ReturnScrapWriteOffService::writeOff` (`:1394-1422`), with a shared catch that rethrows retryable concurrency faults and contains other failures (`:1423-1441`). The symmetric pair is therefore live. M2 must apply D-23/T16d buffering to this interactive pair as well as the projection pair.

Verification run immediately before the stop:

- PostgreSQL `InventoryGlPostingSeamTest`: `3 passed (19 assertions)`; covers one-rounding arithmetic (`3 × 1.6666666 = 5.000` at TND scale), Posted/balanced/idempotent entry, persisted partial-index predicate, `23505`, root rollback reset, and a named `connectionsToTransact(): []` leak-alarm mechanism.
- `StandaloneInvoiceGuidedDeliveryTest`: `11 passed (43 assertions)`, including source-line location preservation and `FEFO_ALLOCATION_FAILED_CONFIRM_MANUALLY_WITH_BATCH` with no draft DN or movement left behind.
- `ReturnCostBasisResolverTest` + `ReturnNoteConfirmSealAndPeriodTest`: `11 passed (37 assertions)`; FIFO weighted exit basis, stable movement ids, honest current-cost fallback, and existing RN period/seal behavior.
- inventory unit paths: `7 passed (15 assertions)`.
- PHPStan level 8 on touched seam/accounting/document files: `[OK] No errors`.
- Pint on all touched PHP files: completed successfully.
- Deterministic two-connection PostgreSQL sensitivity probe: `session_a_sqlstate=40P01`, `session_b_sqlstate=00000`, proving the reversed-lock instrument detects the required failure class rather than green-by-vacuum.

### Sequencing ruling and amended T11c exit

The brief makes T11c (all ten pairs) part of M1 and says pairs 7/8 become green through T16d and pairs 9/10 through T16e; if any pair cannot be green, 3C must stop (`CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:381-390`). The authoritative plan says the same (`plan-wave3.md:2724-2738`). But the brief simultaneously requires T16d and T16e to remain in M2's single indivisible cutover commit (`CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:399-406`), and the plan says T16d/T16e ship in that cutover and may not be separately deployable (`plan-wave3.md:2910-2929`, `:2947-2977`, `:2979-2983`).

The original STOP C was correct and was resolved by
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md`
at `77d07de3c`. The amended M1 exit is pairs 1–6 green plus pairs 7–10 red with
cause-specific evidence; all ten green is now a hard M2 gate. No T16d/T16e production change moved
out of the atomic cutover.

The full evidence is in `docs/handoff/reviews/wave3-3c-3d/M1-evidence.md`. On real PostgreSQL,
pairs 1–6 plus the aborting-savepoint advisory proof passed (`7 passed`, `78 assertions`). Pairs
7–10 each reproduced `40P01`; pair 7/8 name missing T16d and pair 9/10 name missing T16e. A separate
production-path assertion proves every `GoodsReceived` event observes the purchase order already at
`Received` (`1 passed`, `3 assertions`).

The seam ladder now covers historical, non-COGS, flat, periodic document refusal / POS skip,
unmapped chart warning, non-positive value, direction contradiction, closed-period propagation,
unresolvable-device actor degradation, exact one-rounding arithmetic, movement idempotency, and the
migration duplicate pre-check. V-10 now returns a stable machine reason plus an en/fr localized
operator remedy; the red state was a missing `error.reason` and English fallback under `X-Language:
fr`, and the focused green state is `2 passed (10 assertions)`.

Fresh pre-review PostgreSQL path results:

- seam + inventory unit paths: `20 passed (60 assertions)`;
- guided delivery: `12 passed (48 assertions)`;
- return-note period/seal + cost resolver: `11 passed (38 assertions)`;
- goods-receipt production ordering: `12 passed (42 assertions)`;
- T11c green arm + aborting-savepoint proof: `7 passed (78 assertions)`;
- T11c ruled red-before arm: `4 failed (48 assertions)`, each at the desired no-`40P01` assertion;
- PHPStan level 8 on the touched production seam: `[OK] No errors`; Pint and `git diff --check` pass.

### M1 adversarial round 1 remediation

Round 1 returned `CHANGES-REQUIRED`. Its central finding was valid: the original T11c red arm
hardcoded its lock order and therefore would not flip when T16d/T16e landed. The replacement runs
the real writers. A two-line interactive scrap return records the company advisory at query 53 and
later inventory persistence through 79; a voucher-funded POS projection records company GL at 13
and stock persistence through 34. Pairs 7–10 therefore fail at real, task-specific T16d/T16e
boundaries. Four independent two-connection sensitivity controls still reproduce `40P01`.

The remaining round-1 findings were remediated as follows:

- all four posting kinds, count-direction accounts, write-off arguments, and replay idempotency are
  covered;
- enqueue is query-free; savepoint/root/next-root and cross-connection buffer isolation are covered;
- two DN-attributed contexts produce two entries in one root flush with no cross-contamination;
- multi-line return confirmation persists every `return_cost_basis` record and its exact movement
  cost;
- `batch_write_off` and reversal writers now match the blocking partial unique index; the duplicate
  probe across all five source types returned `0`;
- synchronous replay repairs an existing Draft; count-correction account readiness and the pgsql
  migration guard are no longer hardcoded deviations;
- I-2 uses AST nodes and has positive plus comment-decoy/disjoint rule tests;
- voucher ledger amount scaling now takes the explicit ledger currency, allowing the queued POS
  projector trace to run with no `CompanyContext`.

The harness revert/replay is real: `2a4c67c4b` removed the hardening while the tests remained and
produced `23505`, Draft-not-Posted, and cross-connection buffer-loss failures. `a8c797222` reapplied
it and the identical focused run passed `3 tests (19 assertions)`. Fresh green results are seam,
cost-basis, and structural-rule paths `26 passed (116 assertions)`; interactive scrap non-T11c
regressions `9 passed (30 assertions)`; projection non-T11c regressions `7 passed (34 assertions)`;
and the T11c sensitivity/abort suite `11 passed (126 assertions)`. The ruled production red arm is
four expected failures with explicit trace positions. PHPStan on touched production files, Pint,
`git diff --check`, and adversarial-review shell syntax all pass.

Round-1 P3-12 remains a plan-level follow-up rather than an implementation deviation: successive
return notes do not net prior draws from an original exit. It is recorded here for the M3 detector
and plan owner; changing the settled T15a algorithm inside M1 would exceed the approved task.

### M1 adversarial round 2 remediation

Round 2 confirmed the production T16d/T16e reds can flip, then found that the voucher red rows had
been placed inside a class selected by the shared PG merge gate. They now live in the dedicated
`InventoryGlVoucherLockOrderTraceTest`, which is outside that allowlist; the allowlisted projection
class is green (`7 passed, 34 assertions`). Pairs 1–3 are now described honestly as M1 target-order
sensitivity controls because production inventory-GL wiring is atomic M2 scope. Pairs 4/5 retain
their production GR proof and pair 6 retains buffer-level composition proof. Duplicate pair labels
are explicitly a per-writer terminality trace crossed with two D-28 counterpart labels.

The seam's real-root-commit harness is explicitly PostgreSQL-only: in-memory SQLite loudly skips
all 21 methods rather than losing its schema between application refreshes. The voucher-scale
change used only to unblock a no-context fixture was
reverted; the dedicated trace binds the same CompanyContext required by the shipped path and the
scale mechanism is byte-for-byte pre-M1. Batch reversal retains the shipped inline `postEntry`
mechanism while keeping idempotency; voucher actor/refund and BatchExpiry owning suites pass `15
tests (48 assertions)`. `StockMovement` now uses `QuantityScale::SCALE`; the I-2 AST rule matches
table-call arguments rather than arbitrary string nodes, and all PHPStan rule tests are part of a
named phpunit testsuite (`8 passed, 8 assertions`).

Per-task mutation/replay is committed and captured in M1 evidence: T11 `6ab4cb163`/`ea0658233`,
T11e `43bc692ff`/`8654aeda8`, T12 `2ad32b56f`/`059e003fd`, T13
`7ffda98ff`/`f129c5879`, T15a `d0cce02c0`/`c150cfc0d`, and V-10
`005aa9434`/`d7ac34af4`. Every mutation failed on its intended contract and the same focused test
passed after the committed revert. T11c's ruled-red production outputs are its red-before evidence;
T16c is an audit and has no behavioral commit to revert.

The round-2 CI-isolation check ran the workflow's exact PostgreSQL class filter. The M1-owned
allowlisted projection class passed all `7 tests (34 assertions)`, and the dedicated ruled-red
voucher trace was correctly absent from the selection. The diagnostic aggregate was `1004 passed
(4159 assertions), 3 skipped, 5 failed`; the five failures are outside the M1 diff and attributable
to existing manifest-anchor formatting, local PHP/PostgreSQL timezone mismatch, two refund-chain
arithmetic assertions, and the unprovisioned `iziposcentral` test database. The required red paths
were immediately rerun by path and reproduced `53 -> 79` for T16d and `13 -> 34` for T16e.

### M1 adversarial round 4 remediation

Round 4 found that V-10's unconditional factory refusal had escaped the guided endpoint into the
legacy SO converter. The factory now takes an explicit guided-only strict-FEFO option: the SO path
retains its historical unbatched fallback (`8 passed, 51 assertions`), while guided delivery remains
loud, localized, and atomic (`12 passed, 48 assertions`). The red-first SO regression returned 422
and the fixed path returns 201 with one complete Draft DN.

The return-cost resolver/DTO moved to Inventory Domain, removing M1's new Domain-to-Application
deptrac edge. The ratchet still reports base drift (`116` vs baseline `99`) but no M1 resolver
violation; the touched-file JSON report contains only the pre-existing WAC edge in ReturnNoteService.
Return-basis persistence now replaces by line id in one payload write and uses QuantityScale; the
seeded-duplicate red was three rows and the fixed result is two (`3 passed, 24 assertions`).

T11e now has its own rule test and fixtures, is wired into CI, and has a committed mutation/replay:
`7bfc5ae61` suppressed direct-call diagnostics and failed the test; `b55614da0` restored it and passed
`2 tests (2 assertions)`. The full rule directory passes `10/10`. The voucher trace skips loudly on
SQLite, the rollback listener avoids resolving the GL graph for unrelated root rollbacks, and the
full seam passes `22 tests (91 assertions)`. Lock sensitivity remains `11 passed (126 assertions)`;
the amended production reds remain 53→79 and 13→34.

The required directory regression run also reproduced two errors in untouched
`GoodsReceiptDataTest`/`GoodsReceiptData.php`; full-tree PHPStan retains two untouched scale findings.
They are explicitly base drift, while touched-file PHPStan is clean.

## M2 — atomic inventory-movement COGS cutover

The complete cutover and all scoped remediation are one D-13 atomic commit,
`2bd9595d9`. M2 delivers T14–T17: DN, RN, and both live POS writers now capture movement
costs and defer movement-keyed inventory GL to the owning root tail; projected
and interactive refund/scrap paths use the original sale movement basis;
voucher posting occurs after stock projection; the invoice-keyed legacy COGS
listener is removed. The cutover is controlled by the company watermark and
includes the unattended-safe migration and NULL-cost ratchet.

The final scoped remediation is authorized by
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md`.
R-1 uses the original `POSSale` movement at receipt + product + variant grain,
falling back to the receipt line only if no movement exists. Its non-representable
`1.234568` fixture failed under the receipt-line interpretation (`1.234600`) and
passes under the ruled movement interpretation. Guard 4 now runs for every
request/job and gates only on `DB::transactionLevel() === 0`; all six-suite
opt-ins were deleted.

Fresh ruling regression evidence, isolated by transaction model, is 69 tests /
310 assertions for the six real-root suites and 41 tests / 218 assertions for
the three wrapper suites: 110 tests / 528 assertions total. The complete Goods
Receipt ordering suite separately passes 12 tests / 42 assertions. The D-13
NULL-cost ratchet passes and fails when the sale movement is mutated to NULL.
Round-8 revert/replay restores the R-1 and three boundary failures, proving the
new green state is caused by the scoped implementation.

The hardened T11c gate uses the ruling's compositional interpretation. Real
terminal traces cover DN, RN, POS sale, interactive scrap, voucher projection,
GR, and the multi-DN/invoice composite root; the evidence register maps those
traces across all ten pairs. The pair-salt harness is scaffold only.

Deptrac reports 127 violations versus 116 recorded at M1 and baseline 99. The
exact +11 M2 delta is the planned Domain-to-Inventory-Application buffer seam:
Delivery Note +6, Refund +1, Return Note +4. No baseline was changed. P3-6,
P3-8, P3-9, and P3-10 have dedicated tickets; P3-10 explicitly requires the
non-vacuous per-tenant duplicate-count check before promotion. No workflow file
was modified.

Post-squash verification preserves the ruled totals: the six real-root files
pass 69 tests / 310 assertions when the POS real-root class is isolated from
the committed fixtures of the preceding five files; the wrapper trio passes
41 / 218; Goods Receipt ordering passes 12 / 42. Pint and touched-file PHPStan
pass, `git diff --check` is clean, and deptrac remains the reconciled 127.

Adversarial round 8 applied the 2026-08-18 STOP-A ruling as explicit authority
and returned `ACCEPT`. It independently reran the distinguishing R-1 test,
Guard 4 seam, D-13 ratchet, wrapper/non-wrapper spot checks, Pint, and PHPStan;
it verified that `2bd9595d9` is the only production cutover commit and accepted
the per-writer T11c composition across all ten pairs. Its five observations are
P3 notes only; the legacy NULL-cost helper issue shares the existing P3-8
ticket, the deptrac number is deferred to the M5 whole-branch gate, and none is
`CHANGES-REQUIRED` inside the ruled round-8 scope.

## M3 — 3C tail controls

M3 is implemented in `f6e14340c`. T18 removes the obsolete
`createCOGSEntry()` API and migrates its accounting tests to movement-keyed
inventory entries, including the no-`CompanyContext` chain-sequence coverage.
T19 adds a fail-closed per-tenant pre-promotion SQL artifact and the required
seven-section operations note, including 4a/4b/4c and the three new follow-up
tickets. T19b adds the explicit, confirmed, forward-only compensating command;
it posts exact inverse entries, nets each affected account to zero, is
idempotent, and is never called by the normal path.

The detector now implements D-a, D-b, D-e, and D-g plus D-f's POS and
goods-receipt arms. POS has no grace window; goods receipt uses its persisted
line link plus the established `Document` / purchase-order source tuple; D-e
matches D-b's stock-adjustment exclusion. The work-order arm is a named,
ticket-citing no-op with a negative regression. R-5's documented SQL
counterpart now includes tenant, company, and physical-product scope, with a
cross-tenant parity fixture. C-5, pulled into M2 for atomic safety, passes its
live composite-root flush assertion.

The implementation commit's revert-replay retained the new tests and produced
`10 failed, 12 passed (27 assertions)`: all six new detector positives were
absent, the R-5 forged line was reported, and all three reversal tests raised
`CommandNotFoundException`. Aborting the revert restored a clean tree.

Fresh PostgreSQL results are detector `19/42`, reversal plus enum/source
regressions `7/43`, the combined accounting/document movement group `96/332`,
the real complete-sales root `1/55`, C-5 `1/3`, and the touched seeder path
`1/2`. Pint and touched-file PHPStan pass; `git diff --check` is clean; and
deptrac remains 127, identical to accepted M2. The local preflight returned
zero legacy COGS, zero duplicate groups, and zero non-physical delivery groups,
but is explicitly recorded as a syntax/probe run—not deploy-target evidence.
Full details and task-level red/green evidence are in
`docs/handoff/reviews/wave3-3c-3d/M3-evidence.md`.

### M3 adversarial round 1 remediation

Round 1 found that D-f treated intentional POS no-movement refund outcomes as
projection holes, D-e treated not-yet-wired count corrections as missing GL,
D-b treated historical NULL-cost refunds as defects, and D-a would begin
reporting D-20 adjustments once 3D adds their costs. `f9ca0bfe8` closes all
four: POS lines persist an immutable `stock_movement_expected` decision used by
both the stock branch and D-f; D-e temporarily excludes counting until T21
lands; D-b excludes historical rows; and D-a shares the stock-adjustment
exclusion.

The same round replaced the GR detector fixture's fabricated inventory entry
with the real NULL-reason inbound shape, removed arity-vacuous log assertions,
corrected the NOT NULL watermark model type, and recorded the GR reason and D-f
mutable-physical-snapshot P3 follow-ups. The fix commit's revert-replay produced
four detector failures, one projected-refund failure, and two interactive
refund failures with the new tests retained.

Fresh PostgreSQL verification is detector `20/41`, projected refund `14/69`,
interactive disposition `5/19`, return flow `21/119`, and movement
characterisation `15/54`. Pint and touched-file PHPStan pass; deptrac remains
127; no workflow file changed.

### M3 adversarial round 2 remediation

Round 2 found two remaining intentional no-movement populations: a sale at a
location without the product's stock grain, and a scrap refund after the
product is archived. `a59263411` captures the exact stock-grain outcome once,
persists it on the receipt line, and uses the same decision to drive the stock
branch. Active scrap remains movement-expected; archived scrap and no-grain
sales are explicitly movementless. D-f's POS anti-join is now tenant- and
company-scoped, and a cross-company collision fixture proves it cannot hide a
finding.

The same scoped fix normalizes raw disposition values before assigning the
enum-cast projection column, removes the inert interactive marker (interactive
return quantities are negative and outside D-f), and records the required T21
removal of D-e's temporary `inventory_counting` exclusion in a dedicated
ticket. The release note now enumerates the intentional outcome set.

Fresh PostgreSQL verification is projected refund `15/72`, detector `22/43`,
and movement characterization `15/54`. Pint and touched-production PHPStan
pass; deptrac remains 127; `git diff --check` passes; no workflow file changed.
Revert-replay of `a59263411` made all three distinguishing tests fail: both
movementless outcomes stored `true`, and a foreign-company movement suppressed
the detector. Aborting the revert restored a clean tree.

### M3 adversarial round 3 remediation

Round 3 caught a real regression outside the previous evidence set: applying
the product-level no-grain exception to variant lines suppressed both the
existing variant warning and D-f. `0f99bde9e` now distinguishes the two. A
missing product-level location grain remains the temporary movementless
classification; a missing variant grain stays movement-expected, reaches the
locked writer, warns, and remains visible to D-f. The unlocked snapshot no
longer gates the sale writer, closing the concurrent-grain race.

The generic movementless-refund warning was removed. Routine `not_received`
and benign missing-grain outcomes are silent, while the regulated
never-restock case regains its distinct argument-checked warning. The remaining
product-level location-grain approximation has a named Inventory + Product
architecture owner and removal trigger in a dedicated ticket.

All 14 `PosCoreReceiptProjection*Test.php` files were run individually on
PostgreSQL and pass `84/323`, including the previously red variant file.
Detector remains `22/43`, movement characterization `15/54`, Pint and
touched-production PHPStan pass, deptrac remains 127, and no workflow file was
changed. Revert-replay makes the variant flag and never-restock warning tests
red and aborting it restores a clean tree.

Round 4 returned only `claude invocation failed`; its on-disk register is the
harness-generated tool-error marker. It is treated fail-closed as
`CHANGES-REQUIRED`, consumes the fourth fix-round slot, and triggers review
round 5 without a production change.
