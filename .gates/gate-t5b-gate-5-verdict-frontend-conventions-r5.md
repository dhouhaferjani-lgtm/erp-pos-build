I could not re-run the guardrails — `pnpm` execution is denied in this session, and a delegated agent hit the same block. That is recorded as a finding below rather than accepted on trust.

---

GATE VERDICT: REJECT

## BLOCKER

**1. `LinePanel` mutation controls are not gated by statement mutability or reconcile permission — a locked statement renders live mutation actions.**
`apps/web/src/features/treasury/statements/ReconciliationWorkspacePage.tsx:132` passes only `pending` to `LinePanel`; `mutable` (line 77, which folds in `status !== 'reconciled' && status !== 'voided' && hasPermission('bank-statements.reconcile')`) is never threaded down. Consequences inside `LinePanel.tsx`:

- `LinePanel.tsx:82` — the **Remove** (unallocate) button is `disabled={pending}` only. On the reconciled statement in `docs/sessions/treasury-phase5b-e2e/04-reconciled-checkpoint.png`, every matched line has allocations, so this button is rendered enabled. Clicking calls `unallocateStatementLine` (`api.ts:246`), which the backend rejects at `StatementMatchingService.php` `lockMutableAggregate` ("A reconciled statement cannot be changed").
- `LinePanel.tsx:78` — **Restore line** (unignore) is `disabled={pending}` only, so the ignored line on a reconciled statement offers an unignore action that cannot succeed.
- `LinePanel.tsx:84,86` — the ignore fieldset submit and **Create expense/income from line** are likewise gated only on `pending`. A user holding `bank-statements.view` without `bank-statements.reconcile` (distinct entries at `usePermissions.ts:91,93`, both `SERVER_AUTHORITATIVE`) gets the complete mutating panel on any `reconciling` statement.
- `LinePanel.tsx:79` → `SuggestionList.tsx:24` — because `suggestionsQuery` is disabled when `!mutable` (`ReconciliationWorkspacePage.tsx:82`), a locked/read-only statement renders **"No automatic suggestion for this line."** That is an affirmative factual claim about matching, emitted when no suggestion request was ever made.

This fails the gate's own criterion that status/state presentation must be honest, and it undoes on the presentation layer what the route guard (`routes/index.tsx:1827,1838`) establishes. Fix: thread `mutable` into `LinePanel` and gate rendering (not just `disabled`) of unallocate, ignore/unignore, create-from-line, `ManualMatchSearch`, and the suggestions empty-state; add a render test for a `reconciled` statement and for a view-only user.

## MAJOR

**2. `playwright.smoke.config.ts` was replaced with a spread of the local dev config, silently repointing every pre-existing smoke test off staging.**
`apps/web/playwright.smoke.config.ts:1-6` now re-exports `./playwright.config` with only `testMatch` overridden. The previous config (deleted in this diff) set `baseURL: process.env['STAGING_URL'] || 'https://erp.otospex.dev'`, `workers: 1`, `fullyParallel: false`, `retries: 1`, and had **no** `webServer`. The inherited config (`playwright.config.ts:12,5,17-24`) supplies `baseURL: 'http://localhost:5173'`, `fullyParallel: true`, `workers: undefined`, and a `webServer` that boots `pnpm dev`.

Impact beyond this wave's scope (CLAUDE.md rule 4): `auth.smoke.ts`, `product.smoke.ts`, `settings.smoke.ts`, `treasury-spine.smoke.ts`, and `treasury-phase5a-outbound.smoke.ts` no longer honour `STAGING_URL` and now run fully parallel against a single backend while mutating shared treasury state (repositories, instruments, `last_reconciled_at`). The 5b run does not exercise this because it ran as a single file. `REPORT.md:46` further notes the run required hand-editing the Vite proxy and setting `QUEUE_CONNECTION=sync`, neither of which is captured in the committed config — the "live smoke" is not reproducible from the repo as committed.

**3. No claimed guardrail evidence could be independently reproduced in this session.**
The reviewer protocol requires re-running `lint`, `typecheck`, and targeted Vitest and trusting nothing reported. All `pnpm` invocations were denied by the sandbox, directly and via a delegated agent. Therefore the following remain **unverified assertions, not gate evidence**: typecheck pass; `pnpm lint` exit 0 with 0 errors; "8 files / 32 tests passed"; React Doctor 92/100; and the live smoke result. Note also that the request states 26.2s while the committed `REPORT.md:13` states 28.8s for the same six steps — these are two different runs, and only the second is in the repo.

## MINOR

**4. Claimed "page-level completion-control coverage" is a pure-function test.** `ReconciliationWorkspacePage.test.tsx:1-78` imports only the exported helper `getWorkspaceCompletionState` and never renders the page. The page's actual rendered behaviour — completion CTA visibility, the reopen button at `ReconciliationWorkspacePage.tsx:115`, the `ignoredRequiresAdmin` banner at `:118`, and the `LinePanel` wiring at `:132` — has zero render coverage. This is the exact seam that hides Finding 1.

**5. Statement dates are rendered as raw ISO strings, not localized.** `ReconciliationWorkspacePage.tsx:115` (`${statement.period_start} → ${statement.period_end}`), `:129`, `StatementListPage.tsx:64`, `LinePanel.tsx:74`, `ManualMatchSearch.tsx:42` (`occurred_at.slice(0, 10)`). Visible as `2026-07-20 → 2026-07-20` in `04-reconciled-checkpoint.png`. FR/AR users get ISO dates.

**6. Count/label concatenation bypasses i18n interpolation.** `LinePanel.tsx:89` renders `{execution.produced_repository_movement_ids.length} {t('statements.workspace.movementsProduced')}`, with the EN value literally `"ledger movement(s) produced"`. Word order is hardcoded and pluralization is faked; use `t(key, { count })`.

**7. Creating a parser profile never invalidates the cached profile list.** `StatementUploadWizard.tsx:111` appends to local `createdProfiles` state only; the `tenantScopedKey(['statement-import-profiles'])` query at `StatementListPage.tsx:51` is not invalidated, and that namespace is absent from `queryScope.ts:1-8`. Reopening the modal in the same session can show a stale list.

**8. `MoneyInput` receives `min > max` when a line is fully allocated.** `ManualMatchSearch.tsx:35,44` computes `minimumAmount` as one currency ulp while `maxAmount` is `0.000` once `lineRemaining` is zero; only the Allocate button is disabled (`:45`), the input stays editable with contradictory bounds. The `disabled` prop is also not applied to the search `Input`, movement `Select`, or `MoneyInput`.

**9. `t('common:loading')` used under a single-namespace `useTranslation('treasury')`.** `CreateFromLineDialog.tsx:56`. It resolves today only because all namespaces are bundled at init; declare `['treasury', 'common']` as the workspace page does (`ReconciliationWorkspacePage.tsx:50`).

**10. `MODULE_PERMISSIONS` gains a self-referential entry keyed by a permission name.** `usePermissions.ts:272` adds `'bank-statements.view': ['bank-statements.view']` so the sidebar's module-key lookup resolves. It works, but it conflates the module namespace with the permission namespace in a map whose other keys are all module identifiers.

## Verified clean

- Canonical atoms/molecules throughout: `PageHeader`, `ListPageLayout`, `DataTable`, `Modal`/`ModalContent`/`ModalFooter`, `Input`/`Select`/`Textarea`/`Checkbox`/`MoneyInput`/`Button`/`StatusBadge`/`FormField`, `OffsetPagination`, `EmptyState`. No raw `<table>`, no raw form controls. RHF + zod with a translated message at `CreateFromLineDialog.tsx:30`.
- Design tokens only; grep for raw palette utilities (`bg-blue`, `text-gray`, `border-red`, …) across `statements/` returns nothing. No composed/interpolated Tailwind classes. Logical properties used consistently (`ms-`/`me-`/`ps-`/`start-`/`text-start`/`text-end`).
- Money is decimal strings end to end. Only `Big` arithmetic (`status.ts:10-24`); no `parseFloat`/`Number`/`toFixed` on money outside `Big.prototype.toFixed`. The FE signed-allocation rule (`status.ts:14-18`) matches the backend exactly (`StatementMatchingService.php:247-250`).
- Every tenant query uses `tenantScopedKey`; `queryScope.ts:15-21` requires `at(-2) === tenantId && at(-1) === companyId`, so `refreshWorkspace` cannot cross company scope. `usePaymentRepositories` and `useAccounts` are scoped too.
- `resolved_by_creation` is presented as successful (`status.ts:30-32`) and genuinely carries full allocations (`StatementLineMatchStatus.php:35` reaches it only at `allocationTotal == lineAmount`), so remaining is truly zero. Ignored lines contribute zero remaining (`status.ts:11`). `canComplete` requires all lines resolved (`ReconciliationWorkspacePage.tsx:44`). Ignored-total acknowledgment behind `bank-statements.reopen` matches the plan's Task 8 wording.
- EN/FR/AR `statements.*` subtrees are structurally identical and genuinely translated; no untranslated EN carry-over. Removed `common.reconciliation` / `treasury.reconciliation` keys have no remaining referents.
- Design-system baseline change is honest: exactly one removal, corresponding to the deleted `BankReconciliationPage.tsx`; zero additions. No alias/suppression evasion.
- Legacy cutover is complete with real assertions — `routes.test.tsx:67-85`, `Sidebar.test.tsx:313-327`, `FinanceHubPage.test.tsx:68-85`. No dead imports of `BankReconciliationPage`, `api/reconciliation`, or `hooks/useReconciliation` remain (the surviving `useReconciliation` hits are inventory-counting's unrelated hook).
- Upload wizard state cannot go stale: `Modal.tsx:136` returns `null` when closed, unmounting the wizard; `changeRepository` (`StatementUploadWizard.tsx:79-93`) additionally resets file, profile, preview, period, balances, mapping, and the empty-gate acknowledgment.
- The smoke test contains no `page.route`/`fulfill`/`abort` — no network mocking — and asserts real rendered state (`5/5` at `:769`, `Reconciled` at `:774`, `Created and matched` at `:749`, `Expense created` provenance at `:752`) plus real server state (`:785-808`). Screenshots corroborate the report.

VERDICT: REJECT

Before the ⑤b exit review, thread statement mutability and `bank-statements.reconcile` into `LinePanel` so unallocate/unignore/ignore/create and the suggestions empty-state cannot render as available on a reconciled statement or for a view-only user (with a page-render test proving it), restore `playwright.smoke.config.ts` to a staging-targeted, serialized config so the other smoke suites keep their contract, and re-run `pnpm lint`, `pnpm typecheck`, and the targeted Vitest in a session where they can actually execute.
