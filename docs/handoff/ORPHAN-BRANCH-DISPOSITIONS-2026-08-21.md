# Orphan-branch dispositions — 2026-08-21 (owner ruling #4, session 0578e8d8)

Owner ruling (relayed + direct, 2026-08-21): evaluate `feat/dpa-v8-supplier-goods-return`,
`fix/r2f2-cancel-flow-prompt`, `feat/r2f4-correcting-documents`; rebase + fully gate what deserves
landing under the new discipline; discard the rest with recorded rationale. Assessment evidence:
read-only agent report, session 0578e8d8 (summary below verified against worktrees).

## 1. `fix/r2f2-cancel-flow-prompt` @ ff3f4b206 — **DISCARDED**

**Rationale of record:** its entire purpose — prompting for a return note when cancelling an
invoice with delivered goods — landed on dev via `feat/dpa-cf-cancel-flow` (merge `5e817b150`)
as a strict superset that passed four adversarial gate rounds this branch never faced: dev creates
the return note SERVER-SIDE inside the cancel transaction with permission legs
(`RefundService::cancelInvoiceWithDecision`, `DeliveredQuantityResolver`,
`assertDecisionMatchesGoods`) where this branch only client-redirects to a create form; dev's modal
fails CLOSED on an unresolved read model (`requires_return_decision ?? true`) where this branch's
would fail open; dev's i18n namespace (`cancelFlow`) doesn't even overlap this branch's
(`cancelDialog`). Rebase would be L-effort across 6 semantic conflicts (`RefundService` +896 on dev
since the branch's base) for zero net functional gain. Nothing salvageable:
`deliveredDeliveryNotesFor()` is subsumed by `DeliveredQuantityResolver`/`deliveredQuantities()`.

**Mechanics:** tip preserved as tag `archive/r2f2-cancel-flow-prompt-2026-08-21` (nothing deleted
from history); branch + worktree removed. The worktree carried ONE unrelated uncommitted edit
(`DocumentActionBar.tsx` colorClasses→semanticColorTokens token migration, different lane's work) —
preserved as a patch in the session scratchpad and noted here; it belongs to the design-token
migration ratchet and can be recreated trivially (rule 18 covers it whenever that file is next
touched).

## 2. `feat/r2f4-correcting-documents` @ 1950a5e3b — **MERGE-WORTHY, adoption lane dispatched**

Correcting entries as linked documents (owner ruling c4 branch (a)); zero-schema; Shared contract
`DocumentGlCorrectionInterface`; whole-footprint balance invariant; escape hatch for
`UnreversibleDocumentGlException`. Duplication check NEGATIVE — the escape-hatch ticket
(`2026-08-06-l2-correcting-entry-escape-hatch.md`) is still OPEN on dev; no `DocumentCorrection`
source type exists on dev. Relevance has RISEN: LEDGER **O-26** (ruled today — lineless phase-out's
"one-time correcting entries" disposition) and **S-15** (−19.000 stranded entry, "forward
correcting entry" disposition) both need exactly this vehicle. Worktree carries ~1,250 lines of
coherent uncommitted continuation (service/controller/request/endpoint-test + permission
`documents.correct`) — the adoption lane audits it critically (it is UNREVIEWED), commits it,
rebases (1 two-line conflict), raises the Document manifest ceiling deliberately, then full
treasury + fiscal-pos gates. Sequencing: lands BEFORE the O-26 implementation lane (both touch
`AccountingService::reverseDocumentGl` — not parallelizable).

## 3. `feat/dpa-v8-supplier-goods-return` @ 97a6ecf91 — **MERGE-WORTHY, queued behind a slot + one flagged question**

Fully gate-closed off-branch (3 rounds, APPROVED; records in `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/`).
Duplication NEGATIVE. 5 rebase conflicts, all mechanical (one resolves to take-dev). Known items at
rebase: Inventory feature-lane ceiling 106→108 (deliberate), AR key optional, migration DEPLOY NOTE
(scratch DBs that ran `3fdbee6f5` must drop both tables first — no staging/prod exposure),
re-confirm movements against 3C's `MovementGlKind` classification at gate.
**Flagged for owner (sheet B-8):** the recorded park order
(`HANDOVER-dpa-session2-2026-08-09.md:36`: "F-9 must land before its CN-posting wiring", c1-bis
lineage) predates today's land-what-deserves-landing ruling. Session reading: today's ruling names
this branch and authorizes landing under gates; the multi-price residual in the CN-posting wiring is
gate-accepted with failing-visible pins (`SupplierGoodsReturnNoteTest.php:601,656`). The rebase
lane proceeds whole-branch; the gate is instructed to rule explicitly on whether the CN-posting
wiring is safe pre-F-9, with the split (land document+stock half, hold wiring) as the fallback; the
final call is on the owner sheet if the gate flags it.
