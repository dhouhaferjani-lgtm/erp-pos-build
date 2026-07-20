GATE VERDICT: REJECT

## Verification status (read first)

Every execution command in this environment was refused by the permission layer: `pnpm vitest`, `npx vitest`, `pnpm typecheck`, `node tools/audit-tanstack-keys.mjs`. I therefore **could not independently re-run a single guardrail or test**, and the request explicitly conditions approval on a rerun ("so rerun before approval", gate focus 7). Per the reviewer charter — "Verify claimed test evidence by re-running it; never accept reported counts (two historical false-green claims)" — the reported 7 files / 29 tests, typecheck pass, lint exit 0, and 6/6 smoke are **unverified**. Everything below is static review, which I was able to do in full.

Also note: `.gates/gate-t5b-gate-5-verdict-frontend-conventions-rerun.md` and `.gates/gate-t5b-gate-5-verdict-treasury-rerun.md` are **0 bytes**. The "rerun" evidence referenced in the request does not exist on disk.

## MAJOR

**1. The completion-honesty invariant is entirely unasserted.** `apps/web/src/features/treasury/statements/ReconciliationWorkspacePage.tsx:101` — `canComplete = resolved === statement.lines.length && …` is the single guard enforcing "completion is unavailable until every line is resolved." There is no `ReconciliationWorkspacePage.test.tsx` (the 7 new suites are queryScope, status, LinePanel, CreateFromLineDialog, StatementCompletionDialog, StatementReconciliationChips, StatementUploadWizard), and the smoke asserts only the positive path — `e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:769-774` reaches `5/5` and then clicks Complete. Grep for `not.toBeVisible|toBeHidden|toHaveCount(0)` in that spec returns nothing. The negative case — Complete absent while any line is unmatched — is asserted nowhere. Same gap covers `remainingTotal`/`ignoredTotal` aggregation (`:99-100`) and the `bank-statements.reopen` permission gate (`:105`). This is the exact invariant the gate is chartered to protect, and it is the one most likely to silently regress. Fix: add a page-level suite asserting Complete is unavailable with an unresolved line, present at full resolution, that ignored lines contribute zero to remaining at the aggregate, and that reopen is hidden without permission.

**2. Approval preconditions unmet** (see Verification status). Fix: re-run targeted Vitest, `pnpm typecheck`, full web lint, the two audit scripts, and the live smoke in an environment where execution is permitted, and attach the output.

## MINOR

3. `SuggestionList.tsx:30` — `suggestion.reason_code ? t(…) : suggestion.reason` renders a raw server-authored string when `reason_code` is null, bypassing i18n. Fix: render a translated generic fallback, or make `reason_code` non-nullable in the contract.
4. `ManualMatchSearch.tsx:35` — `String(1 / 10 ** getDecimals(currency))` derives a money bound by float arithmetic, against CLAUDE.md rule 19. Values happen to serialize correctly for scales 2–4, so this is a smell rather than a defect. Fix: derive from a decimal helper.
5. `StatementUploadWizard.tsx:246` — new call site routes through DataTable's legacy markup passthrough, whose own docstring (`DataTable.tsx:80-83`) says "Prefer the typed column/data API for new DataTable call sites"; the hand-rolled `<th className="px-3 py-2 text-start">` and `border-t` skip the canonical header/hover/alignment treatment. Low severity only because ~20 existing sites do the same.
6. `LinePanel.tsx:86` — `CreateFromLineDialog` mounts unconditionally, so its `useAccounts({type:'revenue'})` query (`CreateFromLineDialog.tsx:23`) fires on every line selection, including `out` lines where revenue accounts are never used. Fix: render the dialog only when `showCreate`.
7. `ReconciliationWorkspacePage.tsx:71,76` — `suggestionsQuery` and `movementsQuery` omit the `tenantId !== null && companyId !== null` gate that `tenantScopedKey`'s docstring prescribes. Transitively safe today (both depend on `statement`, which is gated), but fragile.
8. `ManualMatchSearch.tsx:30` — the "no eligible movement" option can never be selected; choosing it falls back to `movements[0]`.
9. Smoke selector robustness: `smoke:279` `page.locator('button').filter({hasText}).first()` and `smoke:773` `…{name:'Complete statement'}).last()` rely on DOM ordering.

## Verified clean (no finding)

- Design-system baseline: exactly one removal, `BankReconciliationPage.tsx`, matching the deleted file. No additions, no absorbed debt. Honest.
- Tenant scoping: every new query uses `tenantScopedKey`; `queryScope.ts:19-20` matches on the tenant/company suffix pair and `queryScope.test.ts:20-34` covers both cross-company non-invalidation and unrelated-namespace exclusion. `usePaymentRepositories` and `useAccounts` key shapes are compatible with the predicate.
- Legacy cutover: no dead references to `BankReconciliationPage`, `/treasury/reconciliation`, or the removed treasury `useReconciliation`/`api/reconciliation`; remaining `useReconciliation` hits are the unrelated inventory-counting module; `routes.test.tsx:69` guards the regression.
- i18n: `statements.*` subtree is structurally parallel across EN/FR/AR with genuine translations, not copied English. `common:loading` resolves (root key, `common.json:441`).
- Status honesty: `status.ts:11` returns zero remaining for ignored lines (tested, `status.test.ts:14-21`); `resolved_by_creation` is treated as successful (`status.ts:31`, tested). I confirmed backend-side that create/acquirer-fee actions write `created_from_line` allocations (`StatementMatchingService.php:163-168`), so created lines genuinely reach zero remaining rather than being cosmetically labelled.
- Wizard stale state: `Modal.tsx:136` returns null when closed, so the wizard unmounts and resets between opens; `changeRepository` (`:79-93`) clears file, profile, preview, period, balances, mapping, and acknowledgment.
- Smoke is genuinely live — no `page.route`/fulfill mocking, and it asserts server state (statement status, per-line `match_status` set, repository balance, `last_reconciled_at`, produced movements, journal entries).
- Tokens: all referenced token paths exist in `designTokens.ts`; no interpolated variant prefixes or opacity modifiers; RTL uses logical `me-/ms-/ps-/text-start/text-end` throughout.

VERDICT: REJECT

Before the ⑤b exit review: add page-level coverage asserting the completion gate's negative case (plus remaining/ignored aggregates and the reopen permission gate), and re-run the full guardrail set and live smoke in an environment where execution is permitted — the static review is otherwise clean and no code defect blocks this gate.
