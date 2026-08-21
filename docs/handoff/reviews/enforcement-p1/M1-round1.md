## Adversarial merge gate — enforcement-P1 · milestone p1-M1 · round 1

**Range reviewed:** `41fb478c2..4f4051d92` (`b07fd1de1` = implementation, `4f4051d92` = YAML status). Scope allowlist honoured — diff is `apps/api/tests/Architecture/**` + `docs/handoff/**` only; zero production code touched (DO-NOT-TOUCH respected).

**Lenses.** `inventory-costing` — applies (on-hand/movement/cost-path integrity): findings 1, 2, 3, 4. `stock-gl-interaction` — applies (the guard *is* the stock↔GL seam contract): findings 1, 2, 4. Contracts `.claude/agents/inventory-costing-reviewer.md` / `stock-gl-interaction-reviewer.md` applied; verification is code-first, every claim below was executed against the tree, not read off the brief.

**Verified green before hunting:** `phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` → OK (3 tests, 3 assertions, 1.0 s, no DB). `pint --test` on all new paths → pass. Both completeness gates are non-vacuous (a scan returning nothing turns every cell into `MISSING SITE`). `journal_entries` genuinely carries no monetary column (`JournalEntry.php:51-70`) — the "amounts live on journal_entry_lines" exemption rationale checks out.

---

### P1 — blocks

**1. The documented soft-hold exemption is not the implemented one; it silently clears a live on-hand mutation. CONFIRMED.**
`tests/Architecture/Support/DocumentPerActionWriteScanner.php:441-442` exempts *any* resolvable MUTATE payload whose keys **lack `quantity`**. The docblock (`:88-96`) states the exemption is only for `reserved`/`reserved_quantity`/`min_quantity`/`max_quantity`. The gate-reviewed rule and the enforced rule differ.
Failure scenario, live today: `StockLevelMigrationService.php:58` — `DB::table('stock_levels')->update(['variant_id' => $defaultVariantId, 'updated_at' => now()])` — bulk re-keys every product-level stock row onto a variant with no movement. Scanner output: `not_applicable`, reason string *"mutates only reservation/threshold columns (variant_id, updated_at) — a soft hold with no on-hand or ledger effect"*. That reason is false, and `not_applicable` sites never reach the M2 baseline, so this write is invisible permanently — not merely un-remediated. Same hole admits any future `update(['location_id' => …])` / `update(['cost_layer_id' => …])` on either level table.
Fix: positive allowlist (`array_diff(array_keys($payload['keys']), SOFT_HOLD_COLUMNS) === []`) + a fixture cell for a non-quantity, non-soft-hold column.

**2. Receiver-unresolvable writes are dropped silently, contradicting the stated fail-closed contract; ≥3 live writes to the four tables are invisible. CONFIRMED.**
`:521` — `resolveChainTable()` returns null ⇒ `classifyCall()` returns null ⇒ **no site is emitted at all**. The docblock `:26-30` claims "*whenever a payload **or a receiver type** cannot be resolved statically at a site that is IN CONTRACT, the site is reported as a violation rather than waved through*"; the commit message repeats it. For receivers this is fail-**open**.
Executed proof — scanning each file in isolation returns **0 sites**:
- `app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:84` — `$this->update(['quantity' => $newQuantity])`: an on-hand mutation of `inventory_batch_stock` with no movement in scope ⇒ **violation** by the operative rule, unseen.
- `app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php:186` — `$inverse->save()` where `$inverse` is the `StockMovement` returned by `StockAdjustmentService::receive()`; rule 2 says movement MUTATE is *always* a violation. This is the exact post-hoc-UPDATE shape `StockAdjustmentService.php` calls out in its own comment ("*the shape S0 finding I-5 condemns and that ReverseWriteOffService still ships*"). Unseen.
- `app/Modules/Inventory/Domain/StockLevel.php:205` — `$this->save()` in `recalculateReserved()`; unresolvable payload "never earns the exemption: fail closed" (`:96`) ⇒ violation by rule. Unseen.

Synthetic probe (`$entry = $this->svc->makeEntry(); $entry->update([...]); $entry->delete();`) emitted **no sites** — a JE `delete`, which rule 1 calls "always VIOLATION", vanishes.
The brief's own standard: *"A baseline that misses known violators is worse than no guard: it certifies them clean."* Minimum fix: (a) resolve `$this` to `currentClass` (one line — closes every model-internal write); (b) index declared return types tree-wide, as `buildRelationMap` already does, so service-returned models resolve; (c) restate the docblock honestly for whatever residue remains, so M2's cross-check inherits a truthful blind-spot list.

---

### P2 — fix before merge

**3. The movement-pairing predicate is a bare name match — trivially satisfiable by a no-op. CONFIRMED.**
`:883` credits `pairing['movement']` for *any* call named `recordMovement`, on any receiver, anywhere in the function. Executed probes: a private empty `recordMovement()` in the same class → `StockLevel::create([...])` classified **linked**; `$this->someUnrelatedService->recordMovement()` → `BatchStock::create([...])` classified **linked**. The fixture's own helper (`FixtureStockLevelWrites.php:236`) *is* an intentionally empty stub, so the negative controls prove only that the name matched. The predicate is also order- and branch-insensitive (`saveWithMovement` credits a movement created *after* the write; a movement inside an unrelated `if` credits everything in the function). Failure: a new violator writes `$this->recordMovement();` — or renames an existing helper — and ships an unlinked level write green forever. Bind the arm to the real chokepoint (receiver typed `StockAdjustmentService`, or `$this` inside it) or to a stock_movements CREATE that itself classified `linked`.

**4. "Non-null values" is implemented as "not the literal `null` constant" — the S0 chokepoint is credited LINKED although its linkage is null on every caller that passes none. CONFIRMED.**
`:840` sets `isNonNull` false only for a literal `null`. `StockAdjustmentService.php:1808` (`recordMovement`) writes `'reference_type' => $referenceType?->value, 'reference_id' => $referenceId` from `?StockMovementReferenceType`/`?string` params defaulting to `null`, and `assertReferenceLinkagePaired` (`:1857-1870`) explicitly *permits* null/null. Scanner verdict: `linked — payload carries reference_type + reference_id`. Since essentially all stock movements flow through this one site, the guard's `stock_movements` coverage is near-vacuous for the chokepoint path. Probe: `JournalEntry::create(['source_type' => $type, 'source_id' => $id])` with `?string` params → **linked**. No fixture pins this cell in either direction. Either restate the rule as "key present and not a literal null" (and say what that concedes), or treat a nullable-typed/nullsafe value as unresolved so the chokepoint enters the baseline as a deliberate, visible entry. Add the fixture either way.

**5. Relation-mediated writes are in the required vocabulary but have zero fixture coverage. CONFIRMED.**
Brief §2 deliverable 1 names "relation-mediated writes"; deliverable 6 requires a fixture per mechanism. `buildRelationMap` (`:964-1010`) builds its map **from the scan roots**, and the fixture directory declares no relations — so in `scanFixtures()` the map is empty and the whole relation path (including the `str_contains($code, 'hasMany')` prefilter) is untested by the liveness certificate. Live relations exist and resolve today (`Product::stockLevels` → StockLevel, `Batch::batchStock` → BatchStock, `StockMovement::reversalOf`) — verified by probe, so this is a missing fixture, not a broken mechanism. Silent rot here is precisely the C6-detector class this package exists to prevent.

**6. M1's named handback deliverable is absent.** The YAML M1 title requires "*Handback carries the mechanism-by-table coverage table*". No `docs/handoff/HANDBACK-enforcement-p1-*.md` exists; the commit message lists mechanisms in prose only. (`fixtureMatrix()` is a stronger, mechanically-enforced equivalent — but the named artifact is still undelivered.)

---

### P3 — notes

7. **Red-first is narrative only.** Fixtures, scanner and test landed in one commit (`b07fd1de1`); "TDD: fixtures first" is unverifiable from the record, and with no handback there is no pasted red output. The one documented red→green cycle (the `save()`-erasure hole) is credible and produced a real rule, but it is a claim, not evidence.
8. **PHPStan level 8: 17 errors on the new files** — incl. 2× `Call to an undefined method PhpParser\Node::getArgs()` (`:758`, `:792`; latent only, all current callers pass Call nodes), 2× unreachable statements (`:688`, `:1305`), `list<Node>` variance ×6, and `FixtureJournalEntryWrites.php:127` assigning a string to `JournalEntry::$status`. Not a CI/preflight regression — `phpstan.neon` has `paths: app/` — but CLAUDE.md rules 6/10 say zero errors on new code. Pint passes.
9. **Forward risk for M3:** `./vendor/bin/phpunit --testsuite=Architecture` is **RED at this tip** — 4 failures pre-existing and unrelated to this diff (`AuthLifecycleTest`, `ConsoleCommandTenantContextTest`, `ControllerTenantContextTest`, `QueueJobTenantContextTest`). An M3 job running the whole suite with no `if:` would be red on arrival; the brief's "scope the job + record exclusions" escape hatch will be needed.
10. **Baseline-key churn:** the `#<ordinal>` discriminator (`:378-387`) is positional within (function, table, mechanism), so inserting a write earlier in the same bucket renumbers the ones after it — M2/M3 will report `stale + new` instead of clean growth. Fails safe (still red), but the message will mislead.
11. The `journal_entries` MUTATE exemption also covers `fiscal_hash` / `chain_sequence` / `entry_date` / `journal_code` rewrites. Defensible for a *document*-justification guard, but say so explicitly so a later reader does not read it as hash-chain coverage.
12. **Process:** during this gate two untracked M2 artifacts appeared in the worktree (`tests/Architecture/DocumentPerActionBaselineRatchetTest.php`, `tests/Architecture/Support/write-document-per-action-baseline.php`). Outside `base..HEAD`, no effect on this verdict — but a milestone handover should be quiescent.

---

### Bypasses attempted that the guard CORRECTLY refused
- `JournalEntry::create(['source_type' => $t, 'source_id' => $i] + ['x' => 1])` — array-union payload → unresolvable → **violation**. Held.
- Spread / dynamic-key payloads (`:809-819`) → `resolved = false` → violation. Held.
- Relation-name collision (`->lines()->delete()` on Document vs JournalEntry) — `lines` never enters the map (target-model + ambiguity filters) → no false positive. Held.
- `DB::table(...)` reached via a static hop and via `DB::connection()->table()`, plus table aliases (`:684-706`) → resolved. Held.
- Deleting a matrix cell → both completeness gates fire. Held.
- `upsert` / `createMany` / `insert([[…]])` list payloads → unresolvable → violation. Held (fail-closed, at some false-positive cost).

**Register cross-check spot-audit** (M2's formal job, run here to size the scanner): V1 and V2 are remediated (`TestE2EGLPosting.php`, `createOpeningBalanceEntry` gone), V6 is remediated (`InventoryService` is read-only per ruling D4), V5 is caught (`JournalEntryController.php:97`). The misses above are *not* register entries — they are new blind spots the register never covered, which is the worse direction.

Findings 1 and 2 must be closed before this milestone can be accepted: both put real writes to the contract's own four tables permanently outside the ratchet, which defeats the package's stated purpose ("merciless about tomorrow").

VERDICT: CHANGES-REQUIRED
