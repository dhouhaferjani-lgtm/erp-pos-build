## Adversarial merge-gate register — enforcement-p3 · M1 · round 2

**Scope reviewed:** `git diff 0ca7bbb09..HEAD` (HEAD = `a81b1c07c`; milestone commit `bea3b4936`) — 7 code files (4 production `app/`, 3 tests) + census + `M1-round1.md` + progress YAML. Held against `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` §4 "3(a)" and the `p3-M1` milestone line (§4:448). **Amending authority: none.** No design relitigation below.

### Round-1 findings — disposition verified at code, not from the census

| r1 | claimed disposition | my verification |
|---|---|---|
| 1 (swallowed queued-context refusal) | fixed | **CONFIRMED closed.** `PostShiftCashVarianceAdjustment.php:184` sits *before* the `catch (Throwable)` at `:210`; refusal now audited under `unbalanced_journal_entry` at `error`. Red evidence pasted (census §5.7) shows the exact `"reason":"exception"` payload from finding 1. I re-ran it green (`OK (1 test, 5 assertions)`) and the whole file (`OK (23 tests, 98 assertions)`). Fail-closed atomicity independently verified: `RepositoryAdjustmentService.php:115` wraps the movement + entry in one `DB::transaction`, so nothing partial survives the throw. |
| 2 (unreachable converter re-throw) | withdrawn | **CONFIRMED.** `SalesOrderToInvoiceConverter` now carries **comment-only** delta. The replacement test `it_pins_the_deferral_…` is genuinely discriminating for what it claims: if the enclosing transaction is removed the post is synchronous, the IAE-family refusal lands in `:589`'s catch, nothing propagates, test goes red. Green: `OK (1 test, 1 assertion)`. |
| 3 (false blast-radius claim / `CreditNoteController` envelope) | corrected + type split | **CONFIRMED.** `UnbalancedJournalEntryException` is back to `\RuntimeException`, so `CreditNoteController::post():406` still renders `500 CONFIGURATION_ERROR`. New sibling `UnbalancedJournalEntryPostException extends \InvalidArgumentException` with a byte-identical message. |
| 4 (docblocks left asserting a false contract) | "updated" | **NOT closed — see finding 1.** The update introduced two *new* false statements. |
| 5 (misleading converter comment) | withdrawn with the re-throw | CONFIRMED. |
| 6 (`PostGrIrOnGoodsReceipt`) | reported as R-9(i) | CONFIRMED present, with the `GoodsReceiptService.php:409` fail-closed twin noted. |

---

### 1 · P2 · CONFIRMED · `apps/api/app/Modules/Accounting/Domain/Exceptions/UnpostableDocumentGlException.php:31-35` and `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:313-315`

*The round-1 finding-4 fix re-committed round-1 finding 4: both docblocks now state, as a "correction of record", something the code contradicts — and both cite a test that does not exist.*

Both were written at `1d6fbe63c` (single shared type) and were **not revisited** when `bea3b4936` split the types. At HEAD they assert:

- `UnpostableDocumentGlException.php:31-35` — "the GL posting chokepoint (`GeneralLedgerService::sealAndPersistEntry`) **also raises `UnbalancedJournalEntryException`** now, so it additionally fires from every `postEntry`/`postEntryNow` path."
- `AccountingService.php:314-316` — "Note the **SAME type** is also raised by the GL posting chokepoint … this method is no longer its only source."

The chokepoint raises `UnbalancedJournalEntryPostException` (`GeneralLedgerService.php:3418`), a **sibling under a different parent**. `AccountingService::assertLegsBalance()` remains the *only* source of `UnbalancedJournalEntryException` (verified: no other throw site).

Additionally, both files (`AccountingService.php:313`, `UnpostableDocumentGlException.php:40`) name `ChokepointUnbalancedGuardTest::test_the_house_unbalanced_exception_is_never_a_logic_exception` as the guard. `grep` over the repo: the method does not exist; the real guard is `test_the_two_unbalanced_types_keep_their_load_bearing_parents` (`ChokepointUnbalancedGuardTest.php:111`).

**Failure scenario (concrete, and it is the milestone's own subject matter).** R-10 hands the parent a list of catch sites that downgrade a chokepoint balance refusal to 4xx, prescribing per-site narrowing. A maintainer taking that work reads `UnpostableDocumentGlException` — the file whose stated job is to disambiguate these types — concludes the chokepoint refusal is a `\RuntimeException`-family type, and narrows `DocumentConversionController.php:432` / `POS/ReceiptController.php:378` (the `\RuntimeException` clauses) instead of `:418` / `:385` (the `\InvalidArgumentException` clauses, which are the reachable ones per census §5.5 rows 7 and 17). The 422/400 downgrade of a fiscal imbalance survives, and the grep-for-the-named-test sanity check that would catch the error returns nothing. This is exactly the class of defect round 1 rated P2, in the same two files.

### 2 · P3 · CONFIRMED · `apps/api/tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php:27-29`

Same staleness inside the test that pins the contract: the class docblock says the refusal "must be raised as the house type `{@see UnbalancedJournalEntryException}`", while `:87` asserts `UnbalancedJournalEntryPostException` and `:118-125` asserts the two must **not** be related. The file's prose argues against its own assertions.

### 3 · P3 · CONFIRMED · `docs/handoff/reviews/enforcement-p3/M1-census.md` §1(iii), §5.7

The census is a named review target (brief §7.3), and three of its evidence rows no longer describe HEAD:

- §1(iii) cites `AccountingService.php:404/556/903` and `GeneralLedgerService.php:3440`; actual are `:416/:568/:915` and `:3450` — off by exactly the +12 / +10 lines **this diff itself inserted**. Same for §2.3 rows 44–46 and `GeneralLedgerService.php:4765` (actual `:4767`).
- §5.7 regression table reports `ChokepointUnbalancedGuardTest` → `OK (2 tests, 4 assertions)`; actual at HEAD is **7** assertions (I ran it). The 4-assertion number is the pre-split state.
- §5.7 annotates `GeneralLedgerPostEntryScaleTest` and `GLIntegrationTest` as "(type updated, message preserved)". Neither file appears in `git diff --stat 0ca7bbb09..HEAD` — the split reverted those edits. The annotation understates the result: they are **unmodified and green**, which is stronger evidence for the "byte-identical blast radius" claim than the census claims for itself.

### 4 · P3 · CONFIRMED · `docs/handoff/reviews/enforcement-p3/M1-census.md` §6 R-4 (`AccountingService.php:872-893`)

R-4 records a knowingly-sealed **one-legged, unbalanced, chained** entry (lineless-document cancellation) and files it as "out of 3(a) balance scope". Verified accurate at code (`$balanceAssertable` gates both the pre-flight refusal at `:893` and the `assertLegsBalance` at `:941`). It is out of *guard-addition* scope — closing it would make lineless documents uncancellable, a behaviour change a prior L1 gate deliberately deferred — but it is not out of *balance* scope, and it is the one class-(c) row that would be **red at base**. Reporting rather than fixing is the right call under rule 4; the classification wording should say "deferred by prior gate ruling, needs a parent scope ruling" rather than "not in balance scope", so it does not get filtered out of the parent's list.

---

### Bypasses I tried that FAILED (i.e. the delivery held)

- **Exact-class dispatch broken by subclassing:** searched `app/` for `get_class($e) ===` / `$e::class ===` comparisons that the new subclass would silently stop matching — **none exist**.
- **A new catch site starting to match:** `DeliveryNoteController.php:585-589` has only `\DomainException` + `\RuntimeException`, no IAE clause — census row 6 holds. Ran the two unmodified pre-existing tests that assert on this throw (`GLIntegrationTest.php:471` catches `\InvalidArgumentException`; `GeneralLedgerPostEntryScaleTest.php:75`): `OK (31 tests, 123 assertions)`. Blast radius is byte-identical as claimed.
- **"No queue behind the listener" (the justification for not re-throwing):** traced every `CashCountRecorded` dispatcher — `ReportGenerationService.php:415` and `ZReportSyncController.php:548`; `ReportGenerationService`'s only caller is `ReportController` (HTTP). `PostShiftCashVarianceAdjustment` is `final readonly`, registered as a plain closure at `TreasuryServiceProvider.php:185`, not `ShouldQueue`. Could not falsify; R-8's residual is honestly stated.
- **Partial write on refusal:** could not produce a treasury-movement-without-GL state — `RepositoryAdjustmentService::post` wraps the whole write in `DB::transaction` (`:115`).
- **A missed `journal_entries` write vector:** re-ran the census sweeps myself. `JournalEntryStatus::Posted` assignments = chokepoint + 6 production sites (+2 seeders), exactly as censused; `insert`/`upsert`/`forceCreate`/`firstOrCreate`/`updateOrCreate`/relation-create → **zero**; the only `DB::table('journal_entries')` use (`TreasuryReceiptBridge.php:559`) is a read-only `exists()` probe. Completeness proof holds.
- **Global Eloquent-listener leakage** from the two `JournalLine::created(...)` closures in the new tests: ran both files whole — `OK (23 tests, 98)` and `OK (14 tests, 36)`. No pollution.
- **Boundary regression** from the new Treasury→Accounting import: `php tools/deptrac-ratchet.php` → `RESULT: PASS — no boundary regression against baseline` (176/176 held).

### Standing checks

**Rule 19:** clean — no float touches money; every amount added is a string literal; no scale resolution added, changed, or removed; no bare no-arg `getScale()` introduced. **Red-first:** present and non-vacuous for both behavioural changes (§5.7 pastes the round-1-finding-1 red payload verbatim and the round-0 type red trace); the two withdrawn changes are recorded as withdrawn with their disproofs (M1-D6, M1-D7, M1-D8) rather than dressed as coverage. **Acceptance / nonzero selection (R2-H-7):** all four §5.8 commands re-run by me — `N = 1` each, all `OK`; no placeholder green submitted for the zero-class-(c) modules, per contract. **Constructor injection:** no `app()` added to production (the two `app()` calls are in tests). **Migrations / queues / i18n:** none — nothing to check. **Static/style:** `pint --test` → `{"result":"pass"}`; `phpstan` level 8 over the touched `app/` files → `[OK] No errors`. **YAML:** `status: review`, `fix_rounds: 1 ≤ 5`, `commit: bea3b4936`, `last_verdict: changes_required` — coherent.

**Lenses.** *treasury* — applied (listener disposition, adjustment atomicity, audit-reason discrimination): no defect. *fiscal-pos* — applied: the refusal is raised at `GeneralLedgerService.php:3416-3418`, strictly *before* chain-sequence/`previous_hash`/`fiscal_hash` computation, so no sealed-bytes or chain semantics change; no projection `apply()` touched. *stock-gl-interaction* — applied: `ReverseWriteOffService.php:203-209` posts only via `postEntry` → chokepoint (class (b), untouched); `BatchWriteOffService.php:122`'s `catch (\RuntimeException)` is reported as R-9(ii) and I verified its stated protection (`$postSynchronously = false` default at `GeneralLedgerService.php:4767`). *inventory-costing* — **N/A**: no WAC, stock-movement, or cost-path code is touched by this diff.

---

**Required to clear the gate (documentation-only; the code is correct and verified):** correct the two production docblocks (finding 1) so they name `UnbalancedJournalEntryPostException` as the chokepoint's type and cite the test method that actually exists; fix the test class docblock (finding 2); refresh the census's HEAD-invalid line references and the two stale §5.7 rows (finding 3); reword R-4's classification (finding 4).

VERDICT: CHANGES-REQUIRED
