# Treasury Phase 3 — Gate 4 rc1 Adversarial Review

## Scope and diff basis

Gate 4 covers Wave E (E1 demo-tenant browser E2E fixes + E2 deployment docs) plus a whole-branch coherence audit. The authored Gate-4 delta is `phase3-gate-3..HEAD` (5 commits, 7 files, +209/-14). Whole-branch coherence was checked against sanctioned base `f1d6c1d30`, using the approved Gate 1–3 reviews as the baseline and independently re-verifying the high-risk invariants named in the review brief.

## Mandatory integrity gates

1. **Transfer-port byte-diff — PASS.** `git diff origin/dev..HEAD -- .../TreasuryMovementService.php` is empty. The single-writer port is byte-untouched.
2. **Protected UI-gap interlock files — PASS.** `RepositoryDetailPage`, `ExpenseDetailPage`, and `BankPicker` have an empty `f1d6c1d30..HEAD` diff. `TopBar.tsx` and `Sidebar.tsx` contain the sanctioned D3 notification-bell and cash-movements navigation mounts.
3. **Orphan draft JEs — PASS.** `RepositoryTransferService::transfer()` wraps draft creation and port recording in one database transaction. On idempotent replay, the fresh draft is deleted; exceptions roll the whole transaction back.
4. **Movements without JE — PASS.** Same-GL transfers post no JE by design; cross-GL transfers post exactly one Dr-destination/Cr-source JE. E1 confirmed posted `JE-2026-000096`.
5. **Float on money — PASS.** Backend uses strict decimal helpers throughout. Targeted frontend money-path searches found no numeric coercion or `any` escape.
6. **Spatie team scope — PASS.** `TreasuryAlertRecipients` sets the permissions team to the tenant ID and restores the prior value in `finally`; no company ID is used as the team ID.
7. **Recipient filters/cache — PASS.** Recipient resolution requires active company membership and flushes the permission registrar per tenant.
8. **Maturity zero-count gate — PASS.** Notifications send only when the combined due/overdue count is greater than zero.
9. **Notification isolation — PASS.** Every inbox read and mutation begins from the authenticated user's notification relation.
10. **Currency separation — PASS.** Report totals group by currency and direction; cash-position flows filter to company currency.
11. **TanStack query/invalidation shape — PASS.** Queries use `tenantScopedKey`; mutation invalidations use the sanctioned raw leading prefixes.
12. **Permission gating — PASS.** `treasury.transfer` exists in both backend role seeders and the frontend permission map; the page action is independently gated.
13. **i18n parity — PASS.** Notification and finance keys are present across en/fr/ar. The Gate-3 French window-label low is fixed with `{{days}}` interpolation.
14. **Recorded deviations — PASS.** A-1 race-shape substitution, D4 typed-DataTable use, and raw-prefix invalidation rationale are recorded in the progress ledger/task reports.

## Wave E assessment

- **E1 cache-shape fix (`625d36915`) — correct.** The page now stores the canonical bare repository array under the shared query key. The integration regression drives the real shared-query path, asserts the cache shape, and opens the real modal without a defensive masking fallback.
- **E1 i18n fix (`e1dc510eb`) — correct.** The French widget label now interpolates the server-provided window.
- **E1/E2 evidence and docs — correct.** The progress ledger records the full A→Z flow and the deploy checklist includes the two tenant migrations, per-tenant permission reseed/cache reset, no-chart-reseed note, pre-reseed token caveat, staging smoke, and non-destructive rollback guidance.

## Findings

1. **INFO — defensive scale-3 fallback.** `cashPositionGroupTotal` has a hard-coded `'0.000'` defensive fallback. It is currently unreachable because the controller always emits all three formatted groups.
2. **INFO — replay entry-number gap.** Idempotent replay can allocate an entry number for the fresh draft that is then deleted. The accepted contract requires no orphan draft, which holds.
3. **INFO — notification mutation response shape.** Mark-read/read-all return a message envelope instead of a data resource; this is consistent with Gate 2 and supported by the frontend.

No BLOCKER, HIGH, MEDIUM, or LOW correctness findings. The Gate-3 LOW is resolved.

## Whole-branch coherence

Waves A–D remain as approved in Gates 1–3. Wave E closes the E1 STOP without introducing a new surface. Gate-4 verification is consistent with the reviewed code: Treasury PHPUnit 586 tests, Notification PHPUnit 6 tests, PHPStan clean across 2,508 files, Pint clean, frontend typecheck/lint/audits green, Vitest 394 tests, React Doctor 100/100, and reproduced port/interlock byte-diffs. The branch is coherent and release-candidate-ready pending the owner-side merge.

VERDICT: APPROVE
