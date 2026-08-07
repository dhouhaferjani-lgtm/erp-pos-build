# Adversarial Review — Remediation Round 2 Plan

## VERDICT

**REJECT** — the plan is not dispatch-safe: it omits multiple launch-relevant P1 defects, sends an unresolved accounting-design cluster to implementation, and treats coupled code, schema, data-remediation, and test work as independently parallel.

## Findings

### BLOCKER — Three live, pre-launch purchasing P1 defects are absent

- **Plan target:** premise at lines 5–6 ("covers what those programs ticketed but did not fix"), the complete Phase-1 lane list at lines 30–62, and the exclusions at lines 92–96.
- **Failure scenario:** tenant #1 can launch while (1) landed costs systematically shift a millime between products and contaminate WAC/COGS, (2) normal Tunisian bonus/free-goods receipts cannot be supplier-invoiced, and (3) an RFQ-awarded purchase order silently carries zero VAT. None is assigned to a lane or explicitly deferred.
- **Ticket evidence:** `docs/superpowers/tickets/2026-08-03-w4-purchasing-inventory-defects.md` identifies the allocator defect as P1 at lines 14–18 and its WAC/COGS consequence at lines 50–58; the bonus-line HTTP-boundary defect as P1 at lines 83–95 and says it blocks a routine TN-pharmacy flow at lines 113–122; and the RFQ tax defect as P1 at lines 143–150 with understated payable, matching, and inventory-cost consequences at lines 169–176. The ticket says these were live campaign discoveries and no product code was changed at lines 3–8.
- **Required plan change:** add three bounded lanes (or one purchasing lane with three independent gates) before launch. The RFQ fix needs taxation/procurement review; the allocator needs precision/inventory review; the bonus-line fix needs procurement/API-boundary review.

### BLOCKER — R2-F is not an implementation-ready “cluster”; its source tickets require unresolved rulings and work that the lane omits

- **Plan target:** R2-F at lines 50–53 and the instruction to dispatch it first at lines 60–61.
- **Failure scenario:** a literal implementation can reverse COGS without reversing/restocking inventory, producing a booking with no inventory counterpart; can reverse AP/input VAT without a ruled period treatment; and can add only the period lock while leaving GL VAT and the VAT declaration permanently divergent. Separately, the already-unbalanced document cited by the source ticket remains permanently uncancellable because R2-F does not include the required correcting-entry escape hatch.
- **Ticket evidence:**
  - `docs/superpowers/tickets/2026-08-06-l2-cogs-and-ap-unreversed-on-cancel.md` says COGS remains with zero revenue at lines 21–28, but explicitly requires a stock/restock and offsetting-leg ruling at lines 63–73; the AP reversal requires the same CLOSED/FILED-period treatment at lines 74–82.
  - `docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md` demonstrates the permanent GL/declaration divergence at lines 7–39 and prescribes **two** work items—reconciliation and period lock—at lines 72–89. Plan line 51 includes only the second.
  - `docs/superpowers/tickets/2026-08-06-l2-correcting-entry-escape-hatch.md` says the known stranded entry is permanently uncancellable at lines 21–38 and requires a product/accounting choice between two escape-hatch designs at lines 40–60. The plan cites `2026-08-06-l2-*` yet omits this ticket entirely.
- **Required plan change:** do not dispatch R2-F until an accountant/product ruling selects inventory treatment, AP/input-VAT period treatment, VAT-declaration reconciliation, and the correction/override model. Split it into at least (F1) cancellation policy/period gates, (F2) GL+inventory/AP reversals, (F3) VAT declaration reconciliation, (F4) correction escape hatch, and (F5) remaining balance consumers. Each sublane should merge only after the prior contract it consumes is fixed.

### BLOCKER — R2-H closes only one of three pre-launch withholding P1 failures

- **Plan target:** R2-H at lines 57–58 and Phase-1 completeness at lines 30–62.
- **Failure scenario:** route middleware becomes correct while a zero rate still manufactures and sequences fictitious fiscal certificates, and the sole UI list remains permanently empty—preventing operators from seeing, issuing, voiding, printing, or TEJ-exporting real certificates. The plan could mark withholding “closed” with its fiscal workflow still unsafe and unusable.
- **Ticket evidence:** `docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md` records the zero-rate P1 at lines 20–24 and explains the fictitious fiscal-document/hash-chain/sequence consequence at lines 50–61; it records the permanently empty list P1 at lines 178–213 and its operational consequence at lines 215–220. R2-H corresponds only to ticket #3, lines 106–174. Even within #3, lines 153–174 require the sibling **withholding-rules** group, not just certificate routes, to be gated.
- **Required plan change:** widen R2-H to three explicit deliverables: zero-effective-rate prevention, certificate-list response-contract repair, and route gates for both certificates and rules. Add the existing campaign tripwires named by the ticket to the gate.

### BLOCKER — Phase 0 can go green while known tenant data still falsifies reports, branch cash, VAT, and fiscal evidence

- **Plan target:** Phase 0 post-deploy checklist and staging-green declaration at lines 20–28.
- **Failure scenario:** the listed spot checks pass while the launch tenant still contains paid invoices with full balances, VAT backfill skips that contaminate a declaration, unlocated registers that remove the entire journal leg from branch cash, and immutable campaign fiscal records that require explicit evidence/accountant disposition. “Staging smoke green” would therefore not mean the launch data is certifiable.
- **Ticket evidence:**
  - `docs/superpowers/tickets/2026-08-03-paid-with-unreconciled-balance-investigation.md:1-18` records about 23 `paid` invoices with `balance_due = total`, identifies either a cleanup/API-invariant gap or a P1 money-visibility defect, and requires tracing every write path.
  - `docs/superpowers/tickets/2026-08-03-vat-regate-carryovers.md:7-14` marks manual resolution of every backfill skip **mandatory before any real VAT filing**.
  - `docs/superpowers/tickets/2026-08-06-l3-cash-scope-residuals.md:105-140` labels repository-location assignment a **PRE-LAUNCH DATA TASK** and says the journal leg contributes nothing to tenant #1's branch views until it is done.
  - `docs/superpowers/tickets/2026-08-06-c2-fixture-terminal-location.md:71-76` requires the immutable warehouse POS receipt to be annotated or accountant-ruled before the fiscal evidence run; `docs/superpowers/tickets/2026-08-07-discount-lane-out-of-lane-findings.md:43-49` explicitly groups it with the negative line and stranded GL money on the same accountant-disposition list.
  - `docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md:81-85` requires querying and deciding any already-posted lineless documents because they distort timbre on TN.
- **Required plan change:** make Phase 0 a data-readiness gate with named SQL/artifact outputs and zero-unresolved-or-explicitly-disposed acceptance criteria. The accountant list must include all known immutable/campaign contaminants, not only negative repositories/lines.

### MAJOR — R2-A is a tenant-schema migration with a one-way rollback boundary, but the plan treats it as ordinary code

- **Plan target:** R2-A lines 32–35, Phase-0 migration disclosure lines 16–19, and the “parallel worktrees” framing at line 30.
- **Failure scenario:** changing uniqueness to include `company_id` lands as an unannounced migration across every tenant. The next migration also trips a known hard-coded rollback test. After two companies legitimately create the same type/number, rolling back to the old tenant-wide unique constraint fails unless those fiscal identifiers are altered or rows removed; either would be unacceptable. Concurrent DDL also needs an explicit deploy window and pre/post constraint verification.
- **Ticket/code evidence:** `docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md:106-123` proves the mismatch and says choosing company-inclusive uniqueness versus a company discriminator is a data-model ruling. The current constraint is created at `apps/api/database/migrations/tenant/2025_11_30_080000_create_documents_table.php:38-39`; `company_id` was added later at `apps/api/database/migrations/tenant/2025_11_30_130000_add_company_id_to_existing_tables.php:65-74`. The existing document-number migration shows that this constraint is dropped and recreated through schema DDL at `apps/api/database/migrations/tenant/2025_12_30_085029_make_document_number_nullable_on_documents_table.php:14-25`. `docs/superpowers/tickets/2026-08-07-repobal-lane-followups.md:22-26` says **any** new tenant migration shifts `BankStatementAggregateSchemaTest`'s fixed rollback window and fails it.
- **Risk qualification:** expanding the constraint is not inherently data-lossy on the forward path: the current stronger unique constraint means duplicate non-null `(tenant_id,type,document_number)` rows cannot already exist. A forward migration should neither renumber nor delete rows. The irreversible boundary begins after company-local duplicates are accepted. Any plan to “clean” identifiers is a separate destructive proposal and must not be inferred.
- **Required plan change:** split A into authz/code and numbering/schema sublanes; decide the identifier contract; fix the rollback test first; add per-tenant preflight, migration, constraint inspection, duplicate-number functional proof, backup/restore posture, and an explicit statement that rollback is unavailable after company-local duplicates exist.

### MAJOR — The advertised Phase-1 parallelism has concrete shared surfaces and merge-order hazards

- **Plan target:** Phase-1 heading line 30, R2-E “anytime” at lines 60–62, and the unchanged post-merge green-proof process at lines 7–9.
- **Failure scenario:** independently green branches merge against stale document, VAT, validation, or FE contracts; the second merge either conflicts mechanically or passes its narrow suite while invalidating the first lane's assumptions. A post-merge narrow proof does not repair a stale TDD baseline.
- **Evidence and conflicts:**
  - **R2-E ↔ R2-F:** both change the document lifecycle. The optimistic-locking ticket says document updates read outside the transaction and then destructively delete/recreate lines (`docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md:124-160`); R2-F changes cancel/post behavior in `DocumentPostingService` (`l2-cogs-and-ap-unreversed-on-cancel.md:43-46,74-82`). Their status/`updated_at` and transaction semantics require one integration gate.
  - **R2-B ↔ R2-E ↔ T-6:** quote totals/conversion and document line editing share document totals/editor contracts. The discount follow-up identifies `QuoteController`, `DocumentLine::computeLineTotal`, conversion, and the document editor dependency bug at `docs/superpowers/tickets/2026-08-07-discount-lane-out-of-lane-findings.md:21-41`; plan T-6 promotes `exhaustive-deps` in that same feature at lines 83–84.
  - **R2-F ↔ R2-G ↔ T-4:** cancellation reconciliation and the Q2 backfill both alter VAT-report/backfill semantics. The Q2 ticket requires dry-run coverage in the same backfill touch-up at `2026-08-06-q2-gate-minor-followups.md:29-42`; plan T-4 simultaneously sweeps those commands at lines 77–79.
  - **R2-D ↔ every PHP lane:** landing a repo-wide PHPStan prohibition while A/F/G/H branches were cut from older `dev` can make those branches newly red at merge. The source ticket proposes the rule at `2026-08-07-discount-lane-out-of-lane-findings.md:7-19` but does not make it safe to merge first.
- **Required plan change:** replace “parallelizable” with a merge graph: land/fix schema-test infrastructure first; land business fixes; rebase and re-gate dependents against current `dev`; land the repo-wide PHPStan rule last, followed by all affected PHP suites. Run a combined Document+Accounting+Taxation integration gate after E/F/G.

### MAJOR — Phase 2 is neither independent nor wholly test-only

- **Plan target:** Phase-2 contract at lines 64–66 and items T-2 through T-9 at lines 71–90.
- **Failure scenario:** test-track branches encode contracts that Phase 1 then changes, touch the same test/support files, or cannot be completed until Phase-1 behavior exists. “Test-only” work turns into unplanned product mini-lanes during a supposedly parallel batch.
- **Evidence/dependencies:**
  - T-3 is explicitly “feeds T-2” at plan lines 75–76; T-2 cannot be scoped or declared complete before T-3's risk list.
  - T-4 covers the same dry-run behavior R2-G must change (`q2-gate-minor-followups.md:29-42`). Sequence R2-G implementation before T-4's generalized sweep, or combine them.
  - T-5's error-envelope discrimination consumes R2-C's corrected 403 contract (plan lines 40–42 and 80–82). Build the generic harness first if contract-neutral; bind the report cases only after R2-C.
  - T-6 touches document mocks/dependencies identified by the discount ticket (`discount-lane-out-of-lane-findings.md:31-41`) while R2-B/R2-E alter the same feature behavior.
  - T-9 is a live-stack proof of already-merged discount/permission work and must run only after Phase 0 deployment/reseed succeeds; it is not parallel with lines 20–28.
- **Required plan change:** order T-3 → T-2; R2-G → T-4; harness-only T-5 → R2-C-specific assertions; R2-B/R2-E → T-6; Phase 0 → T-9. Any red-test-discovered defect needs explicit triage authority before it expands scope.

### MAJOR — R2-E needs an atomic, mandatory concurrency contract; POS sync is an explicit negative scope, not an unexamined neighbor

- **Plan target:** R2-E lines 47–49 and open question 3 at line 103.
- **Failure scenario:** if the precondition is optional, old clients continue silent last-write-wins. If `updated_at` is compared before the write transaction/row lock, two concurrent requests can both pass and one still deletes the other's line set. **Hypothesis:** if an implementer applies `If-Match` through global document/fiscal middleware rather than only mutable document update routes, offline POS fiscal-event ingestion could begin rejecting device batches that do not carry that header.
- **Ticket/code evidence:** the source ticket identifies silent last-write-wins and the destructive delete/recreate line update at `docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md:124-160`. The repository already uses payload `expected_updated_at` and a typed stale 409 for workshop transitions (`apps/web/src/features/workshop-work-orders/pages/WorkOrderDetailPage.tsx:94-105`; `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Controllers/WorkOrderTransitionController.php:202-205`). POS offline sync is not a mutable `Document` update: `apps/pos/src/lib/sync/syncService.ts:406-412` says it posts immutable fiscal-event envelopes to `/pos/sync/fiscal-events`, and `apps/api/app/Modules/Fiscal/routes.php:17-31` confirms the dedicated ingestion endpoint.
- **Required plan change:** use required payload `expected_updated_at` on every mutable Draft/Confirmed document update/autosave path, matching the existing API convention. Load with `lockForUpdate`, compare inside the same transaction before delete/recreate, and return a typed 409 carrying current/expected values. Explicitly exempt immutable POS fiscal ingestion and add a negative-scope regression proving `/pos/sync/fiscal-events` still accepts its existing envelope without a document precondition.

### MAJOR — R2-A's “siblings” omit the multi-company report contract that the same source ticket says becomes reachable

- **Plan target:** R2-A lines 32–35 and open question 1 at lines 99–101.
- **Failure scenario:** A makes company 2 usable for authoring and fixes cross-company GL access, but owner/trial-balance reports can still aggregate unlike currencies or emit EUR/TND amounts at inconsistent/root-company scales. The product would expose a newly usable multi-company path with financially meaningless reports.
- **Ticket evidence:** `docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md:130-150` records W-8 F-3 and says it is launch-blocking for any multi-currency tenant. The follow-up `docs/superpowers/tickets/2026-08-05-l4-mixed-currency-report-scale.md:15-35` proves some owner reports refuse mixed currency while others silently sum/render it, and states that the underlying sum is meaningless. Its proposed family-wide decision is at lines 67–88.
- **Required plan change:** if multi-company remains shipped, include a ruled “refuse mixed-currency aggregate or emit per-row currency” contract with R2-A. If tenant #1's single-company status is used to defer that work, hard-disable company creation/switching for launch rather than fixing half the exposed path.

### MAJOR — The plan omits other open launch-relevant integrity/tenancy items, including a sealed-event race and a live location-scope leak

- **Plan target:** completeness claim line 6, Phase-1 list lines 30–62, and exclusions lines 92–96.
- **Failure scenario:** a routine drawer freeze can still mint a sealed deposit receipt that never projects but remains visible as received; a deactivated branch can be implicitly included in cash/report reads even though explicit access is 403; legacy import jobs can remain fatally unretryable and lose a user upload. None is assigned, ruled out, or added to Phase 0 discovery.
- **Ticket evidence:**
  - `docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md:11-17,31-63` documents the permanent orphan receipt and the routine frozen-repository race; it is explicitly OPEN at line 7.
  - `docs/superpowers/tickets/2026-08-06-l3-cash-scope-residuals.md:16-43` records the P1 implicit inactive-location leak across shared report scope. R2-C includes only this ticket's part (b), not part (a).
  - `docs/superpowers/tickets/2026-08-05-bindstenantcontext-promoted-readonly-unserialize.md:90-104` identifies two genuinely exposed P1 import jobs, says blind discard loses a user upload, and supplies the staging/prod `failed_jobs` check that decides urgency.
- **Required plan change:** add a deposit-recoverability/race ruling lane and the location-scope P1 to R2-C or a preceding shared-scope lane. Add the failed-jobs query to Phase 0; schedule the import compatibility fix pre-launch if rows exist, otherwise record an explicit prophylactic deferral.

### MAJOR — Phase 0 understates `channels:reconcile` and omits launch-gate document updates

- **Plan target:** Phase 0 line 23 and the staging-smoke gate at line 28.
- **Failure scenario:** the command can return non-zero or skip/prune due tenant-scope drift while the checklist advances; pre-existing channel webhooks remain 404. Operators can also execute stale Phase-E pass criteria, treating partial tenant coverage as a pass or referring to a removed `--fix` flag.
- **Ticket evidence:** `docs/superpowers/tickets/2026-08-05-channels-reconcile-post-migrate-deploy-step.md:21-55` explains why the step is mandatory and requires exit-code/drift/prune handling; its three-part verification and real webhook probe are at lines 60–101. `docs/superpowers/tickets/2026-08-05-wave2-phase-e-doc-updates-owed.md:9-17,25-81` says the human-executed launch sheets remain stale and specifies exit-code, tenant-coverage, removed-flag, and output-shape corrections.
- **Required plan change:** expand line 23 into the ticket's full command and verification gate, and land/owner-approve the Phase-E document corrections before relying on those sheets for line 28.

### MAJOR — R2-G is not “narrow”: it includes production-data backfill behavior and overlaps the ops test track

- **Plan target:** R2-G lines 54–56, its “narrow” treasury gate, and T-4 lines 77–79.
- **Failure scenario:** an implementation can report only CLOSED periods, miss FILED periods, select one of multiple overlapping periods, or behave differently under the dry-run operators actually use. Backfill output can be accepted without proving idempotency/affected-row accounting or without a tax-qualified review.
- **Ticket evidence:** `docs/superpowers/tickets/2026-08-06-q2-gate-minor-followups.md:29-42` explicitly identifies FILED-period omission, multi-period `first()` loss, and missing dry-run assertions, and calls this a backfill-leg touch-up. The 0%-deductible behavior remains an owner/expert question at lines 44–56.
- **Migration classification:** this is not proven to require schema DDL, but it **is** a data-remediation/backfill lane and needs migration-grade operational controls. Separately, the omitted timbre ticket would become migration-bearing if its ruling requires seeding/backfilling TN rounding accounts (`docs/superpowers/tickets/2026-08-05-tn-timbre-account-carries-rounding-noise.md:53-76`). **Hypothesis:** R2-F may also become schema-bearing if escape-hatch option (a) introduces a new `source_document_id`; that depends on the still-unmade design choice (`l2-correcting-entry-escape-hatch.md:40-60`).
- **Required plan change:** add taxation/compliance and data-migration reviewers; require dry-run/apply parity, per-tenant affected/skipped counts, idempotent rerun, and filed/multiple-period tests. Merge R2-G's command-specific tests before T-4 generalizes the contract.

### MAJOR — The stated per-lane gate has no cross-lane or release/data gate and several reviewer assignments do not match risk

- **Plan target:** process lines 7–9 and all “Gate:” assignments in Phase 1.
- **Failure scenario:** narrow specialists approve locally correct changes outside their domain—e.g. treasury approves a VAT backfill, GL approves a stock cancellation policy, or tenancy approves a schema migration—then the orchestrator merges without the missing domain decision or an integration re-gate.
- **Evidence:** the mismatches follow directly from the source-ticket risks: R2-A is schema/data-model bearing (`w8-isolation-findings.md:106-123`); R2-E is transaction/concurrency bearing (`w7-cross-cutting-findings.md:124-160`); R2-F explicitly needs inventory and fiscal/product rulings (`l2-cogs-and-ap-unreversed-on-cancel.md:63-82`; `l2-gl-vat-declaration-desync.md:72-89`); R2-G is tax backfill work (`q2-gate-minor-followups.md:29-56`). The detailed reassignment is in “Gate and reviewer evaluation” below.
- **Required plan change:** require a rebased-current-`dev` re-gate for every dependent lane, a release/data reviewer for migrations/backfills, and a combined Document/Accounting/Inventory/Taxation integration gate before promotion.

### minor — R2-A cites a nonexistent, wrong-dated ticket reference

- **Plan target:** citation on line 35: `[tickets 2026-08-05-w8-isolation]`.
- **Failure scenario:** an implementer searching the stated date cannot find the source and may miss F-3/F-5/F-6 or assume the lane summary is exhaustive.
- **Evidence:** the actual file is `docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md`; its table at lines 21–27 and detailed sections at lines 31–223 contain the cited findings.
- **Required plan change:** correct the path and cite exact finding IDs rather than “siblings.”

## Recommendations on the plan's open questions

### 1. Is R2-A pre-launch mandatory, or a fast-follow?

**Recommendation: keep the security and creation-integrity parts pre-launch; do not ship an active half-hardened multi-company surface.** W-8 F-1 is a demonstrated P0 read/write cross-company breach (`2026-08-03-w8-isolation-findings.md:31-102`), and F-5 makes every documented company creation commit then return 500 (`:170-199`). Tenant #1 being single-company does not neutralize endpoints and a switcher that remain shipped, as the plan itself acknowledges at lines 99–101.

Split the decision:

1. **Pre-launch A1:** journal/account company scoping, correct target-company currency, POST-company commit/response repair, and denial tests.
2. **Pre-launch A2 if company creation remains enabled:** company-aware document-number constraint plus the migration gate described above.
3. **Pre-launch A3 if mixed-currency companies can be created:** refuse meaningless mixed-currency aggregate reports or emit per-row currency according to a written contract (`2026-08-05-l4-mixed-currency-report-scale.md:67-88`).

The only defensible fast-follow option is to hard-disable company creation/switching for the launch cohort and verify the API also refuses it. A UI-only hide is not a substitute for closing the P0 API breach.

### 2. Should the full PostgreSQL treasury+GL+fiscal leg be preflight or CI-only?

**Recommendation: mandatory on every PR/merge in CI, not a default developer preflight; retain a small PostgreSQL smoke locally.** Plan lines 68–70 name three SQLite-masked PostgreSQL failures in one week. That is evidence against nightly-only or optional coverage. Run the complete treasury/GL/fiscal set as sharded CI jobs required for merge, with path filters only if a periodic full unfiltered run remains required. Keep a short migration + representative lock/savepoint/aggregate smoke available in local preflight so developers get early feedback without paying the whole suite cost.

T-1 must also include tenant migration/rollback coverage before R2-A, because `2026-08-07-repobal-lane-followups.md:22-26` already predicts the next tenant migration will fail a rollback test. Record measured runtime after the first CI run; budget should tune sharding/caching, not weaken the required gate.

### 3. If-Match or `updated_at` in the payload for R2-E?

**Recommendation: required `expected_updated_at` in the payload.** This matches the repository's established Workshop convention (`WorkOrderDetailPage.tsx:94-105`; `WorkOrderTransitionController.php:202-205`), avoids inventing a partial ETag/If-Match contract, and makes the precondition explicit in typed request/command DTOs.

The field must be mandatory for every mutable Draft/Confirmed update path, including autosave, and checked after a tenant+company-scoped `lockForUpdate` inside the same transaction that replaces lines. Return a typed 409 with current and expected values. Do not apply it to POS fiscal sync: that path ingests immutable event envelopes through `/pos/sync/fiscal-events` (`syncService.ts:406-412`; `Fiscal/routes.php:17-31`). Add an explicit exemption test so a future middleware refactor cannot accidentally couple the paths.

## Gate and reviewer evaluation

The per-lane TDD → specialist gate → fix → narrow re-gate process at plan lines 7–9 is reasonable for isolated code changes, but it lacks two gates required by this batch: (1) release/data review for schema and backfill work, and (2) a rebased cross-lane integration re-gate after shared-surface lanes merge.

| Lane | Assigned gate | Risk mismatch | Recommended gate |
|---|---|---|---|
| R2-A | tenancy-authz | Correct for F-1, insufficient for company-number schema, caller/target currency, and commit-then-500 transaction/response behavior. | Tenancy-authz **plus database/migration** and precision/accounting review. Split authz and schema commits; require tenant-migration rollback/forward proof. |
| R2-B | treasury (money) | Directionally correct, but quote conversion and `DocumentLine` ownership cross Document/Procurement/API boundaries. | Treasury/precision **plus Document conversion** review; FE regression only if quote UI serialization changes. |
| R2-C | tenancy-authz (narrow) | Mostly correct. It also changes the API error-envelope contract consumed by T-5. | Tenancy-authz plus a narrow API-contract/FE consumer check. |
| R2-D | precision | Correct for decimal validation. The optional repo-wide PHPStan rule is tooling/architecture work with a repository-wide merge effect. | Precision plus static-analysis owner; land the rule after affected PHP lanes rebase and clear it. |
| R2-E | FE + treasury | Misses backend transaction/concurrency and destructive document-line replacement risk. Treasury is not the primary authority for row locks. | Backend concurrency/database **plus Document-domain** and FE. Add POS/fiscal negative-scope regression, not a POS feature review. |
| R2-F | GL + treasury | Materially incomplete: COGS requires Inventory; input/output VAT requires Taxation/compliance; multiple choices require accountant/product rulings before code. | Accountant/product ruling gate first, then GL + Inventory + Taxation/compliance + Treasury integration review. Split the lane. |
| R2-G | treasury (narrow) | Wrong risk label. This is VAT declaration policy plus operator-run backfill and FE precision. | Expert/accountant ruling, Taxation/compliance, data-migration/ops, precision, and FE for `VatBreakdownTable`. |
| R2-H | tenancy-authz (narrow) | Correct for route middleware, but the source ticket requires certificate and withholding-rule route matrices and the widened lane includes a FE response-contract fix. | Tenancy-authz for route groups, plus Taxation domain and FE/API-contract review for the full withholding lane. |

Cross-lane gate changes:

- Require each branch to rebase onto the latest merged `dev` and rerun its specialist gate; “narrow re-gate” is insufficient where another lane changed the consumed contract.
- Add one combined Document lifecycle gate after R2-B/R2-E/R2-F and one VAT/backfill gate after R2-F/R2-G/T-4.
- A migration/backfill lane cannot pass on unit/feature tests alone: require dry-run output, apply, idempotent rerun, per-tenant counts, and rollback/restore posture.
- Phase-0 operational steps need an owner/release sign-off with captured command exit codes and evidence, not only an orchestrator code green-proof.

## Completeness appendix — missing or mis-scoped tickets (2026-08-03 through 2026-08-07)

The following are pre-launch-relevant and absent or materially mis-scoped. Items described by their ticket as tenant-#1-nonblocking are marked conditional rather than upgraded here.

| Ticket | Missing/mis-scoped pre-launch content |
|---|---|
| `2026-08-03-w4-purchasing-inventory-defects.md` | All three P1s are absent: allocator/WAC drift (`:14-79`), bonus receipts un-invoiceable (`:83-139`), RFQ VAT omitted (`:143-188`). |
| `2026-08-03-w5a-withholding-defects.md` | R2-H covers only #3. P1 zero-rate fictitious certificates (`:20-69`) and P1 empty list (`:178-220`) are absent; #3 must include both certificate and rule groups (`:153-174`). |
| `2026-08-03-paid-with-unreconciled-balance-investigation.md` | The paid/full-balance invariant and data investigation (`:1-18`) are absent from Phase 0 and Phase 1. |
| `2026-08-03-f2f3-regate-carryovers.md` | Certification-relevant draft/confirm millime divergence (`:6-14`) and missing credit-note confirm permission (`:31-35`) are absent. |
| `2026-08-03-credit-note-regate-carryovers.md` | Operator notice for requested-vs-credited (`:24-28`) and the PDF/print-gating whole-unit rendering defect (`:40-47`) are absent. |
| `2026-08-03-vat-regate-carryovers.md` | Mandatory pre-filing disposition of every VAT backfill skip (`:7-14`) is absent from Phase 0. |
| `2026-08-03-w7-cross-cutting-findings.md` | F-8 says web documents use the company cap, not the user's POS cap, and that launch checklist assumptions are wrong (`:381-420`); no ruling/checklist correction is scheduled. |
| `2026-08-03-w7-owner-dashboard-untestable.md` | Launch-critical owner-dashboard money remains without UI coverage (`:1-9,33-65`); T-5 does not name or accept this debt. |
| `2026-08-03-w5b-treasury-remittance-statement-findings.md` | Conditional UX safety gap: the UI performs an irreversible remit without a reviewable draft and can strand instruments on a mid-loop failure (`:16-41`). At minimum it needs an explicit launch disposition. |
| `2026-08-05-deposit-residual-seal-before-resolve-vectors.md` | OPEN seal-before-resolve race/recoverability problem (`:7,11-17,31-80`) is absent. |
| `2026-08-05-bindstenantcontext-promoted-readonly-unserialize.md` | P1 legacy import-job compatibility is absent; Phase 0 should at least execute the evidence query and conditionally schedule the fix (`:90-110`). |
| `2026-08-05-cross-tenant-annotation-ast-check.md` | T-4 does not cover the open architecture guard, five pre-existing architecture failures, or unreachable `fiscal:backfill` command (`:78-126`). This is test/ops launch-debt, not a claim that every item is a product blocker. |
| `2026-08-05-lineless-document-gl-posting.md` | Posting guard/fixture sweep and existing-data query (`:66-85`) are absent. |
| `2026-08-05-tn-timbre-account-carries-rounding-noise.md` | State-liability rounding commingling remains OPEN pending expert ruling (`:37-76`); it is neither scheduled nor explicitly deferred. |
| `2026-08-05-wave2-phase-e-doc-updates-owed.md` | Human-executed launch gate sheets remain stale (`:9-17,25-97`), but Phase 0 relies on them without scheduling correction. |
| `2026-08-05-l4-web-followups.md` | TND inventory reconciliation still truncates unit cost at EUR scale (`:44-59`). This is a live tenant-#1 precision issue, not the conditional multi-company ticket. |
| `2026-08-05-l4-mixed-currency-report-scale.md` | Conditional on retaining multi-company: owner reports silently aggregate unlike currencies (`:15-35,67-88`); R2-A does not include the ruling. |
| `2026-08-06-l2-cogs-and-ap-unreversed-on-cancel.md` | R2-F names the reversals but omits their required inventory/period rulings (`:63-82`). |
| `2026-08-06-l2-gl-vat-declaration-desync.md` | R2-F includes only the period lock, not declaration reconciliation (`:72-89`). |
| `2026-08-06-l2-correcting-entry-escape-hatch.md` | Entire correcting-entry/override decision is absent despite R2-F's wildcard citation (`:21-60`). |
| `2026-08-06-l2-gl-gate-minor-followups.md` | R2-F's wildcard citation does not enumerate journal-code, reversal-audit, line-order, aged-payables, or cross-module coupling follow-ups; these need explicit dispositions rather than implied inclusion. |
| `2026-08-06-l3-cash-scope-residuals.md` | R2-C covers (b) only; P1 inactive-location implicit leak (`:16-43`) and the explicit pre-launch register-location task (`:105-140`) are missing. |
| `2026-08-06-l6-partners-followups.md` | Phase-0 `partner delete 204/409` spot-check does not cover the reachable soft-delete holes across ~11 additional financial/operational tables (`:14-38`). |
| `2026-08-06-c2-fixture-terminal-location.md` | The campaign fixture repair and immutable warehouse receipt disposition before fiscal evidence (`:55-76`) are absent. |
| `2026-08-06-pos-product-images-never-populated.md` | The broad “Device-side work” exclusion at plan lines 92–94 does not name this coordinated POS/server image-contract defect (`:1-6,14-30,56-80`). It is cosmetic, so deferral can be reasonable, but it must be explicit if POS imagery is in first-tenant acceptance. |
| `2026-08-07-repobal-lane-followups.md` | FE `allow_negative` toggle (`:6-11`) and new-migration rollback-test trap (`:22-26`) are absent; the latter directly blocks R2-A's migration. |

Tickets correctly excluded or not elevated by this review include the feature-flagged marketplace admin redesign, the external enrichment-platform contract (ERP already falls back to polling), the minor onboarding spinner, and the next-release reports-view deprecation sweep. Their omission does not cure the concrete gaps above.
