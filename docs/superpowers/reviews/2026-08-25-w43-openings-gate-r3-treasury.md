# W4-3 + W4-4 — treasury/GL + opening-balance gate r3 (verify-only)

**Lane** `fix/campaign-w43-ap-opening-partner-ledger` · worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w43-openings` · HEAD `0a42af41b`
(fix round r2 `a0be9f8ec`, ruling round `4031b092e`, dev merged at `d0cfa624f`) ·
dev at review time **`04ddf0266`** (moved from `d0cfa624f` → `eaf80a1123` → `04ddf0266` during the
review; C-QR0a + C-24 + the wave-4 re-run landed, so the manifest MOVED — see §Manifest).

r1: `2026-08-25-w43-openings-gate-r1-treasury.md` · r2: `2026-08-25-w43-openings-gate-r2-treasury.md`
(CHANGES: F-1 guard placement, F-2 OQ-74, F-3 money-moved proofs).
Handback: `2026-08-25-w43-handback.md` §"FIX ROUND r2" + §"RULING ROUND".

## VERDICT

**spec ✅ · quality APPROVED-with-conditions · merge-blocking: NO**

All three r2 blockers are genuinely closed and I proved each by execution on **sqlite and PostgreSQL 16**.
F-1's fix is the right shape (pure predicate + throwing assertion, MANUAL throws / SERVER skips) and the
throwing branch is structurally unreachable from both queued fiscal projections. F-2 carries the owner's
ALLOW ruling on dev and the six tests now assert the ALLOW behaviour **on the money**, not on a status
code — I re-probed the ledger, the sub-ledger and the till and all three agree. F-3's four tests
discriminate: no-op the guard and the ordinary mis-typed invoice returns 201 and moves 500.000 the wrong
way. Treasury red is back to the 12 inherited, name-for-name identical with dev.

It is **not** blocked, but it ships with one real gap that should be closed in the same lane if the file
is opened again: **nothing in the suite pins the F-1 fix**. Reverting it to the r1 unconditional throw
leaves the ENTIRE `tests/Feature/Treasury` directory at exactly the same 12 failures. The defect that
made r2 CHANGES-REQUESTED can be reintroduced silently.

---

## What I executed

| Check | Result |
|---|---|
| 3 lane classes + the 3 C-0a0 classes, **sqlite**, one process | `OK (58 tests, 248 assertions)` |
| Same six, **PostgreSQL 16** throwaway `autoerp_test_w43g3` (127.0.0.1:5433, dropped after) | `OK (58 tests, 248 assertions)` — both drivers agree exactly |
| `tests/Feature/Treasury` whole directory, sqlite, lane HEAD | **`Tests: 1238, Failures: 12`, 0 errors** — matches the handback digit for digit |
| The 2 classes carrying that red, lane, `--testdox` | `Tests: 22, Failures: 12` ⇒ **all 12 live in those two classes; every other Treasury class is green** |
| The same 2 classes on **dev `04ddf0266`** (main worktree) | `Tests: 22, Failures: 12` — **identical, name for name, 12 for 12** |
| `tests/Unit/Treasury` (the applicability matrix lives here, outside the Feature lane) | `OK, 254 tests, 4468 assertions` |
| **F-3 tamper** — APFS clone, `directionMatchesPartner()` → `return true;` | 10/11 direction tests fail; the 3 ordinary-mis-typed routes fail with **201** |
| **F-3 value probe** on the same tamper (instrumented, guard no-op'd) | `status=201 allocations=1 payments=1 movements=1 dir=in je_source_types=customer_payment repo_balance=500.000` — **money moves, wrong direction** |
| **F-1 tamper** — restore guard, revert `PaymentAllocationService:229-237` to the r1 unconditional `assertDirectionMatchesPartner()` | 4 lane/C-0a0 classes: `OK (37 tests, 184 assertions)`; **whole `tests/Feature/Treasury`: `Tests: 1238, Failures: 12`** — the same 12. **Zero coverage** (finding R3-1) |
| **OQ-74 money probe** (instrumented, untampered) — AP opening 100.000, pay 40.000 | `bank_balance=-40.000 payable_balance=60.000 doc_balance_due=60.000 doc_status=posted` — **GL 401, sub-ledger and document agree; the payable magnitude stays NON-NEGATIVE** |
| **OQ-74 red-proof** — `ArApOpeningLedgerService::sourceTypeFor(SupplierInvoice)` → `'opening_balance'` | exactly 2 failures: `…_is_paid_through_the_supplier_arm`, `test_paying_an_ap_opening_item_debits_the_payable_and_moves_the_cash_out`. **Load-bearing, not vacuous** |
| `./vendor/bin/pint --test` | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` (level 8) | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | **`PASS — 183 / 183`** |
| `php tools/feature-lane-manifest-check.php` | `EXIT=0` — 1438 classes / 74 groups, gated 1183 (lane's own numbers) |

Never ran the full suite. Never more than one test process at a time. Lane worktree untouched
(`git status --porcelain` empty at `0a42af41b`); PG database dropped; APFS clone deleted.

---

## r2 findings — dispositions I verified by execution

### F-1 (CRITICAL) — **CLOSED as code.** The split is correct and the worker path is provably safe.

- `DocumentAllocationStateGuard.php:137-152` — `directionMatchesPartner()`, a pure predicate, no throw,
  `default => true` for every non-AR/AP type, `true` when the document has no partner.
- `DocumentAllocationStateGuard.php:92-116` — `assertDirectionMatchesPartner()` is now built on the
  predicate and is the only thing that raises `HttpResponseException`.
- `DocumentAllocationStateGuard.php:161-168` — `partnerOf()` reuses the eager-loaded relation (r2's
  M-3 stays closed: no new query on paths that already loaded it).
- `PaymentAllocationService.php:229-237` — **MANUAL throws, everything else skips** and records
  `AllocationRefusalReason::PartnerRoleMismatch` via `logAutoAllocationSkip()` (`:688-697`), the same
  shape `allocatableTreatmentOrSkip()` uses three lines below at `:260-266`.
- **Queued projections unreachable to the throwing branch — proven by reading, not asserted.** Only two
  non-self callers of `applyAllocationFromCommand()` exist in `app/`, and both pass FIFO:
  `TreasuryAccountPaymentBridge.php:216` + `:220` and `TreasuryDepositBridge.php:200` + `:204`. The
  branch is gated on `=== AllocationMethod::MANUAL`, so a worker cannot enter it. Belt-and-braces on
  top: `getOpenInvoices()` (`PaymentAllocationService.php:572-628`) selects only
  `Invoice|SalesOrder`, so the only reachable mismatch on the sweep is a customer `Invoice` owned by a
  supplier-only partner — which hits the skip.
- New enum case `AllocationRefusalReason::PartnerRoleMismatch` (`:95-112`) with operator copy in all
  three locales (`lang/en|fr|ar/treasury.php:34`), keyed through `translationKey()` like the rest of the
  family.
- **The four classes r2 flagged are green** on both drivers. They are *not* byte-identical to dev — the
  ruling round deliberately edited three of them (`AutoAllocationSkipsRefusedDocumentsTest`,
  `HistoricalAndPosInvoicesRefusedBeforeProvenanceTest`, `HistoricalOpeningSideSettlementTest`) plus
  `Concerns/PaymentApplicabilityScaffold.php`. Those edits are owner-sanctioned by OQ-74 and each
  carries a docblock saying why. Outcome parity, not file parity — which is the correct reading here.

**Not closed as coverage.** See R3-1 below.

### F-2 / OQ-74 (CRITICAL) — **CLOSED.** The ruling exists, is cited, and the tests assert money.

- Ruling on dev: `docs/handoff/OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:73` —
  *"OQ-74 **RULED 2026-08-25: ALLOW** (W4-3 books AP openings correctly; standing rule: standard ERP
  logic applies as soon as implemented)"*, landed `d0cfa624f`, which is the lane's merge base.
- Cited in the code-bearing commit `4031b092e` and in three docblocks
  (`HistoricalOpeningSideSettlementTest.php:40-53`,
  `HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php:42-60`,
  `DocumentAllocationClassifier.php:338-341`). **No `@owner-ruling` placeholder remains anywhere in
  the repo** (grep, `*.php` + `*.md`, vendor excluded).
- **AP opening paid through the supplier arm**, asserted on the money, not the status code
  (`HistoricalOpeningSideSettlementTest.php:128-176`): `supplier_payment` entry, Dr 401 `40.000` with
  `partner_id = vendor`, Cr bank `40.000`, `repository_movements.direction = out`, and
  `customer_payment` entries asserted to be **zero**.
- **My own probe on the untampered tree adds the two legs the test does not assert**: the repository
  balance goes `0.000 → -40.000` (cash left the till, GL and treasury agree in direction) and
  `partners.payable_balance` goes `100.000 → 60.000` — a **non-negative magnitude**, the house
  convention honoured, agreeing with `documents.balance_due = 60.000` and with GL 401. Three-way tie.
- **`ap_opening` family removed from the provenance provider**
  (`HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php:82-88` and the `mint()` arm at `:199-206`).
  `unknown_side_opening` and both POS families are untouched — 5 test methods before and after, 3
  families instead of 4.
- **The refusal that still stands is pinned**: `…::test_a_historical_ap_opening_is_still_refused_on_the_receivable_path`
  (`:182-199`) asserts `SUPPLIER_INVOICE_NOT_PAYABLE_HERE` on `smart-payment/apply-allocation` with
  zero allocations written.

### F-3 (IMPORTANT) — **CLOSED, and the tests genuinely discriminate.**

`OpeningItemPaymentDirectionTest.php:415-472` — three routes (`POST /payments`, storeMultiple,
split-payment) against `ordinaryMisTypedInvoice()` (`:503-524`: `Invoice` + `Posted` +
`is_historical = false`, partner supplier-only), each asserting `assertNoMoneyMoved()`
(`:531-546`: 0 allocations, 0 payments, 0 repository movements, 0 `customer_payment` entries,
repository balance unchanged) — plus `test_the_direction_refusal_tells_the_operator_the_remedy`
(`:474-495`) pinning the remedy sentence added at `DocumentAllocationStateGuard.php:103-105`.

Tamper (`directionMatchesPartner()` → `return true;`) reproduces r2's probe **on the merged tree**:
`201 | 1 allocation | 1 payment | movement direction IN | JE customer_payment | repository 0.000 →
500.000`. This is the exact campaign defect — a payment on a supplier's document recorded as money
ARRIVING — and it is now the only shape in the suite that proves the guard, exactly as asked.

---

## FINDINGS

### [IMPORTANT] R3-1 — the F-1 fix has ZERO test coverage. Reverting it goes unnoticed by the whole Treasury lane.

`PaymentAllocationService.php:229-237`. I reverted it to the r1 shape (unconditional
`assertDirectionMatchesPartner($document)` — the exact code that produced 1 error + 8 failures at gate
r2) and ran the whole directory: **`Tests: 1238, Failures: 12`. The same 12 inherited. Nothing red.**

Why the r2 tripwire stopped working — two independent causes, both introduced by this lane's own fix
round and ruling round, and neither is wrong on its own:

1. `PaymentApplicabilityScaffold.php:116-134` changed `$this->vendor` from `PartnerType::Supplier` to
   `PartnerType::Both`, so the scaffold's native invoice no longer mismatches.
2. An AP opening is now a `SupplierInvoice`, and `getOpenInvoices()`
   (`PaymentAllocationService.php:582-596`) selects only `Invoice|SalesOrder`, so it never reaches the
   execute loop at all.

Consequence beyond the missing pin: two C-0a0 assertions became **vacuous**.
`AutoAllocationSkipsRefusedDocumentsTest.php:151` (*"the refused AP opening must not be allocated
against"*) and `:124` (*"a refused opening must never be OFFERED"*) now pass because the SQL type
filter excludes the row, not because any refusal ran. The class's headline promise —
`test_the_queued_projection_entry_point_does_not_throw_on_a_refused_opening` (`:133-153`) — no longer
touches the direction guard on any path.

**Why it matters.** The whole of r2's CRITICAL was that a throw on this line dead-letters a SEALED
device fiscal fact after five Horizon retries. That risk is real, the fix is correct, and it is now
guarded by nothing but a comment.

**Fix (small, and it belongs in this lane).** One test in
`AutoAllocationSkipsRefusedDocumentsTest`: a supplier-only partner holding an ordinary posted customer
`Invoice` (F-3's `ordinaryMisTypedInvoice()` shape) that is OLDEST, plus a native invoice behind it,
collected by `applyAllocationFromCommand(… FIFO …)`; assert `success`, zero allocations on the
mismatched row, and the native one collected. That is the only shape that both reaches the sweep and
trips the predicate, and it fails loudly the moment the MANUAL guard is removed.

### [IMPORTANT] R3-2 — `PaymentApplicabilityMatrixTest` is still the designated authority on payment applicability and it now states the OPPOSITE of the ruling. Green, unopened by the lane.

`apps/api/tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php` — unchanged by this lane
(`git diff d0cfa624f HEAD` on that path is empty), and it lives in `tests/Unit`, which the Treasury
Feature lane does not run, so nothing forced the author to look at it.

1. `:568-578` docblock: *"An AP opening is minted as `DocumentType::Invoice` … 'settle it through the
   supplier payment flow' … is an instruction that fails. Settling AP openings arrives with the
   provenance lane (C-0a1)."* Both sentences are now false — the premise died with the retype, and
   OQ-74 ruled the settlement in. `:579-590` then asserts the operator copy for
   `HistoricalOpeningProvenance` must NOT name the supplier flow and MUST say *"cannot be settled yet"*.
   The assertion is still harmless (that reason now fires only for unprovable-side openings, for which
   the copy is true), but the reasoning attached to it is authority-shaped and wrong.
2. `:145-193` + `:199` + `:219`: `EXPECTED_PROVENANCE` and `everyCell()` enumerate provenance families
   for `Invoice` and `CreditNote` only. `supplier_invoice|historical_ap|*` and
   `supplier_credit_note|historical_ap|*` — **the shape the product now mints** — have no cell.
   `test_the_tables_cover_exactly_the_whole_space` (`:259-272`) asserts the tables cover the space; that
   claim no longer holds for the AP side.
3. `:152-157` still declares `invoice|historical_ap|posted ⇒ refused:historical_opening_provenance`.
   That is CORRECT and worth keeping — it is the legacy pre-W4-3 row, and it is the right fail-closed —
   but it should say so instead of reading as the current rule.

Non-blocking: no wrong money, no CI red (`tests/Unit/Treasury` is `OK, 254 tests`). Fix is a docblock
rewrite plus two provenance rows and a widened `everyCell()`.

### [MINOR] R3-3 — the recorded manifest numbers are stale; dev moved twice under them. Merge-time correction, not a defect.

The entry is honest about the method (`feature-lane-manifest.json`, Document note: *"Re-taken from the
FILE SETS at this merge … Do NOT add 1 to a stale number: this entry has been re-taken four times
because dev moved under it each round"*) — and dev moved a fifth time. Recomputed from file sets against
dev `04ddf0266`, not from arithmetic:

| | dev `04ddf0266` | lane `0a42af41b` | **at merge** |
|---|---|---|---|
| `git ls-tree -r apps/api/tests/Feature/Document \| grep 'Test\.php$'` | **88** files | **87** files | union **89** |
| dev-only files | `AuthoritySchemaUnactivatedStateTest.php`, `StagedDeploymentBootTest.php` (C-QR0a) | — | both kept |
| lane-only file | — | `ArApOpeningLedgerTest.php` | kept |
| `groups.Document.classes` | 88 | 87 | **89** |
| `gated_ceiling` | 1185 | 1183 | **1186** |

(merge-base `d0cfa624f` was Document 86 / gated 1182; dev added +2 Document and +1 Company → 1185, the
lane adds +1 Document → **1186**.) `Treasury` (122) and `Accounting` (88) correctly do not move: both are
live lane groups whose ceiling is informational.

**Both raises must stay named in the merged note**, and `feature-lane-manifest-check.php` must be
re-run to `EXIT=0` after the merge commit. deptrac at merge: **183 / 183**.

### [MINOR] R3-4 — the classifier docblock names a refusal reason the AR routes never actually emit. (r2 M-1, unchanged.)

`DocumentAllocationClassifier.php:363-364` says an AP opening on the receivable routes answers
`PayableNotSettleableHere`. It does not: `PaymentAllocationService::rejectSupplierInvoiceAllocation()`
(`:212`, defined `:821-834`) fires first and throws the raw code `SUPPLIER_INVOICE_NOT_PAYABLE_HERE`
with no `reason` field — which is what the lane's own test asserts
(`HistoricalOpeningSideSettlementTest.php:197`). `classifyReceivableSide()`'s typed
`PayableNotSettleableHere` (`:160-166`) is only observable where that early reject does not run.
Two names for one refusal; the docblock should name the one an operator actually sees.

### [MINOR] R3-5 — a skipped document on the sweep silently drops its share of the payment. Inherited pattern, widened by one reason.

`PaymentAllocationService.php:236` `continue` (and the pre-existing `:265`): `$preview['excess_amount']`
is computed BEFORE the loop (`:172-179`), so an amount the preview earmarked for a document that the
execute loop then skips is neither allocated nor booked as a 419 advance. The payment row and the
repository movement still carry the full tender. This is exactly how C-0a0's
`allocatableTreatmentOrSkip()` already behaves and is not introduced here — but `PartnerRoleMismatch`
adds one more way to reach it. Worth a row on the treasury-reconcile backlog, not this lane.

### [MINOR] R3-6 — r2's M-2 (the uncovered `fifo`/`due_date` excess branch) is unchanged, and I now believe it is unreachable. Correcting my own r2 note.

`PaymentController.php:1888-1930` still has neither `assertAllocatable()` nor a direction check
(the guard added at `:1826` covers only the `manual` excess branch). But to reach `:1888`, `storeMultiple`
must first pass `assertDirectionMatchesPartner($primaryDocument)` at `:1495` for the SAME partner, and
the FIFO targets are that partner's own `Invoice|SalesOrder` rows — so a partner whose primary document
matched cannot own a target that mismatches. `rejectSupplierInvoiceInMultiline` (`:1488`) closes the
other side. **No money-moving hole here; downgrade r2's M-2 to a defence-in-depth gap.**

---

## Items 4 and 5 of the gate brief — resolved by reading, confirmed

**W4-2 collision (all four predictions).**

1. **Constructor** — `AccountingOpeningService.php:48-53` carries **all four** parameters
   (`$batchService`, `$controlAccounts`, `$scaleResolver`, `$repositoryOpeningSeeder`). ✅
2. **`validateRow()` is 4-arg** (`:218-223`), called with 4 at `:125`; W4-3's account branch
   (`:229-256`) leaves `account_id` unset on a control-account row exactly as predicted. ✅
3. **The predicted spurious "GL account mismatch" does NOT fire** — `validateRepositoryColumn()`
   (`:311-324`) returns early when `repository_code` is absent or blank, before it ever reads
   `$mappedData['account_id']` at `:352-353`. The handback's reasoning is correct, and it is correct for
   the reason it gives. ✅
4. **`FileUpload.tsx`** — `git diff dev HEAD` on both
   `apps/web/src/features/import/components/FileUpload.tsx` and
   `apps/web/src/features/opening-balances/components/FileUpload.tsx` is **empty**: resolved to dev's
   side verbatim, as claimed. ✅ (r2's M-4 sample-codes residual is now W4-2's line.)

**The classifier docblock correction is TRUE.** `DocumentAllocationClassifier.php:344-355` claims the
cutover-ordering hazard is gone because every opening posts its own entry in the same transaction. It
does: `ArApOpeningService::postBatch()` opens one `DB::transaction` at `:310` and calls
`$this->ledgerService->postOpeningEntry(...)` at `:381` inside the same document loop, one entry per
open item. `ArApOpeningLedgerService::postOpeningEntry()` (`:100-183`) returns `null` in exactly one
case — `bccomp($amount,'0',$scale) <= 0` at `:133`, a fully-settled historical record with no balance to
collect — so an opening that HAS a balance always posts its debit, in every posting order. The GL
opening batch refuses the control account on the other side (`AccountingOpeningService.php:240-250`,
re-asserted at post time via `ArApOpeningService.php:334`), so the balance cannot be stated twice. ✅

Precision (rule 19), lane production diff: **no** `(float)`, `floatval`, `number_format`, `round()` on
money. `ArApOpeningLedgerService::moneyScale()` (`:243-249`) uses
`getScaleSafe($currency, 3)` floored at the storage scale — correct for a path that also runs from
console/import. `CurrencyScale::bcformatStrict` at `:130`. The one no-arg `getScale()` in scope
(`ArApOpeningService.php:63`) is pre-existing (`854a55664`), HTTP-only, and the lane adds no new
arithmetic through it. `journal_lines.debit/credit` verified on live PG 16 as **`numeric(15,3)`** — the
docblock's scale-3 claim is accurate and the historic `decimal(15,2)` drift is not present here.

---

## Treasury red at merge — 12, and all 12 are dev's

| Bucket | Count | Tests |
|---|---|---|
| **Inherited on dev `04ddf0266`** | **12** | `AdvanceReversalGlShapeTest` ×11 (`A1a`, `A1b`, `A1c`, `A1d`, `A1e`, `A1f`, `A1g`, `A1g negative`, `Ca ×2`, `A9 ruling empty partition`) · `RepositoryMovementsEndpointTest::test_search_returns_allocation_capacity_for_manual_matching` ×1 |
| OQ-74 conflict | **0** (was 6) | — |
| Lane-caused by guard placement | **0** (was 8 + 1 error at gate r2) | — |

Method: the whole directory is `1238 / 12` on the lane; those two classes alone are `22 / 12`; therefore
every other Treasury class is green. The same two classes on dev are `22 / 12` with the **same twelve
names**. The handback's table is accurate this round.

## Manifest at merge

**Document `89` · `gated_ceiling` `1186`** · deptrac **183 / 183** · `feature-lane-manifest-check.php`
must return `EXIT=0` on the merged tree. Re-take the file-set diff if dev moves again before the merge —
this entry has now been re-taken five times.

## Residuals carried forward (unchanged, all named)

1. **F-4** — `ArApOpeningService.php:14-15` imports `Accounting\Domain\JournalEntry` / `JournalLine` and
   queries them at `:569-578` (rule 6; deptrac is layer-based and cannot see a cross-MODULE model
   import, so 183/183 proves nothing here). Deliberately deferred; the one-line fix (move the query into
   `PartnerControlAccountResolver`) is written down in r2.
2. **F-5** — `ArApOpeningService.php:586-591` still names an escape that does not exist ("remove the
   control-account line from the GL opening and post it again, or post these open items into a company
   whose GL opening does not state it" — a locked batch is not deletable and the second is not an
   operation), and `getPostPreview()` still does not call the guard, so the operator meets the block at
   the last step. The handback says the remedy text was blocked on OQ-74 and can now be written — it
   should be, in the next lane that opens the file.
3. r2 **M-3** (`->sum('amount')` on money in the C-1 helpers), **M-4** (a net-credit partner breaks the
   three-way tie because `payable_balance` clamps at `0.000`), **M-5** (`openBalance()` branches on type
   without re-asserting `is_historical`).
4. The eight pre-existing rows from r2 §Residuals (aged AP blind to a PO-less supplier invoice; ordinary
   unapplied credit note invisible to aged AR; `PaymentType::SupplierPayment` has no writers;
   `JournalLineData` carries no `partner_id`; `reconcileSubledger()` has no caller;
   `OpeningBalancePosted` has no listener; `ProvisioningRequiredPurposes*` inherited red; the GL sample
   codes, now W4-2's line).

## What to fix before merge

Nothing blocking. **Re-take the manifest to Document 89 / gated_ceiling 1186 in the merge commit** (dev
moved again), and land **R3-1**'s single FIFO test in this lane so the r2 CRITICAL cannot come back
unnoticed; **R3-2** can follow in the next lane that touches payment applicability.
