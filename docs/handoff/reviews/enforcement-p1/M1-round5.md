## Adversarial merge gate — enforcement-P1 · milestone p1-M1 · round 5

**Range:** `41fb478c2..HEAD` (`0968f3606`), fix commit under review `c11d146e7` (+ its register commit `0968f3606`). **Amending authority:** none supplied — brief §2 / the `p1-M1` milestone wording governs unchanged.

**Scope check.** `git diff --name-only` over the range = 10 files under `apps/api/tests/Architecture/**` + `docs/handoff/progress/enforcement-p1.progress.yaml`. Zero production code; DO-NOT-TOUCH honoured. `git status --porcelain` = exactly the two contract-expected untracked entries (handback + register dir).

**Executed independently, not read off the handback:**
- `phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` → **OK (4 tests, 4 assertions)**, 4.9 s, no DB. `phpstan --level=8` on scanner + guard + fixtures → **[OK] No errors**. `pint --test` → `{"result":"pass"}`.
- Census re-derived by running the scanner over `app/` myself: **34 violations · 67 linked · 15 not_applicable**, 25 files — I printed all 34 rows and they are identical to handback §5, and identical to rounds 1–4. Fifth consecutive round with zero live reclassification.
- Key hygiene: **116 sites → 116 unique keys**, zero keys carrying a line number (the M2 seed will not merge two violations into one entry).
- `fixtureMatrix()` counted: **114 cells** (handback §3.5 reproduces). All 114 are non-vacuous by construction — `fixture_matrix_is_classified_exactly_as_the_rules_state()` looks each cell up by key and fails `MISSING SITE` if the scanner emits nothing.
- Handback numbers independently reproduced: **50 files under `app/` carry top-level statements (66 statements)**; **68 candidate files** for the residual coverage audit, 25 emitting.
- **Round-4 fixes verified by probe, not by fixture trust.** Top-level route closure → site emitted (`(none)::(top-level)::journal_entries::create#1`, violation; arrow-fn form too); `fill` / `forceFill($var)` / `setAttribute('source_id', null)` before `save()` → **violation** (all three were `not_applicable` at round 4); `firstOrCreate($attrs, ['source_id' => null])` → **violation** (merge order honoured); `[$t, $i] = ['X', null]` → **violation**; two anon classes with the same method name → **two distinct keys, one per physical write** (the double-emit is gone). All five closed in code.
- §3.8's escalation verified in source: `RepositoryTransferService.php:105` is a real hard `$draft->delete()` on a `JournalEntry`, and `JournalEntry` carries no `SoftDeletes` (`app/Modules/Accounting/Domain/JournalEntry.php:47` — `HasUuids` only). The finding to the parent is accurate, including its mitigating context.

**Lenses.** `stock-gl-interaction` — **applies**: the guard *is* the DPA seam contract (S0 chokepoint deliberately baselined) and the append-only stance on `journal_entries`/`stock_movements`; findings 1–3. `inventory-costing` — **applies**: the movement-pairing predicate is the "no on-hand authored without a movement" invariant; I re-confirmed all nine `WeightedAverageCostService` sites and both opening-balance clusters land in the violation set, and audited all 15 `not_applicable` rows (JE lifecycle/seal updates + reservation-only mutations — all correct under the stated rules). Neither lens's *runtime* dimension (WAC divisor, COGS timing, FEFO ordering, lock order, cash lane) is exercised: this diff adds no production path, so those are **not applicable at M1**.

---

### P1 — none.
### P2 — none.

### P3 — notes

**1. The `(top-level)` scope makes the pairing predicate WHOLE-FILE for class-less files; the reviewed contract still says "function-scoped", and two fixtures assert the contract already says otherwise. CONFIRMED.**
Blind spot B (`DocumentPerActionWriteScanner.php:167-172`) reads *"THE PAIRING PREDICATE IS FUNCTION-SCOPED … A movement recorded anywhere in the same function credits every level write in it"* — and says nothing about top-level code. But `scanFile()` `:527-535` wraps the entire file body in one synthetic scope, so the unit is the FILE. Executed probe (routes-file shape):
```php
Route::post('/a', fn/closure => StockMovement::create(['reference_type'=>'D','reference_id'=>'x']));
Route::post('/b', fn/closure => StockLevel::create(['quantity'=>'5']));
```
→ the level create in closure **b** is credited **linked** by the movement in closure **a**. Meanwhile `FixtureTopLevelRouteWrites.php:24` states *"That scope semantics is stated in blind spot B"* and `FixtureTopLevelLinkedWrite.php:13` says *"(blind spot B)"* — the cross-reference is false; the fact is written down only in handback §3.3 item 4, and the handback itself declares the docblock authoritative (§3.1). Failure scenario: a route file gains an inline closure that posts a linked movement plus a second closure that writes a level unlinked — the second is certified `linked`, never baselined, CI green forever. Exposure is narrow (an extension of an already-accepted limitation; zero live instances — none of the 50 top-level files writes a target table), which is why this is P3 and not a block. **It should be closed as one sentence in blind spot B before M2 seeds**, since M2 inherits this docblock as the reviewed contract and will not otherwise touch the scanner.

**2. `setRawAttributes()` is an uncovered mass-assignment route to linkage erasure. CONFIRMED.** `functionErasesLinkage()` `:1327` matches `['fill','forceFill','setAttribute']` only. Probe: `$e->setRawAttributes(['source_id' => null]); $e->save();` → **not_applicable** (invisible, never baselined) — the round-4-finding-2 class one rung down. The rule-1 docblock enumerates the covered methods rather than claiming the category wholesale, so it is not an overclaim, but the sentence opens with "mass assignment". Not live: the two `setRawAttributes` call sites in `app/` are `InventoryGlPostingService.php:225` (hydrates an in-memory `StockMovement` that is never persisted — verified, it is a pure `amount()` helper) and `ExpenseExportController.php:151` (different table).

**3. `upsert()` can never classify as `linked` — a fail-safe false positive that will pollute the seed if one ever lands. CONFIRMED.** `PAYLOAD_ARG_COUNT['upsert'] = 2` (`:348`) makes `extractPayload()` read arg 1, which for `upsert()` is the positional `uniqueBy` column list; unkeyed items set `resolved = false` (`:1121-1124`), and arg 0 is an array of rows for the same reason. Probe: `StockMovement::upsert([['reference_type'=>$t,'reference_id'=>$i]], ['reference_id'])` → **violation** despite complete linkage. Zero live `upsert` on the four tables, and the direction is fail-closed, so it is a note — but `linkedFormExists('stock_movements','create')` is `true` while no linked `upsert` form is actually reachable.

**4. A named function declared inside a top-level conditional is scanned twice. CONFIRMED.** `topLevelFunctionLikes()` `:1682` finds `Stmt\Function_` at ANY depth, while `topLevelStatements()` `:1661` only skips function declarations at the top statement level. Probe: `if (true) { function helper() { JournalEntry::create([...]); } }` → two keys (`helper::…#1` and `(top-level)::…#1`) for one physical write — the round-4-finding-3 shape reintroduced through the new pass. Fail-safe (over-report → stale+new churn, still red), and measured **zero live instances**: none of the 66 top-level statements in `app/` contains a nested function declaration.

**5. Anonymous-class numbering adds a key-churn axis blind spot C does not mention.** `classLikes()` `:1598` now numbers anon classes `(anonymous#N)` in file order (correctly fixing the round-4 collision), so inserting an anon class earlier in a file renumbers every later one — the same class of churn C documents for ordinals (`:173-179`), stated there for one axis only. One `new class` exists in `app/` and it writes none of the four tables.

**6. Handback §3.4 (red-first) stops at round 3.** Round 4's nine fixture cells landed with the code that satisfies them in a single commit (`c11d146e7`), i.e. the same evidence-by-prior-register status §3.4 spells out for rounds 2–3, plus the before/after probes recorded in §3.7d. §3.4 should say so in one line rather than leaving the reader to infer it — this is the round-4-finding-6 class (a §3.x that no longer describes the tip), at much lower stakes.

**7. Administrative, not a defect.** `enforcement-p1.progress.yaml` M1 still reads `status: review`, `fix_rounds: 4`, `verdict: …/M1-round4.md`, `last_verdict: CHANGES-REQUIRED`, which is correct *as of the commit under review*; the round-5 record (`status: passed`, this register's path) is the executor/parent step that follows this verdict.

---

### Standing checks
Rule 19: no float touches money or quantity anywhere in the diff — fixture quantities are strings (`'3.0000'`, `'1.0000'`), no arithmetic, no scale resolver needed and none faked. No `app()` in production code (none is touched; the scanner is `new`'d at `DocumentPerActionWriteGuardTest.php:341`), no container, no DB, no `RefreshDatabase` — house style honoured. No user-facing strings (en+fr N/A), no migrations, no queues, no routes, no tenancy surface. Red-first: round-1's two reds are pasted and reproduce; rounds 2–4 are evidence-by-prior-register plus in-round probes, and I re-executed the round-4 flips myself (all five were the stated wrong answer before `c11d146e7` and are correct after). The milestone's own named invariants are present and non-vacuous: the mechanism × table matrix is enforced by four gates over 114 key-addressed cells, and both brief-named live mechanisms are pinned — `StockLevel::firstOrCreate` → census #29 (`StockAdjustmentService.php:1618`), `BatchStock::firstOrCreate` → census #4/#6 (`BatchStockService.php:94/:325`).

### Bypasses attempted that the guard CORRECTLY refused
- `collect($rows)->each(function (StockLevel $l) { $l->update(['quantity'=>…]); })` — typed closure parameter → **violation**.
- `$l = StockLevel::find($id); $l->update([...])` → **violation**; `DB::transaction(function () { JournalEntry::create([...]); })` → **violation**.
- `JournalEntry::where('status','draft')->delete()` (builder mass delete) → **violation**.
- `$l->increment($col)` (dynamic column) → **violation** (no soft-hold exemption without a resolvable column); `$l->incrementEach(['reserved'=>1,'quantity'=>2])` → **violation** (allowlist is positive); `$l->increment('reserved')` → **not_applicable** (correct).
- Arrow function at top level, closure inside a `Route::group` closure, top-level code in a file that also declares a class → all emitted and classified.
- `firstOrCreate($attrs, ['source_id'=>null])`, `[$t,$i]=['X',null]`, `fill`/`forceFill`/`setAttribute` erasure — all **violation** (round-4 fixes hold).
- `app(StockLevel::class)->update([...])` → no site, but that is declared blind spot A verbatim ("a container `make()`"), so it is a disclosed limit, not an undisclosed bypass.

---

Nothing survived verification at P1 or P2. Every round-4 finding is closed in code and re-probed, the census is byte-stable at 34/67/15 for a fifth round with 116 unique, line-number-free keys, the fixture matrix is mechanically complete and non-vacuous at 114 cells, and the scope, quality gates and DO-NOT-TOUCH boundary are clean. The six notes are forward-guarantee/documentation items with zero live instances; finding 1 is the only one I would insist be carried into M2's first commit as a docblock sentence, because M2 inherits that docblock as the reviewed contract and will not otherwise reopen the scanner.

VERDICT: ACCEPT
