# Adversarial brief gate — round 2

**Target:** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` r5, `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml`, and `docs/handoff/LEDGER.md` rows S-14/S-15, working tree on `dev` at `a5520f23c`  
**Prior inputs:** round-1 gate register (`4C/11H/2M`) and round-0 r5 mechanical report (PASS). Round-0 checks were not repeated; O-9/O-10 are carried as R2-M-1/R2-M-2.  
**Mode:** hostile, read-only review; no implementation or acceptance-command execution.

## Verdict

**CHANGES-REQUIRED.** The r5 rewrite genuinely resolves most round-1 design defects, but it is not dispatch-safe. Two Critical defects defeat or deadlock the core CI ratchets; seven High defects leave sequencing, CI proof, review execution, and acceptance evidence unsound. Two Minor observations remain.

## Round-1 resolution audit

| Round-1 finding | R2 disposition | Verified result |
|---|---|---|
| C-1 | **RESOLVED** | Three separate harness-shaped YAMLs now exist (`enforcement-p1.progress.yaml:1-8,24-35,54-89`; `enforcement-p2.progress.yaml:1-8,14-28,52-95`; `enforcement-p3.progress.yaml:1-8,22-31,47-82`). Conditional gates are top-level records and no milestone carries `owner_gate:` (`enforcement-p1.progress.yaml:35-52,54-86`; `enforcement-p2.progress.yaml:28-50,52-92`; `enforcement-p3.progress.yaml:31-45,47-79`), matching the harness's unconditional-stop semantics (`docs/handoff/SELF-REVIEW-HARNESS.md:49-50`). |
| C-2 | **RESOLVED in design** | P3 now classifies creators as draft-only / chokepoint-guarded / bypassing, forbids duplicate validators, and disqualifies green-at-base targets (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:263-275`). Its acceptance command list remains unsound; see R2-H-7. |
| C-3 | **PARTIAL** | P3 is now a read-only consumer of `ProvisioningRequiredPurposesV1` and cannot edit the authority or frozen seeders (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:278-288`; `enforcement-p3.progress.yaml:38-45,64-66`). The M0 landing proof is insufficient; see R2-H-5. |
| C-4 | **NOT RESOLVED** | The new base-branch comparison deadlocks bootstrap and is event-ambiguous after promotion; see R2-C-1. |
| H-1 | **PARTIAL** | Fold-in is forbidden, an exact 3C SHA is pinned, ancestry/S0 are checked, and P3 checks P1 ancestry/status (`enforcement-p1.progress.yaml:10-25,55-62`; `enforcement-p3.progress.yaml:10-23,48-55`). P1 still does not prove that the pinned 3C SHA was the accepted M3 output; see R2-H-4. |
| H-2 | **RESOLVED** | P2 branches on recorded UI ownership, not mere artifact presence, and carries the OpenAPI reconciliation rule (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:192-199`; `enforcement-p2.progress.yaml:39-50,53-60,77-84`). |
| H-3 | **RESOLVED in classification** | P2-M1 is explicitly a CI-contract change wired as discrete workflow steps (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:213-231,257`; `enforcement-p2.progress.yaml:61-68`). The newly added quiet-window/announcement sequence is circular; see R2-H-1. |
| H-4 | **RESOLVED** | P1 specifies the full write vocabulary and mechanism/table fixture matrix, including live `firstOrCreate` paths (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:132,140-147`; `enforcement-p1.progress.yaml:63-70`). |
| H-5 | **RESOLVED** | I18n completeness is based on authored provenance, explicitly treats aliases/spreads as untranslated, and requires production-shaped fixtures (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:215-221`; `enforcement-p2.progress.yaml:61-68`). |
| H-6 | **RESOLVED** | P2(b) requires a mechanically exhaustive class-to-lane/exclusion manifest and a planted class in a previously uncovered directory (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:203-211`; `enforcement-p2.progress.yaml:69-76`). |
| H-7 | **PARTIAL** | Executor no-push and owner-side S-14 responsibility now agree textually (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:67,321`; `LEDGER.md:56`), but the stated remote event path cannot be relied on; see R2-H-2. |
| H-8 | **RESOLVED** | P1-M3, P2-M4, and P3-M3 are whole-package gates, and the brief explicitly maps every prose lens label to the contract file the reviewer must open (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:63-64`; `enforcement-p1.progress.yaml:79-86`; `enforcement-p2.progress.yaml:85-92`; `enforcement-p3.progress.yaml:72-79`). This compensates for the bridge passing labels as prose only (`scripts/adversarial-review.sh:49-64`). |
| H-9 | **RESOLVED as aggregate wiring** | P1 requires its new job in `all-checks-pass`; P2 verify/implement paths require aggregate reconciliation (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:139,192-199,220,231`; `enforcement-p1.progress.yaml:79-86`). The workflow's event graph still bypasses direct dev pushes; see R2-C-2. |
| H-10 | **RESOLVED** | The -19.000 item is pointer-only and S-15 is authoritative (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:276,333,339-345`; `LEDGER.md:57`). |
| H-11 | **PARTIAL** | The prose now says one red-first test per genuine class-(c) path (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:268-275,294-298`), but its hard-coded three-module commands/YAML can green with zero selected tests or demand disallowed work; see R2-H-7. |
| M-1 | **RESOLVED** | The architecture-test description now distinguishes the four PHP-Parser users from other static scans (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:132`). |
| M-2 | **RESOLVED** | P1 now uses `<base_sha>..HEAD` plus an explicit path allowlist (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:168-172`; `enforcement-p1.progress.yaml:79-86`). |

## Critical findings

### R2-C-1 — The anti-growth comparison cannot bootstrap either new baseline and is not anchored to the event being gated

**Evidence:** P1 creates the document-per-action baseline as a package deliverable (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:133-137`; `enforcement-p1.progress.yaml:71-78`) but simultaneously requires CI to compare it with the base branch and fail on **any** added key (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:137,166-167`). There is no baseline on the stated base, so the first legitimate bootstrap makes every initial key an addition. P2 inherits exactly the same rule for its newly created i18n baseline (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:218`; `enforcement-p2.progress.yaml:61-68`). No bootstrap seed SHA, one-time mode, or immutable initial-baseline commit is encoded in either YAML.

The proposed example, `git fetch origin <base> && git diff origin/<base>...HEAD`, also does not identify the event's protected before-state (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:137`). On a post-merge push, `origin/<base>` may already resolve to the pushed HEAD; on an unpushed local branch it is not the parent-pinned local `base_sha`. Thus one spelling can reject the initial baseline wholesale and later compare against a moving ref that contains the additions.

**Failure scenario:** implementing the rule literally makes P1-M2 and P2-M1 permanently red on their required bootstrap baselines. Adding an implicit “baseline missing on base” exemption makes the first package change free to add a violation plus its matching baseline key—the original C-4 bypass. After merge, resolving the branch name to the new remote tip can make the diff empty.

**Required repair:** define a two-phase protocol. Generate and review the initial baseline in a dedicated seed commit; record its exact blob hash or commit SHA as the immutable ceiling; only subsequent changes may remove keys. The checker must compare parsed key sets to that pinned seed/previous protected revision, not a moving branch name, and the bootstrap commit itself must be separately proven against the scanner/DPA census. Define event-specific before SHAs for PR and push contexts, then retain the matched-growth tamper case.

### R2-C-2 — “Every push and PR” is false: direct pushes to `dev` do not start this workflow

**Evidence:** P1 promises a no-`if:` Architecture job “so it runs on every push and PR” (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:139`; `enforcement-p1.progress.yaml:79-80`). P2 similarly treats its `frontend-lint` steps as active on every lane (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:186,220,231`). The workflow is triggered only by pushes to `main`, pull requests targeting `main` or `dev`, and manual dispatch (`.github/workflows/ci.yml:3-8`). It explicitly documents that a push to `dev` never triggers the workflow (`.github/workflows/ci.yml:740-747`). The package branches are never pushed and are promoted by the parent (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:67,314,321`; `docs/handoff/SELF-REVIEW-HARNESS.md:83-87`), so the ordinary local-merge/direct-`dev`-push path has neither a feature-branch PR event nor a `dev` push event.

**Failure scenario:** the parent promotes an accepted package to local `dev` and pushes `dev`; no workflow starts. A later direct change can add a violation and matching baseline update on `dev` without any guard run until a future PR to `main` or a human remembers manual dispatch. Aggregate membership cannot gate an event that never exists.

**Required repair:** either add `dev` to `on.push.branches`, or make a PR/pushed-candidate-ref workflow an explicit pre-promotion owner gate. Update every “every push/every lane” claim and S-14 to name the exact event and ref that proves it. Add an event-graph acceptance check showing that the actual parent promotion path invokes the guard and aggregate.

## High findings

### R2-H-1 — P2 requires the announcement before dispatch but does not create its contents until M3

**Evidence:** The dispatch gate says the parent fills `quiet_window_ack` with both the approved window and an announcement already sent (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:41`; `enforcement-p2.progress.yaml:10-18,32-34,53-60`). P2-M3 later creates the merge-announcement checklist, including the actual M1 contract change and per-lane rebase instructions (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:257`; `enforcement-p2.progress.yaml:77-84`). F-6 makes that checklist the announcement's input while still saying the already-sent announcement is what `quiet_window_ack` attests (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:332`).

**Failure scenario:** M0 cannot honestly be satisfied using the M3 checklist because M3 has not run. If the parent sends a preliminary announcement to open M0, it cannot contain the as-implemented M1/M2/M3 rebase consequences that the brief says the checklist supplies.

**Required repair:** split the state. M0 should require an owner-approved quiet window plus a preliminary scope notice. After M3/M4 acceptance, require a separate parent-owned `merge_announcement_ack` proving that the final generated checklist was sent before promotion.

### R2-H-2 — S-14's post-promotion remote proof has no guaranteed run to observe and happens after the invalid change lands

**Evidence:** Local workflow evidence may fall back from `actionlint` to a generic YAML parse (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:321`), which cannot validate the Actions job/event graph. The only remote proof is explicitly postponed until after promotion (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:67,321,345`; `LEDGER.md:56`). But the workflow does not trigger on a `dev` push (`.github/workflows/ci.yml:3-8,740-747`), and S-14 says only “runs/observes” without requiring `workflow_dispatch`, a PR ref, or a successful run before promotion (`LEDGER.md:56`).

**Failure scenario:** the package is promoted with syntactically parseable but Actions-invalid wiring; the dev push produces no run. S-14 stays open with nothing automatic to observe, and there is no rollback/fail-closed consequence after the defect has landed.

**Required repair:** name an executable remote mechanism and make it pre-promotion: push an approved candidate ref and open/target the relevant PR, or manually dispatch the exact candidate SHA if the workflow supports it. Record a run URL and success before merge. If post-promotion proof is intentionally retained, specify the mandatory dispatch command/ref and the rollback/block-next-promotion consequence on failure.

### R2-H-3 — P2 wires the existing tools tests through the wrong test runner

**Evidence:** The brief and P2 YAML require `node --test tools/__tests__/` in CI and acceptance (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:188,231,240`; `enforcement-p2.progress.yaml:61-62,85-86`). Every current file in that directory imports its suite APIs from Vitest, not `node:test`: `apps/web/tools/__tests__/audit-design-system.test.mjs:2`, `audit-pos-local-cache.test.mjs:8`, `audit-quantity-display.test.mjs:2`, `audit-tanstack-keys.test.mjs:2`, `offset-pagination-meta-consolidation.test.mjs:5-6`, and `permission-map-drift-guard.test.mjs:5`. The package's test script is `vitest run` (`apps/web/package.json:17-18`).

**Failure scenario:** the mandated CI command does not execute the existing suite under its declared runner, so P2-M1/M4 cannot produce the promised tools-test green and may fail before evaluating any detector. Rewriting all existing tests to `node:test` would be an unrequested migration, not “extend, don't duplicate.”

**Required repair:** use a Vitest command/script that selects `tools/__tests__` (and keep new tests in that contract), or explicitly scope and review a runner migration. The exact same command must appear in P2-M1 wiring, P2-M4, and package acceptance.

### R2-H-4 — P1-M0 proves ancestry and the seam, not that the pinned 3C commit was accepted

**Evidence:** The human gate requires the 3C lane to be gated, accepted, and merged (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:40`). The operative M0 checks only that a parent-supplied SHA exists, is an ancestor, contains the S0 signature/call, and is used for a fresh worktree (`enforcement-p1.progress.yaml:10-23,55-62`). It never reads `wave3-3c-3d.progress.yaml` M3 status/verdict or matches the pin to M3's accepted commit; that source artifact has a distinct M3 record (`docs/handoff/progress/wave3-3c-3d.progress.yaml:49-56`) and the 3C brief requires gating before merge (`docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:612-616`).

**Failure scenario:** any ancestor carrying `assertReferenceLinkagePaired`, including an intermediate/rejected 3C commit, satisfies M0 when entered in `dpa_3c_merge_sha`. The machine gate therefore enforces “seam exists before P1,” not the claimed “accepted 3C only.”

**Required repair:** pin the accepted M3 commit/verdict artifact and require M3 `status: passed`, a parseable ACCEPT verdict, and commit equality/ancestry tying that verdict to `dpa_3c_merge_sha`. If the merge commit differs from the reviewed tip, pin both and verify reviewed-tip → merge-commit → `base_sha` ancestry.

### R2-H-5 — P3-M0 can accept an unintegrated country-defaults M1 snapshot

**Evidence:** P3-M0 checks that the manifest file exists at `base_sha` and that country-defaults M1 shows its owning milestone “landed” (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:282`; `enforcement-p3.progress.yaml:10-20,48-55`). The country progress schema has only per-milestone status/commit/verdict fields; M1 owns the kernel (`country-defaults-phase-a.progress.yaml:33-40`), while M7 is the whole-branch integration gate (`country-defaults-phase-a.progress.yaml:81-90`). The owning dispatch says the branch is not merged/pushed and the orchestrator merges only after every gate and M7 complete (`CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:824-831`). P3 has no country-defaults landed SHA field, no M7/status-complete check, and no ancestry check tying an accepted country branch tip to `base_sha` (`enforcement-p3.progress.yaml:22-23,48-55`).

**Failure scenario:** a partial cherry-pick containing the manifest and an M1-passed YAML snapshot satisfies the written M0 even though the authority lane has not passed M7 or been merged. P3 then builds a consumer against classification content that is not yet the landed authority.

**Required repair:** add a parent-pinned `country_defaults_landed_sha`; require the country wave's top-level `status: complete`, M7 ACCEPT verdict/commit, and reviewed-tip/merge ancestry to P3 `base_sha`. Checking M1 alone is not evidence that the lane landed.

### R2-H-6 — P3-M2 depends on P2(b)'s outcome, but P3 has no P2 dependency

**Evidence:** The package table opens P3 after P1 and country defaults, and P3-M0 checks only those dependencies (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:42`; `enforcement-p3.progress.yaml:10-23,48-55`). Yet P3's required CI lane is explicitly selected “per 2(b) outcome,” with the pgsql allowlist only as a worst case (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:286`; `enforcement-p3.progress.yaml:64-65`). P2 is independently gated and may still be pending, or may subsequently replace the allowlist/lanes P3 used (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:36-42,201-211`).

**Failure scenario:** P3 reaches M2 before P2-M2 has chosen/landed a strategy, so “per outcome” has no value. It wires into the old allowlist, then P2 deletes/restructures it, silently dropping the P3 test or causing conflicting CI edits.

**Required repair:** gate P3-M2 on an accepted/landed P2-M2 (preferably P2 complete) SHA and ancestry, or assign P3 a stable independent CI job whose contract does not depend on P2 and require P2 to reconcile it explicitly.

### R2-H-7 — P3's hard-coded three-module commands can pass with zero selected tests and contradict class-(c)-only scope

**Evidence:** The design correctly says class-(b) paths receive no new test and only genuine class-(c) paths require red-first tests (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:268-273,294-298`). The acceptance block nevertheless mandates one filtered command in each of Treasury, BatchExpiry, and Accounting (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:299-301`), and the operative M1 title repeats “Treasury AND BatchExpiry AND Accounting” (`enforcement-p3.progress.yaml:56-57`). PHPUnit is not configured to fail on an empty selection (`apps/api/phpunit.xml:2-25`).

**Failure scenario:** if the semantic census finds no class-(c) gap in one named module, the executor either invents a prohibited already-green test/implementation to satisfy the hard-coded trio, or runs a filter selecting zero tests and records an exit-0 command as evidence. Neither discriminates success from failure.

**Required repair:** derive the exact command list from class-(c) census rows rather than fixed modules. For every row, name its test path/filter and assert nonzero test count plus preserved red-first output. For modules with no class-(c) rows, require the reviewed census evidence only—no placeholder green command.

## Minor findings

### R2-M-1 — O-9: all companion headers still identify r4 as the applied revision

**Evidence:** Each YAML line 5 says “r4 of the brief applies all 17 gate-r1 findings” (`enforcement-p1.progress.yaml:5`; `enforcement-p2.progress.yaml:5`; `enforcement-p3.progress.yaml:5`) while this gate targets r5. The statement is historically true but is easy to read as the operative brief version.

**Required repair:** refresh the comments to r5 on the next authorized edit.

### R2-M-2 — O-10: the Accounting directory run line remains outside the cited range

**Evidence:** P2 Option B cites `ci.yml:844-847` as the two-directory pattern (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:209`), but the Accounting run command is at `.github/workflows/ci.yml:848`; `:847` is only its step name (`.github/workflows/ci.yml:844-848`).

**Required repair:** widen the citation to `:844-848` on the next authorized edit.

## Required disposition

Do not dispatch r5. At minimum, repair R2-C-1 and R2-C-2, then close the seven High findings and re-run both round-0 mechanics and this adversarial gate against the resulting working-tree revision.
