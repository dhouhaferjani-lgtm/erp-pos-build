# FINAL-GATE REGISTER — package p1 M3 round 5 attempt 1
accepted_sha: 043d7be58140ed6948de6832304a2d53569b3c01
base_sha: 67d18746a5e1c565c1f33c0394ae518af0e1cbf0   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 043d7be58140ed6948de6832304a2d53569b3c01 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r5.md=937f9c38d29ab9fae8b50766e8dc724c79c89e045a00df99dfd42aa6d9b1530b   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 / M3, round 5

**Package:** enforcement-p1 (document-per-action cementing guard, ratchet mode)
**Snapshot:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.YEOP28VzDI/snap`, detached at `043d7be58140ed6948de6832304a2d53569b3c01`, `git status --porcelain` empty (verified).
**Range:** `67d18746a5e1c565c1f33c0394ae518af0e1cbf0..043d7be58140ed6948de6832304a2d53569b3c01` — 25 commits, 15 files, +4813/−24.
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r5.md`, 1033 lines, `sha256 = 937f9c38d29ab9fae8b50766e8dc724c79c89e045a00df99dfd42aa6d9b1530b`.
**Working directory:** neutral and empty; every path absolute or snapshot-relative.

---

## 1. Content-derived evidence of inspection — the required tables

Each table below was opened, counted, and cross-checked against the sealed tree.

| Handback table | Rows | First row key | Last row key | Verified against tree |
|---|---|---|---|---|
| §2 M0 predicate table | **7** | `1` — `git rev-parse --verify` on base / 3C-merge / 3C-reviewed | `7` — `commit_series`, `ratchet_trust_model_ack`, `control_manifest` non-null | pins present in YAML `:33-35,:119,:125,:131` |
| §3.1 What was built | **3** | `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php` | `apps/api/tests/Architecture/DocumentPerActionFixtures/**` | 8 fixture files present in diff ✓ |
| §3.5 Mechanism × table coverage | **10** | `create` | `raw_sql` | **115 cells** — `grep -c "^            \['mechanism' =>"` → **115** ✓ |
| §3.5 per-round cell origin | **6** | `M1 initial matrix` = 95 | `total` = 115 | 95+7+6+6+1 = 115 ✓ |
| §3.7 M1 gate history | **5** | round `1` → CHANGES-REQUIRED | round `5` → **ACCEPT** | registers on disk ✓ |
| §4 baseline↔DPA-register cross-check | **14** | `V1` — `TestE2EGLPosting` hard-deletes sealed GL | `S0 residue` — `WeightedAverageCostService` reference params still `?string` | ✓ |
| §4 partition arithmetic | **3** | `mapped to a register item or S0 residue` = 13 | `total` = 34 | 13+21 = 34 ✓ |
| §4 never-covered list | **17** (21 census ids) | `1` — `FixOrphanedProducts.php:128` | `34` — `RepositoryTransferService.php:105` | id enumeration re-counted → 21 ✓ |
| §5 violation census | **34** | `1` — `app/Console/Commands/FixOrphanedProducts.php:128` · stock_levels · create · `FixOrphanedProducts::executeCommand` | `34` — `app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` · journal_entries · delete · `RepositoryTransferService::transfer` | baseline JSON holds **34** keys, first = `…FixOrphanedProducts::executeCommand::stock_levels::create#1`, last = `…RepositoryTransferService::transfer::journal_entries::delete#1` ✓ |

**Independent census verification.** 34+67+15 = 116 sites ✓. Seven census rows read at real source in the snapshot — all seven accurate in file, line, table and mechanism: `RepositoryTransferService.php:105` (`$draft->delete();`), `StockThresholdService.php:46` (`DB::table('stock_levels')->insert([`), `StockAdjustmentService.php:1618` (`return StockLevel::firstOrCreate(`), `ReverseWriteOffService.php:186` (`$inverse->save();`), `ResetOpeningBalanceService.php:107` (`$reversal = StockMovement::create([`), `GeneralLedgerService.php:353` (`$entry = JournalEntry::create([`), `StockLevel.php:205` (`$this->save();`).

---

## 2. What I verified independently and found SOUND

- **Round-3's CRITICAL is genuinely closed.** Re-ran the YAML-extracted `bash -n` sweep myself: **5/5** `run:` blocks in `backend-dpa-guard`, **67/67** across the workflow. Executed the pin-tag step from the YAML's own bytes (fetch stubbed) → emits `ci-pin/enforcement-p1-r1`. The handback's claim reproduces exactly.
- **Job graph.** `backend-dpa-guard` has **no `if:`** (parsed, not grepped); present in `all-checks-pass` `needs` (14 entries, **0 unresolved**); `route-manifest-drift` likewise carries no `if:`, so the merged skipped-job-semantics rationale is true for both.
- **Ratchet authority.** Seed `d2802c0b4` **is** an ancestor of HEAD; `git rev-parse <seed>:<baseline>` = `1381983d463e6c546535be907d4aa1c7ca94c597` = HEAD blob = YAML mirror `:72`. Baseline unchanged since the seed. Mirror regex anchors at column 0 with no trailing comment → matches; the two other occurrences of the field name are a comment and a milestone title, neither matching the hash pattern.
- **Fail-closed surface.** Unset/empty, malformed hash, mirror drift, unfetchable blob and any added key each assert independently. Direction (c) reads only the env var; the YAML is a tamper tripwire, not authority.
- **Gate non-vacuity.** Four gates; `every_cell_with_a_linked_form_pins_a_negative_case()` derives its obligation from `linkedFormExists()` rather than a local map, and gate 4's docblock now honestly claims one direction only.
- **Scope/preflight.** Allowlist clean (0 paths outside `apps/api/tests/Architecture/**`, `.github/workflows/ci.yml`, `docs/handoff/**`); **no control file touched**; **no `permissions:`/`contents: write` grant** added.
- **Lens — stock-gl-interaction.** Guard-only diff, no production writer added or made reachable. The implemented rules track the lens's dimensions 1 (document-per-action linkage), 5 (single writer — the movement-pairing predicate), and 9 (append-only: `stock_movements` MUTATE/DELETE and `journal_entries` DELETE unconditionally violations). §3.8's `RepositoryTransferService.php:105` `journal_entries` row delete is a real dimension-9 defect the DPA register never covered; correctly **baselined, not fixed** (brief §1 DO NOT TOUCH), with a parent ticket requested.
- **Lens — inventory-costing.** WAC cluster #20–#28 (9 sites in one file) correctly enumerated. No WAC arithmetic, scale resolution, or divisor basis touched. Rule 19 discharged mechanically: **no** `(float)`, `floatval`, `number_format`, `bcadd` — and no `RefreshDatabase` — anywhere under `apps/api/tests/Architecture/`.

---

## 3. Findings

### [IMPORTANT] F-1 — the brief-mandated aggregate-membership acceptance grep does not reproduce at A

Handback §7.1 pastes, as executed evidence:

```
1192:    # backend-dpa-guard is included below: it carries NO `if:` at all, so on every
1198:    needs: [backend-lint, …]
```

Actual at the sealed tip: **`1281:`** … `is included below on exactly the same reasoning: it` and **`1288:`**. Two of three lines are wrong in both line number *and* comment text. `enforcement-p1.progress.yaml:231` (`m3_evidence.aggregate_membership`) repeats the same stale `:1198`/`:1192`.

This is the acceptance command the brief §2 block names verbatim (`grep -n '<architecture-job-id>' .github/workflows/ci.yml`). §8.8 records that the rebase resolved a conflict *in exactly this comment block* and that the `bash -n` sweep was re-run "because dev changed `ci.yml` underneath this branch" — and §7.4 was re-derived verbatim — yet §7.1 and the YAML evidence field were not. The **conclusion** is true (I verified it), but the pasted output is false at the SHA the owner will digest and tag. This is the same class final-gate round 2 already ruled on for §7.4.

### [IMPORTANT] F-2 — the handback contradicts the authoritative seed pin, and three further pointers dangle

Handback §6.1, under "**Mirror pins recorded**", states `dpa_baseline_seed_commit = ff5642f87998e458059694f256d4033636809605`. The authoritative YAML pin, set by A's own final commit `043d7be58` ("Re-point … after the rebase"), is `d2802c0b457361f1fbe2873ac4773dc8d5c32409`. Two reviewed artifacts state different values for the same pinned protocol field, with no correction marker at §6.1; §8.8 fixes it 480 lines later without a back-pointer.

The same partial substitution persists elsewhere — none of these is an ancestor of A, so all become unreachable once A is promoted:

| Location | Value | Status |
|---|---|---|
| handback §6.1 table + pin block | `ff5642f87` | dangling |
| handback §6.3 acceptance command `seed_blob=$(git rev-parse ff5642f87:…)` | `ff5642f87` | dangling — this is the brief-mandated LOCAL AUTHORITY SETUP command |
| YAML `m2_evidence.seed_commit` | `ff5642f87` | dangling, **contradicts** `:65` |
| YAML `m2_evidence.pins_commit` | `9946b10b9` | dangling |
| YAML `milestones[M1].commit` | `c11d146e7` | dangling |
| YAML `milestones[M2].commit` | `441dad0fe` | dangling |

Load-bearing `M3.commit` (`49270e3fd`) is correct and is an ancestor ✓ — so P3-M0's ancestry chain is unaffected. But an owner or P3 consumer following the handback's own §6.3 command post-promotion gets `fatal: bad object`, and the M1/M2 evidence trail resolves to nothing. §8.8 diagnosed this exact hazard ("every later consumer doing the same (P3-M0 included) would have broken") and repaired one field of six.

### [MINOR] F-3 — final-gate round 4's ACCEPT is absent from the operative state file

`final-gate-r4.md` returned `VERDICT: ACCEPT` on `accepted_sha: 0ffed96c74d7cc70fb98a14cdf0e22aa354a914b`. The YAML carries `final_gate_round1/2/3` and `last_verdict: CHANGES-REQUIRED   # parent final-gate round 3`; there is **no** `final_gate_round4` field, and the string `0ffed96c` appears **0 times** in both the YAML and the handback. `rebase_2026_08_20` never states that what was rebased was an **already-accepted A** — the fact that makes this a brief R4-C-2 stale-A re-gate rather than an ordinary fix round. A reader of the resume point concludes the package has never passed a final gate.

---

## 4. Disposition

The engineering is sound and I could not break it: the guard, the three-direction ratchet, the fail-closed surface, the CI job and its aggregate membership, the scope preflight, and the 34/67/15 census all verify independently at the sealed tip, and round 3's CRITICAL is genuinely fixed. No P1-severity defect survives.

What blocks promotion is record integrity in the two artifacts the trust root binds. Per brief R5-C-1/R8-H-2 the owner tags `sha256` of *these handback bytes*, and P3-M0 requires the landed bytes to match — so a mandated acceptance grep that does not reproduce (F-1) and a pin block that contradicts the authoritative YAML (F-2) would be cemented as permanent, owner-attested evidence, and F-2 leaves a brief-mandated command that fails after promotion. Both are the append-vs-substitute class this series has now ruled on seven times, appearing here in the very document that catalogues it.

**To close:** re-derive §7.1's grep and `m3_evidence.aggregate_membership` verbatim at the handover tip; substitute (not append) the seed/pins/M1/M2 pointers in handback §6.1, §6.3 and YAML `m2_evidence`/`milestones[M1..M2].commit` to their replayed equivalents, or mark them explicitly historical-and-unreachable; add `final_gate_round4` with round 4's ACCEPT and correct `last_verdict`. No code, no re-seed, no re-pin — the protected blob, pre-allocated tag `ci-pin/enforcement-p1-r1`, and variable value all stand unchanged.

VERDICT: CHANGES-REQUIRED
