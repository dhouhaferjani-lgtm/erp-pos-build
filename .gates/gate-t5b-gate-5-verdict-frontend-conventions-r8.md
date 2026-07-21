GATE VERDICT: APPROVE

Reviewer: frontend-conventions-reviewer (claude-opus-4-8), round 8, 2026-07-21, controller-dispatched after quota recovery. Target: HEAD `b61d7c20e`.

I re-ran the guardrails myself this round (sandbox permitted execution, unlike r7). All three reproduced independently at HEAD `b61d7c20e` on `feat/treasury-phase5`. The two r7 blockers are genuinely fixed; only the acknowledged carried Minors remain.

## Re-verification of prior blockers

**r7 MAJOR-1 (date-localization regression) — FIXED.** All six sites now render through `lib/format.ts` `formatDate` (which resolves `i18n.language` at `format.ts:161`, giving DD/MM/YYYY on FR/TN):
- `StatementListPage.tsx:65` — period column: `{formatDate(statement.period_start)} → {formatDate(statement.period_end)}`
- `ReconciliationWorkspacePage.tsx:115` — subtitle: `${formatDate(statement.period_start)} → ${formatDate(statement.period_end)}`
- `ReconciliationWorkspacePage.tsx:129` — line rows: `{formatDate(line.value_date)} · #{line.line_number}`
- `LinePanel.tsx:75` — selected-line header: `{formatDate(line.value_date)}`
- `StatementUploadWizard.tsx:167` — preview "Value date" column: `render: (line) => formatDate(line.value_date)`
- `ManualMatchSearch.tsx:42` — now `{formatDate(movement.occurred_at)}` (passes the full timestamp, so `new Date(...)` applies timezone conversion instead of the raw `.slice(0,10)` that dropped it)

Backed by red-first assertions that also assert the raw ISO string is absent: `LinePanel.test.tsx:114-121` (asserts `queryByText(/2026-07-18/)` not present, and the option carries `formatDate('2026-07-18T10:00:00Z')`), `ReconciliationWorkspacePage.render.test.tsx:74-80`, `StatementUploadWizard.test.tsx:85-86`. All pass.

**r7 MAJOR-2 (smoke permanently consumes a shared bank-repository fixture) — FIXED.** New teardown at `treasury-phase5b-reconciliation.smoke.ts:852-873` (step 7) `POST /bank-statements/{mainStatementId}/reopen`, then re-reads `/payment-repositories/{repository.id}/balance` and asserts `balance.last_reconciled_at` is `null` (`:872`). This releases the checkpoint (per `StatementCompletionService::reopen` recomputing from remaining reconciled statements — none), so the scarce active/GL-linked/unreconciled repository fixture survives repeated runs. The specific re-runnability defect r7 identified is closed.

**r7 MAJOR-3 (guardrails not reproducible) — RESOLVED by independent execution.** From `apps/web`:
- `pnpm vitest run src/features/treasury/statements/` → 9 files / 35 tests passed (2.42s, default pool)
- `pnpm exec eslint src/features/treasury/statements` → exit 0, 0 errors (43 acknowledged warnings)
- `pnpm typecheck` → clean, no errors
- `node tools/audit-tanstack-keys.mjs` → Gate C: 0 new, 0 stale, exit 0 (confirms the profile-invalidation fix)

**r5-fixed items spot-checked, not regressed.** `mutable` still threads into `LinePanel` (`ReconciliationWorkspacePage.tsx:132`) and gates *rendering* of unignore (`LinePanel.tsx:79`), suggestions + manual match (`:80-83`), unallocate (`:85`), ignore fieldset (`:87`), create-from-line button (`:89`), and the dialog (`:94`). Profile-list invalidation is wired (`StatementListPage.tsx:116`) and correctly changed to the bare literal prefix `['statement-import-profiles']` — a proper prefix filter against the `tenantScopedKey(['statement-import-profiles'])` query (`:52`), no longer the positional no-op flagged by the keys audit.

**Baseline honesty.** `git diff t5b-gate-4..HEAD` on `audit-design-system-baseline.json` = exactly one removal, `C3|src/features/treasury/BankReconciliationPage.tsx|...onClose...`, matching the genuinely deleted legacy page. Zero additions. No alias tables, no suppression comments, no renamed-equivalent literals.

## MINOR (acknowledged carried tickets — non-blocking)

**M1. Faked pluralization via concatenation.** `LinePanel.tsx:92` renders `{execution.produced_repository_movement_ids.length} {t('statements.workspace.movementsProduced')}` — word order hardcoded; the AR value cannot inflect. Use `t(key, { count })`.

**M2. `ManualMatchSearch` contradictory bounds + unpropagated `disabled`.** `disabled` (`ManualMatchSearch.tsx:45`) reaches only the Allocate button; the search `Input` (`:41`), the `Select` (`:42`, disabled only when the movement list is empty), and the `MoneyInput` (`:44`) stay editable. With nothing selected/remaining, `maxAmount` is `0.000` (`:34`) while `minimumAmount` is `0.001` (`:35`), so `MoneyInput` renders `min > max`.

**M3. `MODULE_PERMISSIONS` gains a permission-shaped key.** `usePermissions.ts:272` `'bank-statements.view': ['bank-statements.view']` — every other entry in that map is a module identifier.

**M4. Smoke selectors coupled to English UI strings** (`smoke.ts` Tier/label/`'Confirm match'` locators); no locale is pinned, so it depends on the session defaulting to English.

**M5. Unchecked casts where the file's own guard pattern exists.** `StatementUploadWizard.tsx:225,226,227` cast `event.target.value as StatementParserKey / StatementDirectionConvention / StatementDecimalFormat` (also the source of the three `no-unsafe-type-assertion` warnings), while `LinePanel.tsx:37` `isIgnoreReason` models the correct guard.

## Verified clean

Canonical atoms/molecules only (`PageHeader`, `ListPageLayout`, `DataTable`, `Modal`, `Input`/`Select`/`Textarea`/`Checkbox`/`MoneyInput`/`Button`/`StatusBadge`/`FormField`, `OffsetPagination`, `EmptyState`); no raw `<table>` or raw form controls. Design tokens only; no interpolated/composed Tailwind; logical RTL utilities throughout. Money stays decimal strings with `Big` arithmetic only. All five queries use `tenantScopedKey` and cannot cross company scope. Honest status semantics (`resolved_by_creation` successful, ignored lines zero remaining, completion gated on all-resolved). Cutover complete — routes/sidebar/FinanceHub point at `/treasury/statements`, no dead `BankReconciliationPage`/`api/reconciliation`/`hooks/useReconciliation` imports.

VERDICT: APPROVE

Both r7 blockers are genuinely fixed and independently re-verified (date localization across all six sites with red-first tests; smoke teardown that reopens the statement and asserts the repository checkpoint is released); guardrails reproduced green (Vitest 35/35, ESLint 0 errors, typecheck clean, tenant-keys 0 new, baseline honest). The remaining five Minors are acknowledged carried tickets and do not block the ⑤b exit review.
