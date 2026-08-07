# Remediation Round 2 — plan v3 (2026-08-07)

**v2 → v3:** Codex round 2 = REJECT (record: `…-codex-review-round2.md`; round 1 dispositions:
3 RESOLVED, 7 PARTIAL). v3 closes the round-2 BLOCKER (Phase R rebuilt as an executable
decision gate: every ruling now has alternatives, an owner, a recording artifact, and an
outcome→behavior mapping per branch) and all 7 new MAJORs. Overtaken by events since v2:
**R-a IS ANSWERED and R2-G IS SHIPPED+PUSHED (`d8efb5df0`)** — reflected below.

**Process per lane:** unchanged (worktree → TDD → specialist gates → fix → re-gate → ff merge
+ green-proof). **Cross-lane rules:** (1) dependents rebase onto current dev and re-run their
specialist gate; (2) migration/backfill lanes add the release/data gate (dry-run/apply
parity, idempotent rerun, per-tenant counts, rollback/restore posture); (3) combined
integration gates: Document-lifecycle after B/E/F-block, VAT/backfill after F-block/T-4;
(4) static-analysis ratchets land per the R2-D sequence below (the v2 "lands last / rebase
over it" paradox is resolved there).

**Reviewer matrix** (v2 referenced it without including it — it is THIS table):

| Lane | Gates |
|---|---|
| R2-I | general + release/data |
| R2-C | tenancy-authz + API-contract/FE consumer |
| R2-P | (1) precision+inventory-costing (2) imports (3) taxation+procurement — independent |
| R2-H | tenancy-authz + taxation + FE/API-contract |
| R2-K | fiscal-pos + tenancy-authz |
| R2-L | precision + FE + tenancy-authz (permission surface) + Document/Taxation (tax pipeline) |
| R2-A1 | tenancy-authz |
| R2-A2 | tenancy-authz + release/data |
| R2-A3 | precision + FE |
| R2-B | treasury/precision + Document-conversion |
| R2-E | backend concurrency/DB + Document-domain + FE (+POS negative-scope regression) |
| R2-F1..F5 | accountant-ruling gate first; then GL + inventory-costing + taxation + treasury |
| R2-M | GL/accounting + release/data (timbre residue, if R-b rules split) |
| R2-N | treasury (conditional paid/balance write-path fix) |
| R2-J | tenancy-authz (narrow) |
| R2-D | precision + static-analysis owner (final full-tree pass, see sequence) |

---

## Phase 0 — DATA-READINESS GATE on staging (blocking; named artifacts; owner/release
sign-off with captured evidence)

0.1 `channels:reconcile` full contract (exit code, drift/prune evidence, 3-part verification,
    real webhook probe).
0.2 Permission reseed verification (manager-403 spot-checks ×2) + custom-grant drift check
    BEFORE trusting boot-sync.
0.3 Detection queries per tenant → accountant-disposition list:
    a. negative document lines · b. negative repositories (pre/post-migrate variants)
    c. paid-with-balance_due=total (~23 docs): run the investigation; if the WRITE-PATH
       branch is proven, **conditional lane R2-N activates** (treasury gate) — the gate
       cannot close on "documented but unresolved"
    d. lineless posted documents · e. **VAT backfill skips: FIXED/adjusted until the skip
       list is EMPTY** — disposition-instead-of-fix is NOT permitted (ticket
       `2026-08-03-vat-regate-carryovers` §N2 requires empty; v2's "or file disposition"
       escape is withdrawn). Pre-filing hard requirement.
    f. `failed_jobs` legacy-import query → activates R2-J if rows exist, else recorded
       prophylactic deferral
0.4 Branch-register `location_id` data task (runbook 4.2).
0.5 Consolidated accountant-disposition list (C-2 receipt, W-6 D1b 19.000, negative lines,
    legacy/confirmed-window CNs, 0.3 outputs).
0.6 Phase-E launch-sheet corrections landed + owner-approved before any sheet executes.
0.7 Staging smoke green.

## Phase R — DECISION GATE (each ruling: owner · alternatives · recorded-answer artifact ·
outcome→behavior mapping. A lane blocked on a ruling CANNOT dispatch until its row is
ANSWERED in the artifact.)

**Artifact for ALL rulings:** `docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`
(created at first answer; verbatim answer + selected branch per ruling).

- **R-a** ✅ ANSWERED 2026-08-07 (expert): 0%-deductible EXCLUDED entirely.
  Outcome mapping executed: writer writes no row + deletes stale; backfill remediates both
  legacy shapes. **R2-G SHIPPED (`d8efb5df0`).** CLOSED.
- **R-b** (expert) N-9 timbre residue: **Alternatives:** (1) keep absorbing ≤tolerance dust
  in 4375 (status quo, document it) · (2) split dust to SalesRoundingDifference always (4375
  carries ONLY true stamp liability) → changes `AccountingService::residualPlan()` + TN
  seeder + tenant backfill posture. **Mapping:** (1) → docblock+expert-sign-off line only;
  (2) → **lane R2-M** (GL/accounting + release/data gates, ordered AFTER F2 — both touch
  residualPlan; migration-bearing if backfill required). R-b blocks R2-M only; G is shipped
  and unaffected.
- **R-c** (expert/product) cancellation cluster — four decisions WITH alternatives:
  c1 inventory on cancel-reversal: restock (movement-port reversal) vs offsetting
  COGS-contra leg (no stock touch) — mapping: F2 implements the chosen leg shape;
  c2 AP/input-VAT when original period CLOSED/FILED: reverse-in-current-period vs
  refuse-cancel (mirror of the AR refusal) — mapping: F1's gate behavior for purchase docs;
  c3 VAT-declaration reconciliation: reversal-aware aggregation vs correction-row emission —
  mapping: F3's design;
  c4 escape hatch: (a) correcting-entry document w/ `source_document_id` (schema-bearing) vs
  (b) admin-gated manual JE with mandatory link annotation — mapping: F4's shape (a→
  release/data gate added).
  **F1 note (round-2 B2 remnant): F1's non-OPEN-period refusal is the DEFAULT pending c2,
  explicitly flagged reversible; F1 may dispatch first with refusal-everywhere, F2..F4 wait
  for c1..c4.**
- **R-d** (owner) multi-company posture: disable-and-defer (API-level refusal + pinned test;
  A2/A3 deferred) vs keep-enabled (A2+A3 pre-launch). **Secondary rulings owned by R-d if
  keep-enabled:** d1 identifier contract for A2 — company-inclusive uniqueness vs company
  discriminator column (alternatives per `2026-08-03-w8-isolation-findings.md:106-123`);
  d2 mixed-currency report contract for A3 — refuse-mixed-aggregate vs per-row currency
  emission (family-wide, `2026-08-05-l4-mixed-currency-report-scale.md:67-88`).
- **R-e** (owner) W-7 F-8 web-uses-company-discount-cap: **Mapping:** accept → launch-sheet
  correction (0.6 scope) + recorded rationale; reject → small document-validation lane
  (per-user cap resolution) added to Wave 2.
- **R-f** (owner) remittance UX: **Mapping:** accept-for-launch → risk-accepted line in the
  rulings record + post-launch backlog; fix-now → draft-step lane (treasury + FE gates).
- **R-g** (orchestrator/product, NEW) deposit recoverability: what happens to a sealed
  DEPOSIT_RECEIPT whose projection can never apply — void-annotate vs compensating fiscal
  event vs manual-disposition register. **Blocks R2-K's remediation half** (the two preflight
  closures can proceed; see R2-K).
- **R-h** (owner, NEW) `fiscal:backfill` register-or-delete: the command is ABSENT from the
  production CLI while an owner checklist references it (`2026-08-05-cross-tenant-…:106-133`)
  — an operator can believe a fiscal backfill ran when no command exists. Decide: register a
  real command or delete the checklist reference. Cheap; blocks nothing but MUST be answered
  before the E-9 sheets run (feeds 0.6).

## Phase 1 — correctness lanes (merge graph)

**Wave 1 (dispatch immediately):**
- R2-I infra pre-fix (rollback-test retargeting + tenant-migration harness; feeds T-1/A2).
- R2-C shared-scope reports (both parts: inactive-location leak + 403 swallow/leak).
- R2-P purchasing trio (three independent gates as in the matrix).
- R2-H withholding (all three deliverables + tripwires).
- R2-K deposit seal-before-resolve — BOTH vectors: frozen-repository preflight AND
  membership-revoked-mid-request (membership preflight + authz reachability check per ticket
  §65-80); gates fiscal-pos + tenancy-authz. The RECOVERABILITY half (what to do with
  already-orphaned receipts) waits for R-g; the lane ships the two preventions first.
- R2-L small certified-money carryovers — WITH its full gate set (matrix): R1 rounding
  unification (Document/Taxation review), R5 credit-notes.confirm permission decided+aligned
  across seeder/route/FE (tenancy-authz review), CN operator notice, CN PDF whole-unit
  surface, TND reconciliation truncation. **Plus (round-2 completeness): the repobal
  `allow_negative` admin FE toggle** (treasury FE affordance, tenancy-authz-checked).
- R2-O (NEW, tiny, test-only): fix the C-2 fixture itself — authorTier4CardFiscalSale gets a
  location predicate so every campaign run stops minting NEW warehouse fiscal receipts
  (`2026-08-06-c2-fixture-terminal-location.md:51-69`). Fiscal-pos narrow gate.

**Wave 2 (as prerequisites clear):**
- R2-A1 cross-company authz (F-1/F-5) — pre-launch regardless of R-d.
- R2-A2 numbering schema (keep-enabled branch only; blocked on R-d/d1; needs R2-I harness).
- R2-A3 mixed-currency contract (keep-enabled branch only; blocked on R-d/d2).
- R2-B quote totals (before any tenant issues quotes).
- R2-E optimistic locking (after F-block; contract per v2, unchanged).
- R2-F1..F5 cancellation cluster — F1 may lead with default-refusal (see R-c); F2..F4 on
  c1..c4; **F5 scope CORRECTED (round-2 MAJOR): the balance_due??total consumers are
  `PaymentController::storeMultiple()`, TWO `PaymentAllocationService` methods, and
  `SmartPaymentController::previewAllocation()` — read/cap paths per
  `2026-08-06-l2-remaining-balance-due-consumers.md:22-87`, NOT "4 PaymentController
  writers"; a fully credit-noted invoice must stop being smart-allocatable.**
- R2-M timbre residue (only if R-b→split; after F2).
- R2-N paid/balance write-path fix (conditional, activated by 0.3c evidence).
- R2-J import-job compat (conditional, activated by 0.3f evidence).
- **R2-F-adjacent (round-2 completeness):** `2026-08-06-l2-gl-gate-minor-followups.md` is
  ASSIGNED: supplier-invoices-invisible-in-aged-payables (§79-98) joins R2-C's report
  surface (same aged-AP query family); the reversal audit/ordering/coupling follow-ups
  (§13-65,100-129) join F2's deliverables.
- R2-D bcmath/is_numeric code fixes (early, Wave 2) — the RULE lands in the final sequence:
  **all Phase-1 PHP lanes merged → D's rule + parsefloat-rule hardening land together on the
  integrated tree → ONE full-tree PHPStan/ESLint run → a single repair commit for any
  stragglers → final integration re-gate.** This replaces v2's impossible "last but rebased
  over" wording: the rule is not a rebase obligation for lanes; it is a terminal ratchet
  with its own repair pass.

**Deferred WITH REASON (complete list — round-2 completeness closures included):**
- W-7 owner-dashboard UI money coverage → post-launch (T-5 explicitly excludes it).
- `2026-08-06-l6-partners-followups.md` partner soft-delete-into-invisibility → DEFERRED
  post-launch WITH REASON: partner delete now 409s when referenced (BUG-007 fix); the
  residual is a pre-existing listing/visibility UX for already-soft-deleted partners, not a
  money or integrity path; ticket stays open.
- `2026-08-06-pos-product-images-never-populated.md` → DEFERRED to the next coordinated
  device release train WITH REASON: fix spans POS device build + server ProductData emission
  (a device-release dependency this round cannot ship); rides with the next device version
  alongside the v67 wave. NOT part of the Z-gross-as-net program.
- Cross-tenant annotation AST check + 5 pre-existing architecture failures → **T-10**
  (now a REAL Phase-2 item, see below); the `fiscal:backfill` operator risk is carved OUT of
  the deferral into ruling R-h (pre-E-9).
- Marketplace T7, 86ing, impersonation, device Z program, §Z/§Y legs, prod-env buildout,
  configurable CN-stamp feature: unchanged.

## Phase 2 — test-coverage track (ordered)

T-1 PG-mode CI leg (CI-required sharded; includes tenant-migration coverage BEFORE A2; fix
    `2026-08-07-linkedcost-pg-only-reds` with it) · T-3 → T-2 (DI audit feeds bearer-auth
    harness conversions) · T-5 neutral harness → R2-C-bound cases · T-4 after R2-G (G is
    shipped — T-4 is UNBLOCKED NOW, may run in Wave 1 of Phase 2) · T-6 after R2-B/R2-E ·
    T-7, T-8 anytime · T-9 after Phase 0 · **T-10 (NEW): architecture-guard debt — wire the
    cross-tenant annotation AST check, triage the 5 pre-existing failures, ratchet.**
Red-test product defects = STOP + orchestrator triage, never silent widening.
Also owed promptly (dev is RED): the unfunded-fixture treasury repair
(`2026-08-07-repobal-lane-followups` escalation — 3 live ExpenseVatPostingTest reds).

## Resolved question dispositions (unchanged from v2)
R2-A1 always pre-launch; A2/A3 per R-d. T-1 CI-mandatory. R2-E payload `expected_updated_at`.
