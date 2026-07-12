# ADVERSARIAL GATE REVIEW — Treasury UI Gaps, GATE 1 (Wave A)

- **Reviewer:** Claude (Opus 4.8), autonomous gate per handoff §"Autonomous audit gates"
- **Date:** 2026-07-12
- **Scope:** Repository balance adjustment UI (Wave A), reviewed against `docs/handoff/CODEX-treasury-ui-gaps-2026-07-10.md` §1 ground rules + Wave A acceptance criteria.
- **Diff reviewed:** `git diff origin/dev...HEAD` (three-dot / merge-base). See note below on the two-dot caveat.

---

## 0. Diff-scope note (read first)

The brief's literal command is `git diff origin/dev..HEAD` (two-dot). In this worktree that command is **contaminated**: `feat/treasury-ui-gaps` is 7 commits **behind** `origin/dev` (and 1 ahead), so a two-dot diff reverse-shows all 7 dev commits (gutted `audit-tanstack-keys.mjs`, ~90 unrelated hook edits) as spurious "changes". Those are **not** Wave A work.

I reviewed the **merge-base (three-dot) diff**, which isolates the single real Wave A commit `19d6401ff` — exactly **10 files, 616 insertions, 1 deletion**:

```
RepositoryDetailPage.tsx / .test.tsx
components/AdjustBalanceDialog.tsx / .test.tsx
hooks/useAdjustRepositoryBalance.ts / __tests__/useAdjustRepositoryBalance.test.tsx
hooks/usePermissions.ts / usePermissions.treasuryReconciliation.test.ts
locales/en/treasury.json / locales/fr/treasury.json
```

No `apps/api/**` files were modified (contract-verification reads only) — brief §"Out of scope" honored. **Recommendation:** rebase this branch onto current `origin/dev` before the final merge so the two-dot diff is clean for Gate 2 / merge.

---

## 1. Ground-rule verification (all cited to file:line)

| # | Rule | Verdict | Evidence |
|---|------|---------|----------|
| 3 | TS strict, no `any` | ✅ | `useAdjustRepositoryBalance.ts:42-49` `flatErrorMessage(error: unknown)` uses `unknown` + narrowing type guards, no `any` anywhere in the diff. |
| 4 | No double-unwrap (Rule 14) | ✅ | `useAdjustRepositoryBalance.ts:51-56` uses `api.post<AdjustmentResponse>(...)` then reads `response.data.data` — correct for the `{message,data}` 201 shape, matches the brief's prescribed pattern. |
| 5 | Money = decimal strings, no parseFloat/Number (Rule 19) | ✅ | `AdjustBalanceDialog.tsx:88-108` binds `amount` to `<MoneyInput>` via RHF `Controller`, value passed straight through as a string; hook posts `amount` verbatim (`useAdjustRepositoryBalance.ts:53`). No `parseFloat`/`Number()` on money anywhere. Client ceiling regex `POSITIVE_MONEY_PATTERN = /^(?=.*[1-9])\d+(?:\.\d{1,3})?$/` (`:38`) enforces `gt:0` + 3dp, mirroring backend `AdjustRepositoryRequest`. |
| 6 | Design tokens only, zero new hardcoded colors (Rule 18) | ✅ | `grep` for `bg-/text-/border-<color>-<n>` in both new files → **no matches**. Button/Select/Textarea/Modal carry their own tokenized styling. |
| 7 | tenantScopedKey on every invalidation | ✅ | `useAdjustRepositoryBalance.ts:59-79`: `payment-repository`, `payment-repository-transactions`, `treasury-cash-position` all wrapped in `tenantScopedKey([...])`. Movements uses a **tenant-scoped predicate** (`:65-73`) matching `queryKey[0]==='repository-movements' && [1]===repositoryId && last-2===tenantId && last-1===companyId`. This is **correct and superior** to the brief's suggested prefix match: the real key is `tenantScopedKey(['repository-movements', repositoryId, filters])` = `[..., filters, tenantId, companyId]` (`useRepositoryMovements.ts:89`), so a naive `tenantScopedKey(['repository-movements', repositoryId])` prefix would NOT match (tenant/company are suffixes, not at index 2). Test `__tests__/useAdjustRepositoryBalance.test.tsx:87-92` proves it matches tenant-1 and rejects tenant-2. |
| 8 | i18n en+fr, no hardcoded strings | ✅ | Every `t()` key in the dialog/page resolves to a real entry in **both** `en/treasury.json:365-390` and `fr/treasury.json:365-390` (action, title, direction(+.in/.out), amount, reasonCode, reasons.{4 values}, reasonText, validation.{amount,reasonText,reasonTextMax}, submit, success, error). `common:actions.cancel` exists (`common.json:8`). No literal user-facing strings in JSX. |
| 9 | FE permission gating, correct roles | ✅ | `RepositoryDetailPage.tsx:308-312` gates the "Adjust balance" Button on `hasPermission('treasury.adjust')`. New PERMISSIONS entry `'treasury.adjust': ['admin','manager','accountant']` (`usePermissions.ts:68`) **exactly matches** the backend grants: `RolesAndPermissionsSeeder.php` grants it to `admin` (all perms, :444), `manager` (:474), `accountant` (:694) and **not** the `treasury` role — the implementer correctly declined to copy the broader `treasury.view` role list. No false-positive or false-negative gating gap. Backend route `can:treasury.adjust` already live per brief. |
| 11 | Canonical components only | ✅ | Modal/ModalHeader/ModalContent/ModalFooter, FormField, Select, Textarea, MoneyInput, Button, Loader2 — all canonical atoms/organisms; structure mirrors the prescribed `AddPaymentMethodModal.tsx` template. No bespoke modal/input reinvented. |

### Error-envelope handling (the two 422 shapes) — ✅ correct

`useAdjustRepositoryBalance.ts:75-78`: `flatErrorMessage(error) ?? getErrorMessage(error)`, then i18n fallback.
- **Shape 2b (flat string `{error:"..."}`, tolerance-account-missing):** `flatErrorMessage` (`:42-49`) walks `error.response.data.error` and returns it only when `typeof === 'string'` → surfaced verbatim. Directly satisfies the acceptance criterion "flat-string error renders verbatim, not `undefined`/`[object Object]`". Test `:98-112` locks this and asserts `getErrorMessage` is **not** consulted first.
- **Shape 2a (canonical `{error:{code,message}}`, no-GL-account) + Shape 1 (Laravel `{message,errors}`):** fall through to `getErrorMessage`. Test `:114-136` covers the canonical→generic fallback chain.

This is exactly the defensive extractor §A1 mandates; the brief's warning that a bare `getErrorMessage()` resolves `undefined` on the flat-string shape is respected. The shared `@/lib/api.ts` helper was **not** globally "fixed" (out-of-scope respected).

### TDD / test quality — ✅ strong

- Hook test: posts exact decimal-string payload, asserts all 4 invalidations incl. the tenant-negative movements case, both 422 shapes.
- Dialog test: renders all 4 reason options in order, asserts `step="0.001"` + `maxlength="1000"`, submits exact endpoint payload, blocks `0`/`0.000`/`1.2345` before the mutation fires (acceptance criteria 3 covered).
- Page/permission tests updated (`RepositoryDetailPage.test.tsx` +47, `usePermissions.treasuryReconciliation.test.ts` +4).

---

## 2. Findings (numbered, by severity)

### BLOCKER — none
### HIGH — none
### MEDIUM — none

### LOW-1 — `await mutateAsync()` in the dialog can emit an unhandled promise rejection on the error path
`AdjustBalanceDialog.tsx:53-57` uses `const onSubmit = async (...) => { const result = await adjustment.mutateAsync(request); ... }` invoked via `void handleSubmit(onSubmit)(event)` (`:61`). On a server error `mutateAsync` **rejects**; the hook's `onError` fires the toast correctly and the dialog correctly stays open, **but** the rejected promise is swallowed by `void` with no `.catch`, producing an "Uncaught (in promise)" in the console (and potential noise in future tests). The canonical template `AddPaymentMethodModal.tsx:152-154` sidesteps this by using fire-and-forget `mutation.mutate(data)` (never rejects). **Suggested fix:** switch to `mutation.mutate(request, { onSuccess: ... })`, or add `.catch(() => {})` to the `mutateAsync` call. Non-blocking — behavior on error is already correct (toast shown, dialog stays open).

### LOW-2 (informational) — dialog uses `companyCurrency`, not the repository's own currency
Brief §A2 suggested extending the `Repository` interface with `currency` and passing `repository.currency` to `MoneyInput`. The implementation instead passes `repositoryCurrency={companyCurrency}` (`RepositoryDetailPage.tsx:329`). **This is defensible and I am not requesting a change:** (a) the brief explicitly said "verify via GET response before assuming [currency] exists" — the current `Repository` interface (`:26-40`) has no `currency` field; (b) the page's own balance display already formats with `companyCurrency` (`:260-261`), so the dialog's `MoneyInput` scale/symbol is **consistent** with the rest of the page. Using `repository.currency` would have *introduced* an inconsistency. Flagged only as a forward note: if/when foreign-currency repositories (e.g. a USD `bank_account` under an EUR company) become real, both the balance display and this dialog need a coordinated currency-source change — out of scope for Wave A.

---

## 3. Verification commands — NOT executed in this review environment

The §2 verification commands (`pnpm typecheck`, `pnpm lint`, targeted `pnpm vitest run`, `node tools/audit-design-system.mjs`) are **approval-gated in this autonomous review sandbox and could not be run here.** Per the gate protocol, executing and confirming these is the **runner's** responsibility before tagging the rc; this review is the adversarial *diff* pass. Static analysis of the test files shows them well-formed and consistent with the implementation (mocks match real signatures, assertions match the shipped keys/payloads), and the design-token grep came back clean. **Before merge, the runner must attach green output** for typecheck, repo-wide lint, the two targeted vitest paths, and `audit-design-system.mjs` reporting **0 new**.

---

## 4. Acceptance-criteria traceability (Wave A)

| Criterion | Status | Note |
|---|---|---|
| Button visible with `treasury.adjust`, absent without | ✅ (code + test) | `RepositoryDetailPage.tsx:308`; needs live Playwright confirm per rule 5. |
| Submit valid in/count_variance → toast, close, balance updates, movements row w/ GL link | ✅ code path; ⚠️ **live-verify owed** | Invalidations correct; the "journal entry link present" + balance-refresh assertions are Playwright-only and not yet evidenced. |
| 4-decimal amount blocked client-side before request | ✅ | Pattern `:38` + test `:97-114`. |
| Tolerance-account-missing 422 flat string renders verbatim | ✅ (unit) ; ⚠️ live-verify owed | Test `:98-112`; live tenant-with-missing-658/758 path not yet exercised. |

---

## VERDICT: APPROVE

Wave A meets every §1 ground rule and every statically-verifiable Wave A acceptance criterion, with clean money/error/tenant-key/i18n/permission handling and strong TDD. The two findings are LOW and non-blocking (one code-quality nit on the error-path promise, one informational currency-source note). **Two conditions attached to the eventual merge (not to opening Wave B):** (1) the runner attaches green output for typecheck/lint/vitest/design-audit, since I could not execute them here; (2) the branch is rebased onto current `origin/dev` before merge so the diff is clean. Wave B is authorized to proceed.
