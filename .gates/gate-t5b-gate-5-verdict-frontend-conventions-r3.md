GATE VERDICT: REJECT

## BLOCKER

None.

## MAJOR

**M1 — New code smuggles a raw `<table>` through DataTable's legacy passthrough, making the debt invisible to the C5 audit**
`apps/web/src/features/treasury/statements/StatementUploadWizard.tsx:246`

The preview table calls `<DataTable className="min-w-full text-sm">` with hand-rolled `<thead>/<tr>/<th>/<td>` children rather than the canonical `columns`/`data` API used correctly two files over (`StatementListPage.tsx:60-88`). That resolves to the overload documented at `apps/web/src/components/molecules/DataTable/DataTable.tsx:80-89` as:

> *"Legacy markup passthrough for swept pages that already own table semantics. **Prefer the typed column/data API for new DataTable call sites.**"*

and it renders a bare `<table className={className} {...restTableProps}>` at `DataTable.tsx:119-124`.

The consequence is not cosmetic. The C5 detector is a source regex — `table: /<table\b(?:=>|[^>])*>/gs` (`apps/web/tools/audit-design-system.mjs:39`, message at `:304`). Because the source text reads `<DataTable`, the detector never fires, so this new raw table produces **no** audit finding and **no** baseline entry. The baseline diff is a net −1 with zero additions, and the surviving treasury C5 entries (`tools/audit-design-system-baseline.json:752-753`) are the two pre-existing `RepositoryDetailPage`/`RepositoryMovementsTab` tables. The removed entry corresponds honestly to the deleted `BankReconciliationPage.tsx` — but a clean audit that is partly clean by indirection is the reviewer protocol §3 mechanism-audit condition.

*Fix:* convert the preview table to the typed `columns`/`data` DataTable API, or add an explicitly acknowledged baseline entry. Do not leave it undetectable.

**M2 — Every guardrail in the evidence block is unverified; I was unable to re-run any of them**

The protocol requires running the guardrails myself and trusting nothing reported. In this environment all execution was refused: `pnpm typecheck`, `pnpm lint`, `node tools/audit-design-system.mjs`, `node tools/audit-tanstack-keys.mjs`, and targeted `vitest` were each declined by the sandbox. So the claims *"typecheck: pass"*, *"lint exit 0 / 0 errors"*, *"8 files / 32 tests passed"*, and *"live smoke 6/6 in 26.2s"* carry no independent confirmation from this gate.

This matters more than usual here: M1 demonstrates that a clean `audit:design-system` result is achievable while new raw-table debt exists, so "lint exit 0" does not establish what it is being offered to establish. Given the two documented false-green incidents in this repo's history, an exit gate should not close on unverified counts.

*Fix:* re-run the four guardrails in an environment where the reviewer can execute them, and attach raw output — not summaries.

## MINOR

**m1 — Untranslatable count concatenation.** `LinePanel.tsx:89` renders `{execution.produced_repository_movement_ids.length} {t('statements.workspace.movementsProduced')}`, whose EN value is `"ledger movement(s) produced"` (`src/locales/en/treasury.json:722`). The `(s)` hack plus fixed word order does not survive Arabic's plural categories (`ar/treasury.json:111`) or French agreement. Use `t(key, { count })` with proper plural forms.

**m2 — Provenance link demands a write permission.** `LinePanel.tsx:44` maps `income_document` to `/income/${targetId}/edit`, which is gated on `income.update` (`src/routes/index.tsx:1696-1698`), whereas the expense branch (`LinePanel.tsx:43`) targets `/expenses/:id/view` gated on `expenses.view` (`src/routes/index.tsx:1654`). A reconciler holding `bank-statements.reconcile` and read-only income access hits a permission wall from "Open source record".

**m3 — Evidence mischaracterisation.** The React Doctor `StatementUploadWizard` useReducer warning is reported as "pre-existing". That file is introduced by this diff and carries 22 `useState` calls. It is new, not inherited.

**m4 — Profile query not in the refresh scope.** `queryScope.ts:1-8` omits `statement-import-profiles`, and `createStatementProfile` (`StatementUploadWizard.tsx:111`) only pushes into local `createdProfiles` state without invalidating the server query. Benign today because the query is `enabled: showUpload` and refetches on reopen, but it is an inconsistency in an otherwise complete namespace set.

**m5 — Smoke does not assert zero remaining in the browser.** The live spec asserts progress `5/5` (`e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:769`) but never asserts the rendered "Amount remaining" reads `0.000` after the informational line is ignored. The invariant is covered at unit level (`ReconciliationWorkspacePage.test.tsx:51-61`, `status.test.ts:14-21`), so this is a coverage gap in the live tier only.

## Verified clean (no finding)

- **Tenant scoping is correct and non-crossing.** Every query uses `tenantScopedKey` (`StatementListPage.tsx:46,51`; `ReconciliationWorkspacePage.tsx:64,80,85`; `StatementReconciliationChips.tsx:26`), each gated on `tenantId !== null && companyId !== null`. The invalidation predicate (`queryScope.ts:10-21`) anchors on `at(-2)`/`at(-1)`, which exactly matches `tenantScopedKey`'s documented suffix contract (`src/lib/tenantScopedKey.ts`). A company switch cannot reuse statement, suggestion, movement, profile, or repository data.
- **i18n EN/FR/AR parity is complete** across the whole `statements` block, including all four reason codes actually emitted by `StatementSuggestionService.php:87,112,380,425`, plus `matchType`, `sourceType`, `actions`, and ignore reasons. No dynamic key can fall through to a raw key string.
- **Money discipline holds.** Decimal strings end-to-end, `big.js` for display arithmetic only, `MoneyInput` with currency-derived `min`/`max` (`ManualMatchSearch.tsx:34-44`), `formatAtCurrencyScale` at the explicit currency scale; no `Number`/`parseFloat` on money anywhere in the new directory.
- **RTL is fully logical** — grep for `text-left|text-right|ml-|mr-|pl-|pr-|left-|right-` across `features/treasury/statements/` returns nothing; the code uses `me-/ms-/ps-/start-/text-start/text-end`.
- **Legacy cutover is genuinely complete.** No surviving reference to `BankReconciliationPage`, the old treasury `useReconciliation`, `api/reconciliation`, or the removed reconciliation types; `Sidebar.tsx:276`, `FinanceHubPage.tsx:75`, and `routes/index.tsx:81-82,1827-1840` all point at `/treasury/statements`, and `routes.test.tsx:69` guards the regression.
- **The live smoke is honest.** No `route()`/`fulfill()` interception, serial mode, 38 role/label selectors against 3 `nth/first`, and real server-state assertions for instrument status, fee movement amount, checkpoint balance semantics, and 422 rejection of post-reconciliation writes (`smoke.ts:714,734-736,806-808,822-843`).
- **Status honesty is correct in code.** `resolved_by_creation` counts as successful (`status.ts:30-32`), ignored lines contribute zero remaining (`status.ts:11`), and completion requires every line resolved (`ReconciliationWorkspacePage.tsx:44`) — all four branches unit-covered.

VERDICT: REJECT

Before the ⑤b exit review: convert `StatementUploadWizard.tsx:246` off DataTable's legacy markup passthrough to the typed `columns`/`data` API (or add an acknowledged baseline entry), and re-run `pnpm lint`, `pnpm typecheck`, the targeted statement Vitest, and the live smoke with raw output attached, since none of the reported evidence could be independently verified at this gate.
