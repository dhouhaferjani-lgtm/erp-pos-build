## Adversarial merge gate — enforcement-P1 · milestone p1-M1 · round 2

**Range reviewed:** `41fb478c2..26612e81b` (`b07fd1de1` implementation, `a1c8f61b6` fix round 1, `4f4051d92`/`26612e81b` YAML status). Scope allowlist honoured — diff touches `apps/api/tests/Architecture/**` + `docs/handoff/progress/enforcement-p1.progress.yaml` only; **zero production code**. Registers correctly absent from the branch (brief §5 step 5: registers are never committed into A), so the two untracked entries are contract-expected at this milestone.

**Lenses.** `inventory-costing` — applies (the level/batch on-hand and movement-pairing rules ARE the cost-path integrity contract): findings 1, 3, 5. `stock-gl-interaction` — applies (the guard is the stock↔GL seam contract): findings 1, 2, 5.

**Independently executed before hunting (not read off the handback):**
- `phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` → **OK (3 tests, 3 assertions)**, no DB.
- `phpstan --level=8` on `tests/Architecture/Support` + guard + fixtures → **[OK] No errors** (round-1 P3-8 closed).
- `pint --test` on the same paths → `{"result":"pass"}`.
- Census reproduced by running the scanner myself over `app/`: **34 violations · 67 linked · 15 not_applicable over 19 files** — matches handback §3.6 exactly, and the §4 register-crosscheck line items (V2 `AccountingService.php:398` linked, V5 `JournalEntryController.php:97` violation #3, V8 `SupplierCreditNotePostingService.php:666` linked, V10 `ReturnScrapWriteOffService.php:165` violation #33, S0 residue `OpeningBalancePostingService.php:117`, `StockTransferService.php:703`, WAC ×4) all verify.
- **Round-1 fixes verified in code, not prose:** P1-1 → `StockLevelMigrationService.php:58` now reports (violation #17) via the positive allowlist `SOFT_HOLD_COLUMNS` (`DocumentPerActionWriteScanner.php:205`, applied at `:537-539`); P1-2 → all three named sites now report (`BatchStock.php:84` #8, `ReverseWriteOffService.php:186` #10, `StockLevel.php:205` #31); P2-3 → chokepoint binding at `:1151-1180`, local-stub probe returns **violation**; P2-4 → `provablyNonNull()` `:1022`, S0 chokepoint `StockAdjustmentService.php:1808` now violation #30; P2-5 → relation fixture + `hasMany` host present.
- **Red-first evidence corroborated, not taken on trust:** re-running the fixture scan *without* the production context roots flips **exactly** the three cells the handback pastes as Red 2 (`updateQuantityWithRecordedMovement`, `incrementQuantityWithRecordedMovement`, `decrementQuantityWithRecordedMovement` → violation). The chokepoint index is load-bearing; the fixtures are not self-satisfying.
- **All 15 `not_applicable` sites audited individually** (`StockReservationService` ×6, `BatchStock::reserve/releaseReservation`, `StockAdjustmentService:559/:623`, 5 JE lifecycle updates): every one is a genuine soft hold or lifecycle update. No live write is currently mis-cleared.

---

### P2 — fix before merge

**1. `provablyNonNull()` credits four value shapes that prove nothing, including an unconditionally-null local. CONFIRMED.**
`DocumentPerActionWriteScanner.php:1022-1043` refuses only a literal `null`, a nullsafe read, and a variable that is a nullable/null-defaulted **parameter**. Everything else returns `true`. Executed probes (fixture dir scanned with the production tree as context, same wiring as the test):

| shape | verdict |
|---|---|
| `$sourceType = null; $sourceId = null; JournalEntry::create(['source_type' => $sourceType, 'source_id' => $sourceId])` | **linked** |
| `'source_id' => $this->pendingId` where `private ?string $pendingId = null` | **linked** |
| `'reference_id' => $this->maybeId()` where `maybeId(): ?string` | **linked** |
| `'source_id' => $ctx['id']` (array element) | **linked** |

The docblock (`:55-60`, `:81-88`) states the rule as "PROVABLY non-null … the row can be written unlinked on any call", and the blind-spot list (`:136-162`) covers receivers, pairing scope, key ordinals and raw SQL — **not payload-value provability**. So the reviewed contract asserts a property the code does not enforce, in the *credit* direction, which is the direction the brief itself calls worse than no guard. Failure scenario: a new JE/movement writer lands `$sourceId = null;` (or reads a `?string` property) into its payload → classified `linked` → never enters the M2 baseline → CI green forever, and the same refactor applied to an existing baselined violator turns it into a legitimate baseline *removal*. Verified **not** exploited in the live tree today (AST sweep over `app/` for linkage values that are null-assigned locals / nullable properties / nullable-return calls / array elements → zero hits), so this is a forward hole, not a present false certification. The matrix pins only the nullable-**parameter** shape (`FixtureLinkageProofWrites::journalEntryWithNullableLinkage`), i.e. exactly the one shape that is handled. Close it by inverting the default (prove, don't assume) or by naming it as blind spot E with the consequence stated — but the docblock cannot keep claiming provability.

**2. `journal_entries` MUTATE with an unresolvable payload is waved through as `not_applicable`, contradicting the scanner's own headline fail-closed claim. CONFIRMED.**
`:584-596`: for the reference-column tables the erasure test runs only `if ($payload['resolved'])`; an unresolvable payload falls through to `not_applicable`. Probes: `$entry->update($payload)` → **not_applicable**; `$entry->update(['source_id' => null] + ['status' => 'draft'])` → **not_applicable** (the array-union form *demonstrably* nulls linkage). The docblock `:26-28` states "The engine is fail-closed ON EVERY SITE IT SEES: once a write site is identified, an unresolvable payload is reported as a violation rather than waved through" — false for this branch, and the site is not merely un-baselined but invisible (`not_applicable` never reaches M2's baseline). This reopens precisely the hole the save()-erasure rule was added to close: use `update($vars)` instead of `$entry->source_id = null; save();`. Contrast `stock_levels`/`inventory_batch_stock`, where an unresolvable MUTATE correctly falls through to the pairing predicate and fails closed. No fixture pins the unresolvable-payload MUTATE cell on either reference table. Not live today (`JournalEntryController.php:106` and `GeneralLedgerService.php:3439` both pass resolvable lifecycle arrays).

---

### P3 — notes

3. **Erasure-by-assignment matches only a literal `null`.** `:1118-1137` requires `Expr\ConstFetch('null')`; `$entry->source_id = $this->nullish(); $entry->save();` → `not_applicable` (probed). Narrower sibling of finding 2; at minimum say so in rule 1.
4. **`buildRelationMap` prefilter drops `morphOne`.** `:1248` short-circuits on files lacking `hasMany`/`hasOne`/`morphMany`, but the accepted-relation list at `:1264` includes `morphOne`. A model whose only relation to a target model is `morphOne` never enters the map, so relation-mediated writes through it emit no site. No live instance found; a one-token inconsistency that silently narrows a required vocabulary item.
5. **Pairing-arm asymmetry is real but unstated.** Arm (b) (`:1080-1094`) demands the paired `stock_movements` create be itself `linked`; arm (a) (`:1151-1180`) credits any call reaching the chokepoint regardless of whether the movement it writes carries a reference — and the chokepoint's own create is baselined as violation #30 (null/null permitted by `assertReferenceLinkagePaired`). Net effect: the seven `StockAdjustmentService` level writes (`:123/:262/:393/:416/:923/:1369/:1444`) read `linked` while the movement they pair with may carry no document. Defensible (that IS "traceable to a movement"), but M2's cross-check will inherit this docblock — state the asymmetry so nobody reads `linked` as "a document exists".
6. **Handback pointer dangles.** §3.6 says "The full violation list is §5 below"; §5 is an empty placeholder deferred to M2. §4's "violations the register never covered" list is illustrative, not exhaustive — it omits `ReceiptReturnService.php:1699`, `GeneralLedgerService.php:353`, `ResetOpeningBalanceService.php:107/:137`, `UninvoicedDeliveryNoteService.php:257`, `FixOrphanedProducts.php:128` and the four `BatchStockService` sites, all of which are in the 34.
7. **`$positiveOnly` is hardcoded in the test** (`DocumentPerActionWriteGuardTest.php:254-259`) rather than derived from the scanner rules; adding a mechanism to that map silently removes the negative-control requirement for it. Visible in diff, but it is a self-weakening knob inside the liveness certificate.
8. Round-1 P3-9 (`--testsuite=Architecture` red at base) and P3-10 (positional `#<ordinal>` churn) remain correctly carried/documented (blind spot C); no regression. Fixture placement follows the house `tests/Architecture/<Name>Fixtures/` pattern (`BroadcastFixtures`, `ControllerFixtures`), and no other Architecture test scans `tests/`, so no cross-test interference.

---

### Bypasses attempted that the guard CORRECTLY refused
- `create([... ] + $extra)`, `create([..., ...$extra])`, `create([$dynamicKey => …])` → **violation** (fail closed) on both reference tables.
- Local method merely named `recordMovement()` (empty stub) → **violation** — finding-3 fix independently reproduced.
- `$this->stockAdjustmentService->getStockLevelSummary()` (chokepoint-typed receiver, non-entry-point method) + `StockLevel::create` → **violation**.
- `StockLevel::create` inside a `DB::transaction(function () …)` closure → reported under the enclosing method.
- `DB::table('stock_levels as sl')->update([...])` (alias) → **violation**; `DB::statement('UPDATE '.'stock_levels'.' SET …')` (concat-built SQL) → **violation**.
- Scanning the fixture tree with the production context removed → exactly three cells flip red (the test's context wiring is load-bearing, not decorative).
- Blind spot A confirmed honest, not understated: `foreach (iterable $levels as $level) { $level->update(...) }` and `app(StockLevel::class)->update(...)` emit **no site**, exactly as `:136-149` says.

Findings 1 and 2 are the same defect class the round-1 gate blocked on — the reviewed docblock asserting a property the code does not enforce — and this docblock is the artifact M2's baseline and cross-check inherit. Both are cheap to close (tighten, or name the residue as an explicit blind spot with its consequence). Nothing live is mis-certified today; the guard's forward guarantee is what leaks.

VERDICT: CHANGES-REQUIRED
