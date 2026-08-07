# Remediation Round 2 — plan v2 (2026-08-07)

**v1 → v2:** Codex adversarial round 1 = REJECT (record:
`docs/superpowers/reviews/2026-08-07-remediation-round-2-plan-codex-review.md`). v2 folds in
all 4 BLOCKERs, 9 MAJORs, the minor, the three open-question recommendations, and the
completeness appendix. Every appendix ticket is now either assigned to a lane, assigned to
Phase 0 discovery, or EXPLICITLY deferred with reason.

**Trigger:** owner directive post L5/Q-rulings batch (staging push DONE `2219b49e3` +
`f4e641a59`): burn down the remaining pre-launch defect pile with a parallel
test-coverage track.
**Process per lane:** worktree off dev → TDD implementer → specialist gate(s) per the
reviewer matrix below → fix round → re-gate → orchestrator merges ff with green-proof.
**NEW cross-lane rules (Codex MAJOR):** (1) every dependent lane REBASES onto current dev and
re-runs its specialist gate before merge — narrow re-gates are insufficient when a consumed
contract changed; (2) migration/backfill lanes additionally need dry-run/apply parity,
idempotent rerun, per-tenant counts, and rollback/restore posture (a "release/data" gate);
(3) two combined integration gates: Document-lifecycle gate after B/E/F-block, VAT/backfill
gate after F-block/G/T-4; (4) the repo-wide PHPStan rule (D) lands LAST, after every PHP lane
has rebased over it.

---

## Phase 0 — DATA-READINESS GATE on staging (blocking; named outputs, zero
unresolved-or-undisposed acceptance)

Code is deployed (origin/dev `f4e641a59`). Phase 0 is now about DATA and OPERATIONAL
evidence, each item producing an artifact (SQL output / command exit code / signed line):

0.1 `channels:reconcile` — FULL contract per ticket `2026-08-05-channels-reconcile-…`:
    exit-code check, drift/prune report captured, 3-part verification + real webhook probe.
0.2 Permission reseed verification: boot-sync ran (SYNC_PERMISSIONS_ON_BOOT=true) — verify
    with manager-403 spot-checks (trial-balance AND vat-periods/file) + custom-grant drift
    check BEFORE trusting sync (clobbers customisations).
0.3 Detection queries, per tenant, outputs filed to the accountant-disposition list:
    a. negative document lines (discount lane SQL, incl. demo -75.000)
    b. negative repositories (post-migrate query; blocked-type variant)
    c. paid-with-balance_due=total (~23 docs — ticket `2026-08-03-paid-with-…`): run the
       investigation query; classify seed-vs-write-path before any Phase-1 merge touches AR
    d. lineless posted documents (TN timbre distortion — ticket `2026-08-05-lineless-…`)
    e. VAT backfill skips — resolve EVERY skip or file disposition (MANDATORY pre-filing,
       ticket `2026-08-03-vat-regate-carryovers` §N2)
    f. `failed_jobs` legacy-import evidence query (ticket `2026-08-05-bindstenantcontext-…`)
       → if rows exist, the import-compat fix (R2-J) becomes pre-launch; else explicit
       prophylactic deferral recorded
0.4 Branch-register `location_id` data task (runbook 4.2; L3 residual (d)) — tenant #1's 4
    branch registers, else the branch cash journal-leg contributes nothing.
0.5 Accountant-disposition list CONSOLIDATED (immutable/campaign contaminants): C-2 warehouse
    fiscal sale, W-6 D1b stranded 19.000, negative-line artifacts, legacy stamp-inclusive
    posted CNs + N-1 confirmed-window CNs, 0.3 outputs.
0.6 Phase-E launch-sheet corrections (ticket `2026-08-05-wave2-phase-e-doc-updates-owed`):
    exit-code/tenant-coverage/removed-flag fixes landed + owner-approved BEFORE any sheet is
    executed as a gate.
0.7 Staging smoke (campaign smoke spec) green. Owner/release sign-off with captured evidence
    — not only an orchestrator code green-proof.

## Phase R — RULINGS REQUIRED BEFORE THEIR LANES (owner and/or expert-comptable; nothing in
this phase writes code)

R-a (expert) I-3: 0%-deductible expense declared base → unblocks R2-G.
R-b (expert) N-9 TN timbre account carries rounding noise (`2026-08-05-tn-timbre-…`) →
    may make R2-G migration-bearing.
R-c (expert/product) R2-F cluster rulings: (1) cancel-reversal inventory treatment
    (restock vs offsetting leg); (2) AP/input-VAT CLOSED/FILED-period treatment;
    (3) VAT-declaration reconciliation approach; (4) correcting-entry escape-hatch design
    choice (a-vs-b, `2026-08-06-l2-correcting-entry-escape-hatch`).
R-d (owner) Multi-company posture for launch: EITHER hard-disable company creation/switching
    for the launch cohort (API-level refusal + test, not UI-hide) and defer A2/A3 — OR keep
    it enabled and A2 (numbering schema) + A3 (mixed-currency report contract,
    `2026-08-05-l4-mixed-currency-report-scale` §67-88) are pre-launch. Codex recommends:
    A1 is pre-launch regardless (demonstrated P0 breach); disable-and-defer is the only
    defensible fast-follow shape.
R-e (owner) W-7 F-8: web documents use the COMPANY discount cap, not the user's POS cap —
    ruling + launch-checklist correction (`2026-08-03-w7-cross-cutting-findings` §381-420).
R-f (owner) Remittance UX disposition (`2026-08-03-w5b-…` §16-41): irreversible remit
    without reviewable draft — accept-for-launch or schedule.

## Phase 1 — correctness lanes (MERGE GRAPH, not free parallelism)

**Wave 1 (no shared surfaces, dispatch immediately):**
R2-I  **Test/migration infra pre-fix** (NEW; unblocks every migration-bearing lane):
      BankStatementAggregateSchemaTest name/batch-targeted rollback (kills the --step-6
      trap); tenant migration forward/rollback proof harness (feeds T-1). Gate: general +
      release/data.
R2-C  **Shared-scope report hardening** (WIDENED): (a) implicit inactive-location leak
      (`2026-08-06-l3-cash-scope-residuals` part (a), P1) AND (b) resolver-403 swallow → 500
      + message leak (part (b)) on aged-AR/AP + upcomingPayments. Contract note: T-5 consumes
      the corrected envelope. Gate: tenancy-authz + narrow API-contract/FE consumer check.
R2-P  **Purchasing P1 trio** (NEW — Codex BLOCKER; one lane, three independent gates):
      (1) landed-cost allocator millime drift → WAC/COGS contamination; (2) bonus/free-goods
      receipts un-invoiceable (blocks routine TN pharmacy flow); (3) RFQ-awarded PO carries
      zero VAT. Source: `2026-08-03-w4-purchasing-inventory-defects.md`. Gates: precision +
      inventory-costing (1); imports/procurement (2); taxation + procurement (3).
R2-H  **Withholding lane** (WIDENED — Codex BLOCKER): (1) zero-effective-rate manufactures
      fictitious sequenced fiscal certificates — prevent; (2) certificate-list
      response-contract repair (list permanently empty); (3) route gates for BOTH
      certificates and withholding-rules groups. Include the ticket's named campaign
      tripwires in the gate. Source: `2026-08-03-w5a-withholding-defects.md`.
      Gate: tenancy-authz (routes) + taxation domain + FE/API-contract.
R2-K  **Deposit seal-before-resolve residual vectors** (NEW): frozen-repository race can
      mint a sealed DEPOSIT_RECEIPT that never projects; recoverability ruling + fix
      (`2026-08-05-deposit-residual-seal-…`, OPEN). Gate: fiscal-pos.
R2-L  **Small certified-money carryovers** (NEW, one lane): R1 draft-vs-confirm millime
      unification + R5 credit-notes.confirm permission (`2026-08-03-f2f3-regate-carryovers`);
      CN requested-vs-credited operator notice (N2) + CN PDF whole-unit rendering gate (C2)
      (`2026-08-03-credit-note-regate-carryovers`); TND inventory-reconciliation unit-cost
      truncation (`2026-08-05-l4-web-followups` §44-59 — live tenant-#1 precision).
      Gate: precision + FE.

**Wave 2 (dependencies; dispatch as prerequisites clear):**
R2-A1 **Cross-company authz** (pre-launch regardless of R-d): journal/account company
      scoping, target-company currency on post(), POST /companies commit-then-500 repair,
      denial tests. Source: `2026-08-03-w8-isolation-findings.md` F-1/F-5 (CORRECTED
      citation). Gate: tenancy-authz.
R2-A2 **Company-aware document numbering** (ONLY under R-d=keep-enabled): identifier-contract
      ruling → schema migration with the R2-I harness, per-tenant preflight, constraint
      inspection, duplicate-number functional proof, EXPLICIT statement that rollback is
      unavailable once company-local duplicates exist. Gate: tenancy-authz + release/data.
R2-A3 **Mixed-currency report contract** (ONLY under R-d=keep-enabled): refuse-or-per-row
      ruling applied family-wide. Gate: precision + FE.
R2-B  **Quote totals apply line discounts** (after discount-lane contracts stable; before
      any tenant issues quotes): QuoteController through computeLineTotal; conversion
      recompute proof. Gate: treasury/precision + Document-conversion review.
R2-E  **Optimistic locking** (after R2-F-block lands — shared document lifecycle):
      REQUIRED `expected_updated_at` payload on every mutable Draft/Confirmed update path
      incl. autosave (Workshop convention precedent), checked under tenant+company-scoped
      lockForUpdate INSIDE the replace transaction, typed 409 w/ current+expected. EXPLICIT
      POS exemption + negative-scope regression (`/pos/sync/fiscal-events` accepts existing
      envelope). Gate: backend concurrency/DB + Document-domain + FE.
R2-F  **Cancellation cluster — SPLIT, blocked on R-c:**
      F1 cancellation policy/period gates (refuse cancel when period ≠ OPEN) →
      F2 GL + inventory/AP reversals per ruling →
      F3 VAT-declaration reconciliation (BOTH work items of `l2-gl-vat-declaration-desync`) →
      F4 correcting-entry escape hatch (may become schema-bearing per design choice) →
      F5 remaining balance_due??total consumers (SmartPayment + 4 PaymentController writers).
      Each sublane merges only after the contract it consumes is fixed. Gates: accountant
      ruling first; then GL + inventory-costing + taxation + treasury integration review.
R2-G  **Q2/I-3 + backfill hardening** (blocked on R-a; NOT "narrow"): I-3 ruling
      implementation; FILED-period reporting with its own escalation message (m-7);
      multi-period ->get() (m-8); dry-run-mode assertions (m-9); scale-2 pin (m-3);
      VatBreakdownTable parseFloat (m-6). Gates: taxation/compliance + release/data +
      precision + FE. Merges BEFORE T-4 generalizes the contract.
R2-J  **Legacy import-job unserialize compat** (conditional on Phase-0 0.3f evidence).
      Gate: tenancy-authz (narrow).
R2-D  **is_numeric+bcmath sweep + PHPStan rule** — code fixes early, the REPO-WIDE PHPStan
      rule lands LAST in the round (after all PHP lanes rebase). Gate: precision +
      static-analysis owner.

**Deferred WITH REASON (recorded, not silently dropped):**
- W-7 owner-dashboard UI money coverage (`w7-owner-dashboard-untestable`): accepted debt
  this round; named in T-5 as EXCLUDED (harness first; dashboard cases post-launch).
- `2026-08-05-cross-tenant-annotation-ast-check` (architecture guard + 5 pre-existing
  failures + dead fiscal:backfill): test/ops launch-debt → T-10 backlog line, owner ruling
  on fiscal:backfill register-or-delete already owed.
- Marketplace T7, 86ing, impersonation, device Z gross-as-net (own gated program), §Z/§Y
  legs, prod-env buildout, configurable CN-stamp feature: unchanged deferrals.

## Phase 2 — test-coverage track (ORDERED, not freely parallel)

T-1 PG-mode CI leg — **CI-required on every merge (sharded), small local PG smoke in
    preflight** (Codex recommendation adopted); MUST include tenant migration
    forward/rollback coverage BEFORE R2-A2; record measured runtime after first run.
T-3 → T-2: T-3 tenancy-DI audit produces the risk list; THEN T-2 bearer-auth harness
    (`actingAsViaBearer()`) converts the tenancy-critical suites it names.
T-5 error-envelope/FE truth harness: build contract-NEUTRAL harness first (image-rendered
    helper, route-remount request-fired pattern); bind report-endpoint discrimination cases
    only AFTER R2-C merges. Owner-dashboard cases explicitly excluded (deferral above).
T-4 ops-command dry-run sweep: AFTER R2-G merges (shares the backfill contract).
T-6 stable-t mock + deps closure: AFTER R2-B and R2-E land (same feature surfaces).
T-7 fr `_many` plurals: independent — anytime.
T-8 IngressPrecisionTest route-bound override leg: independent — anytime.
T-9 Playwright proof debt (MTP-DSC-04, flipped perms specs): AFTER Phase 0 (needs deployed
    staging + reseed) — NOT parallel with it.
Red-test-discovered product defects in Phase 2 = STOP, triage with orchestrator (explicit
authority), become mini-lanes — never silently widened.

## Resolved open questions (Codex recommendations adopted)
1. R2-A: A1 pre-launch always; A2/A3 governed by owner ruling R-d (hard-disable is the only
   defensible deferral shape).
2. T-1: CI-mandatory sharded on merge; local = small PG smoke.
3. R2-E: payload `expected_updated_at` (Workshop convention), mandatory, in-transaction
   check, POS exempted with pinned negative test.
