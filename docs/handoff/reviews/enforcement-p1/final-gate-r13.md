# FINAL-GATE REGISTER — package p1 M3 round 13 attempt 1
accepted_sha: c5408d568eea711b1003e0e11bb340a73cddc6f2
base_sha: 9eef1f07731ed47193e743b9f5437163f37dec06   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ c5408d568eea711b1003e0e11bb340a73cddc6f2 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r13.md=663dbac3ee51986cf056f109961b0c91e5e867f91abe23c2e3c47ea4fcf1d726   # copied+hashed by this bridge; not in the prompt
manifest_sha256: c57416b8d0385e839b1c801bbf1e2f26f865e3f5b546c5c942593f8fae99ddcb
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 9
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement P1 / M3, round 13

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.7KTCTL4CdM/snap`, detached at `c5408d568eea711b1003e0e11bb340a73cddc6f2` (= A).
**Range:** `9eef1f07731ed47193e743b9f5437163f37dec06..c5408d568eea711b1003e0e11bb340a73cddc6f2` — 42 commits, 17 files, +5480/−26. Base is a strict ancestor of A; `BASE != A`.
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r13.md` — 1591 lines, 138320 bytes, `sha256 = 663dbac3ee51986cf056f109961b0c91e5e867f91abe23c2e3c47ea4fcf1d726`.
**Lenses applied in full:** `stock-gl-interaction`, `inventory-costing`.

---

## 1. Census / classification tables — content-derived evidence of inspection

Each required table was parsed from the handback and cross-verified against the sealed snapshot, not read.

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §3.5 Mechanism × table fixture coverage | **10** mechanism rows × 4 table columns | `create` | `raw_sql` |
| §3.5 per-gate-round provenance sub-table | **7** + total | `M1 initial matrix` (95) | `round 10` (14) |
| §4 Baseline ↔ DPA-register cross-check | **14** | `V1` — `TestE2EGLPosting` hard-deletes sealed GL | `S0 residue` — `WeightedAverageCostService` reference params still `?string` |
| §4 partition arithmetic | **3** | `mapped to a register item or S0 residue` = 13 | `total` = 34 ✓ |
| §4 never-covered-by-register list | **18** rows spanning 21 census ids | `1` — `FixOrphanedProducts.php:128` | `34` — `RepositoryTransferService.php:105` |
| §5 Violation census (seed baseline) | **34** | `#1 app/Console/Commands/FixOrphanedProducts.php:128` · stock_levels · create · `FixOrphanedProducts::executeCommand` | `#34 app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` · journal_entries · delete · `RepositoryTransferService::transfer` |

**Independent verification performed (not accepted on assertion):**

- §5 ↔ baseline JSON is a **1:1 bijection**: all 34 census rows map to exactly one of the 34 keys in `document-per-action-baseline.json`; 0 ambiguous, 0 orphan keys.
- All **34/34** `file:line` anchors resolve in the snapshot to exactly the claimed mechanism — e.g. `#10 ReverseWriteOffService.php:186` → `$inverse->save();`, `#29 StockAdjustmentService.php:1618` → `return StockLevel::firstOrCreate(`, `#34 RepositoryTransferService.php:105` → `$draft->delete();`. Zero stale anchors.
- §3.5's `133` is derived three ways and reproduces: `grep -c "^            \['mechanism' =>"` → **133**; per-round sum 95+7+6+6+1+4+14 = **133**.
- Arithmetic 13 + 21 = 34 holds; the WAC double-count corrected in round 1 is genuinely resolved.

## 2. What I verified and found sound

**Scope / control-file preflight.** All 17 paths inside `apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`; the `grep -v` allowlist filter yields **0** lines. No control file touched (`adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*-reviewer.md`), no `*control-manifest*` surrogate, no `app/` path, and **no `permissions:`/`contents: write` grant** in the `ci.yml` diff (R6-H-5 owner check passes).

**Control plane.** Receipt `base_sha` = YAML `base_sha` = range base; `manifest_sha256 c57416b8d0…` matches the live manifest **and** the YAML `control_manifest` mirror; `progress_path`/`final_milestone: M3`/`lenses`/`max_fix_rounds: 9` all agree; exactly one final milestone at `status: review`; `fix_rounds 9 ≤ 9`.

**Two-phase pinned baseline (R3-H-4 / R4-H-3).** Seed commit `c1bded36a` contains exactly one file; metadata commit `78e1f08ac` is its direct child. Blob identity is exact across all four surfaces: seed blob = A's blob = worktree hash = YAML mirror = `1381983d463e6c546535be907d4aa1c7ca94c597`. Baseline unchanged since seed.

**Ratchet.** Three directions present and genuinely fail-closed on unset/empty variable, malformed hash, unfetchable blob, **mirror drift**, and any added key. `mirrorPin()` returning `null` still fails the comparison — no silent skip.

**CI wiring.** `backend-dpa-guard` at `:180`, no `if:` key (confirmed by parse), skipped-job-semantics comment at `:1281`, `all-checks-pass` `needs` membership at `:1288`. Nonzero-selected-test assertion on both steps under `set -o pipefail`. All 5 `run:` blocks pass `bash -n` — the round-3 CRITICAL bash-syntax class is closed, and the pin-tag `sed|awk|tr` extraction **executes on the real YAML bytes** and yields the pinned name. `on:` graph is exactly PR→main, PR→dev, push→main, `workflow_dispatch`.

**M0 preconditions.** 3C ancestry holds on both hops (`d07868cca → 1e8c0fa03 → base`). S0 seam re-verifies at the base the row names: `:1769` `private function recordMovement(`, `:1784/:1785` the two nullable reference params, `:1788` `assertReferenceLinkagePaired`.

**Rebase integrity.** New base `9eef1f077` touches 3 paths, **none under `app/`** — the scan corpus is untouched, so the census cannot move, as claimed. 20 Architecture classes at A confirms the `20 − 2 − 4 = 14` arithmetic; `PendingSealMigrationTest` is in `tests/Feature`, so the four-red claim is unaffected.

**Lens checklists.** Scanner constants verified against the live models: all four `TABLE_MODELS` FQCNs and `$table` values exact; `source_type`/`source_id` and `reference_type`/`reference_id` present in `$fillable`; `StockLevel`/`BatchStock` carry **no** reference column, correctly driving the movement-pairing predicate; `SOFT_HOLD_COLUMNS` excludes `quantity` and the re-keying columns, so `update(['variant_id' => …])` stays in contract. `linkedFormExists()` agrees with the docblock on every table/mechanism pair, including `stock_movements` MUTATE/DELETE as append-only (lens dim. 9) and `journal_entries` DELETE always-violation. Rule 19: zero float casts, `floatval`, or `number_format` in the new code. Test quality: no `assertTrue(true)`, no mocks, no `RefreshDatabase`, no DB — the matrix gate genuinely runs the scanner and compares, it is not a tautology. Blind-spot "verified zero live instances" claims for I, J and D **independently reproduced**: no `replicate()`/`clone`/`firstOrNew` on a contract model, no relation-mediated create on one, and the only injected-connection raw SQL (`DeliveryNoteBillingClaimService`) targets non-contract tables, exactly as disclosed.

The blind-spot disclosure (A–J + D2), the named re-pin trigger, the candidate-deletable-detector note and the burn-down ticket are unusually honest work. Blind spot H (a baseline key is a slot, not a write) is inherited from the brief's own mandated key-set design, not an executor defect.

## 3. Finding — CONFIRMED, control family, blocking

**[CRITICAL] `docs/handoff/progress/enforcement-p1.progress.yaml:89` — the pre-allocated pin-tag name recorded in A is already consumed by an annotated tag at a superseded, non-ancestor commit, which makes promotion step 3 unexecutable and step 4a a guaranteed re-gate trigger.**

A pins `dpa_baseline_pin_tag: ci-pin/enforcement-p1-r1`, with the inline justification *"no `ci-pin/enforcement-p1-*` tag exists locally or on origin, so n = 1"*, and handback §8.12 lists the name under **"Unchanged and verified"** (§6.2 line 458 restates the allocation evidence as `git tag -l 'ci-pin/enforcement-p1-*'` → **0**).

That precondition is false in the repository this snapshot is a worktree of:

```
git cat-file -t ci-pin/enforcement-p1-r1      -> tag           (annotated, already pushed)
git rev-parse ci-pin/enforcement-p1-r1^{commit} -> d65244fe27d3c0c24f045b820f64f9541e563e01
git merge-base --is-ancestor <that> A          -> NO  (superseded by the fifth rebase)

annotation:
  enforcement-p1 ACCEPT r12
  accepted_sha:     d65244fe27d3c0c24f045b820f64f9541e563e01
  register_sha256:  f969dfcb…  == sha256(final-gate-r12.md)                    [verified]
  handback_sha256:  a5521c96…  == sha256(HANDBACK-…-r12.md)                    [verified]
```

Round 12 ACCEPTed the pre-rebase tip `d65244fe2`, the owner executed step 3 and pushed the tag; the dispatch then went red on three unrelated gates and the candidate rebased to the new A. The tag correctly **stays** (R5-C-2: never deleted) — but the reallocation the same rule mandates was never performed. The current handback's bytes hash to `663dbac3ee…`, so the live tag attests a register and a handback that are **not** the ones under review.

**Failure scenario, reproduced:**
- §5 step 3 — *"the owner creates EXACTLY that tag at A and verifies it resolves to A BEFORE touching the variable or dispatching"* — cannot be executed. `git tag -a ci-pin/enforcement-p1-r1 A` fails on the existing name; R5-C-2 forbids deletion (the r10 delete affordance is REVOKED) and forbids reuse; the allocation rule `n = 1 + highest existing` now yields **`ci-pin/enforcement-p1-r2`**. Promotion deadlocks at step 3.
- Step 4a's mandatory re-verification (`tag target == A`, exact annotation bytes) compares `d65244fe2` against `c5408d568` → **mismatch → named re-gate trigger** (R5-C-3 / R6-H-5). Under R6-H-3 a mismatching tag *is* the "re-gate with a newly reviewed name" case — and R5-C-2 requires that name be **non-null in A and reviewed at that milestone's gate**, so it cannot be repaired after acceptance or in the closing admin commit.
- **The CI guard masks it.** The `Fetch the durable baseline pin tag` step reads the name from the YAML and fetches `r1`, which exists; the protected blob `1381983d4…` is reachable from the superseded commit because the baseline bytes are identical. `backend-dpa-guard` therefore goes **green** while fetching a durable ref that attests a different commit — the ratchet cannot surface this, so nothing downstream catches it before the owner hits step 3.

This is not the self-referential record class the terminal condition contemplates: it is a control-plane value that fails an operative predicate and blocks the promotion sequence. It is also the series' recurring carried-value class — §8.12's "Re-derived, never carried" list re-derived the seed, the pins, the milestone pointers and every M0 predicate, but the tag name sat in the adjacent "Unchanged and verified" set, where only the *string* was checked and its *freeness* — the property the allocation rule actually constrains — was not re-tested after the rebase.

**Fix (small, mechanical, but must be reviewed inside A):** allocate `ci-pin/enforcement-p1-r2` in a metadata commit, update `dpa_baseline_pin_tag` and its now-false inline justification, correct handback §6.2's allocation evidence and §8.12's "unchanged" claim to record that `r1` was consumed at `d65244fe2` under the round-12 ACCEPT, and re-hand over. The protected blob, the variable and the manifest do **not** move.

## 4. Secondary notes (record class — ship as named notes, not blockers)

1. **`docs/handoff/progress/enforcement-p1.progress.yaml:8-19`** — the `max_fix_rounds` header comment still says *"the receipt was re-pinned to base_sha 28c84edb3"*; the receipt and the YAML now both read `9eef1f077` (28c84edb3 is its ancestor). Stale by one rebase.
2. **Handback line 1440** — the gate history places the owner dispatch before "round 12 → PARENT-invoked bridge under the terminal condition", i.e. describes round 12 as pending. The pin-tag annotation shows round 12 already returned ACCEPT and the owner had entered step 3. The narrative understates how far promotion actually proceeded before the red run.
3. **Brief deliverable 5 deviation, disclosed but worth the parent's eye** — the job runs the two DPA classes by path, not `--testsuite=Architecture` scoped to *"the DPA guard class + the provably-static tests"*. The four red classes are named and the 14 excluded-and-green are counted, so the exclusion is recorded; the narrower scoping is a judgement the parent should ratify rather than inherit.
4. Burn-down ticket item 4 (nonzero-count vs expected-count in CI) remains open and correctly ticketed; `M3.commit 7c2229d19` trailing A by two record commits is consistent with the stated "last CODE commit" re-derivation rule and is not faulted.

---

The substance of this package is strong and, on every axis I could mechanise, it held: the baseline is a verified bijection with a census whose 34 anchors all resolve, the ratchet fails closed on every degenerate input, the CI job is syntactically executable and correctly aggregated, and the blind spots are disclosed rather than argued away. It fails this gate on one confirmed control-family defect that the record asserts as verified and that would stop the owner at promotion step 3.

VERDICT: CHANGES-REQUIRED
