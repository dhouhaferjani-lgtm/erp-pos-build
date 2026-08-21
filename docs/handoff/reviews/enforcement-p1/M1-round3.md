## Adversarial merge gate — enforcement-P1 · milestone p1-M1 · round 3

**Range reviewed:** `41fb478c2..18887a8b9` (`b07fd1de1` implementation · `a1c8f61b6` fix round 1 · `11a970d6d` fix round 2 · `4f4051d92`/`26612e81b`/`18887a8b9` YAML status). Scope allowlist honoured — `git diff --name-only` over the range yields only `apps/api/tests/Architecture/**` (8 files) + `docs/handoff/progress/enforcement-p1.progress.yaml`; **zero production code**, DO-NOT-TOUCH respected. Working tree carries the two contract-expected untracked entries (handback + register dir).

**Lens contracts opened and applied.** `stock-gl-interaction` (`.claude/agents/stock-gl-interaction-reviewer.md`) — **applies**: the guard *is* the document-per-action seam contract (its dimension 1) and its append-only-ledger stance (dimension 9); findings 1, 2, 3. `inventory-costing` (`.claude/agents/inventory-costing-reviewer.md`) — **applies**: the level/batch pairing predicate is the "no on-hand authored without a movement" invariant (its dimension 6/ES-68 class); findings 1, 4, 6. Neither lens's *runtime* dimensions (COGS timing, WAC divisor basis, FEFO ordering, lock order, cash lane) is exercised by this diff — it adds no production path — so those are recorded as **not applicable at M1**, with the observation that all nine `WeightedAverageCostService` write sites and both `OpeningBalancePostingService`/`ResetOpeningBalanceService` clusters land in the violation set, which is the correct answer for cost-path integrity.

**Executed independently before hunting (not read off the handback):**
- `phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` → **OK (3 tests, 3 assertions)**, no DB, 4.9 s.
- `phpstan --level=8` on scanner + guard + fixtures → **[OK] No errors** (round-1 P3-8 stays closed across the +232-line round-2 change).
- `pint --test` on the same paths → `{"result":"pass"}`.
- Census reproduced by running the scanner myself over `app/`: **34 violations · 67 linked · 15 not_applicable**, violations spread over **19 files** — matches handback §3.6/§5 row for row, in the same order (I diffed all 34 rows against my own run; §5 is verbatim-accurate).
- **Round-2 fixes verified in code, by probe, not by fixture trust.** All four shapes round 2 proved were credited now classify **violation**: null-assigned local, nullable `$this` property, nullable-returning `$this` method, array element — and the boundary case (non-nullable promoted property) is still `linked`, and the round-1 nullable-**parameter** refusal is intact. P2-2: `JournalEntry::query()->update($vars)` → `violation (unreadable payload — fail closed)`; `update(['source_id'=>null] + $extra)` → violation; `DB::table('journal_entries')->update($vars)` → violation. P3-4: `morphOne` now resolves end-to-end — a `MorphOne`-declared host's `->level()->create([...])` emits a site (probe), not just a prefilter tweak. P3-7: `linkedFormExists()` is genuinely consulted by `every_cell_with_a_linked_form_pins_a_negative_case()` (`DocumentPerActionWriteGuardTest.php:272`).
- Milestone's own named invariants present and non-vacuous: mechanism × table matrix (91 cells) mechanically enforced by two completeness gates; the two brief-named live mechanisms are pinned (`StockLevel::firstOrCreate` → census #29 `StockAdjustmentService.php:1618`; `BatchStock::firstOrCreate` → census #4/#6 `BatchStockService.php:94/:325`); handback carries the coverage table (M1 title's named deliverable, round-1 finding 6 closed).

---

### P2 — fix before merge

**1. Every null-admitting shape the last two rounds forced the scanner to refuse is defeated by one alias assignment. CONFIRMED.**
`DocumentPerActionWriteScanner.php:1105-1157` (`nullAdmitting()`) decides a bare `Expr\Variable` from `$this->nullableVars` / `$this->nullAssignedVars` only (`:1120-1122`) — both populated at `buildVarTypes()` `:1853-1875` from parameters and from *direct* `$x = null` assignments. Nothing propagates through an assignment whose right-hand side is itself a refused shape. Executed probes (fixture dir scanned with the production tree as context, identical wiring to the test):

| shape | verdict |
|---|---|
| `$id = $this->maybeId();` (`maybeId(): ?string`) → `create(['source_type'=>'X','source_id'=>$id])` | **linked** |
| `$id = $this->nullableProp;` (`private ?string`) → same create | **linked** |
| `$id = $ref;` where `$ref` is a `?string` **parameter** → same create | **linked** |
| `$a = null; $b = $a;` → `create([... 'source_id' => $b])` | **linked** |

Row 3 voids the round-1 P2-4 fix and rows 1–2 void the round-2 P2-1 fix, each at the cost of one line. This is **not** covered by the stated residue: blind spot E (`:170-181`) justifies itself as "values the scanner cannot decide" and names `$document->id`, "any call on a collaborator", "an untyped member, a nullable return on another class, a container call" — but the scanner *has already decided* every source in the table above; it simply drops the fact one hop later. Rule 1 (`:56-63`) still tells the reader that "a nullable or null-defaulted parameter, a local assigned null, a nullable property or nullable-returning method on `$this`" do not prove linkage; the alias makes that untrue.
Failure scenario: a new posting service ships `$sourceId = $this->resolveSourceId();` (`?string`) into `JournalEntry::create(['source_type' => …, 'source_id' => $sourceId])` → classified `linked` → never enters the M2 baseline → the ratchet is green forever while a `journal_entries` row can be written with null linkage. The removal direction is worse: applying the same one-line refactor to a baselined violator turns it into a *legitimate shrink*, retiring its baseline key without remediating anything — the one baseline movement the anti-growth ratchet by design does not police.
**Verified not exploited today**: an AST sweep over `app/` for linkage values that are locals aliased from a nullable parameter / nullable `$this` member / nullable `$this` method / a null literal returned **zero** hits, and the census is unchanged. Forward hole, not present false certification.
Fix is inside the loop that already exists: in `buildVarTypes()` `:1857-1866`, when the assigned expression is itself `nullAdmitting()`, record the target variable (iterating to a fixpoint, or simply refusing any local assigned from a refused shape). Alternatively rewrite blind spot E so it states the alias residue explicitly and rule 1 stops claiming the parameter/local/property/method shapes are refused — but the docblock and the code must agree, which is the exact standard rounds 1 and 2 blocked on.

**2. `updateOrInsert` is an INSERT classified as MUTATE, so an unlinked `journal_entries` row creation is `not_applicable` — invisible, never baselined. CONFIRMED.**
`DocumentPerActionWriteScanner.php:300` maps `'updateOrInsert' => ['update', 'MUTATE']`, and the docblock's write-class vocabulary repeats it (`:44-47`). Laravel's `Illuminate\Database\Query\Builder::updateOrInsert($attributes, $values)` **inserts a row** when no match exists — it is a CREATE-class write. Because it lands in the MUTATE arm of `classifySite()`, a resolvable payload with no linkage falls straight through `:660-673` to the `not_applicable` return at `:676`. Executed probes:

| shape | verdict |
|---|---|
| `DB::table('journal_entries')->updateOrInsert(['entry_number'=>'JE-1'], ['status'=>'posted','entry_date'=>'2026-01-01'])` | **not_applicable** |
| `JournalEntry::query()->updateOrInsert(['entry_number'=>'JE-1'], ['status'=>'posted'])` | **not_applicable** |

`not_applicable` sites are never reported and never reach the M2 baseline, so this is the round-1-finding-1 / round-2-finding-2 class exactly: not "un-remediated", but permanently invisible. It is also unlisted in every blind spot — the docblock asserts the classification is right, and it is not. The failure is confined to `journal_entries`: on `stock_movements` the MUTATE arm is unconditionally a violation (`:645`), and on the two level tables the pairing predicate governs regardless of write class (`:601-620`), so both fail safe.
**Not live today** — the only four `updateOrInsert` call sites in `app/` (`MigrateParapharmacyDataCommand.php:239/:276/:309/:345`) target pivot tables outside the contract. Forward hole.
Fix: move `updateOrInsert` to `['updateOrCreate', 'CREATE']` (its payload arg count is already 2, `:337`) and add the matrix cell; the mechanism vocabulary in `MECHANISMS` (`DocumentPerActionWriteGuardTest.php:44-55`) already carries `updateOrCreate`, so no new completeness-gate row is needed.

---

### P3 — notes

3. **Linkage erasure through a collaborator's nullable call is not caught.** `functionErasesLinkage()` `:1230-1240` routes the assigned expression through `nullAdmitting()`, which only decides `$this->…` members — probed: `$entry->source_id = $h->maybe(); $entry->save();` (`Helper::maybe(): ?string`) → **not_applicable**. Consistent with blind spot E's letter, but handback §3.7b's P3-3 disposition reads as if the erasure rule is now general; it is `$this`-scoped. Say so where rule 1 states the erasure path.

4. **A write inside an anonymous class emits three baseline keys for one physical write.** Probed `public function outer(): void { $x = new class { public function write(): void { StockLevel::create([...]); } }; }` on line 7 produces `…::Probe\(anonymous)::write::stock_levels::create#1`, `…::Probe\Anon::outer::stock_levels::create#1` **and** `…::Probe\Anon::write::stock_levels::create#1` — all at L7. The third names a method that does not exist on `Anon`. One remediation would strand three baseline entries as stale at once. Fails safe (over-reporting), and not live: the single `new class` in `app/` (`TreasuryAlertRecipients.php`) writes none of the four tables. Worth a line in blind spot C before M2 seeds the baseline.

5. **Handback numbering and stale predicate names.** §3.8 calls `RepositoryTransferService.php:105` "Violation #33"; in the scanner's own ordering it is **#34** (#33 is `ReturnScrapWriteOffService.php:165`) — §5's table has it right, so §3.8 alone is off by one. Separately §3.2 and §3.7 P2-4 still describe the rule as "provably non-null" / `provablyNonNull()` after the round-2 rename to `nullAdmitting()`; `provablyNonNull()` survives only as a one-line negation wrapper at `:1100-1103`. Cosmetic, but M2's cross-check inherits this document.

6. **`linkedFormExists()` is a parallel restatement of the rules, not a derivation from them.** `:207-225` hand-encodes which cells have a linked form; `classifySite()` `:590-676` decides it independently. It is a real improvement over round-2's hardcoded test map (the knob moved next to the rules), but the two can still drift silently — if rule 2 ever credited a movement MUTATE, this would keep returning `false` and the negative-control requirement would never appear. A cheap tie-breaker is a test asserting the two agree on the cells the matrix already exercises.

7. **Red-first for round 2.** The nine new fixture cells and the code that makes them pass landed in a single commit (`11a970d6d`); there is no separately-committed red. The substitute is legitimate and I re-executed it: the round-2 register itself is the pasted red (it probed all four shapes as `linked` before the fix), and I confirmed the post-fix flip myself. Recording it as evidence-by-prior-register rather than a gap, but the handback should say that explicitly rather than leaving §3.4 showing only the M1 round-1 reds.

8. **Carried, unchanged, correctly:** round-1 P3-9 (`--testsuite=Architecture` red at base, 4 pre-existing failures) → M3; P3-10 ordinal churn → blind spot C. Fixture placement still follows the house `tests/Architecture/<Name>Fixtures/` pattern and no other Architecture test scans `tests/`.

**Standing checks.** Rule 19: no float touches money or quantity anywhere in the diff — fixture quantities are strings (`'1.0000'`), there is no arithmetic, no scale resolver is needed and none is faked. No `app()` in production code (the scanner is `new`'d directly, `DocumentPerActionWriteGuardTest.php:292`); no container, no DB, no `RefreshDatabase` — house style honoured. No user-facing strings (en+fr N/A), no migrations, no queues, no routes, no tenancy surface. The milestone's own named deliverables are present and non-vacuous.

---

### Bypasses attempted that the guard CORRECTLY refused
- `$this->nullableProp?->id` as `reference_id` → **violation** (nullsafe read still refused after the rewrite).
- `$entry->update(...$args)` (spread), `insertUsing(...)`, `createMany`, list-payload `insertOrIgnore` → **violation** (unresolvable → fail closed).
- `DB::table('journal_entries')->where(…)->update($vars)` and `->updateOrInsert(['id'=>1], $vars)` → **violation** via the new unreadable-payload rule (the round-2 P2-2 fix reaches the query-builder mechanism too, not just Eloquent `update`).
- `StockLevel::query()->truncate()` → **violation**; write inside a `trait` used by a service → **violation**; `morphOne`-mediated `->level()->create([...])` → **violation**.
- `JournalEntry::create(['source_type' => $map['t'], 'source_id' => $id])` with a locally-built constant array → **violation** (array-element refusal costs a false positive here, but fails safe).
- `restore()` / `push()` / `saveQuietly()` on `journal_entries` map to mechanism `save` and are correctly excluded from the unreadable-payload rule (they carry no inspectable payload) while still reachable by the property-assignment erasure rule — probed both ways.
- Level write inside a `DB::transaction(closure)` with the linked movement created in the enclosing method → **linked**, matching blind spot B's stated function-scoping rather than leaking.

Findings 1 and 2 are the same defect class the last two gates blocked on — a reviewed docblock asserting a classification the code does not make, in the *credit* direction, on the artifact M2's baseline and cross-check inherit. Neither is live-exploited (I checked both against the tree and the census is unchanged at 34/67/15), and both are small: one propagation step in an existing loop, and one row in `WRITE_METHODS`. Everything round 2 asked for is genuinely closed in code, and the scanner is materially stronger than at round 1 — but the guard's forward guarantee still leaks in exactly the way the brief calls worse than no guard.

VERDICT: CHANGES-REQUIRED
