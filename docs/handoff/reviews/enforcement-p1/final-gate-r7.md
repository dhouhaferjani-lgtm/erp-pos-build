# FINAL-GATE REGISTER — package p1 M3 round 7 attempt 1
accepted_sha: a07e0677df75ecaf820bf0acaf42d443998a5f7f
base_sha: 67d18746a5e1c565c1f33c0394ae518af0e1cbf0   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ a07e0677df75ecaf820bf0acaf42d443998a5f7f (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r7.md=72b7d60cd92c5663d2ebe840ece941241a5b52a86ba84b701edc35a160edce72   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
I have completed verification. Writing the register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 / M3, round 7

**Package:** enforcement-p1 · **Milestone:** M3 (whole-package gate) · **Round:** 7
**Accepted-candidate tip under review (A):** `a07e0677df75ecaf820bf0acaf42d443998a5f7f`
**Range:** `67d18746a5e1c565c1f33c0394ae518af0e1cbf0..a07e0677df75ecaf820bf0acaf42d443998a5f7f`
**Sealed snapshot:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.8HMvEyzKPs/snap` — `HEAD == A` re-asserted, `git status --porcelain` empty
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r7.md`
**Handback sha256 (computed independently here):** `72b7d60cd92c5663d2ebe840ece941241a5b52a86ba84b701edc35a160edce72` (93117 bytes, 1114 lines)
**Lenses applied in full:** `stock-gl-interaction`, `inventory-costing`

---

## A. Structural / contract preflight

| Check | Result |
|---|---|
| Base a **strict** ancestor of A, `BASE != A` | PASS — 28 commits in range |
| Path allowlist (`apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`) | PASS — 15 files, **0 violations** |
| Production code touched | PASS — none (`apps/api/app/`, `apps/web/src/`, `apps/pos/` all clean) |
| Control-file preflight (bridge, brief, harness, control manifest, `*-reviewer.md`, `*control-manifest*` surrogates) | PASS — none touched |
| Workflow permissions grant (gate-r6 R6-H-5) | PASS — no `permissions:` / `contents: write` added |
| Dispatch-receipt field-check | PASS — receipt `base_sha: 67d18746a…` == `--base`; `progress_path`, `final_milestone: M3`, `lenses`, `max_fix_rounds: 5` cross-check; structured `control_manifest {path, sha256: 709b6fe9a8…}` == receipt `manifest_sha256` |
| Exactly one final milestone at `status: review` | PASS (`:214`) |
| No milestone carries an `owner_gate:` field (harness `:49-50`) | PASS |
| Pins non-null: `dpa_baseline_seed_commit` / `_protected_blob` / `_pin_tag`, `ratchet_trust_model_ack`, `commit_series`, `control_manifest` | PASS |
| `fix_rounds <= max_fix_rounds` | passes numerically (3 ≤ 5) — **but the value is not true; see Finding D-1** |

**Baseline-pin topology, verified in the object store, not read from prose:** seed `d2802c0b457361f1fbe2873ac4773dc8d5c32409` contains **exactly one file**, **is an ancestor** of A, and yields blob `1381983d463e6c546535be907d4aa1c7ca94c597` — byte-identical to the blob at A and to the YAML mirror `:72`. Metadata commit `de067d50b` follows it. The two-commit topology survived the rebase; §8.8's "pointer correction, not a re-seed" is confirmed.

---

## B. Census / classification tables — content-derived evidence of inspection

Per gate-r8 R8-H-2, each mandated table with its row count and first/last row keys, parsed from the handback's own bytes and cross-checked against the sealed tree.

**1. §5 — Violation census / M2 seed baseline (the deliverable-3/4 review target)**
- **Row count: 34** (ids contiguous `1..34`)
- **First row key:** `#1 · app/Console/Commands/FixOrphanedProducts.php:128 · stock_levels · create · FixOrphanedProducts::executeCommand`
- **Last row key:** `#34 · app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105 · journal_entries · delete · RepositoryTransferService::transfer`
- **I resolved ALL 34 anchors against the sealed tree, not a sample.** Every one carries the claimed mechanism at the claimed line: `#1` `StockLevel::create([`, `#11` `UninvoicedDeliveryNoteService.php:530` = `$entry = JournalEntry::create([`, `#29` `return StockLevel::firstOrCreate(`, `#34` `$draft->delete();`. **Round 6's IMPORTANT is fully closed** — and closed by regeneration, so all 34 anchors are fresh, not just the one reported.
- **Independent reconciliation with the checked-in baseline:** 34 census `(file, function, table, mechanism)` tuples vs 34 baseline keys — **0 in census not in baseline, 0 in baseline not in census**. Baseline: 34 keys, sorted ✓, unique ✓, line-number-free ✓. Distribution `stock_levels 13 · stock_movements 10 · inventory_batch_stock 7 · journal_entries 4`.

**2. §4 — Baseline ↔ DPA-register cross-check (deliverable 4)**
- **Row count: 14**
- **First row key:** `V1 — TestE2EGLPosting hard-deletes sealed GL` → "no site"
- **Last row key:** `S0 residue — WeightedAverageCostService reference params still ?string` → `:260/:425/:578/:767` → violations #21/#23/#26/#28
- Spot-verified at source: V2 `AccountingService.php:398` = `$entry = JournalEntry::create([` ✓; V3 `GeneralLedgerService.php:1249` ✓; V5 `JournalEntryController.php:97` ✓; V8 `SupplierCreditNotePostingService.php:666` = `$stockLevel->save();` ✓; V10 `ReceiptReturnService.php:1330` / `ReturnScrapWriteOffService.php:165` ✓.

**3. §4 — Partition arithmetic**
- **Row count: 3** · **First:** `mapped to a register item or S0 residue` = **13** · **Last:** blank label = **34 ✓**
- Re-derived, not trusted: mapped `{3,12,19,20–28,33}` = 13; never-covered `{1,2,4–11,13–18,29–32,34}` = 21; `13+21=34`, union exactly `1..34`, intersection empty.

**4. §4 — "Violations the register never covered"**
- **Row count: 17 physical rows expanding to 21 census ids**
- **First row key:** `1 | FixOrphanedProducts.php:128` · **Last row key:** `34 | RepositoryTransferService.php:105`

**5. §3.5 — Mechanism × table fixture coverage (deliverable 6)**
- **Row count: 10 mechanism rows × 4 table columns; 115 pinned cells**
- **First row key:** `create` → `journal_entries P+N (+half-linked P, unresolvable-payload P, nullable-linkage P, proven-linkage N) | …`
- **Last row key:** `raw_sql` → `journal_entries P-only ×3 | stock_movements P-only ×2 | stock_levels P-only | inventory_batch_stock P-only`
- **Counted three independent ways in the snapshot:** `grep -c` → **115**; regex-parsed entries → **115**; unique `(class, method, table, mechanism)` → **115**. Per-mechanism totals reconcile with the table's P/N annotations (`raw_sql` 3+2+1+1 = 7 ✓; `decrement` 1+1+3+3 = 8 ✓; `delete` 1+1+2+2 = 6 ✓).

**6. §3.5 — Per-round cell origin:** **6 rows.** First `M1 initial matrix = 95`; last `total = 115`; `95+7+6+6+1 = 115` ✓.

**7. §2 — M0 predicate table:** **7 rows.** First `1 — git rev-parse --verify on base / 3C-merge / 3C-reviewed`; last `7 — commit_series, ratchet_trust_model_ack, control_manifest non-null`.

**8. §3.1 — What was built:** **3 rows.** First `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php`; last `apps/api/tests/Architecture/DocumentPerActionFixtures/**` (8 fixture files) — all present in the diff ✓.

**9. §3.7 — M1 gate history:** **5 rows.** First `1 — M1-round1.md — CHANGES-REQUIRED (2×P1, 4×P2, 6×P3)`; last `5 — M1-round5.md — ACCEPT (0×P1, 0×P2, 7×P3)`.

**10. §8.8 — Targeted post-rebase verification:** **4 rows.** First `UninvoicedDeliveryNoteService — dev modified one of my baselined violators`; last `T21 count-correction listener + StockAdjustmentService`. Verified independently: `:530` is `JournalEntry::create([` inside `generateYearEndAdjustment`, `:591` is the new `generateReversalEntry`.

**11. §9 — Package gate history:** **4 rows.** First `M0 — setup-only, no bridge review by design — passed`; last `M3 — round 1 → CHANGES-REQUIRED … round 3 → CHANGES-REQUIRED with a CRITICAL; fix rounds 1–3 applied; re-handed over`. **This last row is the subject of Finding D-1.**

---

## C. Guard, ratchet and CI verification — what I confirmed SOUND

- **Round 6's finding is closed at the artifact level.** §5 was regenerated from a fresh scanner run rather than hand-patched, so all 34 anchors are correct, not just row #11. §4's bare-basename anchor now reads `:530`. The two `:257` survivors at handback `:1002`/`:1017` are inside an explicit `<!-- sweep:historical-block-start -->` marker and are the narrative describing the defect — correct, not residue.
- **Rounds 5's F-1/F-2 are closed.** §7.1's brief-mandated grep reproduces **verbatim** at A: `180:  backend-dpa-guard:` · `1281:    # backend-dpa-guard is included below on exactly the same reasoning: it` · `1288:    needs: [ … backend-dpa-guard … ]`. `m3_evidence.aggregate_membership` matches. §6.1/§6.3 seed pointers now read `d2802c0b4`.
- **§7.4's scope proof reproduces byte-for-byte at its own tip.** My independent `git diff --stat 67d18746a..A` returns the identical 15-line stat block and `15 files changed, 4815 insertions(+), 24 deletions(-)`. The round-2 class is genuinely closed.
- **Round 3's CRITICAL stays closed.** I re-ran the standing `actionlint` substitute myself, extracting each `run:` block from the YAML's own bytes: **67/67 OK, 0 FAILED**. The `tr -d "\"'"` repair holds.
- **Job graph.** `backend-dpa-guard` carries **no `if:`**; present in `all-checks-pass` `needs`; every `needs` entry resolves to a real job. Both test steps assert a nonzero selected-test count under `set -o pipefail` — the empty-selection escape (`phpunit.xml` sets no `failOnEmptyTestSuite`) is genuinely closed.
- **Ratchet authority is correct per gate-r3 R3-C-1.** Direction (c) reads `getenv(DPA_BASELINE_PROTECTED_BLOB)` only; the YAML mirror is a tamper tripwire whose disagreement **fails**, and whose absence yields `null` → assertion failure (fails closed, not open). The mirror regex anchors at column 0 and the YAML line `:72` carries no trailing comment, so it matches. Fail-closed on unset/empty, malformed hash, mirror drift, unfetchable blob, and any added key.
- **No scanner-emittable mechanism escapes the completeness gate.** I derived the emittable set from `WRITE_METHODS` + `query_builder` + `raw_sql` and diffed it against the test's `MECHANISMS`: **empty in both directions**. `every_cell_with_a_linked_form_pins_a_negative_case()` derives its obligation from `linkedFormExists()` rather than a local map — the knob that could quietly drop a requirement is absent by construction.

**Lens — `stock-gl-interaction`.** Dimension 1 is the package itself and is implemented as a paired-presence rule per table with the movement-pairing predicate standing in where no reference column exists. Dimension 9 (append-only) is encoded correctly and unconditionally: `stock_movements` MUTATE **and** DELETE always violation; `journal_entries` DELETE always violation — and §3.8's live `RepositoryTransferService.php:105` `$draft->delete();` is a real dimension-9 defect the DPA register never caught, correctly **baselined, not fixed** (brief §1 DO NOT TOUCH) with a parent ticket requested. Dimension 5 (single writer) is what the chokepoint-bound pairing predicate approximates statically. Dimensions 2/3/4/8 are not reachable: the diff adds no production writer and makes no dormant one reachable. Dimension 10: **zero** `(float)`, `floatval`, `(double)`, `number_format` anywhere under `apps/api/tests/Architecture/`. The `journal_entries` MUTATE exemption covering `fiscal_hash`/`previous_hash`/`chain_sequence` diverges from the lens's posted-entry stance, but is prominently disclosed in §3.2 and is faithful to the brief's own contract (presence of `source_type`/`source_id`, not chain integrity). **Not a finding** — closing it would be scope creep into a re-seed event.

**Lens — `inventory-costing`.** No WAC arithmetic, `workingScale()`, divisor basis, or lock order is touched; the WAC cluster #20–#28 (9 sites in one file) is the largest baseline group and is correctly enumerated rather than remediated. Opening-balance sites (#12/#13/#14, #15/#16) and the FEFO batch consumption (#9) are classified and baselined. Precision checklist discharged mechanically as above. **Test quality:** no `assertTrue(true)`, no mocks, no `markTestSkipped`, no `RefreshDatabase`, no DB — static-only, matching house style; SQLite-masking is not reachable. `scanFixtures()` passes the production tree as *context* so fixtures resolve through the same relation/inheritance/return-type/chokepoint indexes as live code — a genuine anti-vacuity measure.

Blind spots A–H in the scanner docblock remain unusually honest, including H ("a baseline key is a SLOT, not a WRITE") and D's newly disclosed injected-connection raw-SQL gap.

---

## D. Findings

### [IMPORTANT · P2] D-1 — The M3 gate record was never advanced past round 3, and `fix_rounds` is the operative casualty

The engineering is clean. What is not clean is the harness state this candidate carries into the closing commit. **Four instances, one class** — the M3 record stopped being updated at round 3 and every later round was appended narratively somewhere else instead of substituted into the record:

**(a) `fix_rounds` is understated by two, and it is the STOP-condition-A counter.**
`enforcement-p1.progress.yaml:215` reads `fix_rounds: 3`. Final-gate round 5 (`final-gate-r5.md`, 2 Important + 1 Minor, `VERDICT: CHANGES-REQUIRED`) and round 6 (`final-gate-r6.md`, 1 Important, `VERDICT: CHANGES-REQUIRED`) were both **substantive** CHANGES-REQUIRED — not tool errors — and each transferred control to the executor, which fixed and committed (round 5 → `49270e3fd`…`3d7f55457`; round 6 → `a07e0677d` + the §5 regeneration). Brief R7-H-1 and harness step 3 both state the executor "commits, **increments `fix_rounds`**, resets to `status: review`". The true count is **5 — exactly `max_fix_rounds`**.

Why this is not bookkeeping: `fix_rounds` is the *only* bound the harness places on the review loop (STOP condition A → `blocked_review`). At the true value the next CHANGES-REQUIRED is an owner escalation, not another silent round. The bridge's `fix_rounds <= max_fix_rounds` field-check is a fail-closed control that currently passes on an untrue value, and the progress YAML is explicitly "the resume point if you crash" — a fresh session resuming from it concludes the package has two rounds of budget left when it has none. The re-gate ruling recorded at `:228` ("*NOT an ordinary fix round — M3 fix_rounds stays 3*") covers the rebase itself; it does not cover the two substantive fix rounds that followed it, and no justification for holding the counter is stated anywhere. Brief §7 item 4 makes an undisclosed deviation of this kind a handback defect on its own terms.

**(b) `final_gate_round5` is absent from the YAML.** `final_gate_round1/2/3/4/6` are all present (`:223-227`); round 5's entry was never written, even though `last_verdict`'s own comment names "rounds 5-6 CHANGES-REQUIRED". Round 5's F-3 asked for `final_gate_round4` precisely so the durable record would show the sequence; the sequence now has a hole at 5.

**(c) §9's package gate-history table stops at round 3.** Its M3 row (handback `:1071`) reads "*round 1 → … round 2 → … round 3 → CHANGES-REQUIRED with a CRITICAL; fix rounds 1–3 applied; re-handed over*" — omitting round 4's **ACCEPT**, the stale-A re-gate, and rounds 5 and 6. Rounds 1, 2 and 3 each get a full disposition table in §9; rounds 5 and 6 get none. This is the same defect final-gate round 2 already ruled on in this document ("*[Minor] §3.7 gate-history row 5 still read (pending) though M1 accepted at round 5*") — fixed for M1's table, never applied to M3's.

**(d) The round-count sentence contradicts §9 itself.** Handback `:1111`: "*Across **nine** executor-run gate rounds…*", while `:1050` states "*The **seven** executor-run registers*" (M1×5 + M2×2 = 7, and the seven files are on disk). "Nine" was true when M3 had two parent rounds; it was never re-derived.

**What is NOT wrong, so the fix is scoped correctly:** no code, no re-seed, no re-pin, no baseline edit. The scanner, baseline, ratchet, fixtures, CI job, seed blob, pins and pre-allocated tag all verify independently at A, and the census is now correct in every one of its 34 anchors.

**Suggested fix:** set `milestones[M3].fix_rounds` to its true value **or** state on the record why it is held, in the YAML, as a deviation (this is an owner-visible decision either way — at 5 the package is at its ceiling); add `final_gate_round5`; substitute §9's M3 row with the full lineage `1–3 CR → 4 ACCEPT → stale-A re-gate → 5 CR → 6 CR`, with round-5 and round-6 disposition tables matching the treatment rounds 1–3 already receive; re-derive "nine" from §9's own table.

### [MINOR · P3] D-2 — §8.8's closing ⚠️ asserts a parent action that has already been taken

Handback `:1033-1038`: "*the dispatch receipt … **still names** `base_sha: 41fb478c2` … It must be updated to `67d18746a…`*". I opened the receipt: it reads `base_sha: 67d18746a5e1c565c1f33c0394ae518af0e1cbf0  # re-pinned 2026-08-20`. Round 6's register reported this resolution back to the executor, so the correction was available. Present-tense and prominently flagged, it will be owner-attested as a live outstanding action that does not exist. **Fix:** mark it done with the re-pinned value, or delete it.

---

## E. Assessment

The package is materially finished. I attacked the guard rather than the prose and could not break it: the 34-key baseline reconciles exactly with a regenerated census whose every anchor I resolved at source; the three-direction ratchet is fail-closed on all five named conditions with authority genuinely outside the candidate; the mechanism vocabulary is provably complete against the scanner's own emission surface; the CI job's 67 `run:` blocks parse and the aggregate membership grep reproduces verbatim; the scope is clean and no control file is touched. Both lenses were applied in full and produced no finding — the append-only rules, the WAC cluster handling and rule-19 compliance are all correct, and §3.8's live `journal_entries` row delete is a real defect volunteered rather than extracted.

I do not carry D-1 as a note. This gate has now returned CHANGES-REQUIRED four times — rounds 1, 2, 5 and 6 — every one of them for a record that did not reproduce at the tip it certified, and round 6's own reasoning was that applying a weaker standard on the binding round would be inconsistent with the rulings that preceded it. D-1 is that class again, and this instance is worse than the anchor: `fix_rounds` is not a human-facing convenience column but the harness's only bound on this loop, and understating it conceals from the owner that the package is at its ceiling on the round where the bytes get cryptographically bound and handed to P3. The document that catalogues six firings of the append-vs-substitute class contains its seventh, in its own gate record.

The remedy is small and touches no code. It is also the item the owner most needs stated truthfully before deciding whether to grant further rounds or accept.

**Findings: 0 Critical · 1 Important · 1 Minor.**

VERDICT: CHANGES-REQUIRED
