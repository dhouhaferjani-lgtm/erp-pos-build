## Adversarial merge gate — enforcement-P1 · milestone p1-M1 · round 4

**Range:** `41fb478c2..7d604ac57` (`b07fd1de1` impl · `a1c8f61b6`/`11a970d6d`/`d398403d4` fix rounds 1–3 · `4f4051d92`/`26612e81b`/`18887a8b9`/`7d604ac57` YAML+register). **Amending authority:** none supplied — the brief §2 / `p1-M1` wording governs unchanged.

**Scope:** `git diff --name-only` over the range = 8 files under `apps/api/tests/Architecture/**` + `docs/handoff/progress/enforcement-p1.progress.yaml`. Zero production code; DO-NOT-TOUCH honoured; the two contract-expected untracked entries (handback + register dir) are the only working-tree extras.

**Executed independently, not read off the handback:**
- `phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` → **OK (4 tests, 4 assertions)**, 4.8 s, no DB.
- `phpstan --level=8` on scanner + guard + fixtures → **[OK] No errors**. `pint --test` → `{"result":"pass"}`.
- Census re-derived by running the scanner over `app/` myself: **34 violations · 67 linked · 15 not_applicable** — row-for-row identical to handback §5, and identical to rounds 1–3. Fourth consecutive round with zero live reclassification.
- `fixtureMatrix()` reflectively counted: **105 cells**; all four completeness/agreement gates are non-vacuous (I re-ran each in isolation).
- **Round-3 fixes verified by probe, not by fixture trust.** All four alias shapes (`$id = $this->maybeId()`, `$id = $this->nullableProp`, alias of a `?string` parameter, `$a = null; $b = $a; $c = $b`) now classify **violation**; `updateOrInsert` now classifies **CREATE** on both the Eloquent and `DB::table()` mechanisms (`violation` unlinked, `linked` linked). Both round-3 P2s are genuinely closed in code.

**Lenses.** `stock-gl-interaction` — **applies**: the guard *is* the DPA seam contract and its append-only-ledger stance; findings 1, 2, 4. `inventory-costing` — **applies**: the movement-pairing predicate is the "no on-hand authored without a movement" invariant; findings 1, 3, 6. Neither lens's *runtime* dimensions (WAC divisor, COGS timing, FEFO ordering, lock order, cash lane) is exercised — this diff adds no production path — so those are **not applicable at M1**; I re-confirmed that all nine `WeightedAverageCostService` write sites and both opening-balance clusters land in the violation set, which is the right answer for cost-path integrity.

---

### P2 — fix before merge

**1. Fifty files inside the scan root are never scanned at all: any write in a top-level closure is invisible, while the code asserts those files are covered. CONFIRMED.**
`DocumentPerActionWriteScanner.php:501-513` reads *"Function-like code outside any class (rare in app/, but real: helpers, **route/closure files**). Scanned with an empty class context"* — and then delegates to `topLevelFunctionLikes()` (`:1516-1525`), which finds **only `Stmt\Function_`**, i.e. named functions. Closures (`Expr\Closure`, `Expr\ArrowFunction`) and bare top-level statements outside any `ClassMethod`/`Stmt\Function_` are never handed to `scanFunction()`. Executed probe — a file shaped exactly like a module route file, scanned with the production tree as context, identical wiring to the test:

```php
Route::middleware(['api'])->group(function (): void {
    Route::post('/quick-entry', function (): void {
        JournalEntry::create(['status' => 'posted', 'entry_number' => 'JE-1']);
        StockLevel::create(['product_id' => 'p', 'quantity' => '1.0000']);
    });
});
```
→ **`total: 0`.** Not "unresolvable receiver", not `not_applicable` — **no site emitted**. A bare top-level `StockLevel::create([...])` and a top-level `$w = function () { JournalEntry::create([...]); };` are likewise zero.

This is not blind spot A: A is about *receiver resolution* ("a write is only classified once the scanner knows the receiver's table"), and these receivers are fully resolvable static calls on the target models. It is an un-enumerated code region, and nothing in the docblock says so.
Extent, measured by AST over the scan root: **50 files under `app/` carry top-level statements** — 46 `routes.php` plus `POS/routes_held_orders.php`, `routes_tables.php`, `routes_kitchen.php`, `routes_orders.php` (66 statements total). Every one of their closure bodies is dark.
Failure scenario: a module route file gains an inline closure endpoint that posts a correction (`JournalEntry::create([...])` with no `source_*`, or a `StockLevel::update(['quantity' => …])` fix-up) — a shape CLAUDE.md rule 12 makes route files a natural home for. The scanner emits nothing, M2 never baselines it, the M3 CI job stays green forever, and the DPA principle is unguarded on a whole file class.
**Verified not exploited today:** the four route files referencing the target models (`Accounting`, `Inventory`, `BatchExpiry`, `POS`) use controller-array routes only — no inline closure touches the four tables. Forward hole, not present mis-certification; the census is unaffected.
Fix is ~5 lines: have `topLevelFunctionLikes()` (and/or `scanFile`) also enumerate `Expr\Closure`/`Expr\ArrowFunction` that are not already inside a scanned function-like, plus a synthetic `(top-level)` function name for bare statements. If the decision is instead to exclude route files, that must be an explicit blind spot **and** the `:501-502` comment must stop naming "route/closure files" as covered — the docblock and the code have to agree, which is the exact standard rounds 1–3 blocked on.

**2. `fill()` / `forceFill()` / `setAttribute()` before `save()` erases `journal_entries` linkage and lands as `not_applicable` — invisible, never baselined — while rule 1 asserts save()'s erasure path IS covered. CONFIRMED.**
`classifySite()` `:662-670` deliberately exempts `save` from the unreadable-payload rule, with the stated justification that *"its erasure path is the property-assignment rule below"*; the docblock repeats it at `:66-78`. But `functionErasesLinkage()` `:1242-1244` matches **only** `Expr\Assign` whose target is a `PropertyFetch` — so every mass-assignment route to the same erasure is unmatched. Executed probes:

| shape | verdict |
|---|---|
| `$e->fill(['source_type'=>null,'source_id'=>null])->save();` | **not_applicable** |
| `$e->forceFill(['source_id'=>null]); $e->save();` | **not_applicable** |
| `$e->setAttribute('source_id', null); $e->save();` | **not_applicable** |

Control: `$e->source_id = $this->maybeProp; $e->save();` → **violation** (the round-2 rule works — it just has three siblings it doesn't cover). `not_applicable` sites are never reported and never reach the M2 baseline, so this is the round-2-finding-2 / round-3-finding-2 class exactly: not un-remediated, **permanently invisible**, and asserted-away by a docblock sentence that claims the save path is covered.
Failure scenario: a reversal/adoption service does `$draft->fill($request->safe()->except('source_id') + ['source_id' => null])->save();` — a `journal_entries` row keeps existing with its justifying document stripped, and the guard reports nothing, ever.
**Not live today:** 111 `fill`/`forceFill`/`setAttribute` call sites in `app/`; none is on a target model (only `TechnicianTimeEntryController.php:208`, a different table). Forward hole; census unchanged.
Fix: treat `fill`/`forceFill` as payload-bearing for the erasure rule (they already have an inspectable array arg), and `setAttribute('col', …)` as a two-arg erasure form — or delete the "covered by the property-assignment rule" claim and name the residue.

---

### P3 — notes

**3. Round-3 finding 4 is only half closed: one physical write in an anonymous class still emits TWO baseline keys, and two anon classes in one file COLLIDE on one key. CONFIRMED.**
`functionLikes()` `:1490-1505` now excludes nested `ClassMethod`s from the outer class, which removed the bogus third key — but `scanFunction()` `:531-545` still recurses with `NodeFinder::find()` **into** the anon-class body while scanning the enclosing method, so the site is emitted twice. Probe: `public function anon(): void { $x = new class { public function write(): void { StockLevel::create([...]); } }; }` at L25 yields `Probe\(anonymous)::write::stock_levels::create#1` **and** `Probe\P4::anon::stock_levels::create#1`. Blind spot C `:174-175` and handback §3.7c both state the double-count was removed; it wasn't. Worse, two anon classes with the same method name in one file produce **byte-identical keys** (probed: both `Probe\(anonymous)::write::stock_levels::create#1`, at L25 and L37) — under a key-set baseline that merges two distinct violations into one entry, so remediating one leaves the other silently exempt (fail-**open**, in the direction the ratchet by design does not police). Not live: the single `new class` in `app/` (`TreasuryAlertRecipients.php:47`) writes none of the four tables. M2 must not seed a baseline before this is settled.

**4. `firstOrCreate($attributes, $values)` where `$values` nulls a linkage key that `$attributes` sets → `linked`, against Laravel's own merge order. CONFIRMED.**
`extractPayload()` `:1083-1085` — *"A key seen non-null anywhere wins"* — unions both payload args. Probe: `JournalEntry::firstOrCreate(['source_type'=>'Doc','source_id'=>$docId], ['source_id'=>null,'status'=>'draft'])` → **linked**, though `firstOrCreate` creates via `array_merge($attributes, $values)` and the created row carries `source_id = null`. Same for `updateOrCreate`/`updateOrInsert`, which share the two-arg path (`:342-347`). Contrived, zero live instances, but it is a decidable shape resolved in the credit direction.

**5. The alias fixpoint does not cover destructuring. CONFIRMED.** `buildVarTypes()` `:1910-1928` only propagates through `Expr\Assign` with an `Expr\Variable` target. Probe: `[$t, $id] = ['X', null]; JournalEntry::create(['source_type'=>$t,'source_id'=>$id]);` → **linked**. Narrower than round-3 P2-1 (a destructure from a *call* is undecidable anyway, so only literal-null destructures leak) and unlikely in practice — but the fixpoint's own comment says "to a fixpoint" and it is additionally capped at 8 passes (`:1911`) with no assertion on non-convergence. One sentence in blind spot E closes both.

**6. Handback acceptance evidence is stale against the delivered tip. CONFIRMED.** §3.6 pastes `OK (3 tests, 3 assertions)`; the tip actually produces **4 tests, 4 assertions** (round 3 added `the_rule_surface_agrees_with_the_pinned_classifications`). §3.5 claims **91 pinned cells**; `fixtureMatrix()` reflectively returns **105**, and the mechanism×table table carries no annotation for the six round-3 cells (alias ×3, `updateOrInsert` ×3). Cosmetic in effect, but §3.6 is the brief's *"exact commands, outputs pasted in handback"* deliverable and M2's cross-check inherits this document — a pasted output that no longer reproduces is the wrong artifact to inherit.

**7. The `app/`-only scan-root decision is load-bearing, not theoretical — the parent should know before M2 seeds.** Handback §3.3 item 1 flags the exclusion of `database/seeders`, `database/migrations`, `tests/` and correctly notes no *register* violator lives outside `app/`. For accuracy: there **is** a live write outside the root — `database/seeders/StockLevelSeeder.php:109` `StockLevel::updateOrCreate(...)`, plus migration backfills touching accounting tables. Within the executor's flagged authority and defensible (provisioning surfaces), so not a defect — but "seeders and migrations are provisioning surfaces with no document by construction" is a *ruling*, not an observation, and a future data-backfill migration writing `journal_entries` would be outside the guard by construction. Worth a parent ticket, not a fix here.

**8. Carried, unchanged, correctly.** Round-1 P3-9 (`--testsuite=Architecture` red at base, 4 pre-existing failures) → M3. Ordinal churn → blind spot C. Fixture placement follows the house `tests/Architecture/<Name>Fixtures/` pattern; no other Architecture test scans `tests/`.

---

### Standing checks
Rule 19: no float touches money or quantity anywhere in the diff — fixture quantities are strings (`'1.0000'`), there is no arithmetic, no scale resolver is needed and none is faked. No `app()` in production code (the scanner is `new`'d directly, `DocumentPerActionWriteGuardTest.php:330`); no container, no DB, no `RefreshDatabase` — house style honoured. No user-facing strings (en+fr N/A), no migrations, no queues, no routes, no tenancy surface, no production code at all. Red-first: rounds 1's two reds are pasted (§3.4) and reproduce; rounds 2–3 are evidence-by-prior-register, now stated as such rather than implied, plus one genuine in-round red for the fixpoint ordering bug — I re-executed the round-3 flip myself and it holds. Milestone's own named invariants present and non-vacuous: the mechanism × table matrix is mechanically enforced by three completeness/agreement gates, and both brief-named live mechanisms are pinned (`StockLevel::firstOrCreate` → census #29 `StockAdjustmentService.php:1618`; `BatchStock::firstOrCreate` → census #4/#6 `BatchStockService.php:94/:325`).

### Bypasses attempted that the guard CORRECTLY refused
- Write inside `try/catch`, inside a `match` arm, inside a closure **declared within a method**, and via `tap(new StockLevel, fn (StockLevel $l) => $l->save())` → all **violation** (the closure gap is strictly the *top-level* one in finding 1).
- `(new JournalEntry)->newQuery()->create(...)`, `JournalEntry::on('tenant')->create(...)`, `JournalEntry::withoutGlobalScopes()->create(...)`, `JournalEntry::query()->where(...)->delete()` → **violation** (chain resolution holds through builder hops).
- `$e->source_id = $this->nullableProp; $e->save();` → **violation** (round-2 erasure rule reaches nullable `$this` members, not just literal null).
- `DB::table('stock_levels')->updateOrInsert([...], [...])` → **violation** via the pairing predicate (the round-3 reclassification does not weaken the level tables).
- `$l->increment('reserved')` → **not_applicable**, `update(['variant_id' => …])` → **violation** (the positive allowlist still refuses re-keying).
- Assigning a nullable call to a **non-nullable-declared** property then reading it back → the shape is a PHP `TypeError` at runtime, so not a laundering path; discounted rather than reported.
- `DB::statement('UPDATE products SET x = (SELECT 1 FROM stock_levels LIMIT 1)')` → **violation** (over-reports a read; fails safe).
- Level write paired with a chokepoint-*shaped* call that is not a derived entry point (`$svc->recordAdjustment()`) → **violation** (arm (a) stays bound to the AST-derived entry-point set).

---

Findings 1 and 2 are the same defect class the last three gates blocked on — the artifact asserting coverage the code does not provide, in the **credit** direction, on the very document M2's baseline and cross-check inherit. Neither is live-exploited (I verified both against the tree; the census is unchanged at 34/67/15 for a fourth round), and both fixes are small. But finding 1 is materially larger than its predecessors: it is not a value-nullability edge, it is fifty files inside the declared scan root that the scanner never opens, contradicted by a comment in the scanner itself. Seeding a "the tree is now clean except these 34" baseline on top of that is precisely what the brief calls worse than no guard.

VERDICT: CHANGES-REQUIRED
