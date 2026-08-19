## M5 whole-branch merge-gate review — round 1 — lens: fiscal-pos

**Diff reviewed:** M5 delta `d425434cd..1db4bafa9` (6 commits, 17 files) plus a whole-branch sweep of every fiscal / POS / device surface over `48cebf0f2..HEAD`.
**Amending authority applied:** `ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md` (OQ-12/H-5 is a deploy-time flag-flip blocker, not a milestone blocker; the three adopted commits fall under this review). `TREASURY-RULING-2026-08-19-t20-option-a.md` account map taken as ruled, not relitigated. `M4-round6.md` findings carried per its handback.
**Not re-litigated:** `M5-round1-treasury.md` (ACCEPT) findings 1-8, and `M5-round1-inventory-costing.md` (CHANGES-REQUIRED), which landed on the tip while this review was in flight — I cross-reference its findings 2 and 5 where they touch mine and take no position on the rest. My verdict below is **lens-scoped**: it is not a vote against that lens's blocking findings, which the parent adjudicates. Where my lens touches the same code I reference its finding number rather than restating it. Its finding 1 (propagating-flush GL failure rolls back the physical correction) and my F-1 below are the two halves of one pre-flag-flip fiscal-period problem.
**Working tree:** clean before and after. One throwaway probe class was written under `tests/Feature/Inventory/`, run, and deleted; `git status --porcelain` was empty immediately after. Beyond this register file I modified, staged and committed nothing.
**Concurrent-register protocol:** honoured. Tip observed at `454ae733d` (treasury register) throughout and re-checked immediately before commit; no non-register commit appeared. The concurrent inventory-costing reviewer's probe files, if any, were ignored; I used my own isolated database.

**Lens applicability.** *fiscal-pos* — applies narrowly but genuinely. The branch authors no fiscal event, touches no hash chain and touches no device code, but it (a) adds a **queued listener** that must obey the no-CompanyContext contract, (b) re-routes the counter-account of the **POS return-SCRAP write-off**, which is reached from the device-refund fiscal projection, and (c) creates a journal entry whose date is the branch's one un-derived fiscal fact.

---

## Register

### F-1 — P2 · CONFIRMED (pre-flag-flip) · the count-correction entry's `entry_date` is the ONLY field not derived from the persisted row, and it bypasses the application clock

`ApplyStockAdjustmentsOnCountingCompleted.php:382` — `entryDate: new \DateTimeImmutable('now')`
vs `:381` — `occurredAt: \DateTimeImmutable::createFromInterface($occurredAt)` (row-derived)
`InventoryGlPostingService.php:81` (`entryDate: $ctx->entryDate`), `GeneralLedgerService.php:4620` (`'entry_date' => $entryDate->format('Y-m-d')`)

T21's stated principle is "one basis, **on the row**" — the amount, the direction, the accounts and the entry description are all recomputed from the persisted `stock_movements` row precisely so a since-changed WAC or a redelivery cannot move them (`M5-evidence.md` §1.2). `entry_date` is the single exception: it is a fresh wall-clock read taken at enqueue time, and `new \DateTimeImmutable('now')` does not consult Laravel's application clock at all.

**Measured, not argued.** A throwaway probe at this tip on PostgreSQL, flag ON, full Option A chart, one shortage item, with the application clock travelled forward one year before the listener fires:

```text
PROBE-FIS travelled_now=2027-08-19
          movement_occurred_at=2027-08-19 18:03:43
          entry_date=2026-08-19 00:00:00
          entry_description=Inventory count correction; occurred 2027-08-19T18:03:43+00:00
```

The movement and the entry's own description agree on 2027; the entry **posts to 2026**. Three consequences:

1. **No test can pin the fiscal period a shrinkage lands in.** `entry_date` is the input to the closed-period check that treasury finding 1 shows will abort the whole counting (`GeneralLedgerService.php:3429-3431`). That failure mode is therefore not deterministically coverable — time-travel cannot reach the value that decides it.
2. **Period attribution is worker-clock, not fact-derived.** A large counting whose movements are written before midnight and whose root flush lands after it posts movements dated D and entries dated D+1. Narrow in production (the two clocks normally agree), but it is a divergence that no recorded fact can adjudicate afterwards.
3. **It contradicts the seam's own established fiscal pattern.** The POS fiscal projection derives its GL date from the **device-authored** event (`PosCoreReceiptProjection.php:297` — `$postedAt = $event->event_time_device`, threaded to every `entryDate:` at `:1849, :2156, :2182, :2277, :2294, :2547`); `ReturnScrapWriteOffService.php:181` uses `$entryDate ?? $occurredAt ?? now()`; `InventoryOpeningService.php:247` uses `$batch->cutover_date`. The count-correction path is the only inventory GL writer that derives its date from nothing.

**Overlap, declared:** the concurrent **inventory-costing** register raises the post-time dating as its finding 5 (P3). This finding is the same field with an additional, measured mechanism — the application-clock bypass — which is what makes it untestable rather than merely late. The parent should treat them as ONE item, not two.

**Scope:** unreachable while `count_correction_gl_posting_enabled` is FALSE (the shipped default, itself an open deploy blocker), so **not a merge blocker** — it belongs on the same pre-flag-flip line as treasury findings 1 and 2. Free to close: `entryDate: \DateTimeImmutable::createFromInterface($occurredAt)`, one line, which also makes treasury finding 1's failure mode testable.

### F-2 — P3 · CONFIRMED · the `ReplayFinalizeTest` ticket overstates its own case count

`docs/superpowers/tickets/2026-08-19-replay-finalize-test-not-pg-runnable.md`, Finding 1 — "so all **14** cases error before asserting anything"

The mechanism is stated **exactly right** and I reproduced it verbatim on my own PostgreSQL database:

```text
SQLSTATE[22001]: String data, right truncated: 7 ERROR: value too long for type character varying(20)
  (… insert into "inventory_countings" … CNT-RPL-6a85efa01264a …)
Tests: 11, Assertions: 0, Errors: 11.
```

`'CNT-RPL-'.uniqid()` is 21 characters against `varchar(20)`, exactly as the ticket says. But the file has **11** test methods (`grep -c 'public function test_'` → 11), not 14, and PG errors on 11 of 11. The count is simply wrong, and a ticket whose headline number is unverifiable invites the next reader to discount the rest of it. I did not attempt to reproduce the ticket's Finding 2 divergence table; the concurrent **inventory-costing** register (`M5-round1-inventory-costing.md`, its finding 2) reports having disproved it by experiment as an executor-environment artifact. I neither confirm nor dispute that — the parent should read the two together and treat the ticket's Finding 2 as unsettled.

**The inherited-file claim VERIFIES.** `git diff d425434cd..1db4bafa9 -- tests/Feature/Inventory/ReplayFinalizeTest.php` appends one method at EOF (`test_both_counting_paths_persist_the_row_unit_cost`) and touches nothing else; `:157` still reads `'counting_number' => 'CNT-RPL-'.uniqid()`. M5 left it exactly as inherited, as it says it did. The file is green on SQLite at this tip: **OK (11 tests, 36 assertions)**.

### F-3 — P3 · CONFIRMED · on a FAILED tenant the *reason* is unobtainable from production logs

`2026_08_19_130000_backfill_inventory_shrinkage_purposes.php:46` (`Log::info`) vs `:52-62` (`Log::warning`), `BackfillInventoryShrinkagePurposesCommand.php:82-84` (`$this->error`), `:97-99` (`Log::warning`)

The dispatch's standard — tenant-attributed tokens that survive `LOG_LEVEL=warning` — **holds for the gate token**: `:52` is `Log::warning`, carries `tenant=<key> status=ok|FAILED exit=<n>`, and the exception arm at `:64` is `Log::error`. The template-overlay tokens are likewise attributed (`InventoryVarianceAccountProvisioner.php:45-50, 69-76` both carry `tenant_id` + `company_id` + `country_code`). The deploy checklist correctly warns "Do not infer success from an empty log: production drops info-level records."

What does **not** survive is the *why*. The per-company refusal text exists in exactly two places: the migration's `Log::info` at `:46` (dropped at warning level, by the checklist's own admission) and the command's `$this->error(...)` at `:82-84`, which writes to console output and **never reaches the log at all**. The command's own summary token at `:98` survives at warning level but is **not** tenant-attributed — it is the bare string `INVENTORY-SHRINKAGE-PURPOSE BACKFILL FAILURES: N`. So an operator holding a warning-level production log of a failed deploy knows *which tenant* failed and nothing else.

Mitigated — the checklist's repair step instructs re-running the command through the tenant runner and reading its output — so P3, not higher. Free to close by promoting the per-company refusal to `Log::warning` with `tenant_id`/`company_id`.

### F-4 — P3 · CONFIRMED · the replay-path count-correction movement carries no actor, while the entry keyed on it does

`StockAdjustmentService.php:1380` (`userId: null`) vs `ApplyStockAdjustmentsOnCountingCompleted.php:236` (legacy path passes `$completedBy`) and `:385` (`postedByUserId: $completedBy`)

T21's whole thesis is that the two counting paths now share one basis. They do for cost and direction; they do not for attribution. The legacy movement names the finalizer, the replay movement names nobody, and the journal entry T21 keys on the replay movement names the finalizer anyway — so the entry has an actor its own source row lacks.

Reconstructable: `reference_id` → `inventory_countings` → `completed_by`, and the `COUNTING_FINALIZED` `InventoryCountingEvent` (`InventoryCountingService.php:1078-1092`) records the acting user independently. So the audit trail is not broken. But `userId: null` predates M5 and M5 is what turned that row into the source of a journal entry, which is when the asymmetry starts to matter.

### F-5 — P3 · CONFIRMED · the new queued-listener test never clears `CompanyContext`

`CountCorrectionGlPostingTest.php:273` (`app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(...)`), `:79-127` (`setUp`)

House rule 20 requires projection/queue tests to `app(CompanyContext::class)->clear()` before invoking the unit under test, precisely so a bound context cannot mask the worker reality. This test does not, and neither does any other case in the file.

**Today the coverage is genuine and I verified it rather than assuming it:** `tests/TestCase.php` binds nothing (its `setUp` only calls `set_time_limit(0)`), the fixture never sets a company context, and the class runs **7/7 green on PostgreSQL with the flag ON** — which is itself proof that no no-arg scale resolution is reachable anywhere on the posting path, because one would have thrown. The gap is that this holds by accident of what `setUp` happens not to do; a future addition to the base `TestCase` or to this fixture would silently convert the strongest guarantee in the file into a vacuous one. One line closes it.

### F-6 — P3 · NOTED (whole-branch, POS seam) · the SCRAP write-off's counter-account changed under existing tenant charts; the fail-soft window is real but detected

`GeneralLedgerService.php:4512` (`hasInventoryWriteOffAccounts` now requires `InventoryShrinkageExpense`), `:4814` (`createInventoryWriteOffEntry` resolves shrinkage), `InventoryGlPostingService.php:99-105` (warning + `null`), `PosCoreReceiptProjection.php:2077` / `ReceiptReturnService.php:419` (the SCRAP disposition contract)

The device-refund SCRAP disposition posts through `ReturnScrapWriteOffService` → `MovementGlKind::BatchWriteOff` → `postForBatchWriteOff`. That path now depends on a purpose no existing tenant chart carries until the backfill migration lands. Between code activation and a successful per-tenant backfill — or **indefinitely**, for a company whose backfill FAILED — the projection degrades from "posts a COGS entry" to "logs a warning and posts nothing" while the stock leg still commits. `ReturnScrapWriteOffService`'s own docblock (`:41-47`) promises the pair "NEVER DECLINES QUIETLY (gate C1)"; that promise covers the two movements, not the GL leg, which does decline quietly by design.

**Bounded on both sides, which is why this is P3 and not a blocker:** D-a catches the resulting orphan — `$costedExitReasons` is built from `Cogs ∪ Shrinkage` (`CheckCogsCoverageCommand.php:167-176`), so `WriteOff` is in scope, and `dA` (`:198-215`) reports any costed non-`stock_adjustment` movement with no entry after a 2-hour grace. And the deploy checklist blocks promotion on any missing or `FAILED` backfill token. Recorded as a deploy-sequencing risk the parent should keep next to the backfill gate, not as a code defect. The checklist's cutover paragraph handles the historical side correctly and with the right fiscal posture: *"Historical Damage/Expiry/WriteOff entries remain in COGS … do not restate hash-sealed historical journals."*

---

## Standing checks

- **Rule 8 — events are immutable forever. PASS, by 0-line diff.** `git diff --stat 48cebf0f2..HEAD` over `apps/api/app/Modules/Inventory/Domain/Events/`, `apps/api/app/Modules/POS/Domain/Events/` and the **entire** `apps/api/app/Modules/Fiscal/` tree is **empty**. `InventoryCountingCompleted` — the event T21's listener consumes — is untouched: nine promoted constructor properties (`countingId`, `tenantId`, `companyId`, `locationId`, `countingNumber`, `itemsCount`, `totalVariance`, `completedBy`, `completedAt`), `getEventName() === 'inventory.counting.completed'`, `getAuditData()` intact. No field added, renamed or removed; no `…V2` needed and none invented. No parallel refund/void event type appears anywhere in the branch.
- **Hash chain / fiscal-event writers. PASS, by 0-line diff.** No file under `Modules/Fiscal/` appears in `git diff --name-only 48cebf0f2..HEAD`. `ZReportHashService`, `ReceiptHashService` and the `fiscal_events` writers are untouched. The whole branch's POS/Fiscal production footprint is **comment-only** — seven hunks changing the words "Dr COGS" to "Dr Shrinkage": `PosCoreReceiptProjection.php:2077`, `ReceiptReturnService.php:419` and `:1416`, `ReturnScrapWriteOffService.php:40` and `:193`, `StockAdjustmentDocumentService.php:618`, `UseBatchWriteOffException.php:15` and `:32`. Zero behavioural lines in the projection; `apply()`, the idempotency probe, `insertReceiptOnConflictDoNothing`, `applyStockMovementForLines`, `resolveReceiptType` and `assertOriginalReceiptResolvableForRefundOrVoid` are all byte-identical to base. The one Fiscal **test** touched (`PosCoreReceiptProjectionRefundDispositionStockTest.php`) only re-codes its COGS fixture to `603` and seeds the new `6586` purpose.
- **Device surface. PASS, by 0-line diff.** `git diff --stat 48cebf0f2..HEAD -- apps/pos apps/web packages/shared` is **empty**. No SQLite TEXT-timestamp boundary is constructed or bound anywhere in the branch; `apps/pos/src/lib/db/sqliteTime.ts` is untouched; no shift re-hydration; no endpoint retired or gated, so no fallback path is promoted to primary. Nothing in the branch can reach the same-day-drop trap.
- **Enum value contract to the device/web. PASS.** `MovementReason` gains `affectsShrinkage()` and the exhaustive `glCounterFamily()` and rewrites `affectsCOGS()` in terms of it; **no case is renamed, removed or re-valued**. `POSSale`/`POSReturn` deliberately stay `Cogs` (a sale exit still relieves COGS) while `Damage`/`Expiry`/`WriteOff` move to `Shrinkage` — that is the ruled behaviour change, and it reaches the POS only through F-6. `StockMovementReferenceType` is untouched.
- **Queued/projection context discipline (rule 20). PASS, verified statically and empirically.** Zero `CompanyContext` references in the listener, `InventoryGlPostingBuffer`, `InventoryGlPostingService` or `StockAdjustmentService` outside docblocks. Currency is resolved once per job from the entity (`ApplyStockAdjustmentsOnCountingCompleted.php:399-402`, `companies.currency`), carried on the non-nullable `MovementGlContext::$currencyCode`, and every scale resolution downstream is explicit. `resolveRowUnitCost` (`StockAdjustmentService.php:1716-1725`) is deliberately resolver-**independent** — it reads `products.cost_price` at the constant `COST_SCALE = 6` and never consults `CurrencyScaleResolver` — and is company-scoped by the already-validated `StockLevel`, so a forged `productId` cannot value a movement off another company's cost. The one no-arg fallback on a nearby path (`GeneralLedgerService.php:4771-4773`) is reached only when `currencyCode` is null, which no inventory caller supplies, and carries an accurate docblock naming the POS projection as the reason. The decisive evidence is empirical: **7/7 green on PostgreSQL with the flag ON and no context bound anywhere** — a reachable no-arg `getScale()` would have thrown.
- **No implicit connection assumption.** Tenant and company are resolved from the location/product inside the lock (`StockAdjustmentService.php:1277-1278`); the migration binds its transaction to `$this->getConnection()` explicitly (`:41`).
- **Idempotency on the REPLAY path, not just live. PASS — four independent layers.** (1) the per-item `replay_audit` marker, stamped in the SAME transaction as the movement (`ApplyStockAdjustmentsOnCountingCompleted.php:130-137`, `:298-336`); (2) the legacy path's `COUNTING:{number}` + (product, location, variant) movement probe (`:195-213`); (3) the replay computation is **target-based**, so a second pass computes `adjustment = 0`, `directionForRow()` returns `flat`, and `InventoryGlPostingService.php:44-46` returns null *before* any account lookup; (4) the DB partial unique index `uniq_je_source_inventory_movement` (`2026_08_11_000100_unique_journal_entries_source_inventory_movement.php:11`) plus the pre- and in-transaction probes at `GeneralLedgerService.php:4577-4581` / `:4605-4610`. Pinned by `CountCorrectionGlPostingTest::test_queue_retry_after_the_marker_posts_no_second_entry`. I could not construct a double-post.
- **Replay determinism — stated precisely rather than asserted.** The GL **amount** is reconstructible from the persisted movement row (`unit_cost × |quantity_after − quantity_before|`) and is stable across redelivery, retry and re-flush; that is the correct fiscal posture and I could not make two flushes of the same row disagree. It is **not** reconstructible from the event stream alone, and does not claim to be: `InventoryCountingCompleted` carries no quantities or costs, `resolveRowUnitCost` reads the mutable `products.cost_price`, and the replay window ends at `now()`. That is the inherited design of the counting replay (target-based and self-correcting), not something M5 introduced. The one field that is derived from **neither** the event nor the row is `entry_date` — F-1.
- **Audit reconstructability — "why did stock change". PASS.** Movement → `reference_type = inventory_counting` + `reference_id = counting.id` on **both** paths (`:240-241` legacy, `:310-311` replay), additive to the free-text `COUNTING:{number}` / `COUNT_REPLAY` label. Item → `replay_audit` with window from/to, replayed delta, on-hand at apply and expected at apply (`:497-503`), stamped for flagged items too (`:464-469`). Entry → `source_type = 'inventory_shrinkage'`, `source_id = movement.id`, description carrying the row's own `occurred_at`. Counting → a `COUNTING_FINALIZED` `InventoryCountingEvent` with the acting user. An auditor can walk entry → movement → counting → finalizer without leaving the tenant database. F-4 is the single asymmetry; treasury finding 7 (the entry description does not name the counting number) is the single legibility gap — referenced, not restated.
- **Device = fiscal source of truth.** Nothing in this branch re-authors, mutates or "corrects" a device-signed fact. The count-correction leg is authored server-side from a server-side counting document, which is the correct provenance; the POS projection's own writes are untouched.
- **New named queues.** `git diff 48cebf0f2..HEAD -- app/ | grep -c '^+.*onQueue'` → **0**. Horizon coverage unaffected; `HorizonQueueCoverageTest` has nothing new to cover.
- **`unit_price` TTC-vs-HT trap.** Not applicable and not tripped — the delta contains no per-line price arithmetic and no `line_subtotal` assertion; the count-correction amount is cost-based (`unit_cost × |Δqty|`), and fiscal integrity is asserted at the entry aggregate (`assertEntryAmountEqualsRowCostTimesAbsoluteDelta`).
- **Sync-queue bypass I tried — the code held.** If `InventoryCountingCompleted` were dispatched *inside* the finalize transaction, a `sync` queue driver would run the listener at `transactionLevel() === 2`, where `flushIfOutermost()` (`InventoryGlPostingBuffer.php:56-62`) registers the leak alarm and posts **nothing** while the stock correction still commits — a silent GL drop. It is not: `InventoryCountingService.php:1100-1107` dispatches under `DB::afterCommit`, so the listener always opens its own level-1 root frame. Not constructible.
- **Test quality.** No `assertTrue(true)` in the delta. `RefreshDatabase` + real models, no faked payloads. `CountCorrectionGlPostingTest` skips **loudly** off PostgreSQL (`:83-88`), drives the replay case through `postCountCorrection` by asserting `movement_type`/`reason`/`reference_type` before touching the entry, and recomputes the expected amount from the persisted row rather than a literal. Its `tearDown` (`:168-179`) cleans the rows it deliberately commits. F-5 is the one gap. The SQLite-masking risk is handled the right way round here: the fiscal-aggregate logic is exercised on **PostgreSQL** by construction, and only the driver-agnostic cost-threading assertion lives on the SQLite lane.

## Verification I ran myself

Isolated PostgreSQL database `autoerp_w3d_m5r1_fis`, created for this review so neither the shared `autoerp_test` nor the treasury reviewer's `autoerp_w3d_m5r1_tr` was touched. **Never the full suite.**

| Command | Result |
|---|---|
| `phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/CountCorrectionGlPostingTest.php` | **OK (7 tests, 42 assertions)** — matches the evidence and the treasury register exactly |
| `phpunit -c phpunit-pgsql.xml tests/Feature/POS/PosReturnScrapWriteOffTest.php tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php` | **OK (27 tests, 119 assertions)** — the two POS/fiscal files the counter-account change touches |
| `phpunit tests/Feature/Inventory/ReplayFinalizeTest.php` (SQLite) | **OK (11 tests, 36 assertions)** |
| `phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/ReplayFinalizeTest.php` | **Tests: 11, Errors: 11** — `SQLSTATE[22001] … character varying(20)` reproduced verbatim; ticket mechanism CONFIRMED, ticket's "14 cases" refuted (F-2) |
| `phpunit OnboardingFirstCountTest + InventoryCountingDefaultBatchTest + CountTimestampSkewTest + LiveCountingScenarioTest` (SQLite) | **OK (18 tests, 110 assertions)** — the counting flow end-to-end under the new root transaction |
| 1 throwaway probe (entry-date source under a travelled application clock), PG, then deleted | see F-1 |
| `grep -c 'public function test_' ReplayFinalizeTest.php` | **11** (F-2) |
| `git diff --stat 48cebf0f2..HEAD -- apps/pos apps/web packages/shared` | **empty** |
| `git diff --stat 48cebf0f2..HEAD -- .../Inventory/Domain/Events .../POS/Domain/Events .../Modules/Fiscal` | **empty** |
| `git diff 48cebf0f2..HEAD -- app/ \| grep -c '^+.*onQueue'` | **0** |
| `git status --porcelain` after the review | empty |

## Bypasses I tried that FAILED (the code held)

1. **A mutated or re-authored device-signed fiscal fact.** The entire `Modules/Fiscal` tree and both `Domain/Events` directories are 0-line diffs; the POS projection's only change is the word "COGS" inside a docblock. Not constructible.
2. **A silently-dropped GL leg via a sync queue driver.** Defeated by `DB::afterCommit` at the dispatch site — the listener can never run nested inside the finalize transaction.
3. **A no-arg `getScale()` reachable from the queued listener.** None exists; the currency is non-nullable and sourced from a `NOT NULL` column; the cost basis is resolver-independent by construction. Confirmed empirically by a green PG run with no context bound.
4. **A double-posted count correction across a queue retry or a replay.** Four layers, the outermost a DB partial unique index and the innermost the target-based replay itself, which reaches `flat` before any account lookup.
5. **An orphaned POS-scrap write-off that no detector would ever surface.** D-a's reason set is `Cogs ∪ Shrinkage`, so `WriteOff` remains in scope after the family split — the fail-soft window of F-6 is reported, not silent.
6. **An event-contract break hidden in a test-only file.** The only Fiscal test touched re-codes a fixture account and seeds a new purpose; it changes no assertion about the event shape.
7. **A device-side timestamp or shift-merge regression.** `apps/pos` is a 0-line diff; there is no JS-supplied boundary anywhere in the branch.

---

**Gate disposition.** On the axes this lens owns, the branch is clean where it matters most: it authors no fiscal event, mutates no device-signed fact, and leaves the hash-chain writers, the `fiscal_events` surface and the entire device codebase at **0-line diffs** — the POS/Fiscal production footprint of the whole branch is seven comment hunks changing the words "Dr COGS" to "Dr Shrinkage", with `PosCoreReceiptProjection::apply()` and its idempotency anchor byte-identical to base. `InventoryCountingCompleted`, the event T21 consumes, is untouched in every field, so rule 8 holds without needing a `…V2`. The new queued listener honours the no-CompanyContext contract not by assertion but demonstrably — 7/7 green on PostgreSQL with the posting flag ON and nothing bound, which a reachable no-arg scale resolution could not have survived — and its cost basis is deliberately resolver-independent and company-scoped. Idempotency on the **replay** path is guarded at four independent layers ending at a DB partial unique index, and the target-based replay reaches `flat` before any account lookup, so a redelivery cannot post a second entry; I could not construct a double-post or a silent drop. The counting audit trail reconstructs end to end — entry → movement (`reference_type = inventory_counting`, `reference_id`) → counting → finalizer — with the per-item `replay_audit` recording the exact window and the on-hand it corrected from.

Six findings, none a merge blocker. One P2, and it is **only reachable once the flag is flipped**: the entry's `entry_date` is a wall-clock read that bypasses the application clock and is the single field in T21's own "one basis, on the row" contract that is derived from neither the event nor the row — measured at a full year's divergence under a travelled clock, which also means the closed-period failure treasury's finding 1 documents cannot be deterministically tested. It should be fixed in the same change that flips `INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED`, alongside treasury findings 1 and 2. The five P3s are a ticket whose case count is wrong while its mechanism is exactly right, a failed-tenant repair reason that no warning-level production log carries, an actor missing from the replay movement its own journal entry names, a missing `CompanyContext::clear()` in a test that is genuine today only by accident, and a POS-scrap fail-soft window that is real but both detected by D-a and gated by the deploy checklist. Nothing here re-authors a fiscal fact, drops a row silently, or breaks the chain.

VERDICT: ACCEPT
