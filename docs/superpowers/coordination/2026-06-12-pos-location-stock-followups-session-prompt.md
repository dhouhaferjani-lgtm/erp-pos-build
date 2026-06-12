# Session prompt — POS location-stock follow-ups (FU-1…FU-10)

> Paste everything below the line into a fresh Claude session started from `apps/erp/`.

---

Work through the 10 follow-up tickets from the POS location-aware-stock feature
(merged to dev via PR #189, 2026-06-12). This is a CLEANUP/HARDENING session —
small, well-bounded changes plus a few owner decisions. Do NOT re-open the
feature's design decisions (they are owner-locked in the spec).

START BY READING:
- The tickets: `docs/superpowers/tickets/2026-06-12-pos-location-stock-followups.md`
  (file pointers were verified 2026-06-12, but re-verify against code before
  editing — some context lines were written by a docs agent).
- The spec (§4.2, §4.4, §4.6): `docs/superpowers/specs/2026-06-11-pos-location-aware-stock-design.md`
- Memory: `project_pos_location_aware_stock` (implementation facts + the Codex
  adjudication), `feedback_no_full_test_suite` (NEVER run the full PHPUnit
  suite — file-scoped only), `feedback_dev_branch_workflow`.
- Key modules: `apps/pos/src/lib/stock/` (availability.ts, stockGate.ts,
  cartIngress.ts, gridStock.ts), `apps/pos/src/lib/fiscal/sellerIdentity.ts`,
  `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`.

WORKTREE: create a fresh `git worktree add` off origin/dev (never share the
main worktree); branch `feat/pos-location-stock-followups`.

## Batch 1 — straight code (no decisions needed; TDD each)
- **FU-2** ESLint rule: ban raw `cartStore.addItem(` / `updateQuantity(` calls
  outside `apps/pos/src/lib/stock/` via no-restricted-syntax (precedent:
  `no-parsefloat-on-money` in the POS ESLint config). Keep the existing
  source-pin test (`homePageIngressPin.test.ts`) until the rule is proven, then
  decide whether to retire it.
- **FU-3** `resolveSellerIdentity` gains a `source: 'location' | 'company'`
  discriminant so display callers (`Header.tsx` handlePrintZReport,
  `buildReceiptData.ts` header block) stop re-deriving completeness.
  CAUTION (fiscal): the resolver's return is assigned DIRECTLY as the seller
  input at three paymentStore call sites — do NOT let the new field leak into
  the seller input object (return `{identity, source}` or strip before
  assignment; `saleReceiptV2CanonicalParity` + PHP golden parity +
  `LocationSellerCoherenceTest` must stay green). Fold in the
  `buildEscPosReceiptData` options-object signature cleanup (5th positional
  param → options bag) while touching the same seam.
- **FU-6** Hoist the 6 duplicated `applyAllMigrations` test helpers into a
  shared SQLite test helper; update the six test files.
- **FU-7** Zero-scale inconsistency: keep '0' storage but normalize the
  interface docs/tests OR normalize to '0.0000' at the repository boundary —
  pick one, implement, document. (bccomp-based code is indifferent; the risk
  is future string-equality display code.)

## Batch 2 — owner decisions FIRST (use AskUserQuestion once, then implement)
- **FU-1** Batch-FEFO × policy: should `warn`/`off` extend to batch-tracked
  products (FEFO exhaustion currently hard-blocks regardless of policy)?
  Context for the owner: restaurant vertical defaults `requires_batch_tracking
  = true`, so a batch-tracked Menu tenant could hit this. Recommend: keep
  hard-block for batch-tracked (perishable traceability) + document; implement
  whatever the owner picks.
- **FU-9** Should approval-flow drains (`approvalFiscalSync.ts`) trigger an
  immediate `pullLocationStock(db, 'delta')` instead of waiting ≤60s for the
  tick? Recommend: yes, one line, same swallow-and-log pattern as runFullSync.
- **FU-10** StockFreshness on pull-error: keep last-good timestamp (current,
  ages visibly) vs explicit "stock data unavailable" state. Recommend: keep,
  plus age-based amber tint beyond 15 min (cheap middle ground).

## Batch 3 — documentation pins only (no behavior change)
- **FU-4** `cleanupStuckReceipts` 90-day phantom-availability residual: add the
  bound to the repository docblock + one sentence in the deploy notes.
- **FU-5** Menu cross-category composite-id alias gap: strengthen the C2
  comment in `availability.ts` into a hard precondition note ("fix alias
  resolution BEFORE granting any Menu tenant stock enforcement").
- **FU-8** PER_PAGE drift: the client paginates by `meta.pagination.last_page`
  so it self-adapts; add one cross-reference comment on both constants and
  close the ticket as documented.

GUARDRAILS (per CLAUDE.md + memory):
- TDD; TypeScript strict / PHPStan L8; t() keys for any new UI text (en+fr);
  no parseFloat/Number on quantities (decimal helpers, EXPLICIT scale 4 — the
  helpers default to 3).
- Fiscal invariants: signed seller SHAPE never changes; parity/golden/
  coherence tests are hard gates; `PosCoreReceiptProjection` stays
  warn-and-continue.
- Scoped test runs only (file paths / --filter). Full vitest for apps/pos is
  fine; full PHPUnit is NOT.
- Codex adversarial review (review saved to a file under
  docs/superpowers/reviews/) before the PR; verify Codex findings against code
  before applying — the feature's review trail had multiple confident-but-wrong
  findings.
- PR → dev when done; reference PR #189 and the tickets file; tick off each
  FU in the tickets file as part of the PR.

Deliverable: one PR closing FU-1…FU-10 (code or documented-decision each),
with the tickets file updated to reflect resolution status.
