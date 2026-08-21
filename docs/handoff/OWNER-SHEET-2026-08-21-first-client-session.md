# Owner sheet — first-client dev session 2026-08-21 (session 0578e8d8)

> Autonomous session under the landed enforcement layer (origin/dev `c6d6308ae`). Working mode:
> local Docker stack + Playwright verification (staging Dokploy down, owner fixing). Decisions the
> session could make under the standing delegation were made and are FLAGGED here for review;
> decisions that are genuinely yours are ASKS. Answer in place.

## A. Decisions made under delegation (review, veto if wrong)

| # | Decision | Rationale / evidence | Status |
|---|---|---|---|
| A-1 | P2 post-hoc full-suite dispatch (run 32465616707) recorded as waiver-consistent: the only red jobs are the 5 documented inherited classes; `tmp-p2-dispatch` branch deleted | Session ledger entry 2026-08-21; matches the recorded waiver expectation | done |
| A-2 | `scripts/adversarial-review.sh` verdict parse hardened (exact final-line match, fail-closed) — the old `*ACCEPT*` glob accepted "NOT-ACCEPT" and verdicts anywhere in the file | Codex brief-gate finding 3, `docs/superpowers/reviews/2026-08-21-z-sale-decomposition-brief-gate-r1.md` | landed local dev |
| A-3 | C-2 (device Z sale-branch gross-as-net) brief GATED (Codex round 1, 6 findings applied) and DISPATCHED; the M1 signed-bytes versioning question will be ruled by the parent-dispatched fiscal-pos specialist gate autonomously (P3-M1 R-4 precedent), recorded here when made | Brief + progress YAML on local dev; LEDGER row C-2 | lane in flight |
| A-4 | Wave-1 fix lanes dispatched on verified premises: fiscal-bytea (OutboxIngestor PARAM_STR→stream), G-3 training-receipt containment, G-4 VAT POS-refund netting, Arabic backfill (145 keys + 9 plurals) | Premise-verification agent report (all four LIVE with file:line) | lanes in flight |

## B. Asks — need your ruling (from the first-client onboarding audit)

Full audit evidence lives in the session transcript; gaps are ranked G1–G13. The asks:

| # | Question | Options / session lean |
|---|---|---|
| B-1 | **Operator-driven tenant provisioning (G2).** Today the ONLY working path to a usable tenant is the public self-service `/register` form (hardcodes 14-day trial, `<slug>.synerivia.tn`, "Main Location"). `php artisan tenant:create` produces a permanently-503 tenant (no DB/migrations/seeds). For tenant #1: do we (a) onboard via the signup form + post-hoc corrections, or (b) build an admin provisioning endpoint/flow first? Session lean: (a) for tenant #1 (fastest, path is battle-tested by the demo seeders), (b) as a post-launch feature. Either way the session queues a small guard lane so `tenant:create` refuses loudly instead of bricking (G1). |
| B-2 | **Opening cash float (G8).** Repository opening cash is entered as a balance *adjustment* (no draft/validate/lock lifecycle) while the ACCOUNTING opening batch debits the same GL cash account — double-count risk is entirely on the operator. Industry standard is an opening-balance kind with reconciliation against the GL opening. Options: (a) ship as-is for tenant #1 with a written runbook line (operator = you), (b) small lane: an `opening_float` adjustment kind excluded/reconciled against the accounting opening batch. Session lean: (a) for launch + (b) queued. |
| B-3 | **`Location.pos_enabled` is a dead flag (G12)** — set by three creation paths, enforced by nothing. (a) wire it (POS terminal claim/open refuses at a disabled location) or (b) remove it from the UI until wired. Session lean: (b) is dishonest-UI removal, (a) is a small lane; recommend (a) post-launch, document meanwhile. |
| B-4 | **`parties` vs `partners` import duplication (G13).** `partners` is a strictly weaker duplicate (no opening balances) — a client picking it loses AR/AP openings silently. Session lean: hide `partners` from the dashboard (keep API for history), or add a deprecation banner steering to `parties`. Cheap; will implement the hide+banner unless you object (flagged, not yet done). |
| B-5 | **Cash-rounding UI (G4)** is artisan-only (`pos:configure-cash-rounding` via `tenants:run`, exit-code swallowed). For TN millime rounding on tenant #1: is a settings UI wanted pre-launch, or is the runbook step enough? (Also gated by O-24 sign-off, unchanged.) |
| B-6 | **G-4 VAT lane open sub-question**: should `document_count` in the VAT declaration POS arm count return receipts? (Money netting is fixed by the lane either way; this is presentation semantics.) |
| B-7 | **Arabic legal copy**: the backfill lane machine-authors the `legal.privacy.*` Arabic tree (38 keys). Needs a native/legal read before production exposure to AR-locale users. |

## C. Standing open items carried
Four rulings arrived mid-session (2026-08-21, relayed from session de182ed3) and are RECORDED in
LEDGER.md + folded into the queue: **O-26** phase-out lineless posting (lane queued, treasury +
fiscal-pos gates) · **O-27** GO on F-4 backfill (lane queued via country-defaults authority) ·
**O-28** Today's-Sales = NET excl. refunds, labeled (audit lane queued; satisfies the D-3 rider (b)
recording precondition) · **orphan branches** dpa-v8 / r2f2 / r2f4 = evaluate + rebase + full
adversarial gates, merge-or-discard-with-rationale (assessment agent dispatched).
Still open elsewhere: F-2 CI feature-suite scope · Dokploy deploy pipeline (yours) · staging owes
S-1..S-13 once deploys work.

## D. Session log pointers
- Wave-1 lane branches: `fix/ar-locale-coverage`, `fix/fiscal-bytea-param-lob`,
  `fix/g3-training-receipt-containment`, `fix/g4-vat-pos-refund-netting`,
  `codex/z-sale-branch-decomposition-2026-08-18` (worktrees under `.worktrees/`).
- Queued next slots: POS mocked Shift/Reports screens (onboarding-audit G3 — fake money on live
  manager routes, confirmed at `apps/pos/src/pages/ShiftClosurePage.tsx:9-21` and
  `ReportsPage.tsx:7-18`); onboarding-hygiene lane (G1 guard, G11 trial-plan warning, G9 dead import
  links); setup-checklist expansion (G5); R-8/R-10 catch-site lanes.
