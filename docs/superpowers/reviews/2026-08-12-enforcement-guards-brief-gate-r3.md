# Adversarial brief gate — round 3

**Target:** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` r6, `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml`, and `docs/handoff/LEDGER.md` rows S-14/S-15, reviewed as one integrated working-tree dispatch package at `a5520f23c`  
**Prior inputs:** round-1 gate register (`4C/11H/2M`), round-2 gate register (`2C/7H/2M`), and round-0 r6 mechanical report (PASS). Round-0 mechanics were not repeated.  
**Mode:** hostile, read-only review; no implementation or acceptance-command execution. The only write is this report.

## Verdict

**CHANGES-REQUIRED.** r6 fixes several r2 defects, but the new control plane is not dispatch-safe. Two Critical findings leave both baseline ratchets bypassable and make the pre-promotion proof self-referential. Four High findings leave accepted-work pins and milestone sequencing forgeable or internally contradictory. There are no Minor findings.

## Round-2 resolution audit

| Round-2 finding | R3 disposition | Re-derived result |
|---|---|---|
| R2-C-1 | **NOT RESOLVED** | r6 removes the moving-branch comparison and defines seed commit/blob pins for both ratchets (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:147-155,242-244`; `enforcement-p1.progress.yaml:35-45,106-113`; `enforcement-p2.progress.yaml:25-33,96-103`). But the checker takes its supposedly protected hash from the same PR-editable progress YAML, with no trusted comparison protecting the pin itself. Matched growth plus a matching pin change is green; see R3-C-1. The “write pins after review” chronology is also inconsistent with the harness; see R3-H-4. |
| R2-C-2 | **PARTIAL** | The event graph is now stated truthfully and a pre-promotion `workflow_dispatch` is required (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:157,359-364`; `.github/workflows/ci.yml:3-8,1090-1107`). However, recording the run in the candidate YAML after the run changes the candidate SHA, so the recorded run cannot both target and be contained by the exact candidate promoted; see R3-C-2. |
| R2-H-1 | **PARTIAL** | The M0 circularity is removed: `quiet_window_ack` is preliminary and `merge_announcement_ack` is post-M4/pre-promotion (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:54,375`; `enforcement-p2.progress.yaml:15-23,55-61,88-95`). But writing the final acknowledgement after M4 acceptance mutates the accepted candidate without another gate and participates in R3-C-2. |
| R2-H-2 | **PARTIAL** | S-14 now requires a pre-promotion green dispatch run, exact head SHA, and executed new jobs/steps (`LEDGER.md:56`; `CODEX-DISPATCH-enforcement-guards-2026-08-12.md:359-364`). The evidence receipt is stored inside the candidate only after that run, creating the SHA cycle in R3-C-2. |
| R2-H-3 | **RESOLVED** | `test:tools` is specified as `vitest run tools/__tests__`, and `pnpm test:tools` is spelled identically in the deliverable, acceptance block, P2-M1, and P2-M4 (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:243,252-256,258-272,289`; `enforcement-p2.progress.yaml:96-103,120-127`). The existing suite imports Vitest APIs, e.g. `apps/web/tools/__tests__/audit-design-system.test.mjs:2`, `audit-pos-local-cache.test.mjs:8`, and `permission-map-drift-guard.test.mjs:5`. |
| R2-H-4 | **PARTIAL** | r6 adds M3 status/verdict checks and `dpa_3c_reviewed_sha`, but the alternate merge-commit path never requires the M3 recorded commit to equal `dpa_3c_reviewed_sha` (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:53`; `enforcement-p1.progress.yaml:18-22,90-97`). An unrelated ancestor can still be supplied; see R3-H-1. |
| R2-H-5 | **RESOLVED** | Country-defaults proof now requires the manifest, top-level `status: complete`, M7 pass/ACCEPT/commit, and accepted-tip → landed SHA → P3 base ancestry (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:312-315`; `enforcement-p3.progress.yaml:18-35,78-86`). The owning source identifies M7 as the whole-branch gate (`country-defaults-phase-a.progress.yaml:81-90`). |
| R2-H-6 | **PARTIAL** | `p2_m2_landed_sha` exists and M2 checks status plus ancestry (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:236,318`; `enforcement-p3.progress.yaml:15-17,61-64,95-102`). It is not tied to P2-M2's recorded commit or P2 final acceptance, and allowing it to be added after P3 dispatch creates an unspecified mid-flight integration; see R3-H-2 and R3-H-3. |
| R2-H-7 | **RESOLVED** | P3 acceptance is derived from class-(c) rows, requires anchored filters and a parsed nonzero test count, forbids fresh tests for class-(b), and makes zero-row modules census-only (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:322-344`; `enforcement-p3.progress.yaml:87-94,103-110`). |
| R2-M-1 | **RESOLVED** | All three progress headers identify r6 (`enforcement-p1.progress.yaml:5`; `enforcement-p2.progress.yaml:5`; `enforcement-p3.progress.yaml:5`). |
| R2-M-2 | **RESOLVED** | Option B now cites `ci.yml:844-848`, which contains both directory commands, including Accounting's run line at `.github/workflows/ci.yml:848` (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:233`). |

## Critical findings

### R3-C-1 — Both “protected” baseline pins are attacker-controlled inputs from the candidate tree

**Evidence:** The DPA checker is instructed to read `dpa_baseline_protected_blob` from `enforcement-p1.progress.yaml` and compare the working key set to that blob (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:150-155`; `enforcement-p1.progress.yaml:35-45,106-113`). The i18n checker does the same with `i18n_baseline_protected_blob` (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:242-244`; `enforcement-p2.progress.yaml:25-33,96-103`). Those pin fields are ordinary tracked candidate files. The repo has no `.github/CODEOWNERS`, and the brief defines no CI comparison of the candidate pin against a trusted external/default-branch value. Indeed, P1's permitted scope explicitly includes all `docs/handoff/**` paths (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:188-192`). The only protection is prose saying later writes are parent-owned (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:153-155`; `enforcement-p1.progress.yaml:73-76`; `enforcement-p2.progress.yaml:66-69`).

**Failure scenario:** a change adds a new unlinked write, regenerates the baseline with the matching key, and changes `dpa_baseline_protected_blob` to the new baseline blob. The checker faithfully loads that candidate-controlled blob; the working and protected key sets match, so both growth checks pass. The same attack applies to i18n. This recreates the exact matched-growth CI-green path R2-C-1 was required to eliminate.

**Required repair:** take the protected value from an authority the candidate cannot edit—for example an owner-controlled repository/environment variable or a trusted-base file read explicitly from the protected ref—and fail if the candidate changes its mirror. Bootstrap/re-pin must be an owner-authenticated operation outside the ordinary candidate diff. If a workflow-dispatch input carries the pin, bind and record that input and make PR/push events obtain the same value from protected state. Retain the parsed-set and matched-growth tests against that trusted input.

### R3-C-2 — Post-acceptance receipts stored in the candidate make exact-head verification self-referential

**Evidence:** The harness commits a milestone before review and marks it accepted only after the bridge verdict (`SELF-REVIEW-HARNESS.md:23-44`). r6 then requires the owner, after package acceptance, to run CI on the “exact accepted candidate SHA” and write the run receipt into that package's `pre_promotion_ci_dispatch` field before merge (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:359-364`; `LEDGER.md:56`; `enforcement-p1.progress.yaml:47-55`; `enforcement-p2.progress.yaml:35-45`; `enforcement-p3.progress.yaml:41-46`). P2 also requires `merge_announcement_ack` to be filled only after M3/M4 acceptance (`enforcement-p2.progress.yaml:20-23,58-61,112-121`). Both receipts live in files included in the promoted package.

**Failure scenario:** final gate accepts commit A. The owner dispatches A and gets run R. Recording R in `pre_promotion_ci_dispatch` creates commit B, so R's head is not the SHA now being promoted. Dispatching B and recording the new run creates C, ad infinitum. For P2, recording `merge_announcement_ack` already changes A before the remote run. Alternatively leaving the receipts uncommitted means the required YAML evidence is not in the promoted package and can be lost.

**Required repair:** store post-acceptance evidence outside the candidate commit—S-14 can hold the run URL/head/result and the announcement ledger can hold its acknowledgement—or define a separate parent attestation object cryptographically bound to the accepted implementation SHA. Do not require the attestation to be contained by the SHA it attests. If administrative metadata commits are allowed after the code gate, define their strict path allowlist, distinguish implementation SHA from promotion SHA, and rerun the remote gate against the actual promotion SHA or verify an explicit tree-equivalence contract.

## High findings

### R3-H-1 — The alternate 3C ancestry path never binds `dpa_3c_reviewed_sha` to the accepted M3 commit

**Evidence:** The source M3 record has a distinct `commit` and `verdict` (`wave3-3c-3d.progress.yaml:49-56`). P1 correctly requires M3 `status: passed` and an ACCEPT artifact, then says the M3 commit equals `dpa_3c_merge_sha` **or**, if `dpa_3c_reviewed_sha` is set, merely checks `dpa_3c_reviewed_sha → dpa_3c_merge_sha → base_sha` ancestry (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:53`; `enforcement-p1.progress.yaml:18-22,90-97`). Neither location says `M3.commit == dpa_3c_reviewed_sha` in that alternate branch.

**Failure scenario:** M3 has an ACCEPT verdict for commit X. The parent supplies a different ancestor Y as `dpa_3c_reviewed_sha` and a merge Z containing Y. Y → Z → base passes, while X need not be in Z at all. The machine gate again accepts a merge not proven to contain the reviewed M3 result.

**Required repair:** require exact equality `wave3 M3.commit == dpa_3c_reviewed_sha`, then verify `dpa_3c_reviewed_sha → dpa_3c_merge_sha → base_sha`. When reviewed and merge SHAs are equal, require M3.commit equality directly as already intended.

### R3-H-2 — `p2_m2_landed_sha` is not tied to P2-M2's accepted commit or the package's final gate

**Evidence:** P2-M2 has its own recorded `commit`, `verdict`, and status fields (`enforcement-p2.progress.yaml:104-111`), while P2-M4 is the package's whole-branch gate (`enforcement-p2.progress.yaml:120-127`). P3's operative check requires only that P2 M2 reads `status: passed` and that a parent-supplied `p2_m2_landed_sha` is an ancestor of the P3 working tip (`enforcement-p3.progress.yaml:15-17,61-64,95-102`; `CODEX-DISPATCH-enforcement-guards-2026-08-12.md:318`). It never requires `M2.commit → p2_m2_landed_sha`, an ACCEPT artifact for M2, P2 top-level `status: complete`, or P2-M4 ACCEPT.

**Failure scenario:** a partial snapshot carries `enforcement-p2.progress.yaml` with M2 marked passed, while `p2_m2_landed_sha` is set to any older ancestor already in P3. The written check passes even though the Feature-lane implementation commit was never merged. P3 then wires against a 2(b) outcome absent from its codebase—the same “progress snapshot without landed authority” defect r6 correctly closes for country defaults.

**Required repair:** pin both the reviewed P2-M2 commit and the landed P2 package SHA, require a parseable M2 ACCEPT verdict, and verify `M2.commit → p2_landed_sha → P3 base/tip`. Because package promotion is final-gate-controlled, also require P2 top-level `status: complete` and P2-M4 ACCEPT before describing M2 as landed.

### R3-H-3 — Allowing the P2 pin after P3 dispatch leaves no coherent base for P3-M2

**Evidence:** P3 says `p2_m2_landed_sha` may be filled after dispatch but before M2 (`enforcement-p3.progress.yaml:15-17`). M0 creates a fresh branch from the already-pinned `base_sha` without requiring P2 (`enforcement-p3.progress.yaml:78-86`). M2 later requires the P2 SHA to be an ancestor of “the tip P3 works from” (`enforcement-p3.progress.yaml:61-64,95-102`). P2-M2 changes the Feature CI strategy and workflow contract (`enforcement-p2.progress.yaml:104-105`; `CODEX-DISPATCH-enforcement-guards-2026-08-12.md:225-236`).

**Failure scenario:** P3 dispatches from base B and completes/reviews M1. P2 then lands at L, which is not an ancestor of the P3 branch. To satisfy M2, the executor must rebase/merge L mid-wave, changing the integrated tree and history after M1 acceptance; otherwise the ancestry check fails. r6 defines neither operation, whether `base_sha` is re-pinned, nor which earlier evidence must be rerun. A partial cherry-pick can appear to solve the ancestry problem while omitting the actual P2 strategy.

**Required repair:** make landed P2 a P3 dispatch/M0 prerequisite and branch P3 from a base containing it, or split P3(a) and P3(b) into separate waves/bases. If mid-wave rebasing is intentional, specify the exact rebase/merge procedure, update the base/pin semantics, and require all affected prior milestones plus the final gate to be rerun against the new integrated ancestry.

### R3-H-4 — The seed-pin write order contradicts the harness gate order

**Evidence:** The P1 YAML says the executor writes the seed commit and protected blob pins “after the seed commit is reviewed at that milestone's gate” (`enforcement-p1.progress.yaml:35-45`); P2 says the same (`enforcement-p2.progress.yaml:25-33`). The harness requires implementation and commit first, then invokes the milestone review, and an ACCEPT verdict advances to the next milestone (`SELF-REVIEW-HARNESS.md:23-46`). Yet M2/M1 acceptance is also supposed to review and validate those pins (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:151-152,243,289`; `enforcement-p1.progress.yaml:106-113`; `enforcement-p2.progress.yaml:96-103`).

**Failure scenario:** if the executor follows the YAML literally, the gate reviews the seed without the pins; adding the pins afterward changes HEAD and leaves the operative checker input unreviewed. If the executor writes the pins before the bridge, it violates the stated “after review” sequence. A fix-round seed change can likewise leave the recorded commit/blob stale unless the whole pinning step is rerun before the next verdict.

**Required repair:** define the seed as a dedicated first commit inside the milestone, compute and record both pins in a second metadata/checker commit, and invoke the milestone bridge only after both commits exist. Every fix round that changes the seed baseline must recompute the pins before re-review. Replace every “after reviewed” phrase with this unambiguous pre-gate order.

## Minor findings

None.

## Round-0 r6 note reassessment

1. **TreasuryReceiptBridge location remains note-only.** The class and cited purpose lists are under `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:446-455,528-535`; the brief does not assert a conflicting directory.
2. **Mixed Arabic-authored entries inside the alias ranges remain note-only.** The ranges include authored entries such as `menu: arMenu` and `channels: arChannels`, but also the claimed English aliases and spreads (`apps/web/src/lib/i18n.ts:392-430`). The authored-provenance rule remains capable of distinguishing them.
3. **Wave-3 M3 pending remains note-only.** `wave3-3c-3d.progress.yaml:49-56` is still pending/null, while r6 treats ACCEPT as a future P1-M0 precondition rather than a current fact.

## Required disposition

Do not dispatch r6. Move the ratchet ceilings and post-acceptance receipts out of candidate-controlled/self-referential state, bind every reviewed SHA to the corresponding progress milestone commit, and make P2 a stable ancestor before P3 begins the work that consumes it. Then rerun round-0 mechanics and a fresh adversarial gate against the resulting working-tree revision.
