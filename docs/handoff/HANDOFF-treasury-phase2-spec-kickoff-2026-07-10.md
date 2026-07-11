# Treasury Phase ② — Instrument Portfolio / Échéancier: spec+plan cycle kickoff (2026-07-10)

**Mandate (owner, 2026-07-10):** run the full spec/plan cycle for treasury Phase ② — the payment-instrument portfolio (chèques/traites/effets) + échéancier (due-date schedule) — **based on industry standards and best practices**. Spec → adversarial review → plan → adversarial review. Implementation dispatch comes later (owner finishes the design-system sweep first, then runs the two ready Codex tracks: `CODEX-treasury-ui-gaps-2026-07-10.md`, `CODEX-bank-directory-2026-07-10.md`).

## Ground truth to load first
1. **Phase ① shipped**: money-movement spine on origin/dev `8643c2573` (append-only `repository_movements` + single write port `TreasuryMovementService::record()/transfer()` + GL-atomic `postEntryNow()` + `treasury:reconcile` freeze-on-drift). THE INVARIANT: repository balance changes ONLY via the port, every movement carries source-doc ref + JE. Full history: memory `project_treasury_spine_rebuild`; audit `docs/superpowers/audits/2026-07-09-treasury-spine-independent-audit.md`.
2. **The gap register that motivates Phase ②**: `docs/superpowers/audits/2026-07-07-treasury-industry-gap-audit/` (G1–G20). The three structural gaps: **instruments post NO GL**, **no échéancier**, **POS-collected checks are orphaned** (never enter a portfolio/lifecycle). Also: `reversePayment` moves no cash and posts no GL (now reachable — perm seeded; deliberately admin-only).
3. **Owner's 5-phase decomposition** (memory `project_treasury_spine_rebuild`): ② instrument portfolio/échéancier → ③ cash-visibility read layer → ④ expense depth → ⑤ bank statement import. NF525 = POS only; back office = NF203/FEC/PCG. TN retenue à la source / TEJ module already exists attached to payments — do NOT rebuild RAS.
4. **Interlocks**:
   - `transfer()` on the port has ZERO production callers today — Phase ② flows (remise en banque etc.) are its likely first consumer; its concurrency contract is test-proven only.
   - Bank-directory track (Codex, later): its design carves instrument surfaces OUT and reserves them for Phase ② — instrument bank fields (`bank_name/bank_branch/bank_account` on `payment_instruments`) get the BankPicker/bank_id treatment HERE, not there.
   - GL roadmap: `feat/accounting-gl-go-live` roadmap `62356eb1d`; expert-comptable gate exists on some accounting decisions — flag GL-account choices (5112/5113 remises, 413 effets à recevoir, escompte) as owner/expert-comptable decision points in the spec.
   - Existing code: `PaymentInstrument` model/controller + `InstrumentListPage` (contract-fixed, 9 statuses), `PaymentInstrumentController::transfer` route (instrument-level, NOT the port), bank reconciliation module exists separately.
5. **Deploy reality**: no production tenants; staging auto-deploys from dev with entrypoint-automated migrate/reseed/cache-reset; staging tenants were brownfield-backfilled 2026-07-10. The opening-balance backfill artisan command is still unbuilt (ticket) — Phase ② work must not assume it.

## Industry-standards research scope (use deep research where useful)
- French/Tunisian commercial-paper practice: portefeuille effets (chèques, traites/LCN), remise à l'encaissement vs remise à l'escompte, dates de valeur, impayés/rejets (dishonor) + re-presentation, endossement; PCG accounts 5112/5113/511x, 413 (effets à recevoir), 403/405 (effets à payer), agios/escompte charges.
- Échéancier best practice: AP+AR due-date ledger unified vs per-side; aging buckets; POS-collected post-dated checks (very common in TN retail) entering the portfolio automatically.
- ERP references: how Odoo/Dolibarr/Sage handle check/effect lifecycles (state machines: received → deposited/remitted → cleared/bounced) and their GL postings at each transition.
- NF203/FEC implications: journal codes (the spine added `journal_code` FEC column; sales path stamping is a known gap), immutability expectations for instrument events.

## Process contract (repo standing rules)
- superpowers:brainstorming FIRST, then spec to `docs/superpowers/specs/2026-07-XX-treasury-phase2-instruments-echeancier-design.md`, Codex adversarial review saved TO FILE (`docs/superpowers/specs/reviews/`), reconcile to Rev 2, then writing-plans → plan review → STOP (owner gates dispatch).
- Every instrument money movement must flow through the Phase-① port with a JE (no new balance writers; the DB trigger will reject them anyway). Lock-order invariant: company advisory → repo row. Precision contract rule 19 everywhere.
- Spec must state the POS-checks bridge design (fiscal-event projection → portfolio) explicitly — that projection context has no CompanyContext (rule 20) and must be canonical-index idempotent like the existing bridges.

## Session-start prompt suggestion
"Read docs/handoff/HANDOFF-treasury-phase2-spec-kickoff-2026-07-10.md and run the Phase ② spec/plan cycle it mandates."
