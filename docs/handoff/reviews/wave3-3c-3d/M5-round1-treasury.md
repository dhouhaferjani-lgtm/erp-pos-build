## M5 whole-branch merge-gate review — round 1 — lens: treasury

**Diff reviewed:** M5 delta `d425434cd..1db4bafa9` (6 commits, 17 files) plus a whole-branch spot-verification of the treasury evidence over `48cebf0f2..HEAD`.
**Amending authority applied:** `ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md` (OQ-12/H-5 amended from a milestone blocker to a deploy-time flag-flip blocker; the three adopted commits fall under this review) and `TREASURY-RULING-2026-08-19-t20-option-a.md` (Option A `6586`/`7586` ratified, not relitigated; its rider is a go-live gate). `M4-round6.md` findings 1, 2 and 5 carried forward per its own handback.
**Working tree:** clean before and after. Beyond this register file I modified, staged and committed nothing. Four throwaway probe cases were written to a temp test file, run, and deleted; `git status --porcelain` was empty immediately after.
**Concurrent-register protocol:** honoured. Tip re-checked at `1db4bafa9` immediately before commit; no non-register commit appeared.

**Lens applicability.** *treasury* — applies (count-correction journal entries, purpose-resolved account map, fail-soft contract, exactly-once, the dormancy gate, the adopted template-overlay fallback). No payment/expense/cash-drawer surface exists in this delta; `payment_repositories` is untouched by `48cebf0f2..HEAD`.

---

## Carried-forward M4-round6 findings — status at the tip

- **M4-round6 #1 (REQUIRED shrinkage hard-aborts registration on a pre-policy third-plan template) — CLOSED by the adopted commits.** `InventoryVarianceAccountProvisioner.php:66-77` now warns and grafts through `fallbackTemplateParent()` instead of throwing; `:249-260` picks the lowest **same-type** root with `parent_id IS NULL`, else installs the account as its own root. `provisionTemplateCompany` is no longer able to abort company creation for either purpose.
- **M4-round6 #2 (missing-parent diagnostic names a code that may be present) — still open on the legacy/backfill arm.** `:126-133` still reports `parent_code` with no type qualification. Unchanged by M5; not re-raised.
- **M4-round6 #5 (seven BatchExpiry fixtures label the shrinkage account "Cost of Goods Sold" at `601`) — unchanged.** Not re-raised.

---

## Register

### 1 — P2 · CONFIRMED · with the flag live, a GL precondition failure rolls back the PHYSICAL stock correction of an already-finalized count, and the job then dies

`ApplyStockAdjustmentsOnCountingCompleted.php:106` (one root `DB::transaction` around the whole item loop), `:161-166` (`flushIfOutermost()` at its tail, **default `$contained = false`**), `InventoryGlPostingBuffer.php:92` (the propagating vs contained fork), `GeneralLedgerService.php:3429-3431` (`ClosedFiscalPeriodException` thrown inside `sealAndPersistEntry`)

The T21 root frame is the fiscal R3-3 ruling and it is correctly implemented for the failure mode it was designed against (an item-N *stock* failure). It also has a failure mode the evidence does not discuss: the GL leg now sits **inside** the frame that owns the stock write, in the **propagating** flush mode. Any hard GL failure — a closed fiscal period for the entry date is the concrete one, chain-seal or balance errors are the others — aborts the whole counting.

**Measured, not argued.** A throwaway probe against the M5 tip on PostgreSQL, flag ON, full Option A chart, one shortage item, with the current month's `FiscalPeriod` set to `Closed`:

```text
PROBE4 threw=App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException
       stock_after=10.0000  movements=0
```

Stock stays at its pre-count value, zero movements, and the item's `replay_audit` is never stamped. The listener is `ShouldQueue` with `$tries = 3` (`:38`) and the fault is deterministic, so all three attempts fail identically and the job lands in `failed_jobs` — leaving a **Finalized** counting whose physical correction was never applied, with no operator-facing signal on the counting itself. D-e will not surface it either: with no movement written there is nothing for the detector to key on.

This inverts the seam's own posture elsewhere. The POS path deliberately uses `contained: true` precisely so a GL failure cannot re-author the already-signed fiscal fact (`InventoryGlPostingBuffer.php:87-92`); a finalized inventory count is the same shape of fact — the count is the business event, the shrinkage/gain entry is the downstream accounting projection.

**Scope:** unreachable while `count_correction_gl_posting_enabled` is FALSE, which is the shipped default and itself an open deploy blocker. So this is **not a merge blocker** — it is a *pre-flag-flip* blocker, and it belongs on the same checklist line as the expert ratification. Free to close either by flushing `contained: true` and letting a failed batch leave the movement in place for D-e (the ticket's stated purpose), or by an explicit recorded decision that a closed period must abort a count.

### 2 — P2 · CONFIRMED · zero-delta count items write a costed movement with no entry, and become a permanent D-e false alarm the moment the flag flips

`StockAdjustmentService.php:1371-1388` (`recordMovement` is called unconditionally, including `adjustment = 0`), `InventoryGlPostingService.php:43-46` (`direction === 'flat'` → `null` — correct, an entry must not be posted), `CheckCogsCoverageCommand.php:237-265` (D-e)

The posting service is right to skip a flat row. The detector is not told about it. D-e selects on `reason IN nonCogsGlReasons` (`CountCorrection` qualifies: `MovementReason.php:83` `DirectionalVariance`, `:108` `requiresGLEntry() === true`) with `whereNotExists(entry)`, excludes only `reference_type = stock_adjustment`, and — unlike D-a (`:202`) and D-b (`:221`) — carries **no `is_historical = false` filter and no grace window**. M5 ties the `inventory_counting` exclusion to the flag (`:256-261`), so at flip time every zero-delta count movement ever written becomes a finding, on every run, forever.

**Measured:**

```text
PROBE2 zero-delta movements=1 entries=0
  mov qty=0.0000 before=10.0000 after=10.0000 unit_cost=4.250000 ref_type=inventory_counting is_hist=false
```

That is byte-for-byte the shape the wave's own test asserts D-e reports (`CheckCogsCoverageCommandTest.php:351-386` — a `CountCorrection` movement with `referenceType: 'inventory_counting'` and no entry, exit code 1 with the flag live). A count where the counted quantity matches expectation is the *normal* outcome, so this is the majority population, not an edge. Historical count corrections (`is_historical = true`, which `postForCountCorrection:38` also declines) are a second, smaller false-alarm class.

The evidence's own justification for keeping the exclusion — "reporting them would make every completed count a permanent false alarm" (`M5-evidence.md` §1.7) — is exactly right, and is only solved for the flag-OFF state. Free to close by excluding flat rows (`quantity_before = quantity_after`) and `is_historical` from D-e, in the same change that flips the flag.

### 3 — P3 · CONFIRMED · the dormancy gate lives at the enqueue site only; the posting service itself posts count corrections regardless of the flag

`ApplyStockAdjustmentsOnCountingCompleted.php:364` (the only production check), `InventoryGlPostingBuffer.php:79` and `InventoryGlPostingService.php:36` (no gate), `InventoryGlPostingSeamTest.php:161-202` (posts two count-correction entries with the flag at its shipped FALSE, and passes)

I verified the contract the dispatch asks for and it **holds today**: `MovementGlKind::CountCorrection` has exactly one production enqueue (`:371`), reached only from `enqueueCountCorrectionGl()`, which returns early when the flag is off; there is no console, replay or projection writer for this kind. The dormancy pin `CountCorrectionGlPostingTest.php:478-497` is real and non-vacuous.

What is missing is depth: the seam test demonstrates that a caller which enqueues directly posts a live journal entry with the shipped default in force. Nothing structural stops a future writer from doing the same. For a gate whose entire authority is an unrecorded expert-comptable ratification, the check belongs at `postForCountCorrection()` as well.

### 4 — P3 · CONFIRMED · the partial-chart count-correction fail-soft is correct in code but untested

`InventoryGlPostingService.php:49-61`

The dispatch asks for **both** purposes' absence paths and for proof that a partially available chart cannot produce an unbalanced entry. Verified — by code and by probe.

*By code:* the guard (`hasAccountForPurpose` → `Account::findByPurpose`, `GeneralLedgerService.php:5012-5015`) and the resolver (`getAccountByPurpose` → `Account::findByPurposeOrFail`, `:4998-5001`, `Account.php:252-266`) share the **same** predicate, and `createInventoryMovementEntry` resolves **both** accounts at `:4591-4592`, *before* opening its `DB::transaction` at `:4594`. A half-resolved chart therefore cannot produce a half-written entry; and `sealAndPersistEntry:3399-3409` refuses any entry where `Σdebit ≠ Σcredit`.

*By probe* (flag ON, Inventory + shrinkage present, **gain absent**, one overage item and one shortage item in one counting):

```text
PROBE3 entries=1
  entry dr=17.000 cr=17.000 lines=2
  stockA=13.0000 stockB=6.0000     # both stock corrections applied
```

The gain-direction correction fail-softs to a warning and `null`; the shrinkage-direction one posts one balanced entry; neither stock correction is harmed.

The gap is coverage, not behaviour. `CountCorrectionGlPostingTest.php:456-469` seeds **no** accounts at all, so it trips the `Inventory` half of the `||` first and never exercises either counter-purpose arm; `InventoryGlPostingSeamTest.php:607` and `:628` are exit-path cases. Neither absence arm of `postForCountCorrection` has a covering test.

### 5 — P3 · CONFIRMED · the operator-facing deploy checklist never names the flag, and its one sentence about the gate is now stale

`docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md:62-63`

> "Expert-comptable ratification under OQ-12/H-5 is still required before **M5 makes** count-correction posting live."

M5 has now shipped. An operator reading the checklist at deploy time has no way to learn that "live" is `INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED` — the config key appears nowhere in the file (grep: zero hits for `count_correction`, `T21` or `flag`). The obligation is stated correctly in `wave3-3c-3d.progress.yaml:184` and in the ruling, but the artifact an operator actually follows is the checklist. The YAML blocker text itself is correct and does bind the flag flip, as the dispatch requires.

### 6 — P3 · CONFIRMED · passing an explicit currency silently changes which source decides the scale

`CurrencyScaleResolver.php:36-40` vs `:55-69`

An explicit code short-circuits to the static ISO 4217 map; the no-arg request-context path prefers `countries.currency_decimal_places`. Every `InventoryGlPostingService` call site passes explicit currency (correctly — house rules 19/20 require it on the queued path), so a tenant whose country row disagrees with the ISO map would post count corrections at a different scale than an interactive document writer. **Inherited, seam-wide, not introduced by M5**; recorded because M5 adds a new consumer of the explicit-currency path. `getScaleSafe` is not the answer here — the divergence is in the explicit branch itself.

### 7 — P3 · CONFIRMED · the count-correction entry does not name its justifying counting document in any human-readable field

`InventoryGlPostingService.php:82` (`'Inventory count correction; occurred …'`)

Document-per-action linkage **is** machine-resolvable and I verified it end to end: `journal_entries.source_id` = the movement id, and the movement carries `reference_type = inventory_counting` / `reference_id = <counting id>` (confirmed on the probe row above and by `CountCorrectionGlPostingTest.php:355-359`). But the entry's own description and `source_type` never mention the counting number, so an expert-comptable reading the liasse — the audience this whole account map exists for — must traverse two hops to answer "which count?". Consistent with the rest of the inventory seam; recorded, not a defect of M5 alone.

### 8 — P3 · CONFIRMED · one test assertion compares money with PHP's loose numeric operator

`CountCorrectionGlPostingTest.php:574` — `$entry->lines[0]->debit > 0`

Selects which leg to compare by coercing a money string through PHP's numeric comparison. Test-only, and the surrounding assertions are `bccomp`-based, so no result is at risk. Worth `bccomp((string) $entry->lines[0]->debit, '0', 3) > 0` for consistency with rule 19's posture.

---

## Standing checks

- **Rule 19 (money precision).** Clean. `git diff d425434cd..1db4bafa9 -- apps/api/app` yields **zero** added lines containing `(float)`, `floatval`, `number_format`, `round(`, `app(` or a bare `getScale()` — the single grep hit is a docblock at `ApplyStockAdjustmentsOnCountingCompleted.php:350` explaining why the no-arg form is forbidden here. Currency is resolved once per job from the entity (`:392-401`, `companies.currency` is `character(3) NOT NULL`), carried on `MovementGlContext::$currencyCode` (non-nullable, `:25`), and every scale resolution on this path is explicit: `InventoryGlPostingService.php:64,109,172,214`, `GeneralLedgerService.php:4560,3395`. The amount is `bcmul` at `scale + 6` rounded once by `CurrencyScale::bcround` (`:216-219`); the row cost is `bcadd(…, COST_SCALE=6)` (`StockAdjustmentService.php:1804-1805`). The only literal written is `'balance' => '0.000'` into `accounts.balance` (`InventoryVarianceAccountProvisioner.php:148`) — a zero, no precision path.
- **Country accounting is seeded settings, never hardcoded.** Every runtime resolution goes through `SystemAccountPurpose::InventoryShrinkageExpense` / `InventoryGainIncome` (`InventoryGlPostingService.php:49-51`). The literals `6586`/`7586` appear **only** in the provisioning/seeding layer — `InventoryVarianceAccountProvisioner.php:164,171` and `InventoryVarianceCoaTemplateV2Importer.php:171,180` — plus comments. No posting path names a code.
- **T20 Option A map, as merged, at the tip.** `InventoryVarianceCoaTemplateV2Importer.php:65-95`: `coa.tn.default-v2` and `coa.fr.default-v2` place `6586` (expense) under `65` and `7586` (revenue) under `75`; `coa.generic.default-v2` uses `6000`/`7000`. `InventoryVarianceAccountProvisioner::definitions:158-178` agrees for the non-template path. Exactly the ruled Option A.
- **M4-accepted counter-family routing, at the tip.** `MovementReason::glCounterFamily()` (`:73-94`) is 17 explicit cases with **no `default` arm**; `CountCorrection` is the sole `DirectionalVariance`; `Damage`/`Expiry`/`WriteOff` remain `Shrinkage`. `MovementReasonClassificationTest` green (re-run below).
- **Exactly-once / idempotency — three independent layers, all verified.** (1) DB: a partial unique index `uniq_je_source_inventory_movement` on `(source_type, source_id) WHERE source_type IN ('inventory_exit','inventory_entry','inventory_shrinkage','batch_write_off','batch_write_off_reversal')` — confirmed by `\d journal_entries` on the migrated review database. This is the answer to "`(source_type, source_id)` is not globally unique": for *this* family it is, at the database. (2) Code: the pre-transaction probe at `GeneralLedgerService.php:4577-4589` and the in-transaction re-probe at `:4606-4613`. (3) Listener: the `replay_audit` marker (`:346-357`) and the legacy `COUNTING:{number}` + (product, location, variant) movement probe (`:195-213`). `CountCorrectionGlPostingTest.php:431-448` pins one movement / one entry after a re-fire. `source_id` is the stock-movement UUID, so no cross-source collision is constructible.
- **Balanced in every branch.** Verified by probe on a mixed gain+loss batch flushed together (below), by the seam's two-direction case (`InventoryGlPostingSeamTest.php:161-202`), and structurally: `createInventoryMovementEntry:4628-4645` writes exactly two lines with the same `$amount` on opposite sides, and `sealAndPersistEntry:3399-3409` refuses to post an unbalanced entry.
- **Constructor injection.** `private readonly` throughout: `ApplyStockAdjustmentsOnCountingCompleted:51-57` (now `+ InventoryGlPostingBuffer`), `InventoryVarianceAccountProvisioner:22-25`. No `app()` in the production delta. The buffer is bound `scoped` (`InventoryServiceProvider.php:40-45`), so it is torn down between queue jobs — no cross-job context bleed.
- **Module boundaries (rule 6).** The Domain→Application inversion was correctly avoided: `StockAdjustmentService` takes a `\Closure` (`:1269,1367`) and the Application-layer listener owns `MovementGlContext` construction. The listener adds `use App\Modules\Company\Domain\Company` — a cross-module Eloquent import — but the same file already imports `Company\Domain\Location`, and deptrac does not flag it (see below).
- **Deptrac.** I re-ran it at the tip: **174 violations, 0 errors** — byte-identical to M4's recorded 174 at both its base and tip. M5 adds **zero** net violations, which is stronger than the evidence's narrower "zero new `ModuleDomain -> ModuleApplication` edges" claim. The ratchet remains inherited-FAIL and parent-owned.
- **Migrations / named queues / i18n.** No migration, no `onQueue`, no user-facing string in the delta. `config/inventory.php` is a new config file with one boolean; it needs no `.env.example` entry to ship FALSE, and does not have one.
- **Frozen seeders / `.github/workflows/**`.** `git diff --name-only d425434cd..1db4bafa9` intersects neither. F-1 and F-8 respected.
- **S-16.** Untouched, correctly recorded as parent-owned in `wave3-3c-3d.progress.yaml:185`. No local probe offered; none accepted.
- **Test quality.** No `assertTrue(true)`. `RefreshDatabase` + real models throughout. No API payload is faked. `CountCorrectionGlPostingTest` skips **loudly** off PostgreSQL (`:83-88`) rather than passing vacuously, drives the replay case through `postCountCorrection` by asserting `movement_type`/`reason`/`reference_type` first (`:353-359`), recomputes the posted amount from the persisted row rather than a literal (`:568-578`), and pins the listener's own item load order before the item-3 rollback pin (`:525-529`). Its `tearDown` (`:168-179`) correctly cleans the rows it deliberately commits, which is what stopped it polluting `accounting:check-cogs-coverage`.

## Verification I ran myself

Isolated PostgreSQL database `autoerp_w3d_m5r1_tr` (created for this review; the shared `autoerp_test` was left alone for the concurrent inventory-costing reviewer). Never the full suite.

| Command | Result |
|---|---|
| `phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/CountCorrectionGlPostingTest.php` | **OK (7 tests, 42 assertions)** — matches the evidence exactly |
| `phpunit -c phpunit-pgsql.xml InventoryGlPostingSeamTest + CheckCogsCoverageCommandTest + StockAdjustByDeltaTest` | **OK (74 tests, 226 assertions)** |
| `phpunit -c phpunit-pgsql.xml ProvisioningFlagMatrixTest + ChartOfAccountsParityTest + MovementReasonClassificationTest` | **OK (43 tests, 147 assertions)** |
| 4 throwaway probes (mixed batch / zero-delta / partial chart / closed period), PG, then deleted | see findings 1, 2, 4 |
| `deptrac analyse` at the tip | **Violations 174, Errors 0** — identical to M4's 174 |
| `pint --test` on all 11 changed PHP paths | `{"result":"pass"}` |
| `phpstan analyse` (level 8) on the 4 changed app files + `InventoryGlPostingService` | `[OK] No errors` |
| `psql \d journal_entries` on the migrated review DB | `uniq_je_source_inventory_movement` confirmed to cover `inventory_shrinkage` |
| `git status --porcelain` after the review | empty |

## Bypasses I tried that FAILED (the code held)

1. **An unbalanced or half-written count-correction entry from a partially provisioned chart** — the sharpest available P1. Both accounts resolve before the entry transaction opens (`:4591-4594`), the guard and the resolver share one predicate, and the seal refuses `Σdr ≠ Σcr`. Probe 3 confirms: gain absent → that direction fail-softs, the other direction still posts one balanced entry, both stock corrections stand.
2. **Cross-contamination in a mixed gain+loss batch flushed in one root frame.** Probe 1: two entries, `dr=17.000 cr=17.000` and `dr=6.000 cr=6.000`, each with the correct purpose-resolved pair (shrinkage/inventory and inventory/gain), both `status=posted`.
3. **A double-post from a re-delivered event or a queue retry.** Blocked at three layers, the outermost being a DB partial unique index. Even a stale-item-collection concurrent redelivery cannot double-apply: the replay path is target-based, so a second pass computes `adjustment = 0` and `directionForRow()` returns `flat` before any account lookup.
4. **A hardcoded `6586`/`7586` reaching a posting decision.** Every runtime resolution is by `SystemAccountPurpose`; the literals are confined to the provisioner and the V2 importer.
5. **A cross-family graft through the newly adopted `fallbackTemplateParent`** — shrinkage under a revenue root or gain under an expense root. `:251-256` filters `type = $definition['type']` **and** `parent_id IS NULL`, and the helper is only reachable for the REQUIRED shrinkage because the SOFT gain returns at `:53-64`. Neither direction is constructible.
6. **A bare no-arg `getScale()` reachable from the queued listener.** None exists in the delta; the context currency is non-nullable and sourced from a `NOT NULL` column.
7. **A second production writer of `MovementGlKind::CountCorrection` that bypasses the flag.** Only one enqueue site exists — which is what keeps finding 3 at P3 rather than P2.
8. **A new deptrac edge introduced by the `Company` import.** 174 at the tip, identical to M4's 174.

---

**Gate disposition.** T21 lands the count-correction GL leg correctly on every axis the treasury lens owns: entries are balanced in every branch I could construct — gain, loss, and a mixed batch flushed together — with a zero-delta row skipped rather than posted at zero; both accounts are resolved through `SystemAccountPurpose`, never by code, so the seeded per-country map stays the source of truth and the merged Option A map (`6586` under `65`/`6000`, `7586` under `75`/`7000`) is intact at the tip; the fail-soft contract degrades to warning-and-`null` on **either** purpose's absence and cannot emit a half-posted or unbalanced entry, because both accounts resolve before the entry transaction opens and the seal refuses an imbalance; exactly-once is enforced by a partial unique index at the database, not merely by a `SELECT`; the queued path carries explicit currency everywhere with no float anywhere; the adopted `fallbackTemplateParent` is family-safe in both directions; and the flag ships FALSE with the YAML blocker correctly rewritten to bind the **flag flip**, exactly as the amending ruling requires. The M4-accepted 17-case counter-family partition and the destructive-loss routing still hold, and deptrac is unmoved at 174.

Eight findings remain, none of them a merge blocker: two P2s that are **only reachable once the flag is flipped** — a GL precondition failure taking the physical stock correction down with it, and a permanent D-e false-alarm class for the majority zero-delta count population — and six P3s covering gate depth, missing coverage of the two fail-soft arms, an operator checklist that never names the flag, an inherited scale-source divergence, entry-description legibility, and one loose money comparison in a test. Findings 1 and 2 must be resolved **in the same change that flips `INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED`**, and the parent should carry them on the LEDGER next to the expert-comptable ratification rather than as merge conditions; the code that ships today is dormant and safe.

VERDICT: ACCEPT
