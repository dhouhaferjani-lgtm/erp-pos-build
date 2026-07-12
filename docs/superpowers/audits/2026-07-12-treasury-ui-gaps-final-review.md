# Treasury UI Gaps (Track 1) — Final Whole-Branch Review (orchestrated multi-agent)

> **Date:** 2026-07-12 · **Branch:** `feat/treasury-ui-gaps` @ `127292330` (5 commits, 25 files, +1,467/−4, based on origin/dev `1223dcc37`)
> **Protocol (Phase-② pattern, per `HANDOFF-codex-tracks-final-review-2026-07-12.md`):** Fable orchestrator ONLY; 2 Sonnet discovery lanes + 4 Opus verification lanes (incl. a live Playwright drive), all independent, all code-grounded (file:line). This review sits ABOVE Codex's autonomous `claude -p` gates (tug-gate-1/2) and is the merge decision.
> **VERDICT: APPROVE — merge to local dev, ff-promote origin/dev, prune worktree.**

## Lane verdicts

| Lane | Scope | Verdict |
|---|---|---|
| D1 (Sonnet) | 25-file diff map + scope drift vs brief | **CLEAN** — every file maps to Wave A/B or required tests/i18n/permissions; ZERO `apps/api`/`apps/pos`/`packages/shared` touches (the track's automatic-drift trigger never fired); the 4 deleted lines are import-line rewrites; full en/fr key parity |
| D2 (Sonnet) | Gate trail, honesty, deviations | **HONEST** — tags/logging/no-push all per brief; Gate-1 rc2 after an rc1 APPROVE was a *conservative* deviation (post-approval rebase forced the invalidation-key inversion; rc2 genuinely re-reviewed and retracted rc1's wrong praise); reproducible claims re-run and matched EXACTLY (239/239 treasury, 80/80+3 todo expenses, both audits, typecheck); a Gate-2 reviewer false positive was rebutted with evidence, not papered over |
| V1 (Opus, frontend-conventions-reviewer) | Post-sweep FE conventions | **APPROVE** — canonical atoms only; MoneyInput strings end-to-end, zero parseFloat/Number on money; design audit 753 baseline / 0 new, baseline file untouched; tanstack Gate C 0 new; generated types untouched |
| V2 (Opus) | API contracts: both 422 shapes, invalidation, permissions | **APPROVE (findings reconciled)** — both 422 shapes handled in the correct short-circuit order (flat-string first, then canonical envelope — `getErrorMessage` alone would return `undefined` on the flat case); payloads/enums/permission strings exact-match backend at file:line; cross-feature `['payment-repository', id]` + `['treasury-cash-position']` invalidation after expense pay present and prefix-matching the tenant-scoped stored keys |
| V3 (Opus) | Independent fresh runs | **GREEN** — 57/57 vitest by path (8 files, one invocation), typecheck 0, eslint 0 errors, audit:keys 0 new, audit:design-system 753/0 new, RuleTester 10/10, no zombie vitest workers |
| V4 (Opus) | Live Playwright drive, local stack (API 8010 main repo @ dev, vite 5173 from worktree, pharmabio demo tenant) | **PASS** — adjust in (+12.345 → 295,595 TND, 201, amount sent as STRING, movements row + real JE link), adjust out (−5.000 → 290,595), 4-decimal input blocked with NO network fire, pay dialog shows total read-only with NO amount input and payload carries exactly the 3 contract fields, expense flips to Payé and the Pay action disappears, cross-feature refresh observed live (repository balance → 245,095 TND with a Sortie/Dépense movement), no console/app errors, all toasts human-readable localized strings |

## Cross-lane reconciliations

- **V2's MEDIUM-1 (dialog fed `companyCurrency`, not `repository.currency`) downgraded to LOW follow-up by orchestrator verification:** the backend `formatRepository()` (`PaymentRepositoryController.php:262-278`) does NOT return `currency` at all, so the "one-line FE fix" requires an `apps/api` change — scope drift for this FE-only track. The model pins repository currency as port-managed and company-defaulted (single-currency today), so the values are identical in every real tenant. Codex's choice was the only in-scope implementation and matches the file's pre-existing balance-display convention. (V2's line-59 cite was the `Transaction` interface; D1 was right that `Repository` lacks the field.)
- **V1's LOW-2 (wrap sibling invalidations in `tenantScopedKey`) is INVALID as a fix:** dev commit `d0620c90d` makes audit Gate C *error on* wrapped cache-filter keys (proven no-op). Bare prefixes are the enforced convention; D1, D2, and V2 independently converged on this. Finding dies.
- D2 confirmed the 4 commits of origin/dev drift past the merge-base touch none of this track's paths (scoped diff empty) — the gate rcs' rebase-before-merge condition is satisfied by a conflict-free merge onto current dev + post-merge targeted re-verification.

## Follow-up register (ALL LOW / non-blocking — ticket, don't block)

1. Expose `currency` in `PaymentRepositoryController::formatRepository()`, add it to the FE `Repository` interface, and pass `repositoryCurrency={repository.currency}` (multi-currency correctness; today a no-op).
2. `PayExpenseDialog.tsx:93` — route the read-only expense total through `formatCurrency(expense.total, expense.currency)` (localization polish).
3. Adjust dialog 4-decimal rejection surfaces the browser-native English `step` bubble before RHF's localized message — consider `noValidate` on the form or aligning the two so the localized error shows.
4. Wave A AC #4 (tolerance-account-missing 422 rendered live) never triggered live — demo tenant chart has 658/758; disclosed by Codex, unit-covered. Exercise once on a bare test tenant when convenient.
5. Pre-existing (origin/dev debt, untouched): `RepositoryDetailPage.tsx` `parseFloat` at old line ~309/318.

## Deploy

Nothing owed: FE-only over shipped backends; permissions `treasury.adjust`/`expenses.pay` already seeded + granted (verified against `RolesAndPermissionsSeeder.php` at admin/manager/accountant); no migrations, no new queues, no seeder steps.
