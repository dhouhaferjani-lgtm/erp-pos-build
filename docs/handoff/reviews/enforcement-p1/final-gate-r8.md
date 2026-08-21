# FINAL-GATE REGISTER — package p1 M3 round 8 attempt 2
accepted_sha: e14dc1f70f6482e7d930de668c10b5b2c031d142
base_sha: 67d18746a5e1c565c1f33c0394ae518af0e1cbf0   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ e14dc1f70f6482e7d930de668c10b5b2c031d142 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r8a2.md=65cd96198f2bf211fad03a521c6f7afbb36fd50eafc4cbf2eae75d8edf1b686d   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 / M3 (whole-package gate), round 8

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.YMvwprLRbb/snap`, detached at `e14dc1f70f6482e7d930de668c10b5b2c031d142` (worktree `HEAD` confirmed == A; `git status --porcelain` empty in the sealed snapshot).
**Range:** `67d18746a5e1c565c1f33c0394ae518af0e1cbf0..e14dc1f70f6482e7d930de668c10b5b2c031d142` — 28 commits, 15 files, +4828/−24.
**Handback evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r8a2.md` — 1154 lines, `sha256 = 65cd96198f2bf211fad03a521c6f7afbb36fd50eafc4cbf2eae75d8edf1b686d` (computed by this reviewer from the file read; byte-identical to the round-8 attempt-1 copy `…-r8.md`, consistent with a parent-owned tool-error retry under R8-H-3 — no `fix_rounds` movement implied by the retry itself).
**Lenses applied in full:** `stock-gl-interaction` (`.claude/agents/stock-gl-interaction-reviewer.md`), `inventory-costing` (`.claude/agents/inventory-costing-reviewer.md`).

---

## 0. Mandated content-derived evidence of table inspection (R8-H-2)

**§3.5 — Mechanism × table fixture coverage (brief §2 deliverable 6).**
Main table: **10 data rows × 4 table columns**. First row key **`create`**; last row key **`raw_sql`**. Independently re-derived from `DocumentPerActionWriteGuardTest::fixtureMatrix()` at A: **115** matrix entries (`grep -c "^            \['mechanism' =>"` → 115), **115** unique `(class, method, table, mechanism)` tuples (0 duplicates), **40/40** distinct table×mechanism cells present, expectation split **75 violation / 32 linked / 8 not_applicable**. The handback's "115 counted three independent ways" reproduces exactly.
Origin sub-table: **5 data rows + total**. First row key **`M1 initial matrix`** (95); last row key **`round 5 — setRawAttributes() erasure ×1`** (1); 95+7+6+6+1 = **115** ✓.

**§4 — Baseline ↔ DPA-register cross-check (brief §2 deliverable 4).**
Cross-check table: **14 data rows**. First row key **`V1` — `TestE2EGLPosting` hard-deletes sealed GL**; last row key **`S0 residue` — `WeightedAverageCostService` reference params still `?string` (`:260/:425/:578/:767` → violations #21/#23/#26/#28)**.
Partition sub-table: **3 data rows**; first row key **`mapped to a register item or S0 residue in the table above`** (13); last row **`34` ✓**. Arithmetic re-checked: mapped = {3, 12, 19, 20–28, 33} = 13; never-covered = the complementary 21 ids; union = 1..34, disjoint ✓.
Never-covered table: **17 data rows** spanning 21 census ids (rows `4–7` and `13/14` are merged). First row key **`1` — `FixOrphanedProducts.php:128`**; last row key **`34` — `RepositoryTransferService.php:105`**.

**§5 — Violation census / M2 seed baseline.**
**34 data rows.** First row key **`1 | app/Console/Commands/FixOrphanedProducts.php:128 | stock_levels | create | FixOrphanedProducts::executeCommand`**; last row key **`34 | app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105 | journal_entries | delete | RepositoryTransferService::transfer`**.
Reconciled independently against `apps/api/tests/Architecture/baselines/document-per-action-baseline.json` at A (**34** keys, sorted ✓, unique ✓, line-number-free ✓; first key `app/Console/Commands/FixOrphanedProducts.php::App\Console\Commands\FixOrphanedProducts::executeCommand::stock_levels::create#1`, last key `app/Modules/Treasury/…\RepositoryTransferService::transfer::journal_entries::delete#1`): **0 in census not in baseline, 0 in baseline not in census**. All 34 `file:line` anchors resolve at A and carry the claimed write token (the three `query_builder` anchors point at the `DB::table('<table>')` root hop, terminal `->update(...)` a few lines below — correct, not stale). Row #11 `UninvoicedDeliveryNoteService.php:530` verified — `'source_id' => null` at `:537`, i.e. the round-6 anchor correction is real.

---

## 1. Findings

### [IMPORTANT] The §7 workflow-evidence and job-scoping records attest the PRE-REBASE tree, not A

Three verifiable instances, all traceable to the 2026-08-20 stale-A rebase, all in the block the brief's §5 layer 1 and §2 deliverable 5 make mandatory:

**(a) `handback §7.3`, under the literal heading "Result at this tip:"**
```
$ python3 check-all-jobs.py .github/workflows/ci.yml
whole workflow: 64 run: blocks, 64 OK, 0 FAILED
```
At A the true count is **67**. Re-derived here by extracting every `run:` block from `.github/workflows/ci.yml` and running `bash -n` on the YAML's own bytes: **67 total, 0 failing; 5/5 in `backend-dpa-guard`, 0 failing.** `64` is exactly the count at `0ffed96c74…` — the *pre-rebase* accepted tip (base `41fb478c2` = 59 + 5; new base `67d18746a` = 62 + 5 = 67). The handback contradicts itself: §8.8 correctly states "**67/67 across the whole workflow (dev added three)**", and `enforcement-p1.progress.yaml` `rebase_2026_08_20` also states 67/67. Only §7.3 — the operative evidence block — was not re-derived.

**(b) `handback §7.1` decision 1:** "*the remaining **twelve** Architecture tests are excluded only because nothing in this package needs them*". At A, `apps/api/tests/Architecture/*Test.php` = **20** files; minus this package's 2 = 18 pre-existing; minus the 4 known-red = **14**, not 12. `12` was correct at the old base (16 − 4). Dev added `OrphanedEventRatchetTest.php` and `ProjectorEmissionRatchetTest.php` in the rebase window (`git ls-tree` diff `41fb478c2` → `67d18746a`), and **neither is named or dispositioned anywhere in the handback or the YAML**. Brief §2 deliverable 5 requires recording *which* Architecture tests were excluded and why; at the tip that record is short by two.

**(c) `handback §7.7`** pins the known-red evidence at the superseded base `41fb478c2` and asserts the suite "stays red at the tip, with the same four failures" — a claim about an 18-class pre-existing set that was measured on a 16-class one. The two rebase-window additions were never run or dispositioned, so the four-failure closure is not established at A.

**Why this matters at this gate specifically.** No guard, ratchet, baseline, CI or scope defect follows — I independently confirmed every conclusion these blocks assert (67/67 run blocks parse; job scoping is sound). But these bytes are about to be `sha256`-bound in the owner-authenticated pin-tag annotation and landed as the durable record in closing commit C, and the handback is a downstream input (P3-M0 verifies its digest; P3(a) consumes its census). This is also the **third distinct sub-class** of the same append-vs-substitute failure the last four rounds each blocked on and each claimed to have closed mechanically: round 5 closed *commit SHAs*, round 6 closed *`file:line` anchors* — neither sweep covers **counts inside pasted command output**. Round 2's F-1 was graded Important on materially the identical defect (an evidence block certifying a different tree than the one it ships with), and round 6 blocked on a single stale anchor with no guard defect. The same standard applies here.

**Fix:** re-derive §7.1 decision 1, §7.3 Layer 1, and §7.7 at A (14 excluded classes, the two named rebase-window additions with their disposition, 67/67, base `67d18746a`); extend the §8.8 sweep with a third class — pasted counts must be regenerated, not carried — and state that class explicitly so the next rebase cannot re-open it.

### [MINOR] Document structure

`§7.5` is placed after `§7.7`; `§3.8` and `§8.8` are emitted at `##` inside a `###` sequence. Cosmetic; no bearing on the verdict, noted only because the closing commit freezes this file.

---

## 2. What I verified and confirm as SOUND (no finding)

**Scope and control surface.** 15 files, **0** path-allowlist violations against `apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`. **No** production code. **No** control-file hit (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*-reviewer.md`) and no added `*control-manifest*` surrogate. **No** `permissions:`/`contents: write` grant in the workflow diff (R6-H-5 owner check clears). `67d18746a` is an ancestor of A — clean fast-forward. §7.4's `git diff --stat` block reproduces **byte-for-byte** at A (15 files, +4828/−24, every per-file figure), so round 2's finding is genuinely closed.

**CI job (`backend-dpa-guard`).** No `if:` ✓. Present in `all-checks-pass` `needs` ✓ with the skipped-job-semantics comment. The §7.1 aggregate-membership grep reproduces **exactly** at A (`:180`, `:1281`, `:1288`) — round 5's finding closed. Round 3's CRITICAL is genuinely fixed: I executed the pin-tag extraction from the YAML's literal bytes against the real progress YAML → `PIN_TAG=[ci-pin/enforcement-p1-r1]`, exit 0; the `[ -z ] || [ = "null" ]` fail-closed branch is correct. Both test steps use `set -o pipefail` + `grep -qE 'OK \([1-9][0-9]* test'` — the empty-selection hole (`phpunit.xml` sets no `failOnEmptyTestSuite`) is genuinely closed. `actions/checkout@v5` / `actions/cache@v5` / `env.PHP_VERSION` are consistent with the rest of the file; `fetch-depth: 0` is required and present for `git cat-file blob`.

**Two-phase pinned-baseline protocol (R2-C-1 / R3-C-1 / R3-H-4 / R4-H-3).** Intact and independently verified: seed commit `d2802c0b457361f1fbe2873ac4773dc8d5c32409` contains **exactly one file** (the baseline); the distinct metadata commit `de067d50b` carries the mirror pins + checker; `git rev-parse <seed>:<baseline>` = `1381983d463e6c546535be907d4aa1c7ca94c597` = the YAML mirror = the blob at A; the seed is an ancestor of A. The post-rebase re-point is correctly reasoned as a *pointer* correction (content-derived blob unchanged) and not a re-seed/re-pin. `dpa_baseline_pin_tag: ci-pin/enforcement-p1-r1` is non-null in A ✓ (R5-C-2). Authority is `${{ vars.DPA_BASELINE_PROTECTED_BLOB }}` mapped into the ratchet step's env ✓ (R4-H-4) — never the YAML, never a branch name.

**Ratchet semantics.** All three directions present and fail-closed: growth, stale, and anti-growth against the variable-held blob. Fail-closed on unset/empty variable, malformed hash, unfetchable object, **and mirror drift** (treated as a tamper signal, not a skip). `repositoryRoot()` = `dirname(base_path(), 2)` resolves correctly under the job's `working-directory: apps/api`. The mirror regex resolves against the real YAML line. `113` assertions is *exactly* reconstructible from the two test bodies (37 + 76), which is strong evidence the pasted output is real rather than transcribed.

**The guard is not a rubber stamp.** 4 gates: classification matrix, positive-coverage (derived from `TABLE_MODELS` × `MECHANISMS`, not a hardcoded map), negative-control (derived from `linkedFormExists()`, deliberately kept next to the rules), and one-direction rule-surface agreement — with the one-directionality stated honestly in the docblock rather than overclaimed. Fixtures resolve through the **production** index maps (`contextRoots`), so a fixture cannot pass in a vacuum. `write-document-per-action-baseline.php` is a standalone bootstrap CLI, invoked by neither CI nor any test; **no test writes the baseline**; no `assertTrue(true)`, no `markTestSkipped/Incomplete`.

**Scanner correctness (spot-checked against the tree).** Independent re-derivation of every `JournalEntry` create-class site with a literal payload (**51** sites) found exactly **2** missing `source_id` — `GeneralLedgerService::createPaymentEntry` (`:353`) and `JournalEntryController::store` (`:97`) — and **both are baselined**; the third baselined JE create (`UninvoicedDeliveryNoteService:530`) is the explicit `'source_id' => null` case. No false negative found. `DB::table()` hits on the four tables that are reads (`TreasuryReceiptBridge:559`, `StockLevelMigrationService:106`) are correctly not reported. `PosCoreReceiptProjection`'s two `StockMovement::query()->create()` sites carry `reference_type`/`reference_id` and are correctly `linked` — and `isQueryBuilderChain()` correctly does **not** remap `Model::query()->create()` to `query_builder`. The `astCache` prefilter (`mayMatter()`) is cache-eviction only — a non-matching file is still parsed, so it cannot cause a false negative. Nested-scope handling (anonymous classes, named functions, closures) is correct; the one theoretical double-scan path (a named function declared inside a class method) has **zero** occurrences in `app/`.

**Record integrity elsewhere.** I re-ran the §8.8 SHA sweep independently over the handback **and** the progress YAML: **16** non-ancestor commit tokens, **0** unmarked — every one sits in an explicitly historical context. §3.1's fixture count (8 files, six declaring 8 classes, two class-less) verifies exactly. §3.3 item 5's "50 files under `app/` carry top-level statements (46 `routes.php` + four POS route files)" verifies exactly (46 + 4 = 50). "Seven executor-run registers" = M1×5 + M2×2 ✓, and seven such files exist on disk. `fix_rounds: 5` of `max_fix_rounds: 5` is disclosed prominently as a deviation with its full lineage — the round-7 D-1(a) correction is honest and I confirm 5 is the defensible count (rounds 5 and 6 were substantive CHANGES-REQUIRED that transferred control to the executor).

**Lens outcomes.** `stock-gl-interaction` — both-sides discipline satisfied: the diff moves no stock and posts no GL; it *encodes* the document-per-action, append-only-ledger (movement MUTATE/DELETE and JE DELETE always violations), single-writer and chokepoint truths as a static contract, and the four blind spots that weaken them (B function-scoped pairing, E nullability-refused-not-proven, F pairing-arm asymmetry, H key-is-a-slot-not-a-write) are named rather than claimed away. Spec ✅ / quality changes-requested on the record only. `inventory-costing` — the WAC cluster (#20–#28, 9 sites, the largest in the baseline) is correctly baselined and mapped to the S0 residue, not silently credited; no float, `(float)` cast, `floatval`, `number_format` or bcmath-scale change appears on any added line; nothing touches the running-average path. Spec ✅ / quality changes-requested on the record only.

---

## 3. What to fix before the ACCEPT can bind

Re-derive §7.1 decision 1 (14 excluded Architecture classes, the two rebase-window additions named and dispositioned), §7.3 Layer 1 (`67/67`), and §7.7 (base `67d18746a`) **at A**, and extend the §8.8 sweep to a third class — pasted counts — so the class closes mechanically rather than instance-by-instance. No code, no re-seed, no re-pin, no baseline edit is implied.

**Procedural consequence the parent must apply, not me:** `fix_rounds` stands at 5 of `max_fix_rounds: 5`. Under the harness STOP condition A and brief R7-H-1, a further substantive round is not available to the executor — this register is a `blocked_review` / owner escalation, not a sixth fix round.

VERDICT: CHANGES-REQUIRED
