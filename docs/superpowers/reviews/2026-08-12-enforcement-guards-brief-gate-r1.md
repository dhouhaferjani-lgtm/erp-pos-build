# Adversarial brief gate — round 1

**Target:** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` r2, working tree on `dev` at `a5520f23c`  
**Round-0 input:** `docs/superpowers/reviews/2026-08-12-enforcement-guards-brief-round0-r2.md` — PASS; its mechanical checks were not repeated. Observation O-6 is carried below as M-1.  
**Mode:** hostile, read-only review; no implementation or test execution.

## Verdict

**CHANGES-REQUIRED.** The brief is not dispatch-safe. Four Critical findings break the execution contract or the core guard claims; eleven High findings leave sequencing, overlap, review, and acceptance paths non-discriminating; two Minor findings remain.

## Critical findings

### C-1 — The mandatory progress/resume artifact does not exist, and the requested shape is not a harness schema

**Evidence:** The brief orders the executor to read and maintain `docs/handoff/progress/enforcement-guards.progress.yaml` first, as one file with nested `p1:`, `p2:`, and `p3:` sections and per-package `entry_gate:` fields (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:25-27`). That file is absent from the working tree. The harness instead requires opening an existing wave YAML, then reads one top-level `base_sha`, `branch`, `owner_gates`, and ordered `milestones` contract (`docs/handoff/SELF-REVIEW-HARNESS.md:12-19,21-27`). The three packages require independent bases and branches (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:13-19,248`), but no nested-package schema or transition rules exist in the harness. Existing dispatch-ready waves ship an operative YAML before dispatch and use the top-level schema, e.g. `docs/handoff/progress/ui-wave0.progress.yaml:1-20,31-39`.

This also leaves the known `owner_gate:` trap unresolved. Harness step 4 treats any populated milestone `owner_gate` as an unconditional STOP (`docs/handoff/SELF-REVIEW-HARNESS.md:49-50`), while F-2 and F-4 are conditional gates (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:261-265`). The corrected ES/UI YAMLs explicitly warn not to encode conditional gates as milestone `owner_gate:` fields (`docs/handoff/progress/es-wave-a0.progress.yaml:38-45`; `docs/handoff/progress/ui-wave0.progress.yaml:105-120`); this brief supplies no equivalent safe encoding.

**Failure scenario:** dispatch starts, the executor cannot perform the harness's first action, invents a nested schema the harness does not define, or wires F-2/F-4 onto milestones and stops unconditionally even when their conditions did not fire. P3's `after P1 lands` dependency is prose-only because there is no operative predecessor-status field.

**Required repair:** ship the progress YAML(s) with an explicit, harness-compatible schema, per-package base/branch and milestone state, executable entry-gate evidence, dependency fields, and conditional owner-gate encoding that cannot trigger the ES/SV class of unconditional STOP.

### C-2 — Package 3's “remaining balance-guard gap” is false for the posting chokepoint

**Evidence:** P3 infers a missing balance assertion from the absence of the strings `DoubleEntryValidator`, `isSumBalanced`, and `Unbalanced`, then requires a new validator at every path and specifically proposes `GeneralLedgerService` as the preferred chokepoint (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:202-216`). The actual chokepoint already loads every journal line, sums debit and credit with `bcadd`, and throws before sealing when `bccomp` differs (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3480-3510`). Both `postEntry()` and `postEntryNow()` funnel into that method (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2874-2885,3450-3457`). Named “gap” callers already use `postEntryNow`, including `TreasuryReceiptBridge` (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:473,547,1387`) and `InstrumentLifecycleService` (`apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:274,456,488,847`).

Not every named creator is necessarily covered: for example, `createPOSChargeEntry()` returns a Draft without posting (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4072-4165`). That is exactly why a semantic census is required; a grep for one implementation class cannot establish absence of the invariant.

**Failure scenario:** red-first tests for already-posted paths are green before any P3 change, or the executor copy-pastes a second balance algorithm into the existing chokepoint. Either result violates TDD and the “do not redo the done part” rule while leaving genuinely draft-only creators poorly distinguished.

**Required repair:** re-census by behavior: classify each creator as draft-only, posted through the existing balance guard, or bypassing it. Scope P3 only to genuine gaps and state whether the existing chokepoint needs exception/alert normalization rather than a duplicate validator.

### C-3 — P3(b) contradicts the active country-defaults authority and double-builds its manifest/conformance layer

**Evidence:** P3 declares that every purpose resolved by a live posting path belongs in `SystemAccountPurpose::requiredPurposes()`, orders that list extended, and builds per-country seeder completeness from it (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:219-229`). The active country-defaults brief makes the opposite binding decision: `requiredPurposes()` “is not the operational manifest” and conformance must not key off it (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:57-66`). That lane owns `ProvisioningRequiredPurposesV1`, a complete 41-case classification plus conformance and AST registration ratchet (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:289-318`; `docs/handoff/progress/country-defaults-phase-a.progress.yaml:33-40`). It also freezes the three country seeder class bodies (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:314-318`).

**Failure scenario:** P3 creates a second operational-purpose authority, keys tests to the authority the country-defaults lane explicitly rejected, and may edit seeders that lane freezes. Depending on merge order, one lane invalidates the other's conformance contract.

**Required repair:** resolve the authority conflict before dispatch. P3 must consume the landed `ProvisioningRequiredPurposesV1` contract (or an explicit superseding decision), and its country-chart test/backfill scope must be coordinated with the country-defaults lane rather than recreated.

### C-4 — “Shrink-only” baselines can grow in the same commit as a new violation

**Evidence:** P1 specifies a `--write-baseline` mode modeled on the quantity audit and calls the result shrink-only (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:106-108`). Its acceptance mutates the baseline only in mismatched directions: delete a real entry or add a bogus stale entry (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:120-129`). The referenced implementation's writer simply replaces the baseline with every current violation and exits 0 (`apps/web/tools/audit-quantity-display.mjs:418-442`); the normal comparison only detects current violations absent from the checked-in baseline or stale baseline keys (`apps/web/tools/audit-quantity-display.mjs:342-358,445-467`). Therefore “add a new violating write + regenerate baseline” is green. The existing unit contract likewise tests new-without-baseline and stale-without-violation, not matched growth (`apps/web/tools/__tests__/audit-quantity-display.test.mjs:163-198`). P2's optional i18n ratchet inherits the same underspecified “shrink-only” claim (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:167-171`) and has no anti-growth acceptance case.

**Failure scenario:** a developer lands a new violation and its freshly generated baseline entry together. CI sees an exact match and passes, so the supposed cementing guard permits precisely the regrowth it exists to block.

**Required repair:** define and test an anti-growth mechanism against the merge base/locked seed baseline, or make post-bootstrap baseline writes removal-only. Acceptance must plant a new violation, add the matching baseline key, and still observe failure. Apply the same proof to every P1/P2 ratchet.

## High findings

### H-1 — P1's entry gate is not actually “only after 3C,” and neither S0 nor P3's dependency is recorded as executable evidence

**Evidence:** The gate says “ONLY after” DPA 3C but immediately permits folding P1 into that still-running session as a closing milestone (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:15-17`). The DPA dispatch defines 3C as its own gated branch that must merge to local `dev` before the 3D branch is created (`docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:612-617`); folding an unreviewed new package into 3C changes that lane's accepted scope. The brief also defers the DPA state/path evidence to the parent (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:51,265`) and provides no M0 transition that records the 3C final SHA as an ancestor of the P1 base, the S0 signature at that exact SHA, or P1 `passed/landed` before P3 opens. The only current DPA progress artifact still has null branches and pending milestones (`docs/handoff/progress/wave3-3c-3d.progress.yaml:8-12,24-73`).

**Failure scenario:** P1 starts inside an active 3C lane, or P3 starts from a branch containing a P1 handback but not the landed P1 commit. A source-file spot check can prove S0 exists somewhere; it does not prove the dispatched base contains the accepted 3C/P1 ancestry.

**Required repair:** remove the fold-in exception and encode exact SHA/ancestry/status checks in M0/entry-gate fields for P1 and P3.

### H-2 — P2's overlap guard tests artifact presence, not active ownership, and omits an integration contract for the second CI-writing Desktop lane

**Evidence:** UI Wave 0 still owns T3(b) in its operative M4 (`docs/handoff/progress/ui-wave0.progress.yaml:64-71`). P2 nevertheless says that if `check-manifest-drift` is absent on its base, P2 implements T3(b) (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:145-150`), while F-3 simultaneously says both lanes must never author the job (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:263`). “Absent from base” does not distinguish “not yet reached on the active UI branch” from “unowned/relinquished.” The OpenAPI Desktop lane is also explicitly running (`docs/handoff/HANDOVER-openapi-lane-orchestration-2026-08-07.md:32-36`) and owns CI drift/coverage wiring (`docs/superpowers/plans/2026-08-06-codex-dispatch-openapi-mcp-layer-a-to-z.md:9-16,21-26`). P2 names OpenAPI only in a broad announcement list (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:18`); it has no verify/rebase/merge-order rule for that lane's concurrent `ci.yml` contract.

**Failure scenario:** P2 sees no landed manifest job while UI M4 is in progress and authors the same job; or it rewrites the aggregate/Feature lanes against a base that omits the active OpenAPI job, forcing a conflict or silently dropping an aggregate dependency.

**Required repair:** gate on recorded ownership/status, not grep alone. “Implement” is allowed only after the owning lane is complete without the artifact or explicitly relinquishes it. Pin merge/rebase order and aggregate reconciliation for both UI Wave 0 and OpenAPI.

### H-3 — P2-M1 is falsely described as having no behavioral CI change

**Evidence:** P2-M1 is labeled “pure additions, no behavioural CI change — safe first” (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:194`). But its required i18n audit is wired into `pnpm lint` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:167-171`), and `frontend-lint` runs on every lane (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:139-142`; `.github/workflows/ci.yml:853-859`). That is a new required CI behavior, even if the initial tree is baselined.

**Failure scenario:** the milestone reviewer accepts M1 as test-only and misses that it has already changed the pass/fail contract for every open frontend branch.

**Required repair:** classify M1 as a CI-contract change subject to the same quiet-window, announcement, observe/baseline, and remote-run evidence as the other P2 milestones.

### H-4 — P1's liveness proof covers two `create` calls, not the scanner contract or the live write vocabulary

**Evidence:** The scanner promises four tables and Eloquent create/update/save/delete/increment/decrement, query-builder writes, and raw SQL (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:92-105`). The tamper contract plants only `JournalEntry::create` and `StockMovement::create`, plus one linked create (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:110`). Current target-table writes include omitted conditional creators: `StockLevel::firstOrCreate` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1574-1590`) and `BatchStock::firstOrCreate` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1838-1863`; `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:88-105,322-335`).

**Failure scenario:** a scanner implements only the two fixture patterns, misses `firstOrCreate`, query-builder updates, deletes, or raw SQL, and still passes every named acceptance command. A zero-valued `firstOrCreate` can write a target row even when the later `update` branch does not run.

**Required repair:** define the complete write-method vocabulary and add positive/negative fixtures per table and write family, including current `firstOrCreate` sites and dynamic/query-builder/raw cases. Acceptance must assert coverage of that matrix.

### H-5 — The i18n audit can report full parity by counting English aliases as Arabic translations

**Evidence:** P2 says to compare final key sets across en/fr/ar and decide full parity versus a ratchet (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:163-171`). The actual Arabic resource graph directly assigns many namespaces to English (`apps/web/src/lib/i18n.ts:392-405,416-424`) and merges English keys underneath partial Arabic objects (`apps/web/src/lib/i18n.ts:429-430`). The runtime also has English fallback (`apps/web/src/lib/i18n.ts:435-442`). A scanner that imports the final `resources` object sees those English-provided keys as present in `ar`; a fixture that deletes a genuinely absent key can still prove the scanner fires without proving it distinguishes authored Arabic from aliased English.

**Failure scenario:** the audit exits 0 with large Arabic translation gaps because the resource graph has already filled them with English. The acceptance fixture passes while the production corpus is vacuously “complete.”

**Required repair:** define completeness on authored locale provenance, not only the merged runtime object. Baseline explicit English aliases/spread-supplied keys as untranslated, and include production-shaped alias/spread fixtures.

### H-6 — P2(b) can leave silent Feature omissions while satisfying its acceptance block

**Evidence:** The task's defect is that new Feature classes can run nowhere (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:152-161`), but Option B merely gives Treasury/Accounting directory inclusion as an example. Those two directories already run wholesale in `treasury-spine-pgsql` (`.github/workflows/ci.yml:833-851`), while the manual full-backend job is the only mechanism that dynamically enumerates every Feature top-level path (`.github/workflows/ci.yml:299-326`). The P2 acceptance block asks for a decision-doc path and CI timing/link, but no exhaustive class-to-lane/exclusion manifest and no planted-new-class proof (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:182-192`).

**Failure scenario:** Option B removes substring shadowing for the existing list or points at the already-covered Treasury/Accounting directories, while classes in other Feature directories remain silently ungated. All named acceptance evidence can still be green.

**Required repair:** require an exhaustive, mechanically checked assignment of every Feature class/directory to an automatic lane or reasoned exclusion, plus a negative proof that a newly planted class in a previously uncovered directory is automatically selected.

### H-7 — Remote CI evidence is impossible under the no-push contract

**Evidence:** The execution banner says “Branch NOT merged, NOT pushed” (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:41-42`), matching the harness deliverable (`docs/handoff/SELF-REVIEW-HARNESS.md:83-87`). The working rules require pushing the branch so its own workflow edit runs and require a CI run link (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:255`); P2 acceptance also asks for CI run links/timing (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:190-191`).

**Failure scenario:** the executor either violates the no-push contract or cannot produce mandatory acceptance evidence. A local YAML inspection cannot substitute for the brief's own remote-run requirement.

**Required repair:** choose one authority. Permit a named non-`dev` branch push with exact ownership/cleanup rules, or move remote CI evidence to the parent promotion gate and define the executor's local substitute.

### H-8 — P2/P3 lack the harness-required final whole-branch gate, and the landed stock↔GL reviewer is not actually wired

**Evidence:** The harness requires the final milestone to re-run accumulated evidence and every lens over the integrated branch (`docs/handoff/SELF-REVIEW-HARNESS.md:66-75`). P1-M3 says whole-package verification, but P2-M3 is only 2(a)+announcement and P3-M2 is only 3(b) (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:133,194,242`). The bridge explicitly scopes review to the named milestone section (`scripts/adversarial-review.sh:49-64`), so a base-to-HEAD diff does not create a whole-branch acceptance contract by itself.

The brief supplies the label `stock-gl-interaction` for P1 but the bridge only passes the label as prose and neither loads nor names `.claude/agents/stock-gl-interaction-reviewer.md` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:28-39`; `scripts/adversarial-review.sh:53-60`). The landed reviewer is specifically responsible for cross-seam exactly-once, event, writer, WAC, batch, and append-only checks (`.claude/agents/stock-gl-interaction-reviewer.md:8-21,31-54`). P3-M1 touches `ReverseWriteOffService` across stock and GL but receives only `treasury,fiscal-pos` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:39,198-216`).

**Failure scenario:** P2/P3 complete without an integrated re-review, and the detailed reviewer added by the enforcement-layer session is unused on the exact stock↔GL changes it was created to gate.

**Required repair:** add explicit final whole-package milestones for P2/P3 and make the bridge load the named reviewer contract (or inline its complete lens). Apply the stock↔GL and inventory-costing lenses to P3-M1.

### H-9 — P1's CI job is not required by the aggregate, and the “already landed” verification path for P2 does not check aggregate membership

**Evidence:** P1 requires a standalone Architecture job but never requires adding it to `all-checks-pass` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:103-110`). The current aggregate has an explicit `needs` list and does not include such a job (`.github/workflows/ci.yml:1090-1107`). In contrast, the UI T3(b) contract explicitly requires adding manifest drift to `needs` (`docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:240-247`). P2's “T3(b) already on base” branch verifies only that the job has no `if:` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:147-150`), not that the aggregate depends on it.

**Failure scenario:** branch protection keys on `All Checks Pass`; the aggregate succeeds while Architecture or manifest drift fails independently, so the new guard is visible but not merge-gating.

**Required repair:** require aggregate membership and verify it in both implement and already-landed branches, with skipped-job semantics checked.

### H-10 — P3 forks an operational owe into the handback instead of the authoritative LEDGER

**Evidence:** P3 orders the −19.000 operational item recorded in its handback (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:217,270-279`). The already-landed ledger declares itself the only authoritative owes list and requires sessions to update it in place rather than create per-project owes lists (`docs/handoff/LEDGER.md:1-5,101-103`).

**Failure scenario:** the debt exists only in a package handback and disappears from the owner's authoritative queue, or two documents acquire divergent status.

**Required repair:** update/link the single LEDGER row and make the handback a pointer, not an independent owes record.

### H-11 — P3 acceptance executes only a Treasury-filtered slice despite non-Treasury targets

**Evidence:** P3 explicitly targets `ReverseWriteOffService` under BatchExpiry and whatever Accounting/GL writers the P1 census finds (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:204-216`). Its only unbalanced-guard command is `tests/Feature/Treasury --filter ...` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:231-240`). The deliverable requires one red-first rollback test per guarded path (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:212-217`).

**Failure scenario:** Treasury tests pass while BatchExpiry/Accounting tests are absent or failing. Conversely, already-guarded `postEntryNow` paths pass before the change because of C-2, yet the handback can present them as new green evidence.

**Required repair:** acceptance must enumerate and run every test path produced by the final census, include pre-change failure evidence only for genuine gaps, and separately characterize already-guarded paths.

## Minor findings

### M-1 — Round-0 observation O-6 remains true

**Evidence:** The brief still says the twelve non-ParserFactory Architecture tests are `token_get_all`/regex static scans (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:105`). `OrphanedTypesCleanupTest` is instead a pure filesystem non-existence assertion (`apps/api/tests/Architecture/OrphanedTypesCleanupTest.php:15-29`).

**Impact:** no executor decision changes, but the census gloss remains literally false. Replace it with “other static tests/scans.”

### M-2 — P1's final scope command is vacuous after milestone commits

**Evidence:** The harness commits each milestone before review (`docs/handoff/SELF-REVIEW-HARNESS.md:23-27`), while P1 acceptance uses bare `git diff --stat` to prove that only guard files changed (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:118-130`). On a clean committed branch, that command prints nothing.

**Impact:** it cannot prove production code was untouched. Use `git diff --stat <base_sha>..HEAD` and a failing path allowlist check.

## Final verdict

**CHANGES-REQUIRED**
