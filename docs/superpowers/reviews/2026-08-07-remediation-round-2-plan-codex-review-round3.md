# Adversarial Review Round 3 — Remediation Round 2 Plan v3

Reviewed artifact: `docs/superpowers/plans/2026-08-07-remediation-round-2-plan.md` at commit `b93465b7f` (the working copy is byte-identical to that commit).

Citation aliases: `plan-v3.md` is the reviewed plan; `round2.md` is `docs/superpowers/reviews/2026-08-07-remediation-round-2-plan-codex-review-round2.md`.

## VERDICT: REJECT

v3 contains genuine closures, including the shipped R2-G implementation, the corrected F5 consumer map, and the original R2-L reviewer coverage, but Phase R still fails its own recorded-answer/outcome-mapping contract and Wave 1 still contains two lanes that require unmade decisions.

## Round-2 Closure Disposition

- **Round-2 BLOCKER — NOT RESOLVED.** Round 2 found that “Phase R names questions but is not an executable decision gate” because implementers could reach Wave 2 without defined contracts and R-e/R-f could be answered without scheduled deliverables (`round2.md:33-36,78-93`). v3 declares one mandatory shared artifact at `plan-v3.md:64-69`, but `docs/superpowers/tickets/2026-08-07-round2-rulings-record.md` does not exist at `b93465b7f`; moreover R-b still leaves cut-over/account choices open, R-e/R-f still point to unnamed lanes absent from the Wave-2 list, and new R-g/R-h do not map every answer to an executable lane (`plan-v3.md:74-80,100-113,135-159`).
- **Round-2 MAJOR — R2-K closes one vector and dispatches before its ruling — PARTIALLY RESOLVED.** The original finding required the active-membership vector, a tenancy-authz gate, and a pre-dispatch recoverability ruling (`round2.md:38-41`). v3 now names both vectors and both gates (`plan-v3.md:25,122-125`), but calls the preflights “closures” even though the ticket says they only narrow TOCTOU windows (`2026-08-05-deposit-residual-seal-before-resolve-vectors.md:31-33,48-63`), and R-g still lacks branch-to-implementation mappings (`plan-v3.md:105-108`).
- **Round-2 MAJOR — F5’s consumer map is factually wrong — RESOLVED.** The original finding named one `PaymentController::storeMultiple()` path, two `PaymentAllocationService` methods, and `SmartPaymentController::previewAllocation()` as read/cap paths (`round2.md:43-46`); v3 enumerates exactly those consumers and the fully-credit-noted-invoice acceptance condition at `plan-v3.md:141-146`, matching the source ticket (`2026-08-06-l2-remaining-balance-due-consumers.md:22-87`).
- **Round-2 MAJOR — R-b is attached to the wrong lane and missing from the merge graph — PARTIALLY RESOLVED.** The original finding required a GL/accounting consumer ordered against F (`round2.md:48-51`). v3 creates R2-M, gives it GL/accounting plus release/data gates, and orders it after F2 (`plan-v3.md:33,74-80,147`), but “migration-bearing if backfill required” leaves the ticket’s retrospective-versus-prospective and shared-versus-TN-specific choices unowned (`2026-08-05-tn-timbre-account-carries-rounding-noise.md:53-76`).
- **Round-2 MAJOR — five appendix obligations remain silently unassigned — PARTIALLY RESOLVED.** The original five omissions are listed at `round2.md:53-61`; v3 assigns the GL follow-ups, creates R2-O, folds `allow_negative` into R2-L, and explicitly defers POS images (`plan-v3.md:126-133,150-170`), but its partner deferral contradicts the ticket’s still-reachable reference gap and T-10 omits the ticket’s required `Schema::hasTable()` sweep (`plan-v3.md:163-173,183-184`; `2026-08-06-l6-partners-followups.md:14-38`; `2026-08-05-cross-tenant-annotation-ast-check.md:62-76,128-133`).
- **Round-2 MAJOR — R2-L’s gate does not cover its bundled risks — RESOLVED for the scope reviewed in Round 2.** Round 2 required tenancy-authz review for the permission surface and Document/Taxation review for the certified tax pipeline (`round2.md:63-66`). The matrix and lane now require those exact gates and cross-surface alignment (`plan-v3.md:26,126-130`). The newly added `allow_negative` scope creates a separate v3 gate issue below.
- **Round-2 MAJOR — R2-D’s merge order is impossible — PARTIALLY RESOLVED.** Round 2 required an executable full-tree repair and re-gate after the ratchet (`round2.md:68-71`). v3 removes the “last but rebased over” contradiction and names a terminal repair commit (`plan-v3.md:154-159`), but places the only explicit full-tree PHPStan/ESLint run before that repair and does not state that the repaired tree reruns those same gates.
- **Round-2 MAJOR — cross-tenant deferral misstates the operator risk and T-10 does not exist — PARTIALLY RESOLVED.** Round 2 required a real T-10 and a register-or-delete owner path for the absent command (`round2.md:73-76`). T-10 now exists in Phase 2 and R-h carves out the operator risk (`plan-v3.md:109-113,171-173,183-184`), but T-10 drops one of the source ticket’s two main deliverables and R-h schedules neither implementation branch.

## R-a / R2-G Shipped-Commit Verification

The implementation claim itself holds. The source ticket records the expert’s verbatim “exclude entirely” answer and maps it to no writer row plus deletion of both pre-ruling backfill shapes (`2026-08-06-q2-gate-minor-followups.md:44-71`). The `d700d5abf..d8efb5df0` ancestry contains the answer record, writer change, backfill change, scale/FE work, tests, and both gate records; at the shipped tip, `ExpenseService::writeDeductibleVatSnapshot()` deletes its old slot and returns without writing at 0% (`ExpenseService.php:517-552`), while the backfill keys on the percentage and deletes either stored base shape with FILED-period protection and pre-delete audit output (`BackfillTaxDetailsCommand.php:447-506`). A fresh PostgreSQL run of the three core backfill tests passed: **3/3 tests, 18 assertions** (V5 shape, interim shape, idempotent rerun).

No v3 lane still treats R-a as open: R-b explicitly says G is shipped and unaffected (`plan-v3.md:79-80`), and T-4 is explicitly unblocked by shipped G (`plan-v3.md:179-184`). The defect is governance evidence, not shipped behavior: v3 says the shared rulings record is created “at first answer” (`plan-v3.md:68-69`), yet that named file is absent; the older Q2 ticket is the only recorded R-a answer.

## New Findings

### BLOCKER — NEW in v3: the rebuilt Phase R still cannot enforce or execute its own answer contract

- **Plan target:** `plan-v3.md:64-113`.
- **Concrete failure scenario:** the orchestrator can mark a row ANSWERED without creating the mandatory shared record, then dispatch a branch whose work is undefined. This is already true for R-a’s missing named record. R-b does not decide historical cut-over or account selection; R-c and R-g still name compound owners (`expert/product`, `orchestrator/product`) without decision authority or a tie-break; R-e’s reject branch says a lane is “added to Wave 2,” but no such lane appears at `plan-v3.md:135-159`; R-f’s fix-now branch has no lane ID, wave, or dependency; R-g lists three fiscal outcomes but maps none to code/data behavior; and R-h says it blocks nothing while its register branch requires production CLI code and its delete branch requires owner-sheet edits. These are not merely missing criteria: each branch lacks an executable consumer.

### MAJOR — NEW in v3: R2-M is ordered, but the split branch remains under-specified

- **Plan target:** R-b/R2-M at `plan-v3.md:74-80,147`.
- **Concrete failure scenario:** an expert answers “split” but requires prospective-only cut-over or a TN-specific account. The ticket explicitly asks both questions and requires historical quantification before restatement (`2026-08-05-tn-timbre-account-carries-rounding-noise.md:53-76`), while v3 hardcodes `SalesRoundingDifference` and leaves backfill as “if required.” The R2-M implementer must therefore invent the fiscal cut-over/account contract. Ordering after F2 safely serializes the shared `AccountingService.php` file, but does not supply the missing decision.

### MAJOR — NEW in v3: R2-K’s Wave-1 unit is still decision-blocked and its “preflight closures” are not closures

- **Plan target:** R-g and R2-K at `plan-v3.md:105-108,117-125`.
- **Concrete failure scenario:** a freeze or membership revocation lands after preflight but before the post-seal projection, minting the same permanent orphan the ticket describes. The ticket explicitly says the frozen check only narrows the race (`2026-08-05-deposit-residual-seal-before-resolve-vectors.md:48-63`), so R2-K is not complete until recoverability is implemented. Because R-g gives no outcome-to-lane mapping, the lane can start but must stop mid-delivery; it is not dispatchable as one Wave-1 unit.

### MAJOR — NEW in v3: R2-L still consumes an unmade permission decision, and its added treasury control lacks a treasury gate

- **Plan target:** R2-L and its matrix row at `plan-v3.md:26,126-130`.
- **Concrete failure scenario:** the ticket says to decide the intended `credit-notes.confirm` permission before aligning seeder, route, and FE (`2026-08-03-f2f3-regate-carryovers.md:31-35`), but v3 neither states the decision nor gives it an owner/artifact/alternatives; a Wave-1 implementer must choose policy. In the same lane, the new `allow_negative` switch can be rendered with the wrong default/polarity and authorize negative outflows, while the matrix has FE/tenancy/Document/Taxation reviewers but no treasury reviewer for this treasury setting (`2026-08-07-repobal-lane-followups.md:6-11`).

### MAJOR — NEW in v3: R2-N’s activation predicate covers only one branch and does not define the activated work

- **Plan target:** Phase 0.3c and R2-N at `plan-v3.md:47-51,148`.
- **Concrete failure scenario:** if the investigation proves direct campaign/API status patching rather than a payment writer bug, R2-N does not activate even though the ticket requires data cleanup and API refusal of direct `paid` writes. If a write path is proven, the lane still does not enumerate the writers, the paid⇒zero invariant, or whether the fix is domain- or DB-enforced (`2026-08-03-paid-with-unreconciled-balance-investigation.md:8-17`), and its treasury-only gate omits the Document/DB contract. “The gate cannot close” does not identify who performs either branch’s work.

### MAJOR — NEW in v3: R2-O is scoped correctly but ordered after the first campaign run that must consume it

- **Plan target:** Phase 0.5/0.7 and R2-O at `plan-v3.md:58-62,131-133`.
- **Concrete failure scenario:** following the document order executes the blocking Phase-0 staging smoke before Phase-1 R2-O. The old helper can therefore mint another warehouse fiscal receipt after the accountant list is consolidated; the ticket says the defect compounds on every run and must select a shop before another campaign invocation (`2026-08-06-c2-fixture-terminal-location.md:51-69`). Add an explicit R2-O → 0.7 (and any C-2 invocation) edge. The lane’s code scope and fiscal-pos gate otherwise match the ticket.

### MAJOR — NEW in v3: the terminal ratchet has no post-repair static-analysis proof and an ambiguous terminal set

- **Plan target:** R2-D at `plan-v3.md:154-159` plus the integration gates at `plan-v3.md:9-15`.
- **Concrete failure scenario:** the one named full-tree PHPStan/ESLint run finds stragglers; the repair commit changes them; then “final integration re-gate” runs only the named Document/VAT integrations, allowing the repaired tree to remain PHPStan/ESLint-red or to bypass a domain gate for a cross-domain repair. The sequence must explicitly rerun full-tree PHPStan and ESLint after repair and rerun every specialist gate implicated by the repair. It must also define “all Phase-1 PHP lanes” as all **activated** lanes with recorded non-activation dispositions, or conditional R2-M/N/J can make the terminal condition impossible to evaluate.

### MAJOR — NEW in v3: the partner deferral reason is factually false

- **Plan target:** `plan-v3.md:161-166`.
- **Concrete failure scenario:** v3 says referenced partners now 409 and the residue is only visibility UX for already-deleted partners. The ticket says the guard covers 3 of roughly 14 `partner_id` tables, so a partner referenced by `journal_lines`, vouchers, work orders, and other live tables can still be soft-deleted into invisibility (`2026-08-06-l6-partners-followups.md:14-38`). The stated reason therefore does not support deferral of the actual open P2 correctness gap.

### MAJOR — NEW in v3: T-10 does not carry the complete source-ticket scope

- **Plan target:** `plan-v3.md:171-173,183-184`.
- **Concrete failure scenario:** T-10 wires the annotation AST check and triages five existing failures, but omits the mandatory console/scheduler `Schema::hasTable()` sweep from the ticket’s proposed work and acceptance list (`2026-08-05-cross-tenant-annotation-ast-check.md:62-76,128-133`). A central-context command can continue laundering a missing tenant database into a clean verdict even after T-10 is declared complete.

## Checks That Held Under the v3 Restructuring

- **0.3e empty skip-list — no new finding.** `plan-v3.md:52-55` now matches the source requirement to fix document data or write an adjustment until every reported skip is resolved (`2026-08-03-vat-regate-carryovers.md:7-14`). The shipped R2-G command still reports data-quality and FILED-policy skips, but neither the plan nor its runbook treats reporting as disposition; the runbook still requires every skip resolved before filing (`docs/handoff/OWNER-INDEX-2026-08-03.md:50-70`). Nothing downstream relies on a non-empty skip list.
- **R2-O scope — held.** The location-type predicate and test-only characterization at `plan-v3.md:131-133` match the ticket’s requested fixture fix (`2026-08-06-c2-fixture-terminal-location.md:55-69`); only its ordering is defective.
- **F5 and the original R2-L gate widening — held.** Their exact Round-2 risks are concretely represented at `plan-v3.md:26,126-130,141-146`.

## Dispatch-Safety for Wave 1

**NO — the Wave-1 list is not dispatch-safe as written.**

- **R2-K must not start as one lane.** R-g is unmade and lacks branch mappings (`plan-v3.md:105-108`), and the prevention-only work does not close either race. A separately named prevention sublane may start, but the recoverability half must remain blocked until R-g is recorded and mapped to an implementation/data lane.
- **R2-L must not start as one lane.** Its R5 permission policy is unmade (`plan-v3.md:126-128`; source ticket `2026-08-03-f2f3-regate-carryovers.md:31-35`). Record that ruling first, and add a treasury gate for the `allow_negative` subtask or split that subtask into a treasury-reviewed lane.

R2-I, R2-C, R2-P, and R2-H have no ruling dependency identified in v3 and can be dispatched independently. R2-O can also dispatch, but it must land before Phase-0 step 0.7 or any other C-2 campaign invocation. This is therefore not a clean Wave-1 handoff today.
