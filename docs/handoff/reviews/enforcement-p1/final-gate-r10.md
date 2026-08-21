# FINAL-GATE REGISTER — package p1 M3 round 10 attempt 1
accepted_sha: 30f6c215402713dd0f6c807d4a5ce18cb489c917
base_sha: e3eea67f9b3f6f79afa2613c04c81826e5852236   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 30f6c215402713dd0f6c807d4a5ce18cb489c917 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r10.md=4fa7f317d4db21328cc46b545146e807ead3f76cf017f7cd850c16187e0f2bd9   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7a5e47c2c9b32f475c8e38e646d7e5dc2befcda5c4a8904f1684b9ac9243f516
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 8
---
I have completed verification. Writing the final-gate register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement package p1, milestone M3, round 10

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.GyW342VKcV/snap`, detached at `30f6c215402713dd0f6c807d4a5ce18cb489c917` (re-asserted: `git rev-parse HEAD` → `30f6c2154`, `git status --porcelain` empty).
**Range:** `e3eea67f9b3f6f79afa2613c04c81826e5852236..30f6c215402713dd0f6c807d4a5ce18cb489c917` — 35 commits, 15 files, +5043/−26.
**Handback opened and evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r10.md` — 1339 lines, 113146 bytes, `sha256 = 4fa7f317d4db21328cc46b545146e807ead3f76cf017f7cd850c16187e0f2bd9`.

---

## 0. Content-derived evidence of inspection — the required tables

Each row count and the first/last row key below was extracted from the handback bytes, not restated from its prose.

| Handback table | Lines | Rows | First row key | Last row key |
|---|---|---|---|---|
| §1 Header | 11–19 | **7** | `Base SHA` | `Final SHA` |
| §2 M0 predicates | 30–38 | **7** | `1` | `7` |
| §3.1 What was built | 44–48 | **3** | `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php` | `apps/api/tests/Architecture/DocumentPerActionFixtures/**` |
| **§3.5 Mechanism × table fixture coverage** (brief §7.3 required) | 116–127 | **10** | `create` | `raw_sql` |
| §3.5 per-gate-round cell origin | 144–152 | **7** | `M1 initial matrix` | `**total**` |
| §3.7 M1 gate history | 182–188 | **5** | `1` | `5` |
| **§4 Baseline ↔ DPA-register cross-check** (brief §2 deliverable 4 / §7.3 required) | 313–328 | **14** | `**V1** — TestE2EGLPosting hard-deletes sealed GL` | `**S0 residue** — WeightedAverageCostService reference params still ?string` |
| §4 mapped/never-covered partition | 338–342 | **3** | `mapped to a register item or S0 residue in the table above` | *(empty label cell — the `34 ✓` total row)* |
| §4 never-covered list | 349–367 | **17** | `1` | `34` |
| **§5 Violation census — the M2 seed baseline** (brief §7.3 required) | 395–430 | **34** | `1` · `app/Console/Commands/FixOrphanedProducts.php:128` · stock_levels/create · `FixOrphanedProducts::executeCommand` | `34` · `app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` · journal_entries/delete · `RepositoryTransferService::transfer` |
| §6.1 two-phase seed topology | 436–441 | **4** | `pre` | `then` |
| §7.1 rebase-window Architecture additions | 620–626 | **2** | `OrphanedEventRatchetTest` | `ProjectorEmissionRatchetTest` |
| §8.8 rebase artifact sweep | 1029–1037 | **7** | `seed commit (§6.1, §6.3, YAML pin + m2_evidence.seed_commit)` | `Phase 4.3.7 (the round-3 CRITICAL fix…)` |
| §9 round-9 disposition | 1300–1304 | **3** | `**[Important]** save/saveQuietly/push were mapped unconditionally to …` | `**[Minor]** §2 row 7 and m0_evidence.control_manifest_verified asserted …` |

**Independent re-derivation of the census (not trusted, recomputed).** I executed the snapshot's scanner against the snapshot's `apps/api/app` tree using an external PHP-Parser autoload:

```
TOTAL SITES: 116
violation => 34 · linked => 67 · not_applicable => 15
violations=34  baseline=34
ADDED (in tree, not baseline):  []
STALE (in baseline, not tree):  []
```

The `34 / 67 / 15` census and the "violation key set identical to the checked-in baseline in both directions" claim are **TRUE at the reviewed tip**. The baseline JSON holds 34 keys, all unique, `sorted == True`, and `git rev-parse 85f0e63f3:<baseline>` == `git rev-parse HEAD:<baseline>` == `1381983d463e6c546535be907d4aa1c7ca94c597` == the YAML mirror `dpa_baseline_protected_blob`. The §3.5 cell count reproduces three ways (`grep -c` → 119, matrix literal → 119).

---

## 1. What I verified green (so the findings are read against a mostly-sound package)

- **Scope / path allowlist (brief §2 acceptance, gate-r1 M-2):** every path in `git diff --stat <base>..HEAD` is inside `apps/api/tests/Architecture/**`, `.github/workflows/ci.yml`, `docs/handoff/**`. No production path, no control-surface path. PASS.
- **CI wiring (deliverable 5).** `backend-dpa-guard` parses; `'if' in job` → **False** (no guard, as required); 8 steps, the two DPA classes run by path with an explicit non-zero-selection assertion (`grep -qE 'OK \([1-9][0-9]* test'`) — correctly closing the `failOnEmptyTestSuite` hole the brief names.
- **Aggregate membership (gate-r1 H-9).** `'backend-dpa-guard' in jobs['all-checks-pass'].needs` → **True**, with the skipped-job-semantics comment adjacent. The handback's verbatim acceptance grep reproduces byte-for-byte at this SHA: `180:`, `1281:`, `1288:`.
- **Embedded-shell validity.** I re-ran the handback's own substitute for the missing `actionlint`: `bash -n` over every `run:` block extracted from the parsed workflow → **67/67 OK**, `backend-dpa-guard` contributing **5/5**. The §7.3 claim reproduces exactly. The pin-tag extraction executes from the YAML's real bytes: `sed … | head -1 | awk '{print $1}' | tr -d "\"'"` → `[ci-pin/enforcement-p1-r1]`, and `^dpa_baseline_pin_tag:` matches exactly one line.
- **Ratchet, three directions.** Growth and stale are one assertion over the scanned key set; anti-growth reads `DPA_BASELINE_PROTECTED_BLOB` and fails closed on unset/empty, malformed hash, mirror drift, `git cat-file` non-zero, and any added key. The authority is never the YAML — the mirror is compared *to* the variable and disagreement fails. Correct per gate-r3 R3-C-1.
- **YAML field-check surface.** `max_fix_rounds: 8`, M3 `status: review`, `fix_rounds: 7` (≤ 8), `base_sha == e3eea67f9…` (the dispatch base), structured `control_manifest: {path, sha256}`, exactly one final milestone. Consistent with what the receipt-driven bridge field-checks.
- **§7.1 decision-1 arithmetic re-derived.** `ls tests/Architecture/*Test.php` → **20**; minus this package's 2, minus the 4 known-red = **14** excluded-and-green. Matches.
- **M0 substance re-verified independently.** `41fb478c2` **is** an ancestor of `e3eea67f9`; the 3C merge and reviewed SHAs resolve and chain; and `git diff <41fb478c2>..<e3eea67f9> -- StockAdjustmentService.php` is **empty**, so the S0 seam is byte-identical at the current base — `recordMovement` at `:1769`, `assertReferenceLinkagePaired` at `:1788`, exactly the anchors §2 row 5 quotes.
- **The 15 `not_applicable` sites are defensible.** I listed all 15: five are `journal_entries` hash/lifecycle updates (`AccountingService.php:506/:719/:946` fiscal_hash, `GeneralLedgerService.php:3439` posting stamp, `JournalEntryController.php:106` the V5 self-source assignment) and ten are reservation/threshold soft holds against the positive allowlist. I read each of the five and none erases linkage. The `fiscal_hash`/`chain_sequence` consequence is stated plainly in the docblock rather than hidden.

**Lens application — both-sides discipline.** Under **stock-gl-interaction**: the guard treats `stock_movements` MUTATE and DELETE as unconditional violations (dimension 9, append-only ledgers) and catches the two live post-hoc stamps — census #10 `ReverseWriteOffService::reverse:186` and #33 `ReturnScrapWriteOffService::writeOff:165` — and the GL side symmetrically (`journal_entries` DELETE always a violation, census #34 `RepositoryTransferService::transfer:105`). Document-per-action (dimension 1) is the rule the scanner encodes. The GL consequence I traced for every stock-side finding below is: none of the range's 15 files is production code — the diff writes no `stock_movements`, no `journal_entries`, no `stock_levels`, no `inventory_batch_stock` row, emits no event, and books nothing; so dimensions 2–8 have no diff surface, and I say so rather than passing them silently. Under **inventory-costing**: no WAC arithmetic, no `workingScale()` path, no movement-direction logic and no lock ordering is touched. Rule-19 float check: `grep` for `(float)`/`floatval`/`number_format` across the 15 changed files returns nothing — the scanner is pure AST/string work with no money or quantity arithmetic. Tests assert real behaviour (no `assertTrue(true)`, no mocking of the unit under test) and correctly use no database, matching the `tests/Architecture` house style. Both lenses' remaining checklist items are inapplicable-by-construction to a guard-only diff, which the package's DO-NOT-TOUCH scope requires.

---

## 2. Findings

### [Important] `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php:886-906` — the round-9 create-by-save fix does not cover a chained `Model::make(…)->save()` receiver, so an unlinked `journal_entries` INSERT still classifies `not_applicable` and passes the ratchet green

`receiverIsUnpersistedModel()` walks the receiver chain with a loop that unwraps **only** `Expr\MethodCall` / `Expr\NullsafeMethodCall` (`:893-895`), then accepts the base if it is `Expr\New_` (`:897`) or a `Expr\Variable` recorded in `$unpersistedVars` (`:903-905`). `$unpersistedVars` is populated in `buildVarTypes()` (`:2246-2264`) **only from `Expr\Assign` nodes** whose RHS is `new <Model>` or `<Model>::make(…)`. A `make()` that is the direct receiver rather than an assignment RHS therefore never reaches either arm: the walk terminates on an `Expr\StaticCall`, which the loop does not unwrap and neither arm accepts, so `receiverIsUnpersistedModel()` returns `false`, `$writeClass` stays `MUTATE`, and the site falls through `classifyWrite()` to the terminal branch at `:825`.

Meanwhile `resolveChainTable()` *does* resolve that same receiver: `:1042-1052` returns `tableForModel(resolveName($cursor->class))` for a `StaticCall` base. So the site is fully visible — it is not a blind-spot-A invisibility — it is **seen and then classified out of contract**.

The docblock states the rule without this restriction (`:73-79`: "`save()`/`saveQuietly()`/`push()` on a receiver that is statically an UNPERSISTED model (`new JournalEntry` or `JournalEntry::make(...)` in the same scope) is an INSERT"), and the emitted reason string asserts a fact that is false for exactly this shape: *"the receiver is not a `new`/`make()` model in this scope"* (`:825`). It is a `make()` model. This is the documentation-overstates-code pattern the guard test's own docblock (`DocumentPerActionWriteGuardTest.php:305-315`) records the package blocking on three times.

**Failure scenario, executed against the snapshot's scanner, not reasoned about.** Probe file scanned with the production tree supplied as context, exactly as `scanFixtures()` does:

```
a_chained_make_then_save   JournalEntry::make([...])->save();                 -> not_applicable
b_var_make_then_save       $e = JournalEntry::make([...]); $e->save();        -> violation
c_chained_new_then_save    (new JournalEntry([...]))->save();                 -> violation
d_chained_make_fill_save   JournalEntry::make([...])->fill([...])->save();    -> not_applicable
e_firstOrNew_then_save     $e = JournalEntry::firstOrNew([...]); $e->save();  -> not_applicable
f_chained_make_save_movement  StockMovement::make([...])->save();             -> violation
```

Rows `a` and `d` are new, unlinked `journal_entries` rows — an INSERT with no `source_type`/`source_id` — that the guard reports as *not in contract*. They are absent from the baseline, so growth passes; the key set is unchanged, so anti-growth passes; nothing is stale. **CI is green and the row lands.** That is verbatim the regrowth the package exists to prevent, reached by a sibling of the verb round 9 closed. Row `f` confirms the round-9 scoping argument holds for `stock_movements` (unconditional MUTATE violation), so the exposure is confined to `journal_entries` — exactly as the ruling reasoned, which is why the gap sits precisely where the ruling's own remedy is incomplete.

Row `e` is the adjacent shape: `firstOrNew()` returns an unpersisted model when no row matches, and the subsequent `save()` is then an INSERT. It is not in the `new`/`make` recognition set at all.

**Not a disclosed residue.** `grep` over the handback for `chained`, `make()->`, `without a variable`, `bound to a variable`, `firstOrNew` returns **zero hits**; the scanner's blind-spot list (A–H) names none of these. Blind spot A covers receivers that cannot be resolved — this receiver *is* resolved. So this is an undisclosed hole, not an accepted one.

**No live exposure today, which is why this is Important and not Critical.** `grep -rnE "(JournalEntry|StockMovement|StockLevel|BatchStock)::(make|firstOrNew)\(" app/` and `grep -rnE "::(make|firstOrNew)\([^)]*\)\s*->\s*(save|saveQuietly|push)\("` both return nothing at the reviewed tip. The census, the baseline and the seed pin are therefore all correct as they stand — a fix here is a detector change with **no re-seed and no re-pin**, provided the fix adds no live violation (re-run the census to confirm 34/67/15 stays byte-identical, as round 9 did).

**Suggested fix.** In `receiverIsUnpersistedModel()`, after the MethodCall walk, additionally accept a base `Expr\StaticCall` whose `class` resolves to one of `TABLE_MODELS` and whose method name is `make` — the same predicate `buildVarTypes()` already applies at `:2256-2260`, lifted into a shared helper so the two cannot drift. Pin both chained shapes (`Model::make(…)->save()` and `Model::make(…)->fill(…)->save()`) as RED-first fixtures alongside the four existing probes, correct the `:825` reason string, and decide `firstOrNew` explicitly — either recognise it (fail-closed, consistent with the `updateOrInsert` precedent at `:374-376`) or name it in the blind-spot list. Note that `journal_entries_save_pins_both_semantics()` (`GuardTest:356-380`) cannot catch this class: it asserts only that *at least one* insert pin and *one* mutate pin exist, which the four current probes already satisfy — so the new pins must be added explicitly.

---

### [Minor] handback `§3.5`, line 142 — the cell-count caption contradicts the table it introduces

Line 142 reads "Per gate round, which also sums to **115**:", immediately above a table whose rows are `95 + 7 + 6 + 6 + 1 + 4` and whose total row states **119** — the figure asserted four lines earlier and independently confirmed by me (`grep -c "^            \['mechanism' =>"` → 119). `115` is the pre-round-9 total: the round-9 row (`create-by-save ×4`) was appended and the total updated, but the caption was not substituted. The §9 round-6 disposition at line 1251 shows this exact sentence being repaired once already ("plus a per-round breakdown that sums to 115"), which is what makes the residue traceable rather than incidental. Fix: substitute `115` → `119` in the caption.

### [Minor] handback `§7.6`, line 857 — the section's closing prose re-asserts the unqualified PHPStan claim its own evidence block just qualified

The evidence block at lines 838–846 corrects the record honestly: `[OK] No errors, EXCEPT one advisory` (`larastan.noModelMake` on `FixtureJournalEntryWrites.php:190`), and explicitly notes *"Previous rounds reported '0 errors' on this command; that claim is now qualified rather than quietly restated."* Eleven lines later the same section's summary paragraph states "PHPStan level 8 on every new/changed path (**0 errors**)". The qualification was added beside the superseded sentence instead of replacing it — the append-vs-substitute class this series has fired on six times. The closing commit freezes this file, so it should read as qualified in both places.

### [Minor] `docs/handoff/progress/enforcement-p1.progress.yaml:185-194` — five `m0_evidence` fields still evidence the superseded base `41fb478c2` while `base_sha` is `e3eea67f9`, and a sixth sibling field was re-derived

`base_sha` is `e3eea67f9…` (`:41`). `m0_evidence.base_sha_verified` (`:185`), `ancestry_merge_to_base` (`:188`), `ancestry_reviewed_to_base` (`:190`), `s0_seam_at_base_sha` (`:193`) and `fresh_worktree` (`:194`) all name `41fb478c2`, and `updated:` (`:184`) is `41fb478c2`. The field name `s0_seam_at_base_sha` asserts the check was performed at the pinned base; the value shows it was performed at a different commit. The handback's §2 row 2 carries the same superseded command text.

**Substantively the predicates hold and I confirmed each one myself** — `41fb478c2` is an ancestor of `e3eea67f9`, so both ancestry chains are transitively satisfied, and `StockAdjustmentService.php` is byte-identical across that span, so the seam anchors §2 row 5 quotes (`:1769`, `:1784`, `:1785`, `:1788`) are correct *at the current base*. This is therefore a record-precision defect, not a false precondition. It is raised because the round-9 Minor already re-derived exactly one member of this field group (`control_manifest_verified`, `:197`, now correctly naming `e3eea67f9`) and left the other five — the named instance closed, the class left open. Fix: re-derive the five fields (and `updated:`) at `e3eea67f9`, or relabel them explicitly as historical beside a current-base line, matching the treatment `control_manifest_verified` already received.

---

## 3. What to fix before merge

Close the chained `Model::make(…)->save()` / `Model::make(…)->fill(…)->save()` create-by-save hole in `receiverIsUnpersistedModel()` with RED-first fixtures for both shapes and an explicit `firstOrNew` ruling, correct the `:825` reason string, and re-run the census to confirm `34/67/15` and the violation key set stay byte-identical so no re-seed or re-pin is triggered; then substitute the three stale record strings (§3.5 caption `115`→`119`, §7.6's unqualified "0 errors", and the five `m0_evidence` fields still pinned at `41fb478c2`).

Lens outcome — **stock-gl-interaction**: spec ❌ / quality CHANGES-REQUESTED (the document-per-action dimension-1 contract is under-enforced on the GL side of the seam). Lens outcome — **inventory-costing**: spec ✅ / quality APPROVED (no costing, movement-direction, scale or lock-order surface in the range; the stock-side rules are correctly encoded and independently reproduced).

VERDICT: CHANGES-REQUIRED
