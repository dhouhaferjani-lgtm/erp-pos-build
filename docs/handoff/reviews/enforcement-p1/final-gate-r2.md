# FINAL-GATE REGISTER — package p1 M3 round 2 attempt 1
accepted_sha: fbdc4e99210fd4390b45be86a8b3c39bf355f340
base_sha: 41fb478c21ae010568036aa66ffaef726ec63f09   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ fbdc4e99210fd4390b45be86a8b3c39bf355f340 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r2.md=3f30aefa5d27c9ec0f1f938ff43d8b9f20a19396dcbcb6a6a04ba8264dfe06ef   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
I have completed the review. Independent verification is done; here is the register.

---

# FINAL-GATE ADVERSARIAL REGISTER — enforcement package P1, milestone M3, round 2

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.SO2CUu717h/snap`, detached at `fbdc4e99210fd4390b45be86a8b3c39bf355f340` (A′)
**Range:** `41fb478c21ae010568036aa66ffaef726ec63f09..fbdc4e99210fd4390b45be86a8b3c39bf355f340` — 22 commits, 15 files, +4785/−24
**Handback evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r2.md` — 859 lines, `sha256 = 3f30aefa5d27c9ec0f1f938ff43d8b9f20a19396dcbcb6a6a04ba8264dfe06ef`
**Lenses applied in full:** `stock-gl-interaction` (`.claude/agents/stock-gl-interaction-reviewer.md`), `inventory-costing` (`.claude/agents/inventory-costing-reviewer.md`)
**Reviewer note on format:** both lens contracts ask for their own `VERDICT:` line. The brief (gate-r9 R9-H-4 / gate-r10 R10-H-3) requires EXACTLY ONE `VERDICT:` line, string-equal, as the final non-empty line. Lens conclusions are therefore rendered below without that token.

---

## 1. Content-derived evidence of inspection — the handback's census/classification tables

Row counts with first and last row keys, derived by parsing the file (not by reading its own claims):

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §2 M0 predicate table | **7** | `1` (`git rev-parse --verify` on base / 3C-merge / 3C-reviewed) | `7` (`commit_series`, `ratchet_trust_model_ack`, `control_manifest` non-null) |
| §3.5 mechanism × table fixture coverage | **10** | `create` | `raw_sql` |
| §3.5 per-round cell origin | **6** | `M1 initial matrix` | `**total**` (115) |
| §3.7 M1 finding disposition | **12** | `P1-1` | `P3-12` |
| §4 baseline ↔ DPA-register cross-check | **14** | `**V1** — TestE2EGLPosting hard-deletes sealed GL` | `**S0 residue** — WeightedAverageCostService reference params still ?string` |
| §4 partition (mapped / never-covered / total) | **3** | `mapped to a register item or S0 residue in the table above` | (total row, `34 ✓`) |
| §4 never-covered list | **17** rows expanding to **21** census ids | `1` (`FixOrphanedProducts.php:128`) | `34` (`RepositoryTransferService.php:105`) |
| §5 violation census (the M2 seed baseline) | **34** | `1` — `app/Console/Commands/FixOrphanedProducts.php:128` · stock_levels · create · `FixOrphanedProducts::executeCommand` | `34` — `app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` · journal_entries · delete · `RepositoryTransferService::transfer` |
| §9 package gate history | **4** | `M0` | `M3` |

**Partition arithmetic re-derived, not trusted:** mapped `{3, 12, 19, 20–28, 33}` = 13; never-covered `{1,2,4–11,13–18,29–32,34}` = 21; union = exactly `1..34`, intersection empty. **13 + 21 = 34 ✓.** The final-gate round-1 correction is sound.

---

## 2. Independent reproduction (I ran the scanner myself, outside the candidate harness)

I installed `nikic/php-parser` v5.8.0 in a neutral temp dir and executed `DocumentPerActionWriteScanner` directly against the sealed snapshot's `apps/api/app` — no Laravel, no candidate test harness.

- **Census: 116 sites — 34 violation / 67 linked / 15 not_applicable, 116 unique keys, 25 files emitting, 19 files carrying violations.** Byte-for-byte the numbers §3.6/§9 claim.
- **The 34 violation keys are set-identical to the checked-in baseline.** Re-encoding my own scan with the writer's exact serialization (`sort(SORT_STRING)` + `JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES` + `\n`) yields a file **byte-identical** to `document-per-action-baseline.json` (`diff` clean).
- **Pin chain verified in git:** `git rev-parse ff5642f87:…/document-per-action-baseline.json` → `1381983d463e6c546535be907d4aa1c7ca94c597` == the YAML mirror `dpa_baseline_protected_blob` == `HEAD:` blob. Seed commit `ff5642f87` contains **exactly one file**; metadata commit `9946b10b9` is distinct and carries the mirror pins + checker. The gate-r3 R3-H-4 two-commit topology holds, and the M2 review (registers `M2-round1/2`) followed both.
- **All four guard gates re-executed independently** (matrix parsed from source, scanner run against the fixtures with `app/` as context root): `fixture_matrix_is_classified_exactly_as_the_rules_state` **PASS over 115 real cell comparisons**; `every_mechanism_is_pinned_for_every_table` **PASS**; `every_cell_with_a_linked_form_pins_a_negative_case` **PASS**; `the_rule_surface_agrees_with_the_pinned_classifications` **PASS**. **115 cells, 115 unique `(class, method, table, mechanism)`** — §3.5's corrected figure confirmed by my own count, and the gates are non-vacuous (they compare against 123 emitted fixture sites).
- **Mechanism vocabulary is closed:** the scanner's write-method map collapses 30 method names into exactly the 10 canonical mechanisms the test's `MECHANISMS` list enumerates, plus `query_builder`/`raw_sql`. No mechanism the scanner can emit escapes the coverage gate.
- **Liveness probed with 26 planted write shapes.** Correctly reported: `Model::create`, `::query()->create`, `where()->update`, `query()->where()->update`, `first()->delete`, `newQuery()->update`, `whereKey()->delete`, `::insert`, `DB::table()->insert/upsert`, `factory()->create`, property-nulling `save()`, `findOrFail()->increment`, `destroy()`, `DB::statement('DELETE FROM journal_entries…')`, a **trait** method, a write inside a closure body, and `reference_id => $this->maybe(): ?string` (correctly a violation via `nullAdmitting()`). Correctly credited: `updateOrCreate` with both linkage columns. **Invisible** shapes were exactly and only `app(Model::class)`, an untyped-return collaborator, an untyped property, an array element, and `DB::table($var)` — every one inside the enumerated blind spot A/D. The docblock's blind-spot list is accurate, not decorative.

---

## 3. Lens contracts, applied

### `stock-gl-interaction`
The diff touches **no production code** — 15 paths, all in `apps/api/tests/Architecture/**`, `.github/workflows/ci.yml`, `docs/handoff/**`. Both-sides discipline applied to what the change actually does:

1. **Document-per-action (dim 1)** — this is the deliverable. Rule 2 requires paired `reference_type`/`reference_id` on `stock_movements` CREATE and makes MUTATE/DELETE unconditional violations; rules 3/4 replace the absent reference column with the movement-pairing predicate bound to the real chokepoint. I verified the discrimination is semantic, not nominal: `StockAdjustmentService::recordBatchMovement` (`:1900-1932`) is `linked` because it takes non-nullable `string $movementId` and unconditionally writes `'movement_id' => $movementId` into `BatchMovement::create`, while `BatchStockService::recordBatchMovement` (`:312-341`) is **violation #6/#7** because its `?string $movementId` is only conditionally merged. That is the correct call, made from the AST.
2. **GL consequence / COGS / event emission / single-writer / WAC / batch invariant / cash lane (dims 2–8)** — no production path is altered, so none can regress. The guard *supports* dim 9 (append-only) for `stock_movements` (`markMovementAsTransfer` → #19, `ReverseWriteOffService::reverse` `$inverse->save()` → #10, `ReturnScrapWriteOffService::writeOff` → #33 all correctly caught) but **deliberately exempts `journal_entries` MUTATE**, including `fiscal_hash`/`previous_hash`/`chain_sequence` rewrites. I verified all five JE `not_applicable` sites (`AccountingService:506/719/946`, `GeneralLedgerService:3439` — the sealing update, `JournalEntryController:106`) and they are exactly that class. This is a **documented scope boundary**, stated in the scanner docblock rule 1 "STATED PLAINLY", in handback §3.2, and adjudicated as M1 finding P3-11. Within the brief's contract (linkage presence, not chain integrity). Recorded, not a finding.
3. **Float on the seam (dim 10)** — zero `(float)`, `floatval`, `(double)`, `parseFloat` anywhere in the 15 added/changed files. Clean.
4. **Test quality** — no `assertTrue(true)`, no mocking of the thing under test, no `RefreshDatabase`, no DB. The assertions are real: I reproduced 115 genuine comparisons in the guard and the ratchet's three directions in code.

**stock-gl-interaction conclusion: spec met; quality — changes requested (documentation-scope only, §4 below).**

### `inventory-costing`
1. **WAC bias / working scale / lock order** — untouched. `WeightedAverageCostService`'s 9 sites (#20–#28) are enumerated and baselined, never edited; DO-NOT-TOUCH honoured.
2. **Exactly-once decrement, movement sign, opening balances, FEFO** — no behavioural change. The guard correctly flags `OpeningBalancePostingService::post` (#12/#13/#14) and `ResetOpeningBalanceService::reset` (#15/#16), and I confirmed #15 is a real `StockMovement::create` with no `reference_type`/`reference_id` at `:107`.
3. **Precision checklist** — no money/quantity arithmetic introduced; no FormRequest, no frontend surface. `bcmath` untouched.
4. **SQLite-masking caution** — not applicable: pure AST, no database, no aggregate assertions.
5. **Brief-named must-cover mechanisms** — `StockLevel::firstOrCreate` (the zero-valued chokepoint helper) is present as **violation #29** at `StockAdjustmentService.php:1618`, and `BatchStock::firstOrCreate` is present at both `:1916` (linked, correctly) and `BatchStockService.php:94/:325` (violations). Both brief-named live mechanisms are seen, not missed.

**inventory-costing conclusion: spec met; quality — changes requested (documentation-scope only).**

---

## 4. Findings

### [Important] `HANDBACK-…-r2.md` §7.4 — the scope proof does not reproduce at the tip it certifies

`§7.4` is a **mandated acceptance-block item** (brief §2: `git diff --stat <base_sha>..HEAD` plus the path-allowlist check; gate-r1 M-2 exists precisely because a vacuous scope proof was once accepted). The block is presented under a `$ git diff --stat 41fb478c2..HEAD` prompt but does not reproduce at A′:

| §7.4 claims | Actual at `fbdc4e992` |
|---|---|
| `15 files changed, 4761 insertions(+), 18 deletions(-)` | `15 files changed, 4785 insertions(+), 24 deletions(-)` |
| `DocumentPerActionWriteGuardTest.php \| 389` | `393` |
| `enforcement-p1.progress.yaml \| 63 +-` | `89 +-` |
| 8 stat lines, fixtures collapsed to `DocumentPerActionFixtures/*.php \| 1428` | 15 stat lines, one per file |

The block attests the **pre-fix-round** tree. The two commits that changed exactly these numbers are `67ba450f2` (the round-1 fix) and `fbdc4e992` (the fix-round record) — i.e. the r2 handback was revised *for* the final-gate fix round (§3.1, §3.5, §4 were all rewritten) but the evidence block that certifies "the diff contains only what I say it contains" was not re-derived when the diff changed. That is the same defect class this package's gates blocked on five times already (M2 gate: §6.3 anchors; round-4: §3.6 stale; final round 1: §3.5 "114", §3.1 fixture count).

**Consequence is bounded and I verified it directly:** the delta is entirely inside the allowlist, so the *conclusion* stands. I independently re-ran the check — all 15 paths are within `apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`; **0 allowlist violations; no control-file hit** (`adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*-reviewer.md`, any `*control-manifest*` surrogate — none touched); **no production code**; **no `contents: write` grant** in the workflow diff. But these are *my* numbers, not the handback's, and the handback is the artifact the owner digests into the pin-tag annotation and P3-M0 later re-hashes.

**Fix:** re-derive §7.4 verbatim at the handed-over tip — full 15-line `git diff --stat`, the true totals, and the allowlist/control/production/permissions lines re-run at A′. No code change.

### [Minor] §3.7 — M1 gate-history row 5 still reads *(pending)*

`| 5 | *(pending)* | | |`, while §9's own gate history, the progress YAML (`M1.last_verdict: ACCEPT`, `verdict: …/M1-round5.md`) and the present register file `M1-round5.md` all record M1 ACCEPT at round 5. §3.5 also itemises "round 5 — `setRawAttributes()` erasure ×1". Internal inconsistency in a table a reader uses to reconstruct the gate trail.

**Fix:** fill row 5 (`M1-round5.md` · ACCEPT · 0×P1/0×P2), matching §9.

### [Minor] §8.3 — the disclosed residual understates its own mitigation

§8.3 states that deleting `DocumentPerActionBaselineRatchetTest.php` "leaves the Architecture suite green with no ratchet". True of `--testsuite=Architecture`, but **the M3 job as wired does not run the suite** — it runs the class **by path** and then asserts `grep -qE 'OK \([1-9][0-9]* test'` with `set -o pipefail`. Deleting the file makes that step fail on both legs. The residual that actually survives is the one §8.3's next sentence names correctly (deleting the *job* from `ci.yml`, brief §6 F-8). Errs on the safe side, but the sentence as written would lead a parent to over-scope the mitigation work.

**Fix:** one clause noting the by-path invocation + nonzero-selection assert closes the file-deletion half; the `ci.yml` half is the F-8 residual.

---

## 5. Advisory to the parent/owner (not a finding, not the executor's to prove)

`backend-dpa-guard` is the **first job in this workflow to run PHPUnit without a job-level `env:` block, without `.env`/`.env.testing`, and without the `pdo, pdo_pgsql, redis` extensions** every other backend job installs. My reading says it will boot — `phpunit.xml` supplies `APP_KEY`, `APP_ENV`, `BROADCAST_CONNECTION=null`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`; Laravel's env loader is `safeLoad()`; the two classes open no database — and the `Architecture` testsuite has **never executed in any CI lane**, so this is untested on a runner. Brief §5 assigns exactly this to the owner's mandatory pre-promotion `workflow_dispatch` gate, which §7.2/`m3_evidence.dispatch_must_show` name precisely (job `backend-dpa-guard`, the two named steps, green, head == A). Also confirmed correct and re-verified by me: the pin-tag `sed` extraction resolves (`dpa_baseline_pin_tag` is at column 0, line 67; the doc comment at `:51` starts with `#` and the milestone-title occurrence at `:189` is beaten by `head -1`); `ci.yml` anchors `180` / `1192` / `1198` match the handback and YAML exactly; the AST cache is in-memory only (`private array $astCache`) and writes nothing to disk, so it cannot dirty the handover seal.

The two disclosed operational consequences — §8.1 (a remediation that renumbers a baseline ordinal produces a red **no contributor-side edit can clear**, requiring an owner re-pin) and §8.2 (the re-pin mirror≠variable red window) — are real, correctly reasoned, and correctly routed as parent runbook items rather than decided unilaterally. §3.8/§8.5 (`RepositoryTransferService.php:105`, a live hard `delete()` of a `JournalEntry` draft the DPA register never covered) and §8.6 (`database/**` outside the scan root) are correctly raised as parent tickets under the guard-only scope bar. All are handled the way the brief requires.

---

## 6. Disposition

The guard itself is sound and I could not break it: the census is independently reproducible, the baseline is exactly the census, the pin chain is intact, the three ratchet directions are correctly implemented against an owner-held ceiling the executor provably cannot write, all four liveness gates are non-vacuous, the blind-spot disclosure survived adversarial probing, scope and control-file preflight are clean, and no production code was touched. Nothing here justifies blocking on substance.

What blocks is narrow and cheap: the acceptance-evidence block whose whole purpose is to certify the diff was not re-derived when the diff changed in the very fix round this handback documents — the sixth firing of the class this package's own gates closed five times. One scoped documentation fix round (`fix_rounds` 1 → 2 of 5), no re-seed, no re-pin, no baseline edit, no code change.

VERDICT: CHANGES-REQUIRED
