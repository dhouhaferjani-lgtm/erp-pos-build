GATE VERDICT: REJECT

I read the authority docs, the full `t5b-gate-4..HEAD` frontend diff, and the deleted-file baselines. **Important caveat on evidence:** every `pnpm`/`npx` invocation in this session was denied by the sandbox (`typecheck`, targeted `vitest`, web `lint` all returned "requires approval"), so I could **not** independently re-run the claimed 9/9, 86-test, typecheck-pass, lint-0-error evidence. Per my own protocol I do not accept reported counts. Findings below are from source verification only.

---

## BLOCKER

**1. Completing a statement leaves the list, repository balances, and provenance chips showing stale state for 5 minutes.**
`apps/web/src/features/treasury/statements/ReconciliationWorkspacePage.tsx:76-82` invalidates only `['bank-statement']`, `['bank-statement-line-suggestions']`, `['repository-movements']`. It never invalidates:
- `['bank-statements', filters, …]` — the list key at `StatementListPage.tsx:45`. `'bank-statement' !== 'bank-statements'`, so TanStack does not match it.
- `['payment-repositories']` (`hooks/usePaymentRepositories.ts:32`) — balances move on every `execute`/`allocate`.
- `['bank-statement-target-provenance', …]` (`StatementReconciliationChips.tsx:26`) — chips on Expense/Instrument detail.

`apps/web/src/lib/queryClient.ts:6` sets `staleTime: 1000 * 60 * 5`, so `refetchOnMount` will **not** refetch. Concrete failure: complete a statement (workspace shows `Reconciled`, `5/5`), click the breadcrumb back to `/treasury/statements` — the row still reads `Imported`/`Reconciling` and the delta/lines are stale for up to five minutes. This is exactly the "status presentation must be honest" requirement failing on the primary flow, and it is the reason the live smoke passes: `treasury-phase5b-reconciliation.smoke.ts:745` asserts `Reconciled` on the *workspace*, never on the list after return.

Fix: invalidate `['bank-statements']`, `['payment-repositories']`, and `['bank-statement-target-provenance']` in `refreshWorkspace`, and assert list-after-completion in the smoke.

---

## MAJOR

**2. Wizard carries stale profile state across a repository change.**
`StatementUploadWizard.tsx:52-56, 74-77, 185-188, 215`. `setRepositoryId` (line 163) never clears `profileId`, `preview`, `mapping`, or `file`. Path: pick repo A → select profile P_A → Back → Back → switch to repo B → forward. `availableProfiles` (line 75) now excludes P_A so the `<select>` renders no matching option, but `profileId` still holds `P_A`, so `disabled={!profileId}` (line 215) leaves **Preview enabled** and `requestPreview` posts `{repositoryId: B, profileId: P_A}`. The server rejects it, but the UI presents a valid-looking action over mismatched state — the exact "no stale repository/profile state" item in gate focus 1. Reset `profileId`/`preview`/`file` in the repository `onChange`.

**3. Statement opening/closing balances bypass `MoneyInput` (CLAUDE.md rule 19).**
`StatementUploadWizard.tsx:235-236` uses raw `<Input inputMode="decimal">` for `openingBalance`/`closingBalance`, then ships those strings straight to `confirmBankStatement` (`api.ts:213-214`) as the money payload. `MoneyInput` is exported from `@/components/atoms` (`components/atoms/index.ts:5`) and is already used correctly for the allocation amount at `ManualMatchSearch.tsx:41`. There is no scale ceiling, no min/max, and no inline error — a user can type `100.12345` or `abc` and the only feedback is a 422. Use `MoneyInput` with `currency={repository.currency}`.

**4. Line selection is not exposed to assistive tech; selected state is colour-only.**
`ReconciliationWorkspacePage.tsx:117` renders each line as `<Button variant="ghost">` with no `aria-pressed`, `aria-current`, `role="option"`, or any non-visual selected indicator. The only difference between selected and unselected is `semanticColorTokens.intent.primary.border` vs `border.subtle`. Gate focus 2 requires "accessible line selection"; this fails both that and colour-only-information. Add `aria-pressed={line.id === selectedLine?.id}` (or a listbox/radiogroup).

**5. Untranslated machine enums and raw identifiers rendered as user-facing text (rule 11).**
- `LinePanel.tsx:77` — `{allocation.match_type}` prints the raw enum `manual` / `suggestion_confirmed` / `created_from_line`. No `statements.workspace.matchType.*` keys exist in any of the three locale files.
- `ManualMatchSearch.tsx:39` — option labels are `{movement.source_type} · date · {movement.source_id}`, i.e. an untranslated enum plus a bare UUID, in all three locales including AR.
- `SuggestionList.tsx:30` — `{suggestion.reason}` renders server English verbatim (fixture: `'Cheque amount and date match.'`, `LinePanel.test.tsx:38`), so an FR/AR user reads English in the primary suggestion card.

Add translated key sets for `match_type` and `source_type`, and return a translatable reason code (or key + params) from the suggestion endpoint.

**6. `CreateFromLineDialog` violates the forms convention and is unusable as authored.**
`CreateFromLineDialog.tsx:21-23, 41`. `useForm` with **no** `zodResolver` and no schema; the only constraint is `register('accountId', { required: true })` with no `FormField` and no inline error rendering, so a failed submit is silent. Worse, `statements.workspace.create.incomeAccount` is literally "Income account ID" — the user is asked to hand-type an account UUID into a free-text `Input`. The canonical answer is a picker from `components/molecules/pickers/`, plus a real zod schema with translated messages.

**7. Tenant-scope test coverage regression.**
Wave 5 deletes `apps/web/src/features/treasury/hooks/__tests__/tenantScope.test.tsx` (416 lines), which asserted per-tenant/per-company key isolation and cross-company cache non-reuse for the old reconciliation hooks, and deletes `usePermissions.treasuryReconciliation.test.ts`. Nothing in `features/treasury/statements/` replaces them — the new suites are `LinePanel`, `StatementUploadWizard`, `StatementCompletionDialog`, `StatementReconciliationChips`, `status`, none of which touch query keys. Gate focus 3 asks precisely for proof that a company switch cannot reuse stale statement/suggestion/movement/profile/repository data, and that proof was removed rather than ported.

---

## MINOR

**8.** `StatementUploadWizard.tsx:150-156` — the four-step `<ol>` has an `aria-label` but no `aria-current="step"` on the active `<li>`; the active step is conveyed only by border/text colour.

**9.** `ReconciliationWorkspacePage.tsx:78-80` — invalidation uses unscoped prefixes, so switching company invalidates the *other* company's cached entries too. Harmless (over-invalidation, not stale reuse) but it defeats the point of `tenantScopedKey`'s suffix design; scope the invalidation keys.

**10.** `ReconciliationWorkspacePage.tsx:71-74` — `movementSearch` is in the query key with no debounce, so every keystroke in `ManualMatchSearch` fires a `/movements` request.

**11.** `StatementUploadWizard.tsx:92` — `Number(headerRows)` on free text yields `NaN` → serialised as `null`. Guard or use a numeric-parse fallback.

---

## What passed verification

- **Design-system baseline is honest.** The single removed entry (`audit-design-system-baseline.json:720`) corresponds exactly to the deleted `BankReconciliationPage.tsx`; nothing was added, and no `--write-baseline` absorption occurred. No alias tables, no detector-keyword suppressions, no arbitrary-value substitutions found in the new files.
- **Legacy cutover is complete.** `Sidebar.tsx:276`, `FinanceHubPage.tsx:75`, and `routes/index.tsx:1826-1842` all point at `/treasury/statements`; `routes.test.tsx:67-70` negatively asserts the old route and component are gone; grep confirms the only surviving `useReconciliation` references are the unrelated inventory-counting module.
- **i18n key parity is complete.** `statements.*` is fully populated in EN, FR, and AR with no orphans in either direction, and the removed `common.reconciliation.*` / `treasury.reconciliation.*` blocks have no surviving referents.
- **Money handling is decimal-string + `big.js` throughout** (`status.ts:8-19`, `ReconciliationWorkspacePage.tsx:98-99`, `ManualMatchSearch.tsx:30-33`); no `Number`/`parseFloat` on money anywhere in the diff.
- **Resolution semantics are correct.** `status.ts:9` returns zero remaining for ignored lines; `status.ts:22` counts `resolved_by_creation` and `ignored` as resolved; `status.ts:26` treats `resolved_by_creation` as successful (green badge); `ReconciliationWorkspacePage.tsx:100` gates completion on **all** lines (unfiltered) being resolved.
- **RTL is logical throughout** — `ms-`/`me-`/`ps-`/`start-`/`text-start`/`text-end`, no `ml-`/`pl-`/`left-` in the new files.
- **The smoke does not mock network.** No `page.route(`/`fulfill` in `treasury-phase5b-reconciliation.smoke.ts`; selectors are role-based and it asserts real rendered preview counts (`:677`), the unparseable row (`:679`), tier matches (`:707`), `Created and matched` (`:720`), `Ignored` (`:731`), `5/5` (`:740`), plus a server-side checkpoint and two 422 rejections (`:774-802`).

---

VERDICT: REJECT

Before the ⑤b exit review: fix the missing list/repository/provenance invalidations so a completed statement is never shown as unreconciled (BLOCKER 1), reset stale profile state on repository change, move the balance fields to `MoneyInput`, make line selection and the create-from-line form accessible and properly validated, translate the leaked `match_type`/`source_type`/suggestion-reason strings, restore a tenant-scope test for the new statement query keys — and re-run `lint`/`typecheck`/targeted `vitest` in an environment where the gate reviewer can execute them, since none of the claimed test evidence could be independently reproduced here.
