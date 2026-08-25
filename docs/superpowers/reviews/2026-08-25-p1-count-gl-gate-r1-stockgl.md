# P-1 gate r1 — stock↔GL lens (VERIFY-ONLY)

Lane `fix/campaign-p1-count-correction-gl-default` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p1-count-gl-default` · HEAD **`a06d2c766`** (unmodified — `git status --porcelain` = 0 lines at gate end, HEAD unchanged).
Diff reviewed: `git diff dev...HEAD` (24 files, +1279/−70). MIGRATION-BEARING.

## VERDICT

**spec ✅ · quality APPROVED (with conditions)**
**merge-blocking: no**
**Manifest value: `gated_ceiling` 1177 · Inventory 116 · POS 155** (dev `b79499c5e` carries 1176 / 115 / 155; union = exactly the lane's values, no re-arithmetic owed — but see F-6).

Nothing in this lane moves stock without value, books a GL consequence twice, adds a second writer to a stock or GL projection, corrupts a WAC or batch invariant, edits an append-only ledger, or puts a float on the seam. The four findings are one monitoring-blast-radius gap, one documentation-accuracy gap on an inherited residual (verified reachable by execution), one FE functional gap against the lane's own stated design, and two minors. **Conditions before promotion: F-1 and F-2 recorded on the LEDGER with the census query in F-1.**

---

## 0. What was verified BY EXECUTION

Throwaway PG 16 (`127.0.0.1:5433`, `autoerp`/`autoerp_secret`, `PGTZ=UTC`): `autoerp_test_p1g` and `autoerp_test_p1g_mig` — **both created and dropped**. All tampering and all probes ran in a throwaway `git worktree add --detach a06d2c766` with a hard-linked `vendor` (`cp -al`) — worktree removed. **The lane was never modified.** One test process at a time; the full suite was never run.

| # | Lane | Command | Result |
|---|---|---|---|
| 1 | sqlite | `CountCorrectionGlPostingDefaultTest` + `CheckCogsCoverageCommandTest` | **40 passed** (101 assertions) |
| 2 | PG | the same two + `CountCorrectionGlPostingTest` (`[PG]` commit-class last) | **50 passed** (161) |
| 3 | PG | `php artisan migrate` **from scratch** on an empty database (`autoerp_test_p1g_mig`) | full chain DONE, incl. `2026_08_25_140000_seed_count_correction_gl_posting_default` (17.97 ms) |
| 4 | PG | `information_schema` + `\d country_inventory_settings` after (3) | `country_inventory_settings.count_correction_gl_posting_enabled` = **boolean NOT NULL DEFAULT true**; `companies.count_correction_gl_posting_enabled` = **boolean NULL, no default** ✅ |
| 5 | PG | seeder run, then drift TN to `false`, then `up()` **twice** | after seed `{TN:true,FR:true}`; after up#1 and up#2 both `true`; `companies` rows with a non-NULL override: **0** ✅ idempotent, never writes the company column |
| 6 | PG | 3 gate probes (§2), then discarded | 3 passed |
| 7 | api | PHPStan level 8, live-DB env, **all 11** changed/new backend files incl. the migration | **No errors** |
| 8 | api | `php tools/deptrac-ratchet.php` | **PASS — 183/183**, EXIT=0 |
| 9 | api | `php tools/feature-lane-manifest-check.php` | **OK, EXIT=0** — 1428 Feature classes, gated **1177** |
| 10 | web | `vitest run src/features/settings/components/InventorySettings.test.tsx` | **11 passed** |
| 11 | web | `tsc --noEmit` | **exit 0** |
| 12 | web | `eslint` on the 3 touched FE files | **0 errors**, 12 pre-existing warnings (`set-state-in-effect` `:199`, `prefer-nullish-coalescing` `:263`) |
| 13 | web | `audit-i18n-completeness.mjs` with the owner-pinned `I18N_BASELINE_PROTECTED_BLOB=26a9ae16…` (mirror verified against `docs/handoff/progress/enforcement-p2.progress.yaml:73`) | **OK** — 55 namespaces, 1 baseline entry burned down, zero added |

### Red-proof (three tampers, each reverted; lane untouched)

| Tamper | Expected | Observed |
|---|---|---|
| **T1** — `config/inventory.php:34` env default `true` → `false` | red | **1 failed**: `CountCorrectionGlPostingDefaultTest::test_a_company_with_no_country_row_falls_back_to_the_system_default` ("Failed asserting that false is true"). The country-row cases stayed green — correct layering: only the SYSTEM link moved. |
| **T2** — make the migration `UPDATE companies … WHERE count_correction_gl_posting_enabled IS NULL` | red | **1 failed**: `test_the_migration_never_stamps_an_override_on_an_untouched_company` ("Failed asserting that 1 is null") |
| **T3** — `CountCorrectionGlPostingResolver.php:52` short-circuit the company override | red on BOTH sides | **3 failed** on PG: GL side `CountCorrectionGlPostingTest::test_a_company_that_explicitly_disables_posting_corrects_stock_and_posts_nothing` (an entry appeared where none may), detector side `CheckCogsCoverageCommandTest::test_de_reports_count_corrections_once_their_posting_flag_is_live` **and** `…::test_de_fires_for_non_cogs_gl_movements_and_excludes_stock_adjustments` (D-e false-alarmed) |

---

## 1. Brief item-by-item

### (1) Resolution chain — ✅

`CountCorrectionGlPostingResolver.php:45-70` reads `companies.count_correction_gl_posting_enabled` (`is_bool` → SOURCE_COMPANY), then `country_inventory_settings` by **raw query builder** (`:97-113`, `strtoupper`, `(bool)` cast for the SQLite 0/1 vs PG bool split), then `config('inventory.count_correction_gl_posting_enabled', true)` (`:83`). Verified: fresh provisioning ⇒ `true`/`country`; company `false` ⇒ `false`/`company`; company `null` ⇒ re-inherits `true`/`country`; no country row ⇒ `true`/`system` (execution #1/#2).

**Rule 20 clean.** The company id is passed **explicitly** at every call site — the queued listener at `ApplyStockAdjustmentsOnCountingCompleted.php:415` (`(string) $movement->company_id`) and the D-e detector at `CheckCogsCoverageCommand.php:321` (`$company->id`, inside a scan already scoped `->where('company_id', $company->id)` at `:240`). No `CompanyContext`, no relation loading, and `Company` carries **no global scope** (`Company.php:133-136` — `use HasFactory;` only), so `Company::query()->findOrFail()` is worker-safe. The per-job memo (`:63`, reset at `:76`, read at `:446-449`) is keyed by company id and cleared at the top of every `handle()`, so a retried job cannot serve a stale answer.

### (2) Migration — ✅

`database/migrations/tenant/2026_08_25_140000_seed_count_correction_gl_posting_default.php`. Additive DDL, `hasTable`/`hasColumn`-guarded at every step (`:59-72`, `:74-77`), one `whereIn`-scoped UPDATE (`:688-694` of the diff / `:88-95` of the file) restricted to countries the pinned map says ON, and it **never names `companies` in a write**. Idempotency and the never-writes-companies guarantee proven by execution #5 and red-proved by T2. Census docblock present (`:44-49` of the file). PG-proven from scratch (execution #3/#4).

### (3) End-to-end at the shipped default — ✅

Default tenant, no flag flip, no override: stock `10.0000 → 6.0000`, movement `unit_cost 4.250000`, entry **Dr 6586 `17.000` / Cr 37 `17.000`** — balanced, at the row's own WAC × |Δ| (`CountCorrectionGlPostingTest::test_the_shipped_default_posts_dr_shrinkage_cr_inventory_with_no_flag_flip`, green on PG in execution #2). Company `false`: stock still corrected, movement still costed, **0 entries**, and D-e stays silent for it (`CheckCogsCoverageCommandTest:526-540`). Settings surface: `PATCH /api/v1/settings/company` is `->middleware('can:settings.update')` (`app/Modules/Tenant/routes.php:26-28`) **plus** `UpdateCompanySettingsRequest::authorize()`; the rule is `['sometimes','nullable','boolean']` (`UpdateCompanySettingsRequest.php:70`); `null` reaches the write through `array_key_exists` (`CompanySettingsController.php:191-199`) and clears the override. i18n authored in en/fr/ar with the `settings` sub-tree deep-merge (`apps/web/src/lib/i18n.ts:326-329`) — audit green (execution #13).

### (4) R-8 status — **PARTLY STALE, and the live half is bigger than the lane says.** See F-2.

### (5) Supersession note / manifest / deptrac / red-proof — ✅

The OQ-12 ruling doc is **appended, not rewritten**: `git diff` shows `@@ -1 +1,46 @@` with the original 2026-08-19 line intact as context and 45 lines added below a `---` (`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md`). Manifest, deptrac, red-proof: executions #8/#9 and the tamper table.

---

## 2. My own probes (3, written for this gate, run on PG, then discarded)

| # | Probe | Result |
|---|---|---|
| **A** | Sale at **exactly** the count instant `T`, counter counted the **post**-sale figure (56), `final_qty_movement_marker` = that movement's id | on-hand stays **56**, **0** corrections, **0** entries — W4-6 gate r2 NEW-1's order-based tie-break **works** ✅ |
| **A2** | The **same shape with a NULL marker** (a line submitted before the marker columns shipped) | on-hand **56 → 52**, **1** correction, **1** shrinkage entry — the double-subtract horn survives, and now books a wrong JE. **See F-2** |
| **B** | Receipt booked **1 s before** `T` but physically shelved after it; counter counted 56 | on-hand **60 → 56**, **1** correction, **1** entry, **debit `17.000`** — R-8's pre-count horn, wrong-JE consequence **confirmed LIVE**, exactly as the lane's supersession note and handback R-2 state ✅ |

---

## 3. Findings

### F-1 [Important] The flip has no watermark: every count correction applied BEFORE it becomes a permanent D-e finding
`apps/api/app/Modules/Accounting/Presentation/Console/CheckCogsCoverageCommand.php:240-241` and `:319-325` · `apps/api/tests/Feature/Accounting/CheckCogsCoverageCommandTest.php:526-540`

D-e's time filter is `->where('created_at', '>=', $cutoverAt)` where `$cutoverAt` is `companies.inventory_gl_cutover_at` — the **COGS-at-exit** watermark, set at company creation. It does not move when count-correction posting flips. The exclusion at `:319-325` is now resolved per company, so the moment posting is ON every non-historical, non-flat count-correction movement above the cutover with no movement-keyed entry is reported.

That is correct for movements written *after* the flip and **wrong for every movement written before it**: those were movement-only *by the then-current design*, and the comment's own words ("count corrections that SHOULD have posted and did not") do not describe them. The lane's own test proves the shape — `:526-540` takes a pre-existing unposted count movement, turns posting on, and asserts `exitCode 1`.

**Why it matters on the seam:** D-e is the *compensating control* for F-5's fail-soft (a company whose 6586/37 accounts are unmapped gets its stock corrected with **zero** GL and only a `Log::warning` — `InventoryGlPostingService.php:53-61`, pinned by `CountCorrectionGlPostingTest::test_counting_on_a_company_with_no_shrinkage_account_still_applies_the_correction`). A detector that exits 1 permanently on historical noise is a detector nobody reads, and the genuine stock-moved-without-value findings drown in it.

*Other side of the seam:* the stock side is untouched — those movements exist, are costed and are correct. Only the detector's interpretation of them changes.

**Reachability:** zero for a freshly provisioned first tenant (no pre-flip counts). Non-zero for any tenant that finalized a **varying** count between T21 landing (3D/M5) and this flip — dev/staging/demo databases at minimum. The migration docblock censuses country rows and company overrides but **not** this.

**Fix (pick one, before promotion):** (a) add the census below to the migration docblock and record the expected D-e finding count as a knowingly-accepted ops residual; (b) stamp a per-company `count_correction_gl_posting_enabled_at` at flip time and add `->where('created_at', '>=', $flippedAt)` to the counting arm of D-e; (c) advance `inventory_gl_cutover_at` — **not** recommended, it would blind the other arms too.

```sql
-- movements that become D-e findings the moment posting goes on, per company
SELECT sm.company_id, count(*)
FROM stock_movements sm
JOIN companies c ON c.id = sm.company_id
WHERE sm.reason = 'count_correction'
  AND sm.is_historical = false
  AND sm.quantity_before <> sm.quantity_after
  AND sm.created_at >= c.inventory_gl_cutover_at
  AND NOT EXISTS (
    SELECT 1 FROM journal_entries je
    WHERE je.source_id = sm.id AND je.source_type = 'inventory_shrinkage')
GROUP BY sm.company_id;
```

### F-2 [Important] The same-second horn is NOT fully closed — it survives for marker-NULL lines, and this lane gives it a journal entry
`apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php:105-114` (the `if ($marker !== null)` branch) and its docblock `:83-86` · `docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md` (supersession note, last paragraph) · `docs/superpowers/reviews/2026-08-25-p1-handback.md` R-2

The brief asked whether R-8's "wrong JE" claim is stale after W4-6's order-based marker. Answer, by execution: **the pre-count horn is live (probe B, confirmed exactly as documented), and the same-second horn is live too whenever the marker is NULL** (probe A2: on-hand 56 → **52**, one count-correction movement, **one shrinkage entry**). `MovementReplayService.php:83-86` says so itself — *"With `$marker` null (a legacy row, or a line counted before the marker columns shipped) the boundary second stays inclusive — the r1 semantics, unchanged."*

W4-6's handback R-8 asserts *"The same-second horn the gate found (P2b) is closed by NEW-1 and is no longer part of this residual"*, and this lane's supersession note inherits that framing verbatim ("**Carried forward unchanged:** W4-6 residual R-8 — the pre-count half…"). Both are true only for a marker-bearing line. The marker is written on the submit path (`InventoryCountingService.php:1110`, `:1221` via `FinalQuantityAsOfResolver::resolveMarker`), so the exposure is bounded to counts whose lines were submitted before `2026_08_25_120000_add_count_movement_markers_to_counting_items` ran on that tenant — i.e. **in-flight counts across the upgrade**, which is exactly the window this promotion opens.

*Other side of the seam:* I confirmed the GL leg is faithful in both probes — balanced, correctly signed, at the row's own cost. The defect is entirely upstream of the posting; the flip only gives it a ledger consequence.

**Fix:** correct the supersession note and handback R-2 to name **both** live horns and the null-marker condition; and consider making a NULL marker on a `basket_window` line a *blocking* flag rather than a silent fall-through to r1 semantics, so the ambiguity is reviewed instead of posted.

### F-3 [Important] The FE can never return a company to "inherit" — the nullable design is unreachable from the product
`apps/web/src/features/settings/components/InventorySettings.tsx:234-241` (the mutation sends `{ count_correction_gl_posting_enabled: enabled }`, always a boolean) · `:344-357` (the checkbox) · `:382-388` (the "Inherited" hint)

The whole justification for the nullable, undefaulted company column — stated three times, in the migration docblock, in `CountCorrectionGlPostingResolver.php:30-34` and in `UpdateCompanySettingsRequest.php:64-69` — is that an untouched tenant **re-inherits any future country ruling instead of being frozen at today's answer**. The backend honours it (`null` clears; proven by `CountCorrectionGlPostingDefaultTest:277-284`). The FE never sends `null`. So the first time an operator touches the checkbox — even to set the value it already had — that company is pinned out of the inheritance permanently, the `source` flips to `company`, the "Inherited" hint at `:382` disappears, and **no control exists to restore it**.

*Other side of the seam:* no GL consequence today (the resolved answer is the same either way); the cost is paid at the next country ruling, when the tenants who once clicked the box silently do not follow it.

**Fix:** add a "Use my country's default" reset that PATCHes `null` (render it when `count_correction_gl_posting_override !== null`), or make the control a tri-state.

### F-4 [Minor] The tenant-scoped settings view hardcodes `true` instead of asking the resolver
`apps/api/app/Modules/Tenant/Application/DTOs/CompanySettingsData.php:98`

`'count_correction_gl_posting_enabled' => true,` is a literal, while the sibling line `:96` uses the real authority (`InventoryValuationModeResolver::SYSTEM_DEFAULT->value`). Consequence: with the documented deployment-wide kill switch `INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED=false` set (handback R-3), the tenant-level payload still reports `enabled: true, source: system` — the one surface where the kill switch is invisible. `CountCorrectionGlPostingResolver::systemDefault()` (`:83`) is public and exists for exactly this.

### F-5 [Minor] `postForCountCorrection` fail-softs on a missing chart but THROWS on a non-perpetual mode — and this lane makes both live fleet-wide
`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:48` vs `:53-61` · `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:108-165`

Missing accounts → log + `return null` (stock corrected, no entry). Non-perpetual valuation → `requirePerpetual()` **throws**, inside the listener's single root transaction, whose flush is at `:164` — so the whole count, every item, rolls back and the job burns its three tries. Not reachable today (`InventoryValuationMode::supportedValues()` yields only `perpetual`, so the settings write is a 422, and both seeded countries are perpetual). But `CountryInventoryDefaults::COUNT_CORRECTION_GL_POSTING` (`:364-367`) is **deliberately independent** of the valuation map ("a jurisdiction that wants the latter must be expressible as an edit HERE and nowhere else"), so posting-ON + periodic is an expressible combination the day periodic ships, and it hard-fails count finalize rather than degrading. Worth a line in `CountryInventoryDefaults`' docblock naming the coupling the two maps still have.

### F-6 [Minor — merge-order note] `gated_ceiling 1177` is claimed by two in-flight lanes
`apps/api/tests/feature-lane-manifest.json:9`

dev moved during this gate (`925306c97 → b79499c5e`, three doc-only commits; manifest unchanged at **1176 / 115 / 155**), so this lane's 1177 / 116 / 155 is the correct union **right now**. But dev commit `a7776dda9` records the Session A **W2-1** gate as also landing on "manifest union 1177". Whichever of the two merges second must re-derive to **1178**, not re-claim 1177 — the exact silent failure mode the lane's own group `note` warns about.

---

## 4. Seam checks that came back clean (stated, not assumed)

* **Document-per-action.** Both counting arms stamp `reference_type = inventory_counting` / `reference_id = $counting->id` on the movement (`ApplyStockAdjustmentsOnCountingCompleted.php:257-258` legacy, `:354-355` replay); the counting row exists by construction. The GL context copies both through (`:430-431`), so the entry is keyed to the same document.
* **GL consequence exactly once.** Movement and entry commit in ONE root transaction (`:108-165`, flush at `:164`) — a posting failure unwinds the stock correction with it, and vice versa. Re-entry paths: replay arm keyed on `replay_audit` (`:147-154`), legacy arm on the existing `COUNTING:{number}` movement discriminated by (product, location, variant) (`:212-230`), plus the `2026_08_23_120000_unique_stock_movements_counting_apply` index. Pinned by `test_queue_retry_after_the_marker_posts_no_second_entry` (green on PG, execution #2).
* **COGS at stock-exit.** Untouched. Count corrections book 6586/7586 against 37, never a COGS account, and only from the movement (`InventoryGlPostingService.php:49-51`).
* **Single writer.** `MovementGlKind::CountCorrection` is enqueued from exactly **one** site (`ApplyStockAdjustmentsOnCountingCompleted.php:422`) and consumed at exactly **one** (`InventoryGlPostingBuffer.php:79`). Grepped `--include='*.php'` across `apps/api/app`. No second writer, and none made reachable.
* **WAC integrity.** The lane authors no cost. The posting basis is the movement row's own persisted `unit_cost` (`:429`), verified `4.250000` and the entry `17.000 = 4.250000 × |6 − 10|` at the TND scale of 3.
* **Batch/lot invariant.** Untouched by this lane (W4-6 owns the FEFO drawdown).
* **Append-only.** No `UPDATE`/`save()` on an existing `stock_movements` or posted `journal_entries` row anywhere in the diff. The only UPDATEs added are on `country_inventory_settings` (migration + seeder) and `companies` (the settings PATCH).
* **Float (rule 19).** `git diff dev...HEAD` added lines, grepped for `(float)`/`floatval`/`parseFloat`/`Number(`/`toFixed` across `apps/api/app`, `apps/api/config`, `apps/api/database`, `apps/web/src`: **zero hits**. PHPStan level 8 clean on all 11 files (execution #7).
* **Test quality.** `CountCorrectionGlPostingDefaultTest` uses `RefreshDatabase`, real models, real seeders (`CountriesSeeder`, `CountryInventorySettingsSeeder`, `RolesAndPermissionsSeeder` at `:304-309`), hits the real HTTP endpoints, and asserts real values — no `assertTrue(true)`, no mocked subject. The seam assertions cover **both** sides (movement + entry, or the verified absence of an entry). The migration cases run the real migration file (`:331-335`). No SQLite-only aggregate risk: every claim in this lane was also proven on PG (executions #2–#5).

## 5. One-line fix list before merge

Record F-1 (D-e has no flip watermark — run the census, choose a watermark or accept the noise) and F-2 (the same-second horn survives for marker-NULL lines and now books a JE) on the LEDGER, correct the supersession note's R-8 paragraph to name both horns, and add the FE "use my country's default" reset (F-3) — none of it blocks the merge.
