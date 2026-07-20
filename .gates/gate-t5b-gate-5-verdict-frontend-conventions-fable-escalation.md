File writes need approval too, so the verdict is delivered inline below, as the gate request's "Required output" specifies.

---

GATE VERDICT: APPROVE

Fable escalation, reviewing `feat/treasury-phase5` @ `941e9e20e` (Wave 5 diff `t5b-gate-4..HEAD`). Charter: reassess the prior M1 and verification findings against HEAD; do not reject solely on reviewer-environment execution limits when committed raw evidence and local runs are present.

## Prior MAJORs — each independently re-verified FIXED at HEAD

**r3-M1 (raw table through DataTable's legacy passthrough) — FIXED.** `apps/web/src/features/treasury/statements/StatementUploadWizard.tsx:163-172,256` now builds `DataTableColumn<StatementPreview['preview_lines'][number]>[]` and renders `<DataTable columns={previewColumns} data={preview.preview_lines} keyExtractor={…} ariaLabel={t('statements.upload.preview')} />` (commit `0c134aec8`). This matches the canonical typed overload exactly (`components/molecules/DataTable/DataTable.tsx:62-80` — `columns`/`data`/`keyExtractor`/`ariaLabel` are real props; `numeric: true` on the amount column yields right-align + `tabular-nums`). The debt is no longer invisible to the C5 audit-by-indirection, and no baseline entry is needed because no raw table exists. The new `statements.upload.preview` key resolves in all three locales (`en/treasury.json:712`, `fr/treasury.json:712`, `ar/treasury.json:101`), and all three designTokens imports in the file remain used post-repair (`StatementUploadWizard.tsx:178,184,219`) — no dead-import fallout.

**r4 Playwright-discovery finding — FIXED.** `apps/web/playwright.smoke.config.ts` exists at HEAD (commit `941e9e20e`) with `testMatch: /.*\.smoke\.ts/` over the base `testDir: './e2e'` (`playwright.config.ts:4`), covering all six `e2e/smoke/*.smoke.ts` files; `.github/workflows/smoke-test.yml:53` (`--config=playwright.smoke.config.ts`) resolves again.

**r4-m9 (untyped dynamic i18n key sources) — FIXED.** `api.ts:25-26` adds `MovementSourceType` and `StatementSuggestionReasonCode` literal unions, applied at `api.ts:72,87`. Value-set parity verified against the backend: the 9 members equal `MovementSourceType.php:9-17` exactly; the 6 reason codes equal the literals at `StatementSuggestionService.php:87,112,337-339,380,425` including both arms of the bounced/pending ternary. A new backend value now fails the build.

**rerun-MAJOR-1 (completion honesty unasserted at page level) — FIXED.** `ReconciliationWorkspacePage.test.tsx` covers all four demanded branches: Complete unavailable with an unresolved line; ignored lines resolved with `remainingTotal '0.000'` and signed `ignoredTotal '-2.000'`; unavailable without reconcile permission; unavailable for ignored lines without acknowledge permission. Critically, the tested `getWorkspaceCompletionState` is the production path — the page consumes it at `ReconciliationWorkspacePage.tsx:111` and `canComplete` alone gates the Complete button (`:115`). Not a parallel test-only function.

**r2-MAJOR-1 (module gating) — FIXED.** Both statement routes wrapped in `RequirePermission moduleKey="treasury" permission="bank-statements.view"` (`routes/index.tsx:1828,1839`); the FinanceHub card carries `permissionModule: 'treasury'` + `permission: 'bank-statements.view'` (`FinanceHubPage.tsx:72-78`).

**r2-MAJOR-2 (provenance chips never mounted) — FIXED.** `LinePanel.tsx:89` mounts `StatementReconciliationChips` behind the narrowing guard `isProvenanceTargetType` (`LinePanel.tsx:48`).

## The verification-gap major — resolved to the escalation standard

I attempted execution through every channel: sandboxed `pnpm typecheck`, direct `node_modules/.bin/tsc`, `playwright test --list`, and an unsandboxed retry — all require interactive approval unavailable to an autonomous session. This is the identical environmental wall three prior rounds hit; it is a property of the reviewer harness, not the diff. Per the charter I verified the evidence trail instead:

- **Fresh raw evidence exists.** The four screenshots in `docs/sessions/treasury-phase5b-e2e/` were regenerated tonight at 23:42, minutes after HEAD (23:42:47) — exactly what a fresh post-repair smoke run produces (`smoke.ts:775` writes `04-reconciled-checkpoint.png` at completion).
- **The claimed server-state assertions are real in source**: reconciled status + exact line-status multiset (`smoke.ts:785-792`); checkpoint balance semantics including ignored-line exclusion from the live balance (`:806-808`); both 422 rejections; rejected-clear provenance — no `cleared` event, exactly one journaled event, status still `received` (`:836-843`). No `page.route`/`fulfill` mocking anywhere.
- **Vitest scope is structurally consistent**: exactly 8 test files in the statements feature; 24 static `it(` sites plus `it.each` expansion (`queryScope.test.ts:9`) is consistent with the claimed 32 runtime tests.
- **Baseline honesty re-verified end-to-end**: the `audit-design-system-baseline.json` diff across the entire gate range is one honest removal (the deleted `BankReconciliationPage.tsx` C3 entry), zero additions. My own sweeps: no raw palette classes, no physical-direction utilities, no `parseFloat`/`Number(` on money in the statements feature.
- Nothing verified by the two prior independent reviewers was contradicted by my checks.

## MINOR (non-gating; carry to the ⑤b exit punch list)

1. `LinePanel.tsx:89` — count + noun concatenation for `movementsProduced` still defeats AR/FR plurals; use `t(key, { count })` (r3-m1, unfixed).
2. `LinePanel.tsx:44` — `income_document` provenance links to `/income/:id/edit` (gated `income.update`) while the expense branch uses `/view`; read-only reconcilers hit a permission wall (r3-m2, unfixed).
3. `queryScope.ts:1-8` — `statement-import-profiles` remains outside the refresh namespace set; benign today, inconsistent (r3-m4, unfixed).
4. `smoke.ts:769-774` asserts `5/5` and `Reconciled` but not the rendered zero-remaining figure, while `REPORT.md:20` says the UI displayed "0.000 TND remaining" — true per screenshot 04 and enforced at unit + server-state level, but either add the rendered assertion or attribute the claim to the screenshot (r3-m5 residual).
5. Evidence hygiene: the four refreshed PNGs are uncommitted working-tree changes, and `REPORT.md:13` still cites the earlier 45.0s run rather than the fresh 26.2s rerun. Commit the refreshed evidence together.
6. Evidence wording: the React Doctor `StatementUploadWizard` useReducer warning is called "pre-existing" but the file is new in this wave (r3-m3). Advisory only.

VERDICT: APPROVE

Before the ⑤b exit review: commit the refreshed smoke evidence (4 PNGs plus a REPORT.md touch-up for the fresh run duration and the zero-remaining attribution) and fold the six MINORs into the exit punch list — none blocks this gate.

---

**Note for the controller:** the pre-created `.gates/gate-t5b-gate-5-verdict-frontend-conventions-fable-escalation.md` is still empty — my write was blocked by the no-file-changes constraint, so the verdict above is the record and should be pasted into that file.
