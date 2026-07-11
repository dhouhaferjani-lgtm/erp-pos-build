# Gate 2 (RC2) — Adversarial Review

**Reviewer:** `claude-opus-4-8`

**Scope:** `git diff phase2-gate-1..HEAD` — Tasks 11–15 (instrument/remittance HTTP surface, customer+supplier payment cutover, side-door + refund guards, FE single-call form).

## Money path verified clean

- **Lock order (§6) honored** — instrument locked/created first, then documents, then GL; no `record()` for deferred customer (`PaymentController.php:706-726`, `:966-1010`, `:1093`).
- **Cash recognition (§7/§8) correct** — portfolio debit-swap on both primary and advance JEs; full amount to portfolio; supplier path byte-identical (Dr 401 / Cr bank + movement) plus outbound instrument; deferred-customer ledgered-repository gate correctly decoupled from `gl_account_id`.
- **No float on money, no afterCommit GL** (only afterCommit is the audit event), **idempotency preserved** on the payment path.
- **All RC1 findings remediated in `698a75fc2`** (MED-3/4/5, HIGH-1) and pinned by tests.

## Findings

1. **LOW** — `createCustomerAdvanceJournalEntry` now posts `SynchronousInTransaction` unconditionally; needed for the deferred path but also changes the immediate-excess path. End-state unchanged, but the "byte-identical" immediate pin only covers a fully-allocated payment (no excess), so this branch is untested and undisclosed.
2. **LOW** — legacy `deposit` endpoint (now a remit wrapper that posts a remise JE for effets) is gated by `can:instruments.transfer`, weaker than remit's `can:instruments.remit`. Not exploitable via seeded roles, but a latent authz inconsistency.
3. **INFO** — side-door 422 guards scope to Cheque/Effet (narrower than the plan's literal "has_maturity"); defensible and disclosed.
4. **INFO/ACCEPTED** — manual web-registration idempotency deferral: **rebuttal adjudicated valid** — `receive()` posts zero GL/movement, so a duplicate is a data-quality (not money) issue at the boundary; Task 11 contracted no idempotency header, and semantic replay is Task 16's assigned contract.

VERDICT: APPROVE
