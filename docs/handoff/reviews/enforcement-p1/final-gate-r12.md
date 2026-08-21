# FINAL-GATE REGISTER — package p1 M3 round 12 attempt 1
accepted_sha: d65244fe27d3c0c24f045b820f64f9541e563e01
base_sha: 28c84edb3105624b917a44efa88254cedd617d41   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ d65244fe27d3c0c24f045b820f64f9541e563e01 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r12.md=a5521c960fe77c298712ff25cd5df38c431c08c744738c2c400f8393ebbe4c1c   # copied+hashed by this bridge; not in the prompt
manifest_sha256: c57416b8d0385e839b1c801bbf1e2f26f865e3f5b546c5c942593f8fae99ddcb
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 9
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 · M3 · round 12

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.YZtQ0ZFnxf/snap` (detached `d65244fe27d3c0c24f045b820f64f9541e563e01`, `HEAD` re-asserted post-run)
**Range:** `28c84edb3105624b917a44efa88254cedd617d41..d65244fe27d3c0c24f045b820f64f9541e563e01` — 42 commits, 17 files, +5478/−26
**Handback:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r12.md`
**Handback `sha256`:** `a5521c960fe77c298712ff25cd5df38c431c08c744738c2c400f8393ebbe4c1c` (1510 lines)
**Lenses applied:** `stock-gl-interaction`, `inventory-costing` — both contracts opened and run as checklists.

Standing posture for this round: I did not accept the handback's numbers. Every load-bearing claim below was re-derived from the snapshot's own bytes or by re-executing the scanner myself.

---

## 1. Required census / classification tables — content-derived evidence of inspection

### §3.5 — Mechanism × table fixture coverage (deliverable 6)
- **Row count: 10 mechanism rows × 4 table columns.**
- **First row key:** `create` — `journal_entries` cell reads `P+N (+ half-linked P, unresolvable-payload P, nullable-linkage P, proven-linkage N)`
- **Last row key:** `raw_sql` — reads `P-only ×3 | P-only ×2 | P-only | P-only`
- Declared **133 pinned cells**. **Independently verified:** `grep -c "\['mechanism' =>"` → `133`; the per-round origin table (95+7+6+6+1+4+14) sums to `133`. The claim is derived, not asserted.
- Supporting sub-table (per-gate-round origin): **7 rows**, first key `M1 initial matrix` (95), last key `round 10` (14), total row `133`.

### §4 — Baseline ↔ DPA-register cross-check (deliverable 4)
- **Row count: 14.**
- **First row key:** `V1 — TestE2EGLPosting hard-deletes sealed GL` → *no site* / remediated, shape stays fixture-pinned.
- **Last row key:** `S0 residue — WeightedAverageCostService reference params still ?string` → `:260/:425/:578/:767` → violations #21/#23/#26/#28.
- Partition sub-table: **3 rows** (mapped 13 · never-covered 21 · total 34) — arithmetic checks.
- Never-covered sub-table: **19 rows** covering 21 census ids; **first row key** `1 | FixOrphanedProducts.php:128`; **last row key** `34 | RepositoryTransferService.php:105`.

### §5 — Violation census, M2 seed baseline
- **Row count: 34.**
- **First row key:** `#1 | app/Console/Commands/FixOrphanedProducts.php:128 | stock_levels | create | FixOrphanedProducts::executeCommand`
- **Last row key:** `#34 | app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105 | journal_entries | delete | RepositoryTransferService::transfer`
- **Independently reproduced.** I ran `DocumentPerActionWriteScanner` myself against the snapshot's `apps/api/app` (php-parser from the main checkout, nothing written into the snapshot): **116 sites → 34 violation / 67 linked / 15 not_applicable**, matching the handback's claimed `34/67/15` exactly. My regenerated key set **diffs empty** against the committed baseline (34/34, all unique). Every `file:line` in §5 matches my scan. The §5 display index is line-ordered within a file while the baseline JSON is key-ordered — a presentational difference only; the sets are identical and the handback states the `file:line` column is deliberately not part of the key.

---

## 2. Independent verification performed (not taken on the record's word)

| Check | Method | Result |
|---|---|---|
| Path allowlist | `git diff --name-only` filtered against the brief's three globs | **0** outside; 17 files |
| Control-surface preflight (R7-C-1) | grep range for `adversarial-review*`, brief, harness, `*-reviewer.md`, `*control-manifest*` | **0 hits** |
| Production code | grep range for `apps/api/app/`, `apps/web/src/`, `apps/pos/` | **0 paths** — guard-only |
| Seed-pin integrity | `git rev-parse <seed>:<baseline>` vs YAML mirror vs `HEAD:<baseline>` | all three `1381983d463e…` — **identical** |
| Baseline regeneration | re-ran scanner, diffed key sets | **byte-identical, 34/34** |
| **Ratchet liveness (growth)** | planted an unlinked `JournalEntry::create` in a scratch tree, re-scanned | **fires** — 1 NEW key, correct reason string |
| **Anti-growth / matched-growth tamper** | appended the matching key to the baseline, compared vs owner-pinned blob | **fails closed** — 1 added key reported |
| CI anchors | `grep -n backend-dpa-guard ci.yml` | `:180` def · `:1281` comment · `:1288` `needs` — reproduces the YAML's claim exactly |
| Workflow authority (R6-H-5) | grep for `permissions:` | **none in the file** — no `contents: write` grant |
| Shell validity (round-3 CRITICAL) | `bash -n` over every `run:` block extracted from the YAML | **67/67 pass** |
| Manifest / receipt binding | sha256 of manifest vs YAML pin vs receipt | `c57416b8d0…` — **three-way match** |
| Projection cross-check (R11-M-2) | receipt `progress_path`/`final_milestone`/`lenses`/`max_fix_rounds` vs manifest p1 block | **all four match** |
| Field-check (R9-C-1 / R10-H-1) | `base_sha` == range base == receipt; M3 `status: review`; `fix_rounds 9 ≤ max 9` == manifest | **passes** |
| §7.4 scope proof | re-ran `git diff --stat <base>..HEAD` | reproduces the pasted stat **exactly** |

---

## 3. Lens contracts applied

**`stock-gl-interaction`** — both-sides discipline honoured: the delta touches **zero** production paths, so no seam behaviour changes; I verified the GL side and the stock side of every dimension against the snapshot rather than assuming. Dim 1 (document-per-action) is the artefact under review and is correctly encoded. Dim 9 (append-only) is enforced for `stock_movements` — MUTATE and DELETE are unconditional violations — and for `journal_entries` DELETE (census #34 is exactly that live row delete, surfaced and escalated in §3.8/§8.5). Dim 5: the scanner *sees* the POS dual-writer pair (`ReceiptCreationService:954/959`, `PosCoreReceiptProjection:2005/2012` and `:2410/2417`) and classifies all four `linked` — correct for a write-linkage contract; dual-writer detection is a semantic judgement outside this guard's remit and is not claimed. Dim 10: no `(float)`, `floatval`, `number_format` or bcmath anywhere in the delta. Lens result — spec ✅, quality APPROVED.

**`inventory-costing`** — WAC engine untouched; the 9-site WAC cluster (#20–#28) is enumerated correctly and is the largest single-file cluster, matching the S0 residue. Opening-balance paths (`OpeningBalancePostingService` #12–#14, `ResetOpeningBalanceService` #15–#16) are caught as unlinked, which is the correct costing-lens reading. Batch/FEFO writes enumerated across `BatchStockService`, `BatchStock`, `FEFOInventoryService`. Precision checklist: no float, no scale arithmetic, no `getScale()` calls introduced. Test quality: no `assertTrue(true)`, no `markTestSkipped`, no mocking of the unit under test (the real scanner runs over real fixtures), and correctly **no** `RefreshDatabase` — static-only, as both the brief and house style require. No SQLite-masking exposure (no DB). Lens result — spec ✅, quality APPROVED.

---

## 4. Named notes (ship with the ACCEPT; no new-family defect)

1. **Blind spot H — a baseline key is a slot, not a write.** Removing a baselined violation and adding a *different* unlinked write in the same (file, class, function, table, mechanism) bucket is CI-green. This is the sharpest limit of the key-set design; it permits substitution but never addition, and the exposure shrinks as the baseline burns down. Named in the scanner docblock rather than left implicit.
2. **Blind spot G — scan root is `app/` only.** `database/seeders/**` and `database/migrations/**` are unguarded by construction; live four-table writes exist there (`CoffeeShopSeeder`, `StockLevelSeeder`). Parent ticket requested; a future data-backfill migration writing `journal_entries` would be outside the guard.
3. **Blind spots I / J / D-residue** — unpersisted-model create paths (`replicate`/`clone`/`firstOrNew`/argument-position relation creates), cross-closure pairing reachability, and injected-connection raw SQL. Each verified **zero live instances**; I re-confirmed the census is exactly `34/67/15`, which is the measurement that claim rests on. Tracked in `docs/handoff/TICKET-dpa-scanner-depth-burndown-2026-08-20.md` (now inside the allowlist — the round-11 widening is closed at its root, verified).
4. **CI asserts nonzero tests, not an expected count.** Deleting a `#[Test]` method drops the guard from 6 to 5 and the `OK \([1-9][0-9]* test` regex still matches. Ticket item 4.
5. **`--testsuite=Architecture` narrowed to the two DPA classes.** 14 green static classes remain CI-dead. The brief's own fallback sanctions scoping to the DPA guard class when the suite cannot be wired wholesale, and wiring it wholesale would land a gate red-on-arrival for four pre-existing failures — which the brief forbids ("never cold"). The exclusion and its arithmetic (20 − 2 − 4 = 14) are recorded in `ci.yml`, §7.1 and §7.7, and the widening is named as a follow-up in the workflow comment itself. Recorded scope decision with disposition, already adjudicated across rounds 8–11 — not re-opened here.
6. **`journal_entries` lifecycle-update exemption** covers `fiscal_hash`/`previous_hash`/`chain_sequence` rewrites. Stated plainly in the scanner docblock; this is a document-justification guard, not hash-chain coverage. Correct scope boundary under the brief's presence-not-uniqueness rule; the meaningful property (linkage *erasure*) is caught by rule and fixture-pinned.
7. **Two disclosed operational residuals stand:** the re-pin trigger (an ordinal renumber reads as an added key, so a legitimate remediation can produce a red only an owner re-pin clears) and the detector being candidate-deletable. Both are in the ratchet docblock and the handback's operational owes, with the correct mitigations named.

---

## 5. Disposition

This round's authorized scope was four items; all four landed and verify: the ticket is inside the allowlist and `scope_proof` re-derives at the true tip (17 files, 0 violations); the PHPStan claim is qualified in both the YAML and §7.6; M3's commit pointer names `e259405f5` with the self-reference constraint stated inline; and §9 carries the round-10 disposition with its RED-first evidence.

No defect of the code, guard, census, control, or scope-of-substance family survived verification. The substance held under independent reproduction on every axis I could mechanise — including the two that matter most and that I proved myself rather than reading: the baseline regenerates byte-identical, and the ratchet fires and fails closed on a matched-growth tamper.

VERDICT: ACCEPT
