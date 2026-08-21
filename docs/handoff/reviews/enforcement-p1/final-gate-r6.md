# FINAL-GATE REGISTER — package p1 M3 round 6 attempt 1
accepted_sha: 3d7f55457a12d045dd3df9eb57e1f9e4acd65b88
base_sha: 67d18746a5e1c565c1f33c0394ae518af0e1cbf0   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 3d7f55457a12d045dd3df9eb57e1f9e4acd65b88 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r6.md=31dd0c50e635aaf5ce06c78998fbc8e81fffdf5b948ec9dea0de01f469d002a6   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
Verification complete. Writing the register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 / M3, round 6

**Package:** enforcement-p1 · **Milestone:** M3 (whole-package gate) · **Round:** 6
**Accepted-candidate tip under review (A):** `3d7f55457a12d045dd3df9eb57e1f9e4acd65b88`
**Range:** `67d18746a5e1c565c1f33c0394ae518af0e1cbf0..3d7f55457a12d045dd3df9eb57e1f9e4acd65b88`
**Sealed snapshot:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.A9Zfieom9o/snap` — `HEAD == A`, `git status --porcelain` empty
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r6.md`
**Handback sha256 (computed here, independently):** `31dd0c50e635aaf5ce06c78998fbc8e81fffdf5b948ec9dea0de01f469d002a6` (90646 bytes, 1080 lines)
**Lenses applied in full:** `stock-gl-interaction`, `inventory-costing`

---

## A. Structural / contract preflight

| Check | Result |
|---|---|
| Base a **strict** ancestor of A, `BASE != A` | PASS — 28 commits in range |
| Path allowlist (`apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`) | PASS — 15 files, **0 violations** |
| Production code touched | PASS — none (`apps/api/app/`, `apps/web/src/`, `apps/pos/` all clean) |
| Control-file preflight (bridge, brief, harness, control manifest, `*-reviewer.md`, `*control-manifest*` surrogates) | PASS — none touched |
| Workflow permissions grant (gate-r6 R6-H-5) | PASS — no `contents: write` / `permissions:` added |
| `ci.yml` deletions | PASS — exactly one: the old `needs:` line, replaced by the same list **plus** `backend-dpa-guard`. No existing job weakened or removed |
| Progress-YAML field-check vs. dispatch receipt | PASS — `base_sha` `67d18746a`==receipt; `progress_path`, `final_milestone: M3`, `lenses`, `max_fix_rounds: 5` all cross-check; structured `control_manifest {path, sha256: 709b6fe9a8…}` matches receipt `manifest_sha256` |
| Exactly one final milestone at `status: review`; `fix_rounds (3) <= max (5)` | PASS |
| No milestone carries an `owner_gate:` field (harness `:49-50` safe encoding) | PASS |
| Pins non-null: `dpa_baseline_seed_commit` / `_protected_blob` / `_pin_tag`, `ratchet_trust_model_ack`, `commit_series` | PASS |
| Dispatch receipt currency — the handback's own ⚠️ flagged it stale at `41fb478c2` | **RESOLVED** — receipt now reads `base_sha: 67d18746a…` (re-pinned 2026-08-20) |

**Baseline-pin topology (gate-r3 R3-H-4 / gate-r4 R4-H-3), verified in the object store:** seed commit `d2802c0b457361f1fbe2873ac4773dc8d5c32409` contains **exactly one file** (the baseline), **is an ancestor** of A, and yields blob `1381983d463e6c546535be907d4aa1c7ca94c597` — byte-identical to the blob at A and to the YAML mirror. Distinct metadata commit `de067d50b` follows it. The two-commit topology survived the rebase intact; §8.8's "pointer correction, not a re-seed" is **confirmed**, not merely asserted.

**M0 S0-seam re-verification at `base_sha`, line-for-line:** `:1769` `private function recordMovement(` · `:1784` `?StockMovementReferenceType $referenceType = null,` · `:1785` `?string $referenceId = null,` · `:1788` `$this->assertReferenceLinkagePaired($referenceType, $referenceId);`. PASS.

---

## B. Census / classification tables — content-derived evidence of inspection

Per gate-r8 R8-H-2, each mandated table with its row count and first/last row keys, derived by parsing the handback's own bytes:

**1. §5 — Violation census / M2 seed baseline (the review target for deliverables 3–4)**
- **Row count: 34** (ids contiguous `1..34`)
- **First row key:** `#1 · app/Console/Commands/FixOrphanedProducts.php:128 · stock_levels · create · FixOrphanedProducts::executeCommand` → baseline key `app/Console/Commands/FixOrphanedProducts.php::App\Console\Commands\FixOrphanedProducts::executeCommand::stock_levels::create#1`
- **Last row key:** `#34 · app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105 · journal_entries · delete · RepositoryTransferService::transfer` → baseline key `…RepositoryTransferService::transfer::journal_entries::delete#1`
- **Independently cross-checked against the checked-in baseline: the 34 census `(file, table, mechanism, class::function)` tuples equal the 34 baseline keys EXACTLY, in both directions — 0 in census not in baseline, 0 in baseline not in census.** Baseline itself: 34 keys, sorted ✓, unique ✓, line-number-free ✓. Distribution `stock_levels 13 · stock_movements 10 · inventory_batch_stock 7 · journal_entries 4`.

**2. §4 — Baseline ↔ DPA-register cross-check (deliverable 4)**
- **Row count: 14** (V1–V10, one combined G1/G2/G3 row, three S0-residue rows)
- **First row key:** `V1 — TestE2EGLPosting hard-deletes sealed GL` → scan result "no site"
- **Last row key:** `S0 residue — WeightedAverageCostService reference params still ?string` → `:260/:425/:578/:767` → violations #21/#23/#26/#28
- Spot-verified in the tree: V2 `AccountingService.php:398` = `$entry = JournalEntry::create([` ✓; V3 `GeneralLedgerService.php:1249` = `$entry = JournalEntry::create([` ✓; V8 `SupplierCreditNotePostingService.php:666` = `$stockLevel->save();` inside `issueBonusReturnStock` (`:591`), paired with `StockMovement::create([` at `:668` — the arm-(b) pairing credit, accurate as written ✓.

**3. §4 — "Violations the register never covered" sub-table**
- **Row count: 17 physical rows expanding to 21 census ids**
- **First row key:** `1 | FixOrphanedProducts.php:128`
- **Last row key:** `34 | RepositoryTransferService.php:105`
- **Partition arithmetic re-derived, not trusted:** mapped `{3,12,19,20–28,33}` = 13; never-covered `{1,2,4–11,13–18,29–32,34}` = 21; `13+21=34`, union is exactly `1..34`, intersection empty. The r6 correction of the long-carried "17" is **sound**.

**4. §3.5 — Mechanism × table fixture coverage (deliverable 6)**
- **Row count: 10 mechanism rows × 4 table columns; 115 pinned cells**
- **First row key:** `create` → `journal_entries P+N (+half-linked P, unresolvable-payload P, nullable-linkage P, proven-linkage N) | stock_movements P+N (+half-linked P, nullsafe P, coalesced N) | stock_levels P+N (+relation-mediated P+N) | inventory_batch_stock P+N`
- **Last row key:** `raw_sql` → `journal_entries P-only ×3 | stock_movements P-only ×2 | stock_levels P-only | inventory_batch_stock P-only`
- **Count verified three independent ways in the snapshot:** `grep -c "^            \['mechanism' =>"` → **115**; regex-parsed entries → **115**; unique `(class, method, table, mechanism)` → **115** (no cell double-listed). Per-mechanism totals reconcile with the table's own P/N annotations (e.g. `raw_sql` 3+2+1+1 = 7 ✓; `decrement` 1+1+3+3 = 8 ✓; `delete` 1+1+2+2 = 6 ✓).

**5. §3.5 — Per-round cell origin table:** 5 rows + total. First `M1 initial matrix = 95`; last `round 5 — setRawAttributes() erasure ×1 = 1`; sums to **115** ✓.

**6. §2 — M0 predicate table:** 7 rows. First `git rev-parse --verify` on base/3C-merge/3C-reviewed; last `commit_series` / `ratchet_trust_model_ack` / `control_manifest` non-null + manifest sha256 `709b6fe9a8…`.

---

## C. Guard, ratchet and CI verification

**The round-3 CRITICAL is genuinely closed.** I re-ran the standing `actionlint` substitute myself, extracting each `run:` block from the YAML's own bytes: **`backend-dpa-guard` 5/5 OK; whole workflow 67/67 OK, 0 FAILED.** I then executed the "Fetch the durable baseline pin tag" step's own bytes — it emits `Fetching pin tag: ci-pin/enforcement-p1-r1`, resolving the name from the progress YAML. The `tr -d "\"'"` repair holds.

Brief-mandated acceptance grep reproduces **verbatim** at the digested tip:
```
180:  backend-dpa-guard:
1281:    # backend-dpa-guard is included below on exactly the same reasoning: it
1288:    needs: [backend-lint, …, backend-dpa-guard, …, route-manifest-drift, types-drift]
```
Job carries **no `if:`** ✓ · in `all-checks-pass` `needs` ✓ · every `needs` entry resolves to a real job (0 dangling) ✓.

**Ratchet (`DocumentPerActionBaselineRatchetTest`)** implements all three directions and fails closed on every named condition — variable unset/empty, malformed hash, **mirror ≠ variable drift**, unfetchable blob (`git cat-file` exit), and any added key. Authority is `getenv(DPA_BASELINE_PROTECTED_BLOB)`; the YAML field is read only as a tamper tripwire, and a deleted/unparseable mirror yields `null` → assertion failure (fails closed, not open). Correct per gate-r3 R3-C-1.

**No scanner-emittable mechanism escapes the completeness gate:** the 10 buckets reachable from `WRITE_METHODS` + `query_builder` + `raw_sql` equal the test's `MECHANISMS` const exactly — set difference empty.

**Lens application.** `stock-gl-interaction` and `inventory-costing` both bite mainly on dimension 1 (document-per-action), 6/7 (WAC + batch), 9 (append-only) and 10 (float), and on test quality. Findings: the per-table rules encode append-only correctly (`stock_movements` MUTATE **and** DELETE always violation; `journal_entries` DELETE always violation), and the WAC cluster (#20–#28, 9 sites in one file) is the largest baseline group — correctly enumerated, not remediated, per the guard-only scope. **Rule 19:** zero `(float)`, `floatval`, `(double)`, `number_format` across all new files. **Test quality:** no `assertTrue(true)`, no mocks, no `markTestSkipped`, no DB/`RefreshDatabase` (static-only, matching house style); `scanFixtures()` passes the production tree as *context* so fixtures resolve through the same relation/inheritance/return-type/chokepoint indexes as live code — a genuine anti-vacuity measure, not a formality; `linkedFormExists()` is derived from the scanner's rule surface rather than hardcoded in the test. The journal-entry MUTATE exemption covering `fiscal_hash`/`previous_hash`/`chain_sequence` diverges from the lens's append-only stance for posted entries, but is explicitly and prominently disclosed as a scope boundary and is faithful to the brief's own `journal_entries` contract (presence of `source_type`/`source_id`, not chain integrity). **Not a finding.**

Blind spots A–H in the scanner docblock are unusually honest, including H ("a baseline key is a SLOT, not a WRITE"), which names the sharpest limit of the key-set design rather than leaving it implicit.

---

## D. Findings

### [IMPORTANT · P2] Census row #11's file:line anchor is stale against the reviewed tip, and the handback contradicts itself on that row

**Where:** handback §5 line 402 and §4 line 356 — both anchor violation **#11** at `app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php:257`.

**What the tree says at A:**
```
:257  ->orderBy('document_date')            # inside getToBillPartnerRows() (230–300)
:530  $entry = JournalEntry::create([       # inside generateYearEndAdjustment() (487–570)  <- the real site
:591  $reversalEntry = JournalEntry::create([   # dev's new generateReversalEntry(), correctly LINKED
```
At the **pre-rebase** base `41fb478c2`, line 257 *was* `$entry = JournalEntry::create([`. The rebase moved it to `:530`; the anchor was not re-derived.

**Why it matters, precisely:**
1. It falsifies two explicit claims in the digested bytes — §5's *"Pasted verbatim from the scanner at this tip"* and §8.8's *"THE CENSUS WAS RE-DERIVED AT THE REBASED TIP AND IS UNCHANGED."* The key set was re-derived; the human-facing `file:line` column was not.
2. **The document contradicts itself on this exact row.** §8.8's own targeted-verification table states the correct post-rebase fact (*"`:530` is still inside `generateYearEndAdjustment` and still the baselined violation"*) while §5 and §4 still print `:257`. One of the two is wrong in bytes the owner is about to bind.
3. Brief §7 item 3 makes the census tables *"the review targets, not appendices"*, and P3(a) **consumes this census**. A P3 executor following row #11 lands on an unrelated pagination query in a 600-line file.
4. These bytes are one step from being frozen: the owner's annotated pin tag records `handback_sha256`, and P3-M0 verifies the landed handback against it. After tagging, correcting this costs a new A, a new digest and a full re-gate.

**What is NOT wrong** (stated so the fix is scoped correctly, and so this is not read as a guard defect): the **baseline key is unaffected** — keys are line-number-free by design (blind spot C), the enclosing function is still `generateYearEndAdjustment`, and key `…::generateYearEndAdjustment::journal_entries::create#1` is byte-identical in the census and the baseline. The scanner, the baseline, the ratchet, the seed blob, the pins and the CI job are all correct. **No re-seed, no re-pin, no baseline edit, no code change is required.**

**Scope of the defect — I swept for the pattern rather than reporting one instance:** of **38** resolvable `app/` `file:line` references in the handback, **exactly one** is stale. The other 37 (including all 33 remaining census rows, the §4 register anchors, and the M0 S0-seam quadruple) resolve to the claimed write or seam token. This is an isolated slip, not systemic rot.

**Suggested fix (two anchor substitutions, nothing else):** `:257` → `:530` at §5 line 402 and §4 line 356. **And extend the §8.8 post-rebase sweep to close the class mechanically:** that sweep already resolves every 7–40 hex token as a commit and asserts ancestry — it caught 15 dangling SHAs — but it does not validate `file:line` anchors. Adding an anchor check (resolve each `<file>:<line>`, assert the line still carries the claimed mechanism/table token) would have caught this row and prevents the seventh firing of the append-vs-substitute class the brief tracks at r4/R0-3, r10, r12, r14, r18 and r21.

---

## E. Assessment

This is a strong package. The guard, baseline, ratchet, fixture matrix and CI job all verify independently, the scope is clean, the trust-anchor mechanics are correct, and the handback is exceptionally candid — §3.8's live `journal_entries` row delete, §8.1's named re-pin trigger, §8.3's candidate-deletable detector, blind spot H, and the §8.8 disclosure of the injected-connection raw-SQL gap are all volunteered, not extracted. The round-3 CRITICAL is fixed at the artifact-bytes level rather than by promise.

I do not carry the finding as a note, for one reason: this package's own final-gate rounds 1 and 2 were both CHANGES-REQUIRED for precisely this defect class — round 1 for §4's "COMPLETE list" that was not complete, round 2 for a scope proof that *"did not reproduce at the tip it certified."* §5 is a higher-standing table than the scope proof and it does not reproduce at the tip it certifies. Applying a weaker standard now, on the round where the bytes get cryptographically bound and handed to P3, would be inconsistent with the gate that produced those two rulings. The fix is two characters of substance and one sweep extension.

**Findings: 0 Critical · 1 Important · 0 Minor.**

VERDICT: CHANGES-REQUIRED
