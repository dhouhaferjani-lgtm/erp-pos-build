# W4-3 + W4-4 — treasury/GL + opening-balance gate r2 (verify-only)

**Lane** `fix/campaign-w43-ap-opening-partner-ledger` · worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w43-openings` · HEAD `03b38df7a`
(fix round: `3e73f3650` C-1, `7281c9121` I-1..I-4, `521f3adaf` smart-payment scope, dev merged at
`5e1948e55`) · dev at review time `90e968eb0` (moved from `3bbe28480` during the review — docs only,
manifest unchanged).

r1: `docs/superpowers/reviews/2026-08-25-w43-openings-gate-r1-treasury.md` (CHANGES: C-1, I-1..I-5).
Handback: `docs/superpowers/reviews/2026-08-25-w43-handback.md` §"FIX ROUND r1".

## VERDICT

**spec ✅ · quality ❌ CHANGES-REQUESTED · merge-blocking: YES**

Every r1 finding is genuinely fixed and I tamper-proved each one. C-1 is closed with the exact numbers
from my r1 probe; the direction guard is real, load-bearing and fails closed; the control-account rule
is now post-time, bidirectional and ancestor-aware; the manifest union is right.

It is held on **one defect the fix round itself introduces, proven by execution on both sqlite and
PostgreSQL 16**: the hoisted guard was inserted three lines above the comment block that explains why a
refusal on this path must not throw. **Eight tests that are GREEN on dev go RED on the lane** —
`AutoAllocationSkipsRefusedDocumentsTest` (3 + 1 **error**),
`HistoricalAndPosInvoicesRefusedBeforeProvenanceTest` (4, all `ap_opening`),
`HistoricalOpeningSideSettlementTest` (1) — all of them lane C-0a0's, all merged to dev. The handback's
inherited-red table (12, "identical, name for name, 18 for 18") was measured before the last dev merge
and is stale; the Treasury directory was never re-run on the merged tree.

---

## What I executed

| Check | Result |
|---|---|
| 3 lane classes, sqlite, one process | `OK (28 tests, 99 assertions)` |
| 3 lane classes + the 3 regressed C-0a0 classes, **PG 16** throwaway `autoerp_test_w43g2` (127.0.0.1:5433, dropped after) | `Tests: 58, Errors: 1, Failures: 7` — lane classes green, **all 8 red are C-0a0's** |
| `tests/Feature/Treasury` whole directory, sqlite, lane HEAD | `Tests: 1186, Errors: 1, Failures: 19` |
| The 4 classes carrying that red, run **on dev** (`90e968eb0`, main worktree) | `Tests: 40, Failures: 1` — only the known inherited `RepositoryMovementsEndpointTest`. **⇒ 8 of the 20 are lane-caused, not inherited** |
| C-1 negative control — APFS clone, `patch -p3 -R` of `3e73f3650`'s production hunks | aged AP `620.0000` (vs 380.000 GL), aged AR `150.0000` (vs 110.000 GL) — my r1 probe reproduced digit-for-digit |
| I-1 tamper — `assertDirectionMatchesPartner()` → `return;` | 6/7 direction tests fail; **but every one fails with a DIFFERENT 422 code, not 201** (see F-2) |
| I-1 value probe — ORDINARY (non-historical) mis-typed customer Invoice owned by a supplier, guard tampered | `storeMultiple` → **201**, `customer_payment` JE, repository **+500.000 IN**; split-payment → **201**. Guard restored → **422 `PAYMENT_DIRECTION_MISMATCH`**, repository `0.000`, 0 allocations, 0 JEs. **The guard is load-bearing and fails closed.** |
| I-2/I-3/I-4 tamper (ancestor walk → depth 1; post-time re-assert removed; I-3 check `return;`) | exactly 4 failures — child-account, post-time, AP-order, AR-order — **both negative controls (sibling `413`, AR-not-blocked-by-payable-only) stayed green** |
| `./vendor/bin/pint --test` | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` (level 8) | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | `PASS — 183 / 183` |
| `php tools/feature-lane-manifest-check.php` | `EXIT=0` — 1424 classes / 74 groups, gated 1174 |

Never ran the full suite. Never more than one test process at a time. Lane worktree untouched
(`git status --porcelain` empty); PG database dropped; clone deleted.

---

## FINDINGS

### [CRITICAL] F-1 — the hoisted guard throws on the AUTO sweep and inside a QUEUED FISCAL PROJECTION, re-opening C-0a0 gate F-1. 8 green-on-dev tests go red. **Merge-blocking.**

`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:219` inserts
`assertDirectionMatchesPartner($document)` into the execute loop. The very next statement is the
30-line comment (`:221-247`) that this file already carries:

> *"Gate r1 / F-1 — WHO CHOSE THIS DOCUMENT decides what a refusal does. On MANUAL the operator named
> it… On FIFO / due-date the SERVER named it… throwing would abandon every allocatable document queued
> behind the refused one AND roll back the whole transaction. Worse, this exact call runs inside two
> queued fiscal projections (`TreasuryAccountPaymentBridge`, `TreasuryDepositBridge`), where a
> `DomainException` is not the `NonRetryableProjectionException` the job special-cases — it is retried
> five times and then dead-letters a SEALED device fiscal fact. So the sweep skips…"*

…and `:243-244` implements it (`MANUAL ? classifyReceivableSide() : allocatableTreatmentOrSkip()`).
The new call sits **above** that split and throws unconditionally — and it throws
`HttpResponseException` (`DocumentAllocationStateGuard.php:117`), an HTTP-layer exception, which is
even further from `NonRetryableProjectionException` than the `DomainException` the comment warns about.

**Proven, both drivers.** `AutoAllocationSkipsRefusedDocumentsTest::test_the_queued_projection_entry_point_does_not_throw_on_a_refused_opening`
(`:129-152`, the exact `applyAllocationFromCommand(… FIFO …)` signature the two bridges call) now
**ERRORS**:

```
Illuminate\Http\Exceptions\HttpResponseException:
  app/Modules/Treasury/Application/Services/DocumentAllocationStateGuard.php:117
  app/Modules/Treasury/Application/Services/PaymentAllocationService.php:219
```

and `::test_the_http_auto_path_skips_a_refused_opening_and_still_collects_the_native_invoice`
(`:62-82`) goes `Expected 200, received 422` — **the collection outage C-0a0 fixed, back**: the
native posted invoice queued behind the refused row is never collected and the transaction rolls back.

Full lane-caused red set (green on dev, verified by running the same classes on dev):

| Class | Red on lane | Symptom |
|---|---|---|
| `AutoAllocationSkipsRefusedDocumentsTest` | 1 error + 2 failures | `HttpResponseException` out of a queued projection; auto sweep 200→422; manual reason `DOCUMENT_NOT_ALLOCATABLE`→`SUPPLIER_INVOICE_NOT_PAYABLE_HERE` |
| `HistoricalAndPosInvoicesRefusedBeforeProvenanceTest` (`ap_opening` data set ×4) | 4 failures | `DOCUMENT_NOT_ALLOCATABLE` → `INSUFFICIENT_REPOSITORY_BALANCE` (direct endpoint) / `SUPPLIER_INVOICE_NOT_PAYABLE_HERE` (3 allocation paths) |
| `HistoricalOpeningSideSettlementTest::test_a_historical_ap_opening_is_still_refused` | 1 failure | `DOCUMENT_NOT_ALLOCATABLE` → `INSUFFICIENT_REPOSITORY_BALANCE` |

`Treasury` is a whole-directory CI lane (`treasury-spine-pgsql/feature-treasury`,
`tests/feature-lane-manifest.json`), so these are picked up automatically — this is CI red at merge,
not a parked group.

**Fix.** The direction check must obey the same MANUAL-vs-SERVER split as everything else on this path:
either express it as an `AllocationRefusalReason` inside `DocumentAllocationClassifier` (where
`allocatableTreatmentOrSkip()` already knows how to skip), or call
`assertDirectionMatchesPartner()` from `PaymentAllocationService` **only** when
`$command->allocationMethod === AllocationMethod::MANUAL`. Either way it must not raise
`HttpResponseException` on a code path two queued fiscal projections enter. Then re-run
`tests/Feature/Treasury` on the merged tree and re-take the inherited-red table.

### [CRITICAL] F-2 — W4-3 reverses owner ruling OQ-74 (historical AP openings are refused) without a ruling, and the four route tests do not prove what the handback says they prove. **Merge-blocking (needs an owner line, not necessarily code).**

`docs/handoff/OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:73` — OQ-74, ruled **"AP side only
refused; historical AR openings collect as receivable clearing"**, landed on dev at `05a074699`.
`HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php:28-37` states the premise in code: *"`ArApOpeningService`
mints an AP (payable) opening as `DocumentType::Invoice`… There is no column to tell the two apart yet
(that is lane C-PROV0), so this lane refuses BOTH sides."*

W4-3 **removes that premise** — an AP opening is now `SupplierInvoice` on `HIST-SINV`
(`ArApOpeningService.php:186-187`, `:513-521`) with a posted `supplier_invoice` JE carrying Cr 401
partner-tagged — and consequently makes it **payable** through the supplier arm
(`OpeningItemPaymentDirectionTest::test_paying_an_ap_opening_item_debits_the_payable_and_moves_the_cash_out`
asserts exactly that, and it is green). The proof it is no longer refused is the failure text itself:
the direct endpoint now returns `INSUFFICIENT_REPOSITORY_BALANCE`, i.e. the payment reached the
supplier-payment arm and was stopped only because the fixture's repository was empty.

I think W4-3's position is the **better** one — a properly typed `SupplierInvoice` with its own posted
Cr-401 entry is provably a payable, which is the whole thing C-PROV0/OQ-74 was hedging for. But that is
an owner call, it reverses a recorded ruling from a sibling session, and the four C-0a0 tests that pin
the old contract are simply left red.

**Related, and it changes what r1's I-1 fix can claim.** Handback §"FIX ROUND r1 / I-1" says
storeMultiple / split / apply-deposit *"returned 201 / 201 / 200 and moved the money without it"*.
On the tree being merged that is no longer true: with `assertDirectionMatchesPartner()` tampered to a
no-op, **all six** direction tests still refuse — `DOCUMENT_NOT_ALLOCATABLE` on the four historical
routes, `SUPPLIER_INVOICE_NOT_POSTED` on the customer-owned supplier invoice — because the fixture
(`OpeningItemPaymentDirectionTest.php:406-427`) is `is_historical = true` with no
`opening_balance_import_rows` row, so C-0a0's `HistoricalOpeningProvenance` catches it first. None of
the seven tests demonstrates money moving.

The guard **is** load-bearing — I proved it with a fixture the suite does not have (see F-3) — but the
containment sentence must be re-stated from the merged tree, and at least one route test should use a
document the provenance rule does *not* already refuse, or the suite will keep passing if the guard is
deleted for the wrong reason later.

**Fix.** Owner line on OQ-74 (re-rule or revert the retype), update the C-0a0 tests in the same commit
as whichever way it goes, and re-state handback §10 / §"I-1" from the merged tree.

### [IMPORTANT] F-3 — the guard's only real hole is untested, and it is the one that still moves money.

Proven by reviewer probe (APFS clone, guard tampered to `return;`), an **ordinary, non-historical**
customer `Invoice` owned by a `supplier`-typed partner:

```
storeMultiple  -> 201 | JE source_types ["customer_payment"] | repository balance 0.000 -> +500.000 (IN) | 1 allocation
split-payment  -> 201 | repository +500.000
```

Guard restored: `422 PAYMENT_DIRECTION_MISMATCH`, repository `0.000`, `0` allocations, `0` journal
entries. **This is the shape that justifies the guard and the suite has no test for it** — every
existing test uses a historical fixture that C-0a0 already refuses.

Reachability: `CreateDocumentRequest.php:65-69` validates `partner_id` with `ScopedExists` only — no
role check — so the shape is creatable via API/import, and via re-typing an existing `both` partner to
`supplier` after its invoices exist. The web picker does filter by role
(`DocumentForm.tsx:232`, `:595`), so it is not reachable from the normal UI.

**Fix.** Add that case to `OpeningItemPaymentDirectionTest`. Also add the remedy to the message
(`DocumentAllocationStateGuard.php:120`) the way the control-account refusal now does — an operator
who hits this has no way to know the answer is "set the partner to Both".

### [IMPORTANT] F-4 — `ArApOpeningService` (Document) now queries Accounting's Eloquent models directly. Rule 6, and deptrac cannot see it.

`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:12-15` imports
`Accounting\Domain\JournalEntry` / `JournalLine` and queries them at `:571-580`. The deptrac ratchet is
layer-based (Domain/Application/Infrastructure/Presentation — see its own category table), so a
cross-**module** model import is invisible to it; 183/183 held proves nothing here.

There is in-file precedent (`OpeningBalanceBatch`, `OpeningBalanceImportRow` were already imported),
which is why this is IMPORTANT and not blocking. But the sanctioned pattern exists in this very diff:
C-0a0's `Shared/Contracts/Accounting/HistoricalOpeningSideReaderInterface`. The one-line fix is to move
`assertGlOpeningDidNotStateControlAccounts()`'s query into `PartnerControlAccountResolver` (already an
Accounting service this class holds) and return a `list<string>` of conflicting entry numbers.

Same family, smaller: `DocumentAllocationStateGuard.php:8` (Treasury → `Partner\Domain\Partner`) and
`AgedReceivablesService.php:12` / `AgedPayablesService.php:12` (Accounting →
`Document\Domain\CreditNoteAllocation`). All have in-file precedent.

### [IMPORTANT] F-5 — the I-3 refusal names an escape that does not exist, and the post preview does not warn.

`ArApOpeningService.php:588-593`: *"remove the control-account line from the GL opening and post it
again, or post these open items into a company whose GL opening does not state it."* A **locked** GL
opening batch is not deletable and has no in-product correction path — that is the premise the refusal
itself is built on (`:325-333`) — and "post into another company" is not an operation. So the tenant
this guard was written for is hard-blocked from ever importing open items, with a message that implies
otherwise.

Compounding it: the guard runs only at `postBatch()` (`:334`); `getPostPreview()` (`:479-510`) does not
call it, so the operator discovers the block at the last step. r1's I-3 asked for "refuse **or, at
minimum, return the count in the post preview**" — the preview half was not done.

**Fix.** Surface the conflicting entry numbers in `getPostPreview()`, and change the message to name
the real remedy (a correcting entry via `CorrectingEntryService`, or support).

### [MINOR] M-1 — refusal order differs between routes, so the same document is refused for different reasons.

`PaymentController::store()` puts the direction guard first (`:499`, before `assertAllocatable` at
`:506`), which is r1's stated rationale — "refused for what is actually wrong with it".
`PaymentAllocationService` puts it **third** (`:212` `rejectSupplierInvoiceAllocation`, `:214`
`assertAllocatable`, `:219` direction), so a `SupplierInvoice` owned by a customer answers
`PAYMENT_DIRECTION_MISMATCH` on one route and `SUPPLIER_INVOICE_NOT_PAYABLE_HERE` on another. Observed
in the F-1 failure output.

### [MINOR] M-2 — the guard is on 9 sites, not 8, and one allocation writer is still uncovered.

Grep-verified call sites: `PaymentController:499`, `:1495`, `:1826`; `MultiPaymentController:187`,
`:401`; `PaymentAllocationService:219`, `:852`; `CreditNoteService:1308`;
`CloseInvoiceWithToleranceService:71` — **nine**. The handback says "all eight" and then lists nine
with pre-merge line numbers.

Uncovered: `PaymentController.php:1888-1930`, the `fifo`/`due_date` **excess**-allocation branch, has
neither `assertAllocatable()` nor `assertDirectionMatchesPartner()`; it creates the allocation at
`:1917` after `classifyReceivableSide()` at `:1911`. A historical opening is contained there by the
classifier; a non-historical mis-typed document (F-3's shape) is not. Note that adding the guard here
would hit F-1's problem — this is a server-chosen set too.

### [MINOR] M-3 — `->sum('amount')` on money in the new C-1 helpers.

`AgedReceivablesService.php:239-241` and `AgedPayablesService.php:456-458`:
`bcadd('0', (string) $appliedSum, $scale)` where `$appliedSum` comes from
`CreditNoteAllocation::query()->…->sum('amount')`. `Query\Builder::sum()` returns the raw aggregate —
a string on pgsql, a float on the SQLite runner — and `(string)` on a float uses `precision`, so a
large enough value would stringify to scientific notation and `bcadd` would silently truncate it.
House precedent is identical and pervasive (`Document.php:872`, `:876` — the very method these helpers
wrap; `MultiPaymentService.php:291`, `:372`), so this is not new drift and not blocking. Worth a
`reduce(… bcadd …)` next time the file is open, as `InstrumentLifecycleService.php:543-547` already does.

### [MINOR] M-4 — a net-credit partner breaks the three-way tie the lane claims.

Reviewer probe (AP batch: opening invoice 100.000 + opening credit note 250.000, same supplier):

```
GL 401 net              = -150.000
partners.payable_balance =    0.000     <- clamped
aged AP grand_total      = -150.0000
```

The clamp is pre-existing and deliberate (`PartnerBalanceService.php:392-407` logs the anomaly and
returns `'0.000'`, per the non-negative-magnitude convention), and the lane's C-1 fix is what makes
aged AP agree with the GL here (it read `350.0000` before). But the handback's "aged == GL == partner
page" holds only while the partner is net-debtor. Record it; a negative aged grand total also reaches
the FE (`apps/web/src/features/finance/hooks/useAgedReceivables.ts`) unrendered-for.

### [MINOR] M-5 — `openBalance()` branches on type without re-asserting `is_historical`.

`AgedReceivablesService.php:212` / `AgedPayablesService.php:418` test only
`type === CreditNote` / `SupplierCreditNote`. Correct today because the only caller path is narrowed by
the SQL filter at `:173-179` / `:210-213`, but the two halves of the invariant sit 40 lines apart and
nothing pins them together. One `&& $doc->is_historical === true` would make the branch self-contained.

---

## r1 findings — dispositions I verified by execution

| r1 | Status |
|---|---|
| **C-1** aged AR/AP vs GL on opening credit notes | **CLOSED.** 380.000 / 110.000 green; reverted → 620.0000 / 150.0000, my r1 probe reproduced exactly. |
| **C-1 trap probe (asked at r2)** | An ORDINARY CN is **not** double-netted: unapplied → aged AR `150.0000`; 25/40 applied → `125.0000`; fully applied → `110.0000`. A **historical opening CN applied to an opening invoice** also nets once → `110.0000` (not 70). The `is_historical` narrowing is right, and `remainingCreditAsNegative()` subtracting the outward `credit_note_allocations` is what makes the applied case safe. Residual (pre-existing, unchanged): an ordinary **unapplied** credit note is still invisible to aged AR. |
| **I-1** guard on one route | **CLOSED as a hoist** — 9 sites, tamper-proven load-bearing (F-3). Containment *claim* is over-stated (F-2) and the guard's placement is wrong (F-1). |
| **I-2** validate-time only | **CLOSED.** `AccountingOpeningService.php:324-334` re-asserts on the row about to be written; tamper → `test_a_row_marked_valid_before_the_guard_shipped_is_still_refused_at_post` fails. |
| **I-3** one-directional | **CLOSED.** `ArApOpeningService.php:334` + `:557-596`; tamper → both order tests fail, negative control (AR batch not blocked by a payable-only GL opening) stays green. See F-5 for the message/preview. |
| **I-4** child accounts escape | **CLOSED.** `PartnerControlAccountResolver.php:72-97` walks the ancestor chain; tamper (depth 1) → `4011` test fails, `413` sibling test stays green. |
| **413 / 416 exclusion — is it right? (asked at r2)** | **YES.** Chart probe on the seeded TN chart: `401` (purpose `supplier_payable`) parent `40`; `4011`, `4017` parent `401`; `411` (purpose `customer_receivable`) parent `41`; **`413 'Clients - Effets à recevoir'` parent `41`**, **`416 'Clients douteux'` parent `41`** — neither descends from `411`. `4111` is absent from the TN chart (it is the FR shape). The rule "refuse an account that IS or DESCENDS FROM a purpose-tagged control account" therefore catches exactly `401/4011/4017/411` and correctly leaves 413/416 alone: they are distinct control accounts (bills receivable, doubtful debts) with their own semantics, not a restatement of the open items, and no open-item batch writes them — so admitting them cannot double-count. **One gap worth a row:** `419 'Clients créditeurs'` carries purpose `customer_advance` and is equally partner-dimensioned, but is not in `CONTROL_PURPOSE_BATCHES` (`:49-52`), so a GL opening may still state a partnerless customer-advance balance the sub-ledger cannot see. No double count (nothing else writes it), so MINOR. |
| **catch-all escape in the message** | **DONE.** `PartnerControlAccountResolver.php:165-176`, asserted. |
| **I-5** manifest | **DONE** — see below. |
| **M-2** null-partner docblock | Comment corrected; the `=== null` test is genuinely blocked by the model docblock. Accepted. |
| **M-3** eager `Partner` load on every payment | **GONE** — the guard resolves the document's own partner and reuses the eager-loaded relation (`DocumentAllocationStateGuard.php:98-103`). |
| **M-4 / M-5** | Correctly recorded as residuals. |

## `PartnerFactory` default → `Both` (asked at r2)

`apps/api/database/factories/PartnerFactory.php:37-51`. **Tests and seeders only** — factories are not
loaded on any production path (grep: zero `Partner::factory()` under `app/`). Every seeder that uses it
passes an explicit state (`->customer()`, `->supplier()`, `->both()`) —
`DatabaseSeeder.php:375-408`, `ParapharmacySeeder.php:1229-1259`,
`TunisianParapharmacySeeder.php:401-413`. The one exception is
`DatabaseSeeder.php:405 ->inactive()`, whose state sets only `is_active` (`PartnerFactory.php:87-92`),
so those 5 partners move from a random draw to `both` — a strictly narrower, deterministic shape. The
change is sound and the reasoning in the docblock is accurate.

**The claim it supports is not.** "Treasury red is now stable at 12 inherited, name-for-name identical
with the round reverted" does **not** hold on the merged tree: I measured **20** (1 error + 19
failures) on the lane and **8 of them are green on dev** (F-1). The 12 the handback names —
`AdvanceReversalGlShapeTest` ×11 and `RepositoryMovementsEndpointTest::test_search_returns_allocation_capacity_for_manual_matching`
×1 — I confirm as genuinely inherited. The table must be re-taken after F-1 is fixed.

## Manifest at merge

Recomputed from **file sets**, not arithmetic, against dev `90e968eb0` (manifest identical to
`3bbe28480`):

| | dev | lane HEAD | at merge |
|---|---|---|---|
| `git ls-tree -r tests/Feature/Document \| grep 'Test\.php$'` | **84** files | **85** files | delta = exactly one file, `ArApOpeningLedgerTest.php`, added; nothing removed |
| `groups.Document.classes` | 84 | 85 | **85** |
| `gated_ceiling` | 1173 | 1174 | **1174** |
| `feature-lane-manifest-check.php` | — | `EXIT=0`, 1424 classes / 74 groups | must stay `EXIT=0` |

`Treasury` (120) and `Accounting` (86) correctly do **not** move for this lane's two new classes: both
entries are marked *"Informational for lane groups: the ceiling is enforced only for
deferred/excluded groups; a laned directory picks up new classes automatically"*, and their on-disk
counts (132 / 95) already diverge from the recorded numbers on dev. Only `Document` is ceilinged.

deptrac: **183 / 183, PASS**.

**If dev advances before the merge, re-take the Document file-set diff — do not add 1 to a stale
number.** Both raises must stay named in the entry's note.

## Collision with W4-2 (`fix/campaign-w42-opening-cash-float`, worktree `w42-cash-float`, HEAD `40989770c`)

One production file, `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php`.
W4-2's hunk headers vs dev: `@@ -10`, `-43`, `-115`, `-135`, `-144`, `-168`, `-224`, `-231`, `-279`,
`-328`, `-416`. Overlaps, in merge order:

1. **Constructor, `@@ -43` (textual conflict, certain).** W4-3 inserts
   `PartnerControlAccountResolver $controlAccounts` **between** `$batchService` and `$scaleResolver`;
   W4-2 appends `RepositoryOpeningBalanceSeederInterface $repositoryOpeningSeeder` **after**
   `$scaleResolver`. Resolution: keep all four parameters.
2. **`validateRow()`, W4-3 `@@ -186` vs W4-2 `@@ -224` (semantic, read both).** W4-3 turns the account
   branch into `if (null) … elseif (control) … else { $mappedData['account_id'] = … }`, so a control-account
   row **never sets `account_id`**. W4-2's `validateRepositoryColumn()` (called at `-224`, i.e. after
   that branch) reads `$mappedData['account_id'] ?? null` and compares it to `$descriptor->glAccountId`
   — with W4-3 applied it will add a second, spurious "GL account mismatch" error to an already-invalid
   row. Harmless but noisy; the merger should re-read the whole account branch, not accept both hunks.
3. **`postBatch()`, W4-3 `@@ -309` (the I-2 re-assert) vs W4-2 `@@ -328` (the repository seeder).**
   Adjacent, may or may not conflict textually. Semantically fine: both are inside the one
   transaction and W4-3's `RuntimeException` rolls the seeder back.
4. **`getPostPreview()`, `@@ -416`.** W4-3 rewrites the `note` string; W4-2 adds `repository_*` keys to
   the preview rows. Likely textual conflict, trivially resolved by keeping both.
5. **`apps/api/tests/feature-lane-manifest.json`** — both raise. Recompute from file sets (W4-2's own
   gate r1 recorded union 1174 as well; do **not** add the two deltas together).

**Merge order recommendation: W4-2 first, then W4-3.** W4-2 restructures `validateRow()` /
`postBatch()` far more; landing W4-3's two small insertions on top of the restructured file is the
smaller re-read. Either way, item 2 must be resolved by reading, not by `git`.

## Residuals confirmed (pre-existing, out of lane)

1. Aged AP is blind to a supplier invoice raised without a PO (`AgedPayablesService.php:151-179`);
   the `is_historical` narrowing at `:210-213` is correct and this needs its own lane. **Unchanged.**
2. An ordinary **unapplied** credit note is invisible to aged AR (probe: 40.000 CN, 25.000 applied →
   aged AR `125.0000`, the 15.000 unapplied credit nowhere). Pre-existing; C-1 deliberately does not
   widen to it. Worth a row.
3. `PaymentType::SupplierPayment` still has zero writers in `app/`.
4. `JournalLineData` carries no `partner_id`.
5. `PartnerBalanceService::reconcileSubledger()` has no caller and no report surface.
6. `OpeningBalancePosted` has no listener.
7. `ProvisioningRequiredPurposes*` ratchet inherited red on dev (M-5 registration deferred behind it).
8. `FileUpload.tsx:52-67` GL template still cites `101000` / `213000` / `301000`, in no seeded chart.

## What must change before merge

**F-1** — make the direction check skip on the server-chosen path and never raise
`HttpResponseException` inside `applyAllocationFromCommand()`; then re-run `tests/Feature/Treasury` on
the merged tree and re-take the inherited-red table (it is 20, not 12, and 8 are yours).
**F-2** — an owner line on OQ-74, and the C-0a0 tests updated in the same commit whichever way it goes;
re-state handback §10 / §"I-1" from the merged tree.
**F-3** — one test for the non-historical mis-typed document (the only shape that still moves money),
and the remedy sentence in the 422.
F-4 and F-5 should land with this lane if the F-1 fix reopens the file; M-1..M-5 may be named residuals.
Manifest at merge: **Document 85 / gated_ceiling 1174**, deptrac 183/183.
