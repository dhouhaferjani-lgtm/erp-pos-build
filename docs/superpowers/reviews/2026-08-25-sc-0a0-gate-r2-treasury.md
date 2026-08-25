# C-0a0 gate r2 — TREASURY lens

**Lane** `feat/sc-0a0-payment-applicability` · worktree `.worktrees/sc-0a0-payment-applicability`
**Reviewed SHA** `4ab709d04` (fix commit `4ee16617c`) · **r1 tree** `ffcdd8555` · **Base** local `dev` `faf822f8a`
**Date** 2026-08-25 · **Lens** treasury (adversarial, code-grounded) · **Reviewer** treasury-reviewer
**Prior record** `docs/superpowers/reviews/2026-08-25-sc-0a0-gate-r1-treasury.md` (CHANGES-REQUESTED, merge-blocking)
**Tree discipline** READ-ONLY except authorised revert- and tamper-probes, each restored. `git status --short` on the
lane is EMPTY at `4ab709d04` after every probe; `git stash list` unchanged (2 pre-existing entries, other sessions).
Throwaway PG `autoerp_test_sc0a0_gate2` created on 5433 and DROPped. Full suite never run; one process at a time.

---

## VERDICT: spec ⚠ (one recorded deviation, ruling owed) + quality **ACCEPT-WITH-CONDITIONS** — **MERGE-BLOCKING: NO**

All nine r1 findings are closed by execution, including the CRITICAL one, and the fix round did **not** introduce a
new wrong-money path — the one I hypothesised from r1 (a fully-refused sweep leaving `payment.journal_entry_id` NULL
and dead-lettering both queued bridges) is **DISPROVEN by probe**: the pure-advance branch posts and links a 419 JE.
What remains is one spec-conformance deviation that needs an owner ruling, a stale handback whose first two sections
now state the opposite of the shipped policy, and four minor items. None of them is a reason to hold the code.

---

## 1. Verification legs

### Leg 1 — surface, migration, rules 19/13/6/enums/envelope

```
git diff --stat ffcdd8555..4ab709d04   → 21 files, 1816 insertions(+), 254 deletions(-)
git diff --name-only ffcdd8555..4ab709d04 -- apps/api/app   → 9 production files
```
- **Migration: still NONE — CONFIRMED.** No `database/` path in either round's diff.
- **Rule 19 — CLEAN.** `git diff ffcdd8555..4ab709d04 -- apps/api | grep '^+' | grep -Ei '\(float\)|floatval|parseFloat|number_format|getScale\(\)'` → **no matches**. The new test helper compares money with `bccomp($sum,$expected,3)`, not string equality (`AutoAllocationSkipsRefusedDocumentsTest:216`).
- **Rule 13 — CLEAN in production.** `git diff … -- apps/api/app | grep '^+' | grep 'app('` → **empty**. All eight `app()` hits in the diff are in tests.
- **Rule 6 — CORRECT SHAPE** (see Leg 6). `DocumentAllocationClassifier.php:1-15` imports
  `App\Shared\Contracts\Accounting\{HistoricalOpeningSide, HistoricalOpeningSideReaderInterface}` and **no Accounting
  model**. Deptrac 183, no lane class in the violation list.
- **Enums — CONFORMANT.** New `HistoricalOpeningSide` (`ar`/`ap`, Shared) and
  `AllocationRefusalReason::PayableNotSettleableHere` (`:93`). Both `match`es in the classifier remain exhaustive with
  no admitting `default`; `HistoricalOpeningSideReader::sideFor()` has a `default => null` that fails **closed**.
- **Envelope — UNCHANGED.** `bootstrap/app.php:964`/`:970` still emit `{'error':{'code','message','details'}}`, 422.
- **i18n — COMPLETE.** `payable_not_settleable_here` added and `historical_opening_provenance` rewritten in **all
  three** locales (`lang/{en,fr,ar}/treasury.php`).

### Leg 2 — GREEN on the lane (sqlite), by path

```
php artisan test tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php tests/Unit/Treasury/PaymentAllocationWriterCensusTest.php
→ Tests: 141 passed (3972 assertions)
php artisan test tests/Feature/Treasury/AutoAllocationSkipsRefusedDocumentsTest.php \
                 tests/Feature/Treasury/HistoricalOpeningSideSettlementTest.php \
                 tests/Feature/Treasury/ReceivableSideSeamTest.php
→ Tests: 14 passed (37 assertions)
```

### Leg 3 — REVERT-PROBE of F-1, BOTH halves (r2 tests against r1 production)

Five production files reverted to `ffcdd8555` (`PaymentAllocationService`, `AllocationRefusalReason`,
`DocumentAllocationClassifier`, `PaymentRefundService`, `VendorRefundService`; the three new additive files left in
place because r1 code never references them):

```
php artisan test tests/Feature/Treasury/AutoAllocationSkipsRefusedDocumentsTest.php
⨯ the http auto path skips a refused opening and still collects the native invoice   Expected 200, received 422
⨯ the http auto path skips a pos derived invoice and still collects the native …     Expected 200, received 422
⨯ the auto preview offers exactly what the execute allocates    Failed asserting that an array does not contain '…'
⨯ the queued projection entry point does not throw on a refused opening
      DocumentNotAllocatableException: Document HIST-INV-2026-00001 (invoice, posted) … (historical_opening_provenance)
      at DocumentAllocationClassifier.php:117
✓ the manual path still throws for the same document
⨯ an auto sweep whose whole set is refused returns cleanly                           Expected 200, received 422
Tests:    5 failed, 1 passed (9 assertions)      ← matches the handback exactly
```
Both halves are proven by the same run: **the AUTO branch now skips and continues** (4 cases red on r1, green on r2),
and **MANUAL still throws** (green on BOTH — a preservation assertion, correctly so). The **queued-projection entry
point** case exercises the exact `ApplyPaymentAllocationCommand` shape `TreasuryAccountPaymentBridge:216` /
`TreasuryDepositBridge:200` construct, and it threw on r1 and does not on r2.

**Preview/execute agreement** is the third red case and is now *structural*, not two hand-kept predicates:
`previewAllocationForContext():114-128` filters the AUTO set through `rejectUnallocatable()` (`:623-636`) →
`refusalReasonFor()`, and `applyAllocationFromCommand()` iterates only `$preview['allocations']` and re-takes the
**same** predicate on the locked row via `allocatableTreatmentOrSkip()` (`:642-665`). One classifier, two reads; the
only possible divergence (a status change between them) resolves to a SKIP, never a throw.

Tree restored (`git checkout -- apps/api`; `git status --short` empty).

### Leg 4 — REVERT-PROBE of F-2 / F-3 / F-4 (same reverted tree)

```
php artisan test tests/Feature/Treasury/HistoricalOpeningSideSettlementTest.php tests/Feature/Treasury/ReceivableSideSeamTest.php
⨯ a historical ar opening can be collected on the direct payment endpoint      Expected 201, received 422
⨯ a historical ar opening is offered and collected by the auto sweep           Expected 200, received 422
✓ a historical ap opening is still refused
✓ a historical opening whose side cannot be resolved is refused
⨯ the split payment service refuses a posted supplier invoice with the payable reason   two strings are not identical
⨯ the deposit application service refuses a posted supplier invoice                     two strings are not identical
✓ the split payment service still admits a posted invoice
✓ the split payment service books a confirmed invoice as an advance
Tests:    4 failed, 4 passed (14 assertions)
```
The AR-collection cases are red on r1 and green on r2 (F-2 closed); the AP and unprovable cases are green on BOTH,
which is the fail-closed half correctly unchanged. The two seam cases fail on r1 **with the seam firing but the wrong
reason** — exactly F-4's shape, and it confirms `ReceivableSideSeamTest` reaches the seam for real
(`MultiPaymentService::createSplitPayment()` / `::applyDepositToDocument()` called directly, past the controller
pre-guards, on a supplier invoice that carries a posted Cr-401 JE).

### Leg 5 — TAMPER-PROBES

**(a) the new literal matrix table (F-8).** `EXPECTED_PROVENANCE['invoice|historical_ap|posted']` flipped from
`refused:historical_opening_provenance` to `admit:receivable_clearing`:
```
→ Tests: 1 failed, 137 passed (874 assertions)   (failure at PaymentApplicabilityMatrixTest.php:241)
```
**Exactly one** data set fails. That is the property a clone oracle cannot have: the r1 oracle shared the
implementation's control flow, so one edit moved a whole family. The table is now a genuine literal oracle.

**(b) the opening-side reader, fail-OPEN direction.** `HistoricalOpeningSideReader::sideFor()` forced to always return
`AccountsReceivable`:
```
✓ a historical ar opening can be collected on the direct payment endpoint
✓ a historical ar opening is offered and collected by the auto sweep
⨯ a historical ap opening is still refused
⨯ a historical opening whose side cannot be resolved is refused
Tests:    2 failed, 2 passed (7 assertions)
```
The two fail-closed guards have teeth, and they are the two that matter: a fail-open reader is exactly how F-2's fix
could have re-created F-107.

Tree restored after each probe.

### Leg 6 — **judgement (1): the `HistoricalOpeningSideReader` shape** — SOUND, rule-6 correct, excursion acceptable

*Is the side derivation sound?* Yes, and the evidence predates the lane:
- `opening_balance_import_rows.row_type` is `string(20)` **NOT NULL** since the table was created
  (`database/migrations/tenant/2025_12_11_100000_create_opening_balance_tables.php:48`), so there are **no legacy
  NULLs** to fail on. It is stamped at INSERT from the batch's own type
  (`OpeningBalanceBatchService::addImportRows():201,210` ← `OpeningBatchType::rowType():24-32`, `AR`/`AP`/`GL`/`INVENTORY`).
- `mapped_entity_id` is written by exactly ONE writer, `markRowsPosted():641-648`, in the same `update()` that sets
  `status = Posted`, and only from `ArApOpeningService::postBatch():352`'s `$rowEntityMap`. The other occurrence
  (`:559`) is a hash READ, not a write. So one document ↔ one row, deterministic; `value('row_type')` cannot pick
  arbitrarily between duplicates because there are none.
- Verdict table as implemented (`DocumentAllocationClassifier::refusalForHistoricalOpening():349-365`): AR + `posted`
  ⇒ admitted (`ReceivableClearing`); AR + any other live status ⇒ `status_not_allocatable_for_type`; AP ⇒ refused;
  `null` (no row / GL / INVENTORY row_type) ⇒ refused; CreditNote either side ⇒ refused. **Fail-closed by
  construction** — only positive `AR` evidence admits — and proven so by tamper-probe (b).
- `PartnerType` correctly rejected as the discriminator: `PartnerType::Both` exists and both `scopeCustomers()` and
  `scopeSuppliers()` admit it, so a `both` partner's opening side is genuinely unknowable from the partner row. The
  reasoning is recorded in the reader's own docblock rather than only in the handback.

*Is it rule-6 correct?* Yes. Treasury Domain depends on `App\Shared\Contracts\Accounting\*` only; the implementation
sits in Accounting; the binding is `AppServiceProvider:123`, two lines below the identical
`PaymentLedgerPartitionReaderInterface` precedent (`:119`). Constructor-injected `private readonly`
(`DocumentAllocationClassifier:95-101`), no `app()`. Deptrac unchanged at 183 with zero hits for the new classes.

*Is the scope excursion acceptable in-lane, or must it be a separate lane?* **Acceptable in-lane.** Three reasons,
in order: (i) it is *gate-directed* — r1 F-2's "required change (a)" named this exact derivation; (ii) it **cannot**
be done inside `app/Modules/Treasury/**` without violating rule 6, so the brief's boundary and the required fix are
in direct conflict and the Shared-contract shape is the minimum resolution of both; (iii) splitting it out would mean
merging C-0a0 *without* it, i.e. shipping the r1 availability break to `dev` and relying on a follow-up lane to
withdraw it. The excursion is 3 new files + 4 lines of provider wiring, all additive, none touching existing
Accounting behaviour. **Condition 1** below covers the paperwork, not the code.

### Leg 7 — **judgement (2): R-R1-3, the omitted `is_historical = false` SQL predicate** — CORRECT DEVIATION

The disposition's literal wording would now be a **defect**, not a fix: F-2 admits AR openings, so
`is_historical = false` in `getOpenInvoices()` would hide every collectable opening from the sweep — the identical
availability break from the other side. That is not theoretical: `HistoricalOpeningSideSettlementTest::a historical
ar opening is offered and collected by the auto sweep` is RED on r1 and green on r2 (Leg 4).

What shipped instead is the right split: the **POS** marker IS a `documents` column, so it is excluded in SQL
(`PaymentAllocationService:585-594`, built from
`DocumentAllocationClassifier::POS_ACCOUNT_CHARGE_REFERENCE_PREFIX` so the two cannot drift); the **AR/AP** marker is
not on the row, so it is decided by the classifier filter that preview and execute share.

*Does `getOpenInvoices()` still offer anything the execute loop refuses?* In raw SQL, yes — it still SELECTs AP-side
and unprovable-side openings. But nothing reaches the operator or the write: `rejectUnallocatable()` sits between
`getOpenInvoices()` and the preview's return value, and the execute loop consumes only the preview's output. The
r1 leg-9 shape (offered → then refused) is **gone**, pinned by
`test_the_auto_preview_offers_exactly_what_the_execute_allocates` (RED on r1) and re-proven in my Leg 3. The residue
is a bounded read of rows that are then discarded — cost, not correctness (see G-5).

### Leg 8 — **judgement (3): F-5, the census claim** — EXACT

```
grep -c "Document::query()->find" PaymentRefundService.php VendorRefundService.php   → 0, 0
grep -rn "allocationClassifier->assertReversalAdmitted" apps/api/app | wc -l         → 4
  VendorRefundService.php:150 · PaymentRefundService.php:224, :1325, :1980
```
Exactly four reversal sites, in exactly the two files
`PaymentAllocationWriterCensusTest::REVERSAL_SEAM_SITES_PREDICATE_DEFERRED` declares, and
`test_the_reversal_seam_sites_are_declared_as_predicate_deferred()` asserts both the count (`assertSame(4, …)`) and
the file whitelist. No unguarded reads remain. The signature is now `assertReversalAdmitted(string $documentId)`
(`DocumentAllocationClassifier:290`), so the seam costs nothing until it decides something, and the docblock plus
both call-site comments say **SEAM PRESENT, PREDICATE DEFERRED TO C-0a1** in those words. **F-5 fully closed**, both
the runtime half and the evidential half.

### Leg 9 — **judgement (4): any NEW wrong-money path?** — my r1-derived hypothesis DISPROVEN by probe

I predicted one: if the sweep now skips everything, `bccomp($totalAllocated,'0',4) > 0` (`PaymentAllocationService:428`)
is false, no JE is posted, `payment.journal_entry_id` stays NULL, and **both** bridges throw their own
`\DomainException` (`TreasuryAccountPaymentBridge:253-259`, `TreasuryDepositBridge:236-241`) → generic
`catch (Throwable)` in `ApplyFiscalEventProjectionJob:414` → `$tries = 5` → dead-letter. That would have relocated
F-1 rather than fixing it. Probe (disposable suite, run and deleted), vendor holding ONLY a refused AP opening,
FIFO, the bridge's exact command shape:

```
=== GATE R2 PROBE (fully-refused sweep) ===
applyAllocationFromCommand success: true
threw: no
payment.journal_entry_id: 01a0369f-90c6-7330-90d0-68dd012a72e8      ← NOT NULL
allocations on the AP opening: 0
```
**Hypothesis refuted.** The whole amount falls to `excess_amount` and the pure-advance branch
(`PaymentAllocationService:428-471`) posts `createCustomerAdvanceJournalEntry()` (Dr bank / Cr 419), links it as the
payment's journal entry (`:461-463`) and flips `payment_type` to `Advance` (`:465-467`). Money is banked, the GL
consequence exists, and both bridges' null-JE guard is satisfied. **No new wrong-money path.** The residual shape is
G-6 below, and it is a change of an inherited wrongness, not a new one.

I also checked the two other candidates and cleared both:
- **AR opening admitted ⇒ Cr 411.** Correct at the account level: `ArApOpeningService` creates **no** per-document JE
  (`:37`, `:257`, `:427` — "GL was handled by accounting opening"), so the 411 comes from the separate
  `AccountingOpeningService` batch. Collecting credits a control balance the opening batch debited. Unchanged from
  base (base admitted these too), so not a lane defect — but it is a *precondition*: see the residual note.
- **Reader as a false-admission vector.** A non-opening document can only be admitted as an AR opening if an import
  row with `row_type='AR'` claims it, which only `ArApOpeningService::postBatch()` produces. No path found.

### Leg 10 — gates

```
./vendor/bin/pint --test app/Modules/Treasury app/Modules/Accounting app/Shared/Contracts/Accounting \
    app/Providers/AppServiceProvider.php tests/Unit/Treasury tests/Feature/Treasury lang bootstrap/app.php
→ {"result":"pass"}

./vendor/bin/phpstan analyse  <11 Treasury production paths + 3 Shared/Accounting + AppServiceProvider
                               + 7 new/rewritten test paths>          → [OK] No errors

./vendor/bin/deptrac analyse  (lane) → Violations 183  ⇒ UNCHANGED (main dev checkout also 183 at r1);
                                       grep for this lane's classes in the violation list → 0 hits

php apps/api/tools/feature-lane-manifest-check.php  (cwd = lane worktree)
→ OK, exit 0 — 1411 Feature classes in 74 groups (1408 at r1: the three new suites are absorbed with no manifest
  edit), every group dispositioned, every --filter anchored across 1804 test classes.
  Same two inherited ⚠ advisories (70 groups parked behind the F-2 execution-gate flag; 1 coverage-debt group).
```

### Leg 11 — PG throwaway (`autoerp_test_sc0a0_gate2`, 5433, dropped)

```
AutoAllocationSkipsRefusedDocumentsTest · HistoricalOpeningSideSettlementTest · ReceivableSideSeamTest
→ Tests: 14 passed (37 assertions)   (36.58s)
PaymentApplicabilityMatrixTest · PaymentAllocationWriterCensusTest · PurchaseOrderAllocationRefusedTest ·
HistoricalAndPosInvoicesRefusedBeforeProvenanceTest
→ Tests: 168 passed (4126 assertions)  (70.01s)
   — includes the F-7 rename, green on PG:
     ✓ the auto allocation sweep skips it without refusing the request  (×4 provenance families)
```

### Leg 12 — regressions on the lane (sqlite, by path)

```
DocumentPaymentStatusTransitionTest · CloseInvoiceWithToleranceServiceTest ·
CloseInvoiceWithToleranceEndpointTest · PaymentRefundTest · VendorPrepaymentRefundTest
→ Tests: 56 passed (220 assertions)

ArApOpeningPostLifecycleTest · SmartPaymentIntegrationTest · PaymentAllocationServiceTest   (F-2 + F-1 blast radius)
→ Tests: 26 passed (127 assertions)
```

### Leg 13 — r1 findings, re-derived line by line

| r1 finding | Status | Evidence in this record |
|---|---|---|
| **F-1** CRITICAL — auto sweep threw instead of skipping | **CLOSED** | Leg 3 (revert-probe, both halves + projection entry point + preview/execute), Leg 7, Leg 9 |
| **F-2** IMPORTANT — historical AR openings had no settlement path | **CLOSED** | Leg 4 (RED on r1), Leg 6 (soundness + rule 6 + excursion) |
| **F-3** IMPORTANT — `classifyReceivableSide()` had zero coverage | **CLOSED** | unit: `test_the_receivable_side_seam_{refuses_a_payable_settlement,admits_the_ar_documents,still_refuses_what_the_matrix_refuses}` (`PaymentApplicabilityMatrixTest:443,476,487`); feature: `ReceivableSideSeamTest`, RED on r1 (Leg 4) |
| **F-4** IMPORTANT — payable refused with credit-note copy | **CLOSED** | `AllocationRefusalReason::PayableNotSettleableHere` `:93`, used at `DocumentAllocationClassifier:165`; en/fr/ar copy added; RED on r1 with "two strings are not identical" (Leg 4) |
| **F-5** IMPORTANT — three discarded `Document::find()` reads | **CLOSED** | Leg 8 (0 reads, exactly 4 declared seam sites) |
| **F-6** MINOR — handback line table wrong | **CLOSED** | Every re-derived symbol verified by `grep -n`: classifier ctor `:95`, constants `:108/:115/:121`, `classify` `:126`, `classifyReceivableSide` `:152` (F-4 reason `:165`), `classifyOrNull` `:177`, `isAllocatable` `:184`, `refusalReasonFor` `:198` (SalesOrder `:235`, PurchaseOrder `:249`), `assertReversalAdmitted` `:290`, `treatmentFor` `:301`, `refusalForHistoricalOpening` `:349`, `isHistoricalOpening` `:377`, `isPosAccountCharge` `:391`; PAS ctor `:45`, write `:250`, `NOT LIKE` `:592`, `rejectUnallocatable` `:623`, `allocatableTreatmentOrSkip` `:642`, `logAutoAllocationSkip` `:667`; enum's 8 cases `:31/:39/:47/:54/:61/:70/:79/:93`; provider `:123`. **All exact.** One trivial off-by-one (the AUTO/MANUAL branch is cited `:240`, the ternary opens at `:239`) — not worth a finding |
| **F-7** MINOR — auto-path tests asserted no status code | **CLOSED** | renamed to `test_the_auto_allocation_sweep_skips_it_without_refusing_the_request` with `assertOk()`; green on PG (Leg 11). The two vacuous PO auto cases are KEPT and carry an explicit "HONEST COVERAGE NOTE" |
| **F-8** MINOR — matrix oracle was a structural clone | **CLOSED** | Leg 5(a): `expectedRefusalReason()` deleted; `EXPECTED_NATIVE` (78 literal cells) + `EXPECTED_PROVENANCE` (48) + `test_the_tables_cover_exactly_the_whole_space()`; one flipped cell ⇒ exactly one failure |
| **F-9** MINOR — SalesOrder narrowing contradicted the N-6 gate | **RESOLVED IN THE OPPOSITE DIRECTION** → now **G-2** below |

---

## 2. Findings (r2)

### [IMPORTANT] G-1 — the handback's §1 and §6 now state the OPPOSITE of the shipped policy

*Where.* `docs/superpowers/reviews/2026-08-25-sc-0a0-handback.md` §1 "What shipped" and §6 "Scope note", both
untouched by `4ee16617c`; only the appended fix-round section is current.

*Evidence.*
- §1: *"Admitted — and nothing else (4 cells of 78)"*, with a table listing `SalesOrder | confirmed` and no historical
  row. The shipped admitted set is **18 literal strings over 6 (type,status) cells**
  (`PaymentApplicabilityMatrixTest::test_the_admitted_set_is_closed_and_exactly_this():330-350`), and it includes
  `invoice/posted/historical_ar=receivable_clearing` and `sales_order/posted/*=prepayment`.
- §1's behaviour table still says *"`SalesOrder` … `Prepayment` only at `confirmed`"* (now false, `:235`) and
  *"`Invoice`/`CreditNote` with historical-opening … Refused"* (now false for AR, `:349-365`).
- §6: *"one file outside the brief's declared boundary" / "Two edits fall outside" / "Nothing else outside the
  boundary was modified … readers … are untouched."* The fix round added **four** more out-of-boundary paths —
  `app/Shared/Contracts/Accounting/HistoricalOpeningSide.php`,
  `app/Shared/Contracts/Accounting/HistoricalOpeningSideReaderInterface.php`,
  `app/Modules/Accounting/Application/Services/HistoricalOpeningSideReader.php`,
  `app/Providers/AppServiceProvider.php:123` — and a **reader** is precisely what it added. §6 also still carries the
  stale `bootstrap/app.php:963-977` citation that F-6 corrected elsewhere in the same document.

*Consequence.* The handback is the merge artefact. A parent gate that reads §1 and §6 — the two sections written for
exactly that purpose — signs off on a narrower policy and a smaller scope than what ships. The excursion is defensible
(Leg 6), but it has to be *declared* to be approved.

*Required change.* Rewrite §1's admitted-set table and behaviour table from the code, and rewrite §6 to list all six
out-of-boundary paths with the rule-6 justification from the fix-round section. No code change.

### [IMPORTANT — CONDITION, not a blocker] G-2 — `SalesOrder + posted` is admitted, which contradicts spec r11 §2.1 rule 7 and the brief

*Where.* `DocumentAllocationClassifier.php:235` — `DocumentType::SalesOrder => null` (rule 1 has already excluded
every non-live status, so this admits **both** `confirmed` and `posted`).

*Evidence.* SPEC r11 §2.1 rule 7 reads *"SalesOrder + confirmed ⇒ `Prepayment`"*, and rule 9 is
*"EVERYTHING ELSE ⇒ `Refused`"*; the brief repeats *"SalesOrder + `confirmed` ⇒ `Prepayment`"*. r1's F-9 asked for the
spec-vs-N-6-gate conflict to be **recorded**; the implementer resolved it the other way, restoring N-6's ruling
(base docblock: *"`Posted` IS reachable … anything narrower here is a regression, not a fix"*) and documenting the
reasoning at `:226-234`.

*Assessment.* **No wrong money.** `treatmentFor():307` returns `Prepayment` for a sales order at either status, and a
sales order never carries a 411, so the booking is Cr 419 in both cases. The widening is fully visible — five
`sales_order/posted/*` strings in the closed-set assertion — and pinned by name in
`test_the_cells_this_lane_closes()`. This is a documented spec deviation, not a defect.

*Required change.* An owner/spec ruling: either amend spec §2.1 rule 7 to "SalesOrder + confirmed **or posted**", or
narrow the code back and formally withdraw the N-6 gate's ruling. Do not merge into a program that pins rule 7 as
written without one of the two.

### [MINOR] G-3 — the `historical_opening_provenance` copy names a remedy that does not exist yet

*Where.* `lang/en/treasury.php` (and fr/ar equivalents): *"This is a supplier opening balance … Settle supplier
opening balances **through the supplier payment flow**."*

*Consequence.* There is no such route. An AP opening is minted as `DocumentType::Invoice`
(`ArApOpeningService.php:310-332`), and the supplier branch of `PaymentController::store()` is gated on
`$document->type === DocumentType::SupplierInvoice` (`:514`). The operator is told to do something that will fail.
The handback's own F-2 table says AP settlement is *"C-0a1's route"* — the copy should say the same.

*Required change.* Reword to state that supplier opening balances cannot be settled yet (as the r1 copy did for both
sides), or ship the AP route. Copy-only.

### [MINOR] G-4 — `HistoricalOpeningSideReader::sideFor()` is the only allocation-path read with no company predicate

*Where.* `HistoricalOpeningSideReader.php:32-36` —
`OpeningBalanceImportRow::query()->where('mapped_entity_id', $documentId)->value('row_type')`.

*Consequence.* Safe today: tenants are separated by database, and `mapped_entity_id` holds a document UUID, so there
is no cross-company read. But it deviates from the invariant this very file-cluster enforces — `getOpenInvoices():519-522`
carries BOTH `tenant_id` and `company_id` "on every read whose anchor came from a route param" (the round-3/round-4
finding recorded in its own docblock). A reader that decides *which side of the ledger money moves on* is the last
place to make an exception.

*Required change.* Either add the company predicate (via the document join) or record in the reader's docblock why
the UUID key makes it unnecessary, so the next reader does not have to re-derive it.

### [MINOR] G-5 — `refusalReasonFor()` runs twice per locked AUTO row, so R-R1-2 understates the reader's query cost

*Where.* `PaymentAllocationService::allocatableTreatmentOrSkip():644,655` — `refusalReasonFor($document)` first, then
`classifyOrNull($document)`, which calls `refusalReasonFor()` again internally
(`DocumentAllocationClassifier:179`).

*Consequence.* For a historical opening that is **3** `HistoricalOpeningSideReader` queries per document per sweep
(one in `rejectUnallocatable()`, two on the locked row), not the "one query per historical document classified"
R-R1-2 records. Bounded by the number of openings in the offered set and zero on the native path, so it is a cost
note, not a correctness one — but the residual should say the real number.

*Required change.* Reuse the verdict (`$reason === null ? $this->allocationClassifier->…` restructured to one call,
or a `classifyOrNull()` that takes the already-computed reason), and correct R-R1-2.

### [MINOR] G-6 — a fully/partially refused AUTO sweep silently parks the remainder as a **customer advance (Cr 419)**

*Where.* `PaymentAllocationService:428-471` (pre-existing excess handler), now reached in a new set of cases because
the sweep skips instead of throwing.

*Evidence.* Leg 9's probe: vendor with only a refused AP opening, FIFO ⇒ `success: true`, zero allocations,
`journal_entry_id` set, and `payment_type` flipped to `Advance` (`:465-467`).

*Consequence.* For a customer this is correct and is what makes the F-1 fix safe. For a **supplier** partner it books
a customer-side credit (Cr 419) against a supplier. That is **not new and not worse** — on base the same fixture
booked Cr 411 against the same supplier (r1 Leg 6, the F-107 bug) — and reaching it requires an operator to run a
customer collection against a supplier. Recording it because C-0a1 owns the AP route and this is the shape it must
close, and because R-R1-1 (skips not surfaced in the response) means the operator gets no signal that a collection
became an advance.

*Required change.* None in this lane. Fold into R-R1-1's disposition so the reader lane surfaces both the skipped
documents AND the fact that the payment landed as an advance.

### [MINOR] G-7 — the MANUAL/AUTO split keys on the method enum alone, and `manual` with no `manual_allocations` is accepted

*Where.* `PaymentAllocationService:239` branches on `$command->allocationMethod === AllocationMethod::MANUAL`, while
`previewAllocationForContext():115` branches on `MANUAL **&&** $manualAllocations !== null`.
`SmartPaymentController.php:74` validates `'manual_allocations' => ['nullable', 'array']`.

*Consequence.* `allocation_method=manual` with the array omitted produces a **server-chosen** (AUTO, filtered) preview
that the execute loop then evaluates under **operator-chosen** (throwing) semantics. Today that cannot throw, because
the preview already dropped every refused row; it can only diverge if a status flips between the unlocked and locked
read, where MANUAL 422s and AUTO would skip. The underlying "manual with no selection silently becomes an automatic
allocation" shape is pre-existing and out of this lane.

*Required change.* None blocking. Consider branching the execute loop on the same condition the preview uses
(`MANUAL && $manualAllocations !== null`) so one predicate governs both.

---

## 3. Residual disposition

| # | Handback position | Gate ruling |
|---|---|---|
| R-C0a0-1 (reversal seam total) | implementation fixed, policy deferred to C-0a1 | **ACCEPTED.** Leg 8 verified exactly. |
| R-C0a0-2 (`getOpenInvoices()` mirror drift) | CLOSED, it was F-1 | **CONFIRMED CLOSED** — Leg 3 + Leg 7. Agreement is structural, not two predicates. |
| R-C0a0-3 (auto returns 200 with no reason) | superseded; reasons logged server-side, response shape is R-R1-1 | **ACCEPTED**, with G-6 folded in. |
| R-C0a0-4 / -5 / -6 / -7 | unchanged | **ACCEPTED.** The older supplier-invoice type guards remain in place, which is what keeps F-3/F-4's seam a belt-and-braces rather than the only guard. |
| R-GATE-1 (`type_never_allocatable` copy) | addressed in copy | **CONFIRMED** — enum docblock `:74-79` and the three locales now name the metadata flags. |
| **R-R1-1** (skips not surfaced in the API response) | reader lane | **ACCEPTED**, widen per G-6. |
| **R-R1-2** (one reader query per historical document) | C-PROV0 removes it | **ACCEPTED with a correction** — it is three, not one. See G-5. |
| **R-R1-3** (no literal `is_historical = false` predicate) | deliberate, reasoned deviation | **ACCEPTED AND ENDORSED.** Leg 7: the literal wording would now be a defect. The POS/AR split (SQL for the column-backed marker, classifier for the row-backed one) is the right resolution. |
| **R-R1-4** (OQ-74 needs an owner ruling) | recorded default | **ACCEPTED** → Condition 3. |

**New residual opened by this gate — R-R2-1:** F-2's AR admission credits 411 on collection, but AR opening
*documents* create no JE at all (`ArApOpeningService.php:37, :257, :427` — "GL was handled by accounting opening").
The matching debit exists only if the tenant also posted an `AccountingOpeningService` opening batch. This is
inherited (base admitted the same collections), but C-0a0 is what re-enables the path, so the cutover runbook should
state the ordering: post the accounting opening batch **before** collecting against AR open items.

---

## 4. Conditions

1. **Rewrite handback §1 and §6** from the code — the admitted set is 6 (type,status) cells including
   `invoice/posted/historical_ar` and `sales_order/posted`, and six paths now fall outside the brief's boundary
   (`bootstrap/app.php`, the two `app/Shared/Contracts/Accounting/*` files, `HistoricalOpeningSideReader.php`,
   `AppServiceProvider.php`, plus the flagged Treasury test). Documentation only; no code change. (**G-1**)
2. **Obtain a ruling on `SalesOrder + posted`** — amend spec r11 §2.1 rule 7, or narrow the code back and formally
   withdraw the N-6 gate's ruling. Money-safe either way (Cr 419), but the lane currently contradicts the spec it
   implements. (**G-2**)
3. **Get OQ-74 onto the owner sheet** (historical AR openings collectable on the AR path before C-0a1) — the
   implementation records it as the default; it is not yet ruled. (**R-R1-4**)
4. **Fix the `historical_opening_provenance` copy** so it does not send operators to a supplier route that does not
   exist until C-0a1. Copy-only, three locales. (**G-3**)
5. **Add the cutover-ordering note** to the runbook: post the accounting opening batch before collecting AR open
   items. (**R-R2-1**)

G-4, G-5, G-6 and G-7 are recorded for the follow-on lanes and are not conditions of this merge.

---

## 5. What to fix before merge

**Nothing in the code. Land conditions 1–4 (three are documentation, one is an owner ruling on `SalesOrder + posted`)
and this lane is mergeable as `4ab709d04`.**

---

## VERDICT: spec ⚠ (one recorded deviation, ruling owed) + quality **ACCEPT-WITH-CONDITIONS** — **MERGE-BLOCKING: NO**
