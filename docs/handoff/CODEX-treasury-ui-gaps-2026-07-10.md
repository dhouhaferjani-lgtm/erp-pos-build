# CODEX HANDOVER — Treasury UI Gaps (Repository Adjustment + Expense Pay)

> **Date:** 2026-07-10 · **AUTONOMOUS-GATES REVISION 2026-07-12** (owner order): gates run INSIDE this workflow via `claude -p` — no human wait; see "Autonomous audit gates" below, which SUPERSEDES the "Hard-stop gates" section at the bottom. **Runner:** Codex desktop (CLI-brokered Codex cannot write to `apps/erp.*` worktrees). Do NOT merge to dev or push yourself — when Gate 2 closes, leave the worktree and report; the Claude session runs the final review and owns the merge.
> **Track:** Track 1 of a parallel work plan. Both backends are ALREADY SHIPPED, verified, and merged to `dev` (Treasury spine, `feat/treasury-spine` history). This brief is FRONTEND-ONLY: two missing UI surfaces over existing, working endpoints. Do not touch `apps/api/**` except tests you add for FE-adjacent contract checks — there should be none needed.
> **⚠️ STALENESS (2026-07-12):** origin/dev has moved a lot since this brief was written — the design-system unification sweep AND treasury Phase ② (instrument portfolio) are both merged. Consequences: (a) `RepositoryDetailPage.tsx` was restyled by the sweep and already has a Movements tab — RE-VERIFY every line anchor in this brief before editing (cited line numbers are hints, not gospel); (b) POST-SWEEP conventions are mandatory: canonical `Input/Select/Textarea/Button` atoms, `PageHeader` pattern, design tokens only, and `node apps/web/tools/audit-design-system.mjs` must report **0 new** at every gate; (c) base the worktree on CURRENT origin/dev (`git fetch origin dev` first).

## Autonomous audit gates (SUPERSEDES "Hard-stop gates" below)

At each of the two gates (end of Wave A, end of Wave B) you do NOT wait for a human:

1. Commit everything, run the §2 verification commands, tag `tug-gate-<N>-rc<attempt>`.
2. Run from the worktree root (**Opus is the standard reviewer**):
   `claude -p --model claude-opus-4-8 "ADVERSARIAL GATE REVIEW, Treasury UI Gaps, GATE <N>. Review ONLY the diff git diff <prev-tag-or-origin/dev>..HEAD against docs/handoff/CODEX-treasury-ui-gaps-2026-07-10.md §1 ground rules + Wave <A|B> acceptance criteria. Verify with file:line citations; hunt: error-envelope mishandling (the two 422 shapes), double-unwrap, parseFloat/Number on money, missing tenantScopedKey, hardcoded colors, missing i18n en+fr, permission-gating gaps, design-audit regressions. Write the review to docs/handoff/gate-reviews-tug/GATE-<N>-rc<attempt>.md ending 'VERDICT: APPROVE' or 'VERDICT: CHANGES-REQUIRED' with numbered severity findings."`
3. **Escalation (owner tiering):** re-run the same prompt with `--model claude-fable-5` ONLY on a BLOCKER/HIGH touching a money-adjacent contract (wrong adjustment/expense-pay payloads, amount handling, invalidation that could show stale balances). This track is FE-only — escalation should be rare.
4. CHANGES-REQUIRED → fix test-first, bump rc, re-run until APPROVE. 3 consecutive rc failures on the same BLOCKER → STOP and report.
5. APPROVE → tag `tug-gate-<N>`, log verdict + review path in `docs/handoff/treasury-ui-gaps-progress.md`, continue. After Gate 2: leave the worktree intact and report done.

---

## 0. Mission

Two waves, in order, each its own commit series. Wave A = repository balance adjustment dialog. Wave B = expense pay/settle action. A HARD STOP after each wave for owner + Claude-session gate review of the worktree diff before continuing.

## 1. Ground rules (non-negotiable — from CLAUDE.md, violations block merge)

1. Worktree off **origin/dev**, not local dev: `git worktree add ../erp.treasury-ui -b feat/treasury-ui-gaps origin/dev`. Never commit to shared `dev` directly; never push to `dev`.
2. TDD: failing Vitest test first for every component/hook. Run vitest **by path**, never the full suite unattended (hung worker pools OOM the machine — if a run hangs, `ps aux | grep 'node (vitest'` and kill workers). Component tests may `vi.mock` hooks/providers (e.g. `usePermissions`, `useAuthStore`) — do not mock axios with fake payloads for API-client tests.
3. TypeScript strict, no `any`. No new backend code — the endpoints exist and are final for this brief.
4. **Rule 14 (API responses):** `apiGet`/`apiPost`/etc. already unwrap `response.data.data` — never double-unwrap. This repo's endpoints in scope return `{message, data}` on success (201/200) — use `api.post`/`api.get` + read `.data.data` yourself (mirror `expenseApi.ts`'s `post()`/existing hooks), OR `apiPost` if you only need the unwrapped payload. Follow the exact pattern already used by `usePostExpense`/`expenseApi.post` for consistency.
5. **Rule 19 (money):** amounts are canonical decimal strings. Use `<MoneyInput>` (`@/components/atoms/MoneyInput`) for the adjustment amount — never `parseFloat`/`Number()` on it. `direction` is a plain enum select, not money.
6. **Rule 18 (design tokens):** any Tailwind color class you touch/add must come from `@/lib/designTokens` (`tokens`, `textColors`, `borderColors`) — zero new hardcoded colors. Both files you're editing (`RepositoryDetailPage.tsx`, `ExpenseDetailPage.tsx`) already import these; keep using them.
7. **Tenant query keys:** every tenant-data `useQuery`/`useMutation` invalidation MUST use `tenantScopedKey([...])`. `apps/web/tools/audit-tanstack-keys.mjs` enforces this in lint/preflight/CI.
8. **i18n:** all user-facing text via `t()`. New keys go in BOTH `apps/web/src/locales/en/treasury.json` + `fr/treasury.json` (Wave A) and `en/expenses.json` + `fr/expenses.json` (Wave B). No hardcoded strings.
9. **Permission gating, both layers:** backend routes are ALREADY gated (`can:treasury.adjust`, `can:expenses.pay` — confirmed present in `RolesAndPermissionsSeeder.php`, already granted to the relevant roles). Your job is FE gating only: `usePermissions().hasPermission('treasury.adjust')` / `hasPermission('expenses.pay')`, mirroring the existing `hasPermission('expenses.post')` pattern in `ExpenseDetailPage.tsx:139`.
10. Before declaring either wave done: `pnpm typecheck`, `pnpm lint` (scoped to touched files is fine to run first, but lint must be clean repo-wide before the gate — run full `pnpm lint` once), targeted `pnpm vitest run <paths>`.
11. **No new components when a canonical one exists.** Modal → `@/components/organisms/Modal` (`Modal`, `ModalHeader`, `ModalContent`, `ModalFooter`) — template: `apps/web/src/features/treasury/components/AddPaymentMethodModal.tsx`. Form → `react-hook-form` + `FormField` (`@/components/atoms/FormField`) — same template.

## 2. Verification commands (run at both gates)

```bash
cd apps/web
pnpm typecheck
pnpm lint
pnpm vitest run src/features/treasury/**/*.test.tsx src/features/treasury/**/*.test.ts
pnpm vitest run src/features/expenses/**/*.test.tsx src/features/expenses/**/*.test.ts
```

Live verification (Playwright MCP or manual against the local stack — see `docs/handoff/RESUME-2026-07-08.md` for container bring-up, demo tenant `owner@pharmabio.tn`): drive the actual flow, not just green tests, per repo rule 5.

---

## Wave A — Repository balance adjustment UI

**Backend contract (read before writing any FE code — do not assume, verify against these files):**
- `POST /api/v1/payment-repositories/{id}/adjustments` → `RepositoryAdjustmentController::store` (`apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryAdjustmentController.php`)
- Validation: `AdjustRepositoryRequest` (`apps/api/app/Modules/Treasury/Presentation/Requests/AdjustRepositoryRequest.php`) — fields are:
  - `direction`: enum `MovementDirection` → values `in` / `out`
  - `amount`: string, `gt:0`, regex `^\d+(\.\d{1,3})?$` (3dp, **no negative sign** — direction carries the sign, not the amount)
  - `reason_code`: enum `MovementReasonCode` → **FOUR** values: `count_variance`, `correction`, `theft_loss`, `other` (not just the two named in the kickoff — build the select with all four; label them via i18n)
  - `reason_text`: **string, required, max 1000** — ⚠️ the field name is `reason_text`, NOT `notes`. Bind your form field to `reason_text`.
- Route middleware: `can:treasury.adjust` (`apps/api/app/Modules/Treasury/Presentation/routes.php:86-88`) — already live.
- Success (201): `{ message, data: { movement_id, balance_after, ordinal, idempotent_replay } }`.
- **Two distinct 422 failure shapes you must handle** (see §3 below, "contract ambiguity" — read it before building error handling):
  1. Standard Laravel FormRequest validation failure (e.g. bad `amount` regex) → `{ message, errors: { field: [...] } }`.
  2. Domain guard failure — two sub-shapes: repository has no linked GL account → `DomainException` caught by the GLOBAL handler (`apps/api/bootstrap/app.php:344`) → canonical `{error:{code:'BUSINESS_ERROR',message}}` 422 (getErrorMessage handles it); missing 658/758 tolerance account → caught explicitly in the controller, returns `{ error: "<translated string>" }`, a **flat string**. Your error extractor must handle BOTH (flat string first, then fall back to getErrorMessage). Do NOT write tests asserting a raw/default exception envelope — it never occurs.

**A1. `useAdjustRepositoryBalance` mutation hook** (new file, `apps/web/src/features/treasury/hooks/useAdjustRepositoryBalance.ts` — TDD, test first):
- `mutationFn`: `POST /payment-repositories/{repositoryId}/adjustments` with `{ direction, amount, reason_code, reason_text }`.
- `onSuccess`: invalidate `tenantScopedKey(['payment-repository', repositoryId])`, `tenantScopedKey(['payment-repository-transactions', repositoryId])`, `tenantScopedKey(['repository-movements', repositoryId, ...])` (movements hook takes a `filters` object in its key — invalidate by **key prefix** `tenantScopedKey(['repository-movements', repositoryId])`, TanStack matches by prefix, don't try to reconstruct the exact filters object), and `tenantScopedKey(['treasury-cash-position'])` (cash position aggregates across all repositories — the kickoff's "cash position queries" instruction, confirmed key name in `useCashPosition.ts:48`).
- Error handling: do NOT rely solely on the generic `getErrorMessage()` helper (`@/lib/api.ts`) without checking — it assumes `error.error.message` (the canonical `ApiError` shape); this endpoint's tolerance-account 422 returns `error` as a flat string, so `data.error.message` resolves to `undefined` at runtime. Write a small local extractor: if `error.response.data.error` is a string, use it directly; else fall back to `getErrorMessage(error)`; else the generic i18n fallback. Surface via `toast.error(...)`.

**A2. `AdjustBalanceDialog` component** (new file, `apps/web/src/features/treasury/components/AdjustBalanceDialog.tsx` — TDD):
- Built on `Modal`/`ModalHeader`/`ModalContent`/`ModalFooter` + `react-hook-form`, template = `AddPaymentMethodModal.tsx`.
- Fields: direction (`Select`, in/out — label per i18n, e.g. "Cash over (in)" / "Cash short (out)"), amount (`MoneyInput`, `currency={repository.currency}` — confirm the `Repository` interface in `RepositoryDetailPage.tsx` currently has no `currency` field; extend the local interface with `currency: string` — it exists on the backend model per `RepositoryAdjustmentController.php:120,129`; verify via `GET /payment-repositories/{id}` response before assuming), reason_code (`Select`, all 4 enum values), reason_text (`Textarea`, required, max 1000 — client-side maxLength mirrors server).
- Client validation ceiling on amount: reuse the same regex rule (`gt:0`, 3dp) via zod or RHF `pattern`, not just `required`.
- Props: `isOpen`, `onClose`, `repositoryId`, `repositoryCurrency`, `onSuccess?`.

**A3. Wire into `RepositoryDetailPage.tsx`:**
- Add an "Adjust balance" `Button` in the `PageHeader` `actions` block (near the balance display), gated `hasPermission('treasury.adjust')` (import `usePermissions` — not currently imported in this file, add it).
- Opens `AdjustBalanceDialog`; on success, toast + dialog closes (invalidation already handled by the hook).
- The Movements tab (`RepositoryMovementsTab.tsx`) already renders `adjustment` as a `source_type` option and has no dedicated detail route for it (`sourceDocHref` returns `null` for `adjustment` → falls back to raw id + copy button) — this is EXISTING, working behavior. Do not build a detail route for adjustments; out of scope.

**A4. i18n:** add keys under `treasury:repositories.adjustBalance.*` (dialog title, field labels, reason code labels for all 4 values, action button label, success toast, generic error fallback) in both `en/treasury.json` and `fr/treasury.json`.

**Acceptance criteria (Playwright-verifiable):**
- Navigate to a repository detail page as a user with `treasury.adjust` → "Adjust balance" button visible; without the permission → button absent.
- Open dialog, submit `direction=in`, valid amount, `reason_code=count_variance`, reason text → toast success, dialog closes, header balance updates, Movements tab (revisit or refetch) shows a new `adjustment` row with the correct signed amount and a journal entry link (not "no journal entry" — the backend always posts GL).
- Submit an amount with 4 decimal places → client-side validation blocks submit before the request fires.
- Trigger the tolerance-account-missing 422 (a company whose chart lacks 658/758 — check demo tenant's seeded chart, or use a test tenant) → the flat-string error message renders verbatim in the toast (not "undefined", not `[object Object]`).

---

## Wave B — Expense pay/settle UI

**Backend contract (read before writing any FE code):**
- `POST /api/v1/expenses/{id}/pay` → `ExpenseController::pay` (`apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:253-288`), calling `ExpenseService::settle()`.
- Validation: `PayExpenseRequest` (`apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php`) — fields are:
  - `payment_repository_id`: **required**, uuid, must exist scoped to tenant+company
  - `payment_method_id`: **nullable**, uuid, scoped
  - `payment_date`: **required**, date
  - ⚠️ **There is NO `amount` field.** The kickoff brief assumed one — it does not exist. `PayExpenseRequest`'s own docblock says so explicitly: "No money field here (the amount settled is the expense total, not user input)". The full expense total is always settled; do not add a MoneyInput for amount, there is nothing to bind it to.
- Route middleware: `can:expenses.pay` (`apps/api/app/Modules/Expense/routes.php`) — already live.
- Eligibility (enforced server-side in `ExpenseService::settle`, `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:290+`) — gate the FE button on ALL of:
  - `expense.status === 'posted'` (thrown `DomainException` "Only a posted expense can be settled" otherwise)
  - `expense.metadata.is_paid === false` (already-paid → 422 "This expense has already been paid")
  - `expense.metadata.expense_kind !== 'linked_cost'` (linked-cost expenses settle at capitalization, not via `/pay` → 422 with an explanatory message) — the FE `Expense` type already exposes `expense_kind` (`features/expenses/types/index.ts:40`) and `is_paid` (`:36`).
- Success (200): `{ message, data: <ExpenseResource> }` (same shape as `post()` — `is_paid` becomes `true`, `metadata.payment_date`/`payment_repository`/`payment_method` populate).
- 422 domain failures: same **flat-string `{ error: "..." }`** shape as Wave A (`ExpenseController::pay` catches `\DomainException` and returns `response()->json(['error' => $exception->getMessage()], 422)` directly — apply the same defensive error extraction as A1, do not assume `getErrorMessage()` alone is safe here either).

**B1. `expenseApi.pay()`** (add to `apps/web/src/features/expenses/api/expenseApi.ts`, mirroring `expenseApi.post()`):
```ts
pay: async (id: string, data: { payment_repository_id: string; payment_method_id?: string | null; payment_date: string }): Promise<Expense> => {
  const { data: response } = await api.post<ExpenseResponse>(`/expenses/${id}/pay`, data)
  return response.data
},
```

**B2. `usePayExpense` mutation hook** (add to `apps/web/src/features/expenses/hooks/useExpenses.ts`, mirror `usePostExpense` exactly — same invalidation predicate `expensesInvalidationPredicate` + detail-key invalidation; same toast pattern). Additionally invalidate `tenantScopedKey(['payment-repository', paymentRepositoryId])` and `tenantScopedKey(['treasury-cash-position'])` — settling an expense moves cash out of the chosen repository, so that repository's detail page and the cash position widget go stale too. TDD first.

**B3. `PayExpenseDialog` component** (new file, `apps/web/src/features/expenses/components/PayExpenseDialog.tsx` — TDD, template = `AddPaymentMethodModal.tsx` for the Modal/RHF shape):
- Fields: `payment_repository_id` (`Select`, populated from `useActivePaymentRepositories()` — `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:45`), `payment_method_id` (`Select`, optional — populated from an existing payment-methods hook, e.g. `usePaymentMethods` if one exists in `features/treasury/hooks`, else fetch via existing pattern — check before writing a new one), `payment_date` (native date input, default = today).
- Show the expense total (read-only, `expense.total` + `expense.currency` — NOT an editable MoneyInput, it's informational only per the "no amount field" contract above).
- Props: `isOpen`, `onClose`, `expense: Expense`, `onSuccess?`.

**B4. Wire into `ExpenseDetailPage.tsx`:**
- Add a "Pay" `Button` next to the existing Post action in the `PageHeader` `actions` block, visible when `isPosted && !expense.metadata?.is_paid && expense.metadata?.expense_kind !== 'linked_cost' && hasPermission('expenses.pay')` (mirror the existing `isDraft && hasPermission('expenses.update')` pattern already in this file at line 121).
- Opens `PayExpenseDialog`; on success, toast + the existing "paid" `StatusBadge` (already rendered conditionally at line 244-248 on `expense.metadata?.is_paid`) appears without a page reload (mutation invalidation handles it).

**B5. i18n:** add keys under `expenses:pay.*` (dialog title, field labels, action button label "Pay", success toast, generic error fallback) in both `en/expenses.json` and `fr/expenses.json`. `expenses:paid` badge label already exists (line 246) — reuse it, don't duplicate.

**Acceptance criteria (Playwright-verifiable):**
- Posted, unpaid, non-linked-cost expense as a user with `expenses.pay` → "Pay" button visible; toggle any one gating condition false (draft status, already paid, linked-cost kind, missing permission) → button absent. Verify at least the permission case and the `is_paid` case live.
- Submit valid repository + date → toast success, "Paid" badge appears, Payment Details section shows the chosen repository/method/date without navigating away.
- Attempt to pay an already-paid expense (e.g. resubmit before invalidation lands, or via a second browser tab) → flat-string 422 error renders verbatim in the toast.
- Repository detail page for the chosen repository, if visited after payment, reflects the reduced balance (confirms the cross-feature invalidation in B2 actually works — this is the one most likely to be silently skipped, verify it explicitly).

---

## Hard-stop gates

**GATE 1 — after Wave A.** Stop. Do not start Wave B. Leave the worktree as-is (uncommitted work committed, not pushed). Notify the owner. The Claude session reviews the full Wave A diff (`git diff origin/dev...HEAD` scoped to Wave A commits) against §1 ground rules + Wave A acceptance criteria before authorizing Wave B.

**GATE 2 — after Wave B.** Stop. Same protocol: full diff review of both waves, verification command output re-run and confirmed, before any merge/push decision. Codex does not merge to `dev` or push under any circumstance — that decision belongs to the owner/Claude session.

## Out of scope (do NOT touch)

Backend Treasury/Expense modules (`apps/api/app/Modules/{Treasury,Expense}/**`) beyond reading them for contract verification; the `RolesAndPermissionsSeeder` (permissions already granted); POS/Tauri app; any other treasury pages (`PaymentDetailPage`, `InstrumentDetailPage`, `BankReconciliationPage`, etc.) beyond the two named files; `packages/shared/types` by hand; the canonical `ApiError`/`getErrorMessage` shared utility in `@/lib/api.ts` — work around its blind spot locally per §Wave A/B error-handling notes, do not "fix" it globally as a side quest (that's a separate, larger contract-consistency fix affecting every 422 in the app).
