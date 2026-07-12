# ADVERSARIAL GATE REVIEW — Treasury UI Gaps, GATE 1 (Wave A) — rc2

- **Reviewer:** Claude (Opus 4.8), autonomous gate per handoff §"Autonomous audit gates"
- **Date:** 2026-07-12
- **Scope:** Repository balance adjustment UI (Wave A), reviewed against `docs/handoff/CODEX-treasury-ui-gaps-2026-07-10.md` §1 ground rules + Wave A acceptance criteria.
- **Supersedes:** GATE-1-rc1.md (reviewed commit `19d6401ff`; this branch was re-committed since).

---

## 0. Diff-scope note (read first)

The brief's literal command `git diff origin/dev..HEAD` (two-dot) is **contaminated** in this worktree: `feat/treasury-ui-gaps` is currently **4 commits behind** `origin/dev` (and 2 ahead), so a two-dot diff reverse-shows the 4 dev-ahead commits as spurious "changes."

I reviewed the **merge-base (three-dot) diff** `git diff 1223dcc37..HEAD`, which isolates the two real Wave A commits (`582886105` "Add repository balance adjustment UI" + `2f8f8f541` "Align adjustment UI with current dev gates"). Ten code files, all under `apps/web/**`:

```
features/treasury/RepositoryDetailPage.tsx / .test.tsx
features/treasury/components/AdjustBalanceDialog.tsx / .test.tsx
features/treasury/hooks/useAdjustRepositoryBalance.ts / __tests__/useAdjustRepositoryBalance.test.tsx
hooks/usePermissions.ts / usePermissions.treasuryReconciliation.test.ts
locales/en/treasury.json / locales/fr/treasury.json
```

No `apps/api/**` modified (contract-verification reads only) — brief §"Out of scope" honored. **Recommendation carried forward:** rebase onto current `origin/dev` before the final merge so the two-dot diff is clean for Gate 2 / merge.

### What changed since rc1 (and why it matters)

`2f8f8f541` re-aligned the mutation's cache invalidation. rc1 reviewed a version whose invalidations were wrapped in `tenantScopedKey([...])` and *praised* that. **That praise was wrong**, and rc2 corrects it. The current dev gate `apps/web/tools/audit-tanstack-keys.mjs` (lines 62–82) explicitly classifies `invalidateQueries`/`removeQueries`/`resetQueries`/`refetchQueries`/`cancelQueries` as **CACHE_FILTER_FACTORIES**: their `queryKey` is a positional-**prefix** match filter, and because `tenantScopedKey` appends tenant/company as **suffixes**, wrapping a filter in it is a *proven no-op* — the scanner **FLAGS** `tenantScopedKey(...)` on these factories and **APPROVES bare literal prefixes**. So rc2's bare-prefix invalidations are the **required** form, not a regression. The brief's §7 ("every … invalidation MUST use `tenantScopedKey`") is stale relative to the post-sweep gate the brief itself mandates ("audit must report 0 new"). rc2 correctly follows the code-as-SoT gate over the stale prose.

Also fixed since rc1: the dialog now uses fire-and-forget `mutate(request, { onSuccess })` (`AdjustBalanceDialog.tsx:56-61`) instead of `await mutateAsync`, eliminating rc1's LOW-1 unhandled-rejection nit.

---

## 1. Ground-rule verification (cited to file:line)

| # | Rule | Verdict | Evidence |
|---|------|---------|----------|
| 3 | TS strict, no `any` | ✅ | `useAdjustRepositoryBalance.ts:33` `flatErrorMessage(error: unknown)` narrows with type guards; no `any` in the diff. |
| 4 | No double-unwrap (Rule 14) | ✅ | `useAdjustRepositoryBalance.ts:48-52` `api.post<AdjustmentResponse>(...)` then `response.data.data` — correct for the `{message,data}` 201 shape, matches the brief's prescribed pattern. |
| 5 | Money = decimal strings, no parseFloat/Number (Rule 19) | ✅ | `AdjustBalanceDialog.tsx:90-112` binds `amount` to `<MoneyInput>` via RHF `Controller`; value passes through as a string, hook posts it verbatim (`useAdjustRepositoryBalance.ts:47-52`). Client ceiling `POSITIVE_MONEY_PATTERN = /^(?=.*[1-9])\d+(?:\.\d{1,3})?$/` (`:32`) enforces `gt:0` + 3dp, mirroring backend `AdjustRepositoryRequest`. No parseFloat/Number on the adjustment amount. |
| 6 | Design tokens only, zero new hardcoded colors (Rule 18) | ✅ | Grep for `bg-/text-/border-/ring-<color>-<n>` across both new files → no matches. Page's new nodes use `textColors.*`/`Button` variants (`RepositoryDetailPage.tsx:300,309,317`). Loader spinner is color-free (`AdjustBalanceDialog.tsx:155`). |
| 7 | Tenant-scoped invalidation (audit-tanstack gate) | ✅ | `useAdjustRepositoryBalance.ts:59-76`: bare-prefix filters `['payment-repository', repositoryId]`, `['payment-repository-transactions', repositoryId]`, `['treasury-cash-position']` — the **approved** form for cache-filter factories (audit tool lines 62–82). Movements uses a tenant-scoped **predicate** (`:66-72`) derived from `tenantScopedKey(['repository-movements', repositoryId])` (`:55-57`), matching real key `[..., filters, tenantId, companyId]`. Predicates carry no `queryKey` prop → not gate-flagged; correctness proven by test `__tests__/…test.tsx:87-92` (tenant-1 true, tenant-2 false). |
| 8 | i18n en+fr, no hardcoded strings | ✅ | Every dialog/page `t()` key resolves in **both** `en/treasury.json:365-390` and `fr/treasury.json:365-390` (action, title, direction+directions.{in,out}, amount, reasonCode, reasons.{4}, reasonText, validation.{amount,reasonText,reasonTextMax}, submit, success, error). FR fully translated (proper apostrophes, "1 000"). `common:actions.cancel` reused. No literal user-facing strings in JSX. |
| 9 | FE permission gating, correct roles | ✅ | `RepositoryDetailPage.tsx:308` gates the button on `hasPermission('treasury.adjust')`. New entry `'treasury.adjust': ['admin','manager','accountant']` (`usePermissions.ts:68`) **exactly matches** backend grants — `RolesAndPermissionsSeeder.php`: admin (`:444` `Permission::all()`), manager (`:474`), accountant (`:694`); no `treasury`-role grant exists (verified: only three occurrences, none in a `treasury` role block). No false-positive / false-negative gap. Backend route `can:treasury.adjust` already live per brief. |
| 11 | Canonical components only | ✅ | Modal/ModalHeader/ModalContent/ModalFooter, FormField, Select, Textarea, MoneyInput, Button, Loader2 — structure mirrors the prescribed `AddPaymentMethodModal.tsx` template; nothing bespoke reinvented. |

### Error-envelope handling (the two 422 shapes) — ✅ correct

`useAdjustRepositoryBalance.ts:78-81`: `flatErrorMessage(error) ?? getErrorMessage(error) ?? t(...fallback)`.
- **Flat-string `{error:"…"}` (tolerance-account-missing):** `flatErrorMessage` (`:33-40`) returns `data.error` only when `typeof === 'string'` → surfaced verbatim. Test `:98-112` locks this and asserts `getErrorMessage` is **not** consulted first (satisfies AC "renders verbatim, not `undefined`/`[object Object]`").
- **Canonical `{error:{code,message}}` (no-GL-account) + Laravel `{message,errors}`:** fall through to `getErrorMessage`, then the i18n generic. Test `:114-136` covers the full fallback chain.

Shared `@/lib/api.ts` `getErrorMessage` was **not** globally "fixed" — out-of-scope respected.

### TDD / test quality — ✅ strong and consistent with rc2 code

- Hook test asserts the exact decimal-string payload, all four invalidations incl. the tenant-negative movements case, and both 422 shapes; mock re-derives `tenantScopedKey` so the predicate assertion is real (`__tests__/…test.tsx:38-40,84-95`).
- Dialog test uses the rc2 `mutate({ onSuccess })` contract (`AdjustBalanceDialog.test.tsx:18-23,68-76`), verifies all 4 reasons in order, `step="0.001"` + `maxlength="1000"`, and blocks `0`/`0.000`/`1.2345` before the mutation fires (`:82-122`).

---

## 2. Findings (numbered, by severity)

### BLOCKER — none
### HIGH — none
### MEDIUM — none

### LOW-1 (informational) — invalidation tenant-scoping is asymmetric
`useAdjustRepositoryBalance.ts`: the movements invalidation is tenant-scoped via predicate (`:66-72`) while the three sibling invalidations use bare prefixes (`:60-64,73-75`) that prefix-match across all cached tenants. This is **not a defect**: bare-prefix cross-tenant over-match only triggers a harmless refetch on next mount (each refetch still hits the correct per-tenant DB under db-per-tenant), and it is the gate-approved form. The asymmetry is cosmetic. No change requested.

### LOW-2 (informational, carried from rc1) — dialog uses `companyCurrency`, not repository's own currency
`RepositoryDetailPage.tsx:329` passes `repositoryCurrency={companyCurrency}` (`:233` `currentCompany?.currency ?? 'EUR'`). The `Repository` interface (`:26-39`) still has no `currency` field, and the page's balance display already formats with `companyCurrency`, so the `MoneyInput` scale/symbol stays **consistent** with the page. Defensible; the brief itself said "verify currency exists before assuming." Forward note only: real foreign-currency repositories would need a coordinated currency-source change to both the balance display and this dialog — out of scope for Wave A.

### Out-of-scope observation (not a finding against this diff)
`RepositoryDetailPage.tsx:318` uses `parseFloat(repository.balance) >= 0` to pick the balance color — a pre-existing line, **not** introduced by this diff, correctly left untouched per Rule 4 / "don't refactor untouched lines." Noting it only so the record is complete; it belongs to a separate precision-drift cleanup, not this gate.

---

## 3. Verification commands — NOT executed in this review environment

The §2 commands (`pnpm typecheck`, `pnpm lint` [chains `audit:keys`], targeted `pnpm vitest run`, `node tools/audit-design-system.mjs`) are approval-gated in this autonomous sandbox and were not run here. Static analysis is clean: design-token grep empty; invalidations conform to the cache-filter gate (bare prefixes) so `audit:keys` should report **0 new**; no new `useQuery` storage keys introduced; test mocks match real signatures and shipped keys/payloads. **Before merge, the runner must attach green output** for typecheck, repo-wide lint, the two targeted vitest paths, and `audit-design-system.mjs` reporting **0 new**.

---

## 4. Acceptance-criteria traceability (Wave A)

| Criterion | Status | Note |
|---|---|---|
| Button visible with `treasury.adjust`, absent without | ✅ (code + test) | `RepositoryDetailPage.tsx:308`; live Playwright confirm owed per rule 5. |
| Submit valid in/count_variance → toast, close, balance updates, movements row w/ GL link | ✅ code path; ⚠️ **live-verify owed** | Invalidations correct (`:59-76`); "journal-entry link present" + balance-refresh are Playwright-only, not yet evidenced. |
| 4-decimal amount blocked client-side before request | ✅ | Pattern `:32` + tests `AdjustBalanceDialog.test.tsx:82-122`. |
| Tolerance-account-missing 422 flat string renders verbatim | ✅ (unit); ⚠️ live-verify owed | Test `:98-112`; live tenant-with-missing-658/758 path not yet exercised. |

---

## VERDICT: APPROVE

Wave A satisfies every §1 ground rule and every statically-verifiable acceptance criterion: correct decimal-string/MoneyInput money handling, the mandated dual-422 defensive error extractor, gate-correct cache invalidation (bare prefixes for filters + a tenant-scoped movements predicate), full en+fr i18n, exact permission-list/backend parity, canonical components, and strong TDD. rc2 additionally fixes rc1's LOW-1 (promise rejection) and realigns invalidation to the current `audit-tanstack-keys` gate (which rc1 had endorsed incorrectly). Both remaining findings are LOW/informational.

**Two conditions attached to the eventual merge (not to opening Wave B):** (1) the runner attaches green output for typecheck / repo-wide lint (incl. `audit:keys`) / the two targeted vitest paths / `audit-design-system.mjs` = 0 new, since I could not execute them here; (2) the branch is rebased onto current `origin/dev` before merge so the diff is clean for Gate 2. Wave B is authorized to proceed.
