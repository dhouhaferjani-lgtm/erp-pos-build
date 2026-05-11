# PR #115 — CompanyRecoveryScreen fold into BootstrapErrorScreen — Codex review trail

**Branch:** `feat/pos-bootstrap-companyrecovery-fold`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 2 rounds, APPROVE.

This PR folds the duplicated empty-company recovery screen into the bootstrap recovery surface. The bootstrap `fetching-companies` phase now owns the empty-company refresh, and `BootstrapErrorScreen` owns the cashier-facing retry/sign-out UX.

## Round 1 — P2

> The patch introduces a transient but real fall-through from empty-company bootstrap recovery into terminal setup while the company refresh is pending, causing terminal API calls without company context. This breaks the recovery behavior the change is intended to preserve.

- [P2] Block terminal setup while companies are refreshing — `apps/pos/src/App.tsx:153-154`
  - A cached authenticated session with `companies.length === 0` could render `TerminalSetupPage` while `fetching-companies` was still in flight.
  - Fix: added an AppRouter loading guard for authenticated empty-company state before terminal setup, and a regression test proving TerminalSetupPage stays unmounted while the refresh promise is pending.

## Round 2 — APPROVE

> The changes consistently move empty-company recovery into the bootstrap state machine, preserve terminal API gating while the company refresh is pending, and add coverage for the new behavior. I did not identify a discrete introduced correctness issue in the diff.

No findings. PR ready for merge.

## Final shape

- **3 commits**.
- **10 files changed**:
  - `apps/pos/src/App.tsx`
  - `apps/pos/src/__tests__/AppRouter.test.tsx`
  - `apps/pos/src/__tests__/recoveryScreenI18n.test.tsx`
  - `apps/pos/src/components/BootstrapErrorScreen.tsx`
  - `apps/pos/src/components/__tests__/BootstrapErrorScreen.test.tsx`
  - `apps/pos/src/locales/en/common.json`
  - `apps/pos/src/locales/fr/common.json`
  - `apps/pos/src/stores/bootstrapStore.ts`
  - `apps/pos/src/stores/__tests__/bootstrapStore.test.ts`
  - `docs/superpowers/reviews/2026-05-11-pos-companyrecovery-fold-codex-rounds.md`
- POS gates:
  - targeted fold tests — 46/46 pass.
  - `pnpm typecheck` — 0 errors.
  - `pnpm lint` — 0 errors / 41 warnings.
  - `pnpm test` — 1207/1207 pass across 133 files.

## Pre-flight audit

- **L1 cross-tenant audit:** Menu, standard-retail, hybrid, and non-Menu tenants share the same auth/company bootstrap path. The change does not read or mutate menu, cart, scan, payment, receipt, or tenant-specific product data.
- **L8 ownership audit:** `CompanyRecoveryScreen` no longer owns empty-company fetch/retry/sign-out. `bootstrapStore.fetching-companies` owns the refresh, `BootstrapErrorScreen` owns retry/sign-out UI, and `AppRouter` only blocks terminal setup while company context is absent.
- **L9 ingress audit:** audited bootstrap mount, bootstrap retry, AppRouter dep-change retry, `authStore.fetchCompanies()` wire boundary, company-cache reads before/after refresh, AppRouter render precedence, and i18n key lookup.
- **Recoverability decision:** `fetching-companies` remains non-recoverable, so `Use cached data` is not rendered for empty-company failures.
- **Terminal API guard:** authenticated empty-company state renders the loading surface while refresh is pending, preventing `TerminalSetupPage` from mounting without company context.
