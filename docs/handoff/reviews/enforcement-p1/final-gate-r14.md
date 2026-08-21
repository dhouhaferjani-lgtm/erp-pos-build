# FINAL-GATE REGISTER — package p1 M3 round 14 attempt 2
accepted_sha: e55a1c8aee706237a3222485b74f72e71b274423
base_sha: 82a032e04e1e0a42d67503fcf2bf91095826e7f8   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ e55a1c8aee706237a3222485b74f72e71b274423 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r14a2.md=b02aeb18510ba1d2edfd2cb437a5c11fd4dbd8af646ac7b842ae852a9f5a73b8   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7d53797b1849b6adeb2fcb39789935a48fc32a6f4804a9833d4bb8a78f1aa2b7
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 10
---
I have completed the review. Independent re-execution of the scanner, the 133-cell matrix gate, and 23 planted adversarial probes all reproduce the record's claims.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 / M3, round 14

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.tcuU2aaOfS/snap`, detached at `e55a1c8aee706237a3222485b74f72e71b274423` (= A).
**Range:** `82a032e04e1e0a42d67503fcf2bf91095826e7f8..e55a1c8aee706237a3222485b74f72e71b274423` — 42 commits, 17 files, +5507/−26. `BASE` is a strict ancestor of A; `BASE != A`.
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r14a2.md` — 1710 lines, 151497 bytes, `sha256 = b02aeb18510ba1d2edfd2cb437a5c11fd4dbd8af646ac7b842ae852a9f5a73b8`.
**Lenses applied in full:** `stock-gl-interaction`, `inventory-costing`.

## 1. Census / classification tables — content-derived evidence of inspection

Row counts and boundary keys **derived by parsing the handback**, then cross-verified against the sealed snapshot.

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §3.5 Mechanism × table fixture coverage (L116) | **10** mechanism rows × 4 table cols | `create` | `raw_sql` |
| §3.5 per-gate-round provenance (L144) | **8** (7 origins + total) | `M1 initial matrix` = 95 | `**total**` = 133 |
| §4 Baseline ↔ DPA-register cross-check (L314) | **14** | `V1` — `TestE2EGLPosting` hard-deletes sealed GL | `S0 residue` — `WeightedAverageCostService` reference params still `?string` |
| §4 partition arithmetic (L339) | **3** | `mapped to a register item or S0 residue in the table above` = 13 | *(unkeyed total row)* = **34 ✓** |
| §4 never-covered-by-register (L350) | **17** rows spanning **21** census ids | `1` — `FixOrphanedProducts.php:128` | `34` — `RepositoryTransferService.php:105` |
| §5 Violation census — M2 seed baseline (L396) | **34** | `1` · `FixOrphanedProducts.php:128` · stock_levels · create · `FixOrphanedProducts::executeCommand` | `34` · `RepositoryTransferService.php:105` · journal_entries · delete · `RepositoryTransferService::transfer` |

**Independently reproduced, not accepted on assertion:**

- **Scanner re-run from the sealed snapshot** (snapshot's own scanner class, snapshot's `app/`): `{"violation":34,"linked":67,"not_applicable":15}` — **exactly the claimed 34/67/15**. Diff against the checked-in baseline: **0 added, 0 stale**, both directions.
- §5 ↔ `document-per-action-baseline.json` is a **1:1 bijection** on (file, class::function, table, mechanism): census-only `∅`, baseline-only `∅`.
- **All 34/34 `file:line` anchors resolve to exactly the claimed mechanism** — `#10 ReverseWriteOffService.php:186` → `$inverse->save();`; `#18 StockThresholdService.php:46` → `DB::table('stock_levels')->insert([`; `#29 StockAdjustmentService.php:1618` → `return StockLevel::firstOrCreate(`; `#34 RepositoryTransferService.php:105` → `$draft->delete();`. Zero stale anchors.
- §3.5's **133** reproduces three ways: `grep -c` → 133; parsed `fixtureMatrix()` rows → 133; per-round sum 95+7+6+6+1+4+14 → 133.
- §4 arithmetic: 13 mapped + 21 never-covered = 34 ✓ (17 table rows carry 21 ids because four rows fold ranges).

## 2. Round-13's blocking finding — RESOLVED

The r13 CRITICAL (pin tag `ci-pin/enforcement-p1-r1` already consumed at the non-ancestor `d65244fe2`) is closed correctly and verified live:

```
git tag -l 'ci-pin/enforcement-p1-*'        -> ci-pin/enforcement-p1-r1   (only)
git rev-parse ci-pin/…-r1^{commit}          -> d65244fe2…  ; is-ancestor of A -> NO
YAML dpa_baseline_pin_tag                   -> ci-pin/enforcement-p1-r2
```
`n = 1 + highest existing = 2` — correct under R5-C-2; `r1` neither deleted nor moved nor reused. The reallocation landed in a dedicated metadata commit (`2bcb04536`), and I re-ran **the workflow step's own extraction against the real YAML bytes**: `PIN_TAG=[ci-pin/enforcement-p1-r2]`. All four r13 notes are folded (stale `28c84edb3` comment rewritten; ceremony narrative corrected to STEP 3 reached; deliverable-5 scoping **PARENT-RATIFIED** in `m3_evidence.suite_scoping`; burn-down item 4 left OPEN and ticketed).

## 3. What I verified and found sound

**Control plane.** Receipt `base_sha` = YAML `base_sha` = range base = `82a032e04`; `manifest_sha256 7d53797b18…` matches the live manifest **and** the manifest blob at base **and** the YAML `control_manifest` mirror. All four projection fields agree (`progress_path`, `final_milestone: M3`, `lenses`, `max_fix_rounds: "10"`). Exactly one final milestone at `status: review`; `fix_rounds 10 ≤ 10`. No `*control-manifest*` surrogate in `base..A`.

**Scope / preflight.** All 17 paths inside `apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`. **Zero `apps/api/app/`, `apps/web/`, `apps/pos/` paths — this is guard-only, as ruled.** No control file touched. No `permissions:`/`contents: write` grant in the `ci.yml` diff (R6-H-5 owner check passes).

**M0.** 3C ancestry holds on both hops (`d07868cca → 1e8c0fa03 → 82a032e04`). S0 seam re-verified at the **exact** `dpa_3c_merge_sha` and at base: `?StockMovementReferenceType $referenceType = null` / `?string $referenceId = null` / `assertReferenceLinkagePaired(...)`.

**Two-phase pinned baseline (R3-H-4 / R4-H-3).** Seed `9cab548f8` contains **exactly one file**; metadata `6ced0d514` is its **direct first-parent child** (`6ced0d514^ == 9cab548f8`). Blob identity exact across all surfaces: seed blob = A's blob = YAML mirror = `1381983d463e6c546535be907d4aa1c7ca94c597`.

**Ratchet.** Three directions present and genuinely fail-closed on unset/empty variable, malformed hash, unreadable blob, **mirror drift**, and any added key. `mirrorPin()` returning `null` still fails the comparison — no silent skip. Authority is the repo variable, never the YAML, never a branch name.

**CI wiring.** `backend-dpa-guard` present, **no `if:` key** (confirmed by YAML parse), member of `all-checks-pass` `needs`. `on:` graph is exactly `{push: [main], pull_request: [main, dev], workflow_dispatch}` — matching the brief's event-graph statement. **67/67 `run:` blocks across the whole workflow pass `bash -n`** (the r3 CRITICAL class stays closed). Nonzero-selected-test assertion on both steps under `set -o pipefail`. `--write-baseline` appears nowhere in `ci.yml` — bootstrap-only, as specified.

**Guard liveness — independently re-executed.** I reproduced the matrix gate outside the harness: parsed all **133** pinned cells, live-scanned the fixtures with the production tree as context, compared classifications → **0 failures**. The 8 unpinned fixture sites are the incidental linked `StockMovement::create` companions inside the level-table negatives — expected by construction (pairing arm (b)), not a coverage gap. The gate is genuinely non-vacuous: it runs the real scanner and compares.

**Adversarial probes — 23 planted shapes, all classified correctly and conservatively:** trait method, enum method, abstract class, `Model::query()->create`, builder erasure `where()->update(['source_id' => null])`, `DB::table()->insert`, create-by-save (`new` / property-assign / `fill` / `make`), write inside a closure, `forceCreate`, `withoutEvents(fn() => create())`, relation-mediated `$doc->journalEntries()->create()`, typed-property `update`, `destroy`, `truncate`, `restore` (correctly `not_applicable` per the stated journal_entries lifecycle exemption), **aliased table** `DB::table('stock_levels as sl')->update`, **lowercase raw SQL**, **heredoc raw SQL**, `->when()` chains, concatenated table names, `upsert` (fails closed as blind spot D2 states), and a properly linked create (correctly `linked`).

**Scanner constants vs. live models.** All four `TABLE_MODELS` FQCNs and `$table` values exact. `source_type`/`source_id` and `reference_type`/`reference_id` present in `$fillable`. `StockLevel`/`BatchStock` carry **zero** reference columns, correctly forcing the movement-pairing predicate. `linkedFormExists()` agrees with the docblock on every pair: `raw_sql` → no linked form anywhere; `stock_movements` → linked form only for CREATE-class (MUTATE/DELETE always violation — **lens dim. 9, append-only ledger, enforced**); `journal_entries` → no linked form for `delete`/`increment`/`decrement`.

**`mayMatter()` is a cache heuristic, not a skip filter** — `parseFile()` returns `$stmts` regardless, so no file is silently excluded from scanning. Parse errors throw rather than being swallowed.

**Lens checklists.** Rule 19: **zero** `(float)`, `floatval`, `parseFloat`, `number_format` anywhere in the new code. Test quality: no `assertTrue(true)`, no `RefreshDatabase`, no Mockery/`createMock`, no DB — nothing mocks the thing under test. Both CI-run classes carry real assertions (9 and 12). All other lens dimensions (GL-exactly-once, COGS-at-exit, event emission, single-writer, WAC divisor basis, batch invariant, cash lane, opening balances, lock order, scale-resolver injection, FormRequest ceilings, frontend inputs) are **not reachable by this diff** — it adds no production code — and I confirmed that rather than assuming it.

The blind-spot disclosure (A–J + D2), the named re-pin trigger, the candidate-deletable-detector note, the `§3.8` live `journal_entries` row-delete finding raised *for* the parent, and the scan-root ruling raised as a ticket rather than decided remain unusually honest work.

## 4. Named notes (record class — ship with ACCEPT, no blocker)

1. **Undisclosed marginal resolution gap: schema-qualified builder tables.** `DB::table('public.journal_entries')->insert(...)` emits **no site** (every other name shape I probed — alias, concatenation, heredoc, lowercase — is caught). Grep of `app/` confirms **zero live instances**, and this sits squarely inside the already-disclosed blind-spot family A/D ("receiver resolution is not total… adding a resolution path is the way to shrink this list"). It belongs as a fifth item on `docs/handoff/TICKET-dpa-scanner-depth-burndown-2026-08-20.md`, not in this gate — closing it is a scanner change and therefore a re-seed event.
2. **`fixtureKey()` pins ordinal `#1` only.** A fixture method containing two same-(table, mechanism) writes would leave the second unpinned by the matrix gate. No current fixture does; worth a line in the ticket's standing note.
3. **Round-13's own register said the never-covered table had "18 rows spanning 21 census ids."** The true row count is **17** (21 ids; four rows fold ranges). The handback never asserts a row count there and its 21/13/34 partition is correct — the slip was the prior register's, and it is recorded here so the next reader does not inherit it.
4. **Deliverable-5 by-path scoping** is now parent-ratified with reasoning; **burn-down item 4** (nonzero-count vs expected-count) correctly remains OPEN.

---

The package holds on every axis I could mechanise independently rather than read: the scanner re-runs to the identical 34/67/15 census, the baseline is a verified bijection whose 34 anchors all resolve to the claimed mechanism, the 133-cell liveness matrix reproduces with zero failures, 23 planted evasion shapes are all caught or conservatively failed closed, the ratchet fails closed on every degenerate input, the CI job is syntactically executable and correctly aggregated on the true event graph, and the one control-plane defect that blocked round 13 is fixed and re-derived live. The only new observation is a marginal, non-live resolution gap of the family the package already discloses and tickets.

VERDICT: ACCEPT
