# FE Batch Gate — 2026-08-02

**Scope:** FE deltas of `520ffb533` (TopBar z-50 + flipped AUTH-09/MTP-I18N-04a + TopBar.test.tsx),
`aa3fc1cc4` (settings.update affordance gating on 4 screens + `common:permissions.readOnlyEditHint`
en/fr + Dashboard F7 test + MTP-CFG-14 rewrite), and the FE portion of `10ad37743`
(RequirePermission on `/settings/setup` + Dashboard onboarding `enabled` gate).

**Branch:** local `dev` @ `aa3fc1cc4`, ahead 5 of `origin/dev` (`b4f03c8dc`). NOT pushed.

**Verdict: APPROVE-WITH-FIXES**

---

## Guardrails re-run (not trusted from report)

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web lint` | **FAIL (exit 1)** — 0 eslint errors / 6515 warnings; `audit:keys` 0 new / 0 stale; `audit:design-system` **2 new** (see B1). Pre-existing on `origin/dev`. |
| `pnpm --filter @autoerp/web typecheck` | PASS (clean) |
| `npx vitest run src/components/organisms/TopBar src/features/dashboard/__tests__ src/features/settings` (default pool) | PASS — 31 files / 123 tests |
| Red-without-fix check on `TopBar.test.tsx` | CONFIRMED red — reverting `z-50` in `TopBar.tsx` → 2 failed / 1 passed, restored |
| Mutation check on the F1 gate (`|| !canEdit` deleted from all 4 files) | **STILL GREEN** — 29 files / 117 tests (see M2), restored |
| Live E2E `AUTH-09` | PASS (10.3s) |
| Live E2E `MTP-I18N-04a` | PASS (18.5s) |
| Live E2E `MTP-CFG-14` | FAIL then PASS on retry (24.1s) — flake in the redundant owner `beforeEach` login (see m4) |
| Baseline honesty | `apps/web/tools/audit-design-system-baseline.json` **UNCHANGED** by the batch (`git diff origin/dev..dev` empty). No `--write-baseline` absorption. |
| Mechanism audit | No alias tables, no detector-keyword suppression comments, no renamed-equivalent literals. The only className delta is the literal `z-50` (statically present, no interpolated variant/opacity). |

---

## BLOCKER

**B1 — `pnpm lint` is red on this branch; two C2 design-system violations are detector FALSE POSITIVES on prose.**
`apps/web/src/features/documents/DocumentForm.tsx:124` and `:373` are **comment lines** that contain the
literal string `<input type="date">`. The C2 detector (`apps/web/tools/audit-design-system.mjs:246-258`)
scans raw source with no comment stripping — deliberately, per the hardening note at
`tools/audit-design-system.mjs:55` ("a bare `// react-hook-form` comment silenced the detector").
Provenance: `4cfe0b0e25` (2026-08-01), an **ancestor of `origin/dev`** — `DocumentForm.tsx` is byte-identical
between `origin/dev` and `dev` (`git diff --stat origin/dev..dev -- apps/web/` does not list it).
**Not caused by this batch**, but the batch cannot clear the lint gate as-is.
_Fix directive:_ reword both comments to avoid the literal tag (e.g. "an HTML date input") in a dedicated
commit, or baseline the two entries explicitly — never via a blanket `--write-baseline`.

---

## MAJOR

**M1 — The F1 ruling is not applied to the largest `settings.update` surface: `CompanyPage.tsx`.**
`apps/web/src/features/settings/CompanyPage.tsx` imports no `usePermissions` and leaves **five**
`settings.update`-gated mutations with fully enabled affordances:
- `CompanyPage.tsx:959` general Save → `api.patch('/settings/company')` at `:186`
  (`UpdateCompanySettingsRequest::authorize()` + route-level `can:settings.update` added by **this batch**,
  `apps/api/app/Modules/Tenant/routes.php:26-31`)
- `CompanyPage.tsx:632` procurement Save → `api.put('/procurement-policies')` at `:202`
  (`can:settings.update`, `apps/api/app/Modules/Procurement/Presentation/routes.php:36-38`)
- `CompanyPage.tsx:795` logo upload → `api.post('/settings/company/logo')` at `:230` (route-gated by this batch)
- `CompanyPage.tsx:806` logo delete → `api.delete('/settings/company/logo')` at `:246`
  (controller-level check, `CompanySettingsController.php:229`)

Net effect: on the SAME page, a manager sees the Receipt tab's Save correctly disabled
(`ReceiptSettingsTab.tsx:385`, rendered from `CompanyPage.tsx:458-459`) and the General tab's Save enabled —
exactly the 403 dead-end the ruling was written to remove.
_Fix directive:_ hoist `const canEdit = hasPermission('settings.update')` into `CompanyPage` and apply
`disabled`/hint to all four buttons, same shape as the four fixed screens.

**M2 — The F1 gate has zero test coverage; deleting it is invisible (mutation-verified).**
I removed `|| !canEdit` from `TaxSettingsPage.tsx:352`, `InventorySettings.tsx:465`,
`ReceiptSettingsTab.tsx:385`, `PosRefundPoliciesPage.tsx:743` and re-ran `src/features/settings`:
**29 files / 117 tests still green.** The one test that touched permissions on these screens was *loosened*
(`SettingsPages.tenantScope.test.tsx:83`, `roles: []` → `roles: ['admin']`), and
`PosRefundPoliciesPage.test.tsx:57-64` mocks `hasPermission: vi.fn(() => true)`, so its
`toBeDisabled()` assertions at `:104`/`:112` only exercise `isDirty`.
_Fix directive:_ add one deny-path test per screen (Save disabled + carries the hint for a non-`settings.update`
holder), mirroring the F7 pattern in `Dashboard.tenantScope.test.tsx:194-205`.

**M3 — The disabled+`title` affordance is unreachable for keyboard and screen-reader users, and skips the
codebase's own better pattern.**
`Button` uses the native `disabled` attribute (`src/components/atoms/Button/Button.tsx:48-62`), so the control
leaves the tab order; `title` only surfaces on mouse hover and is generally not announced on a non-focusable
element. The repo already has a strictly better precedent at
`src/features/purchases/GoodsReceiptListPage.tsx:478-497` — disabled + `title` **plus** a visible
`<span className={`text-xs ${textColors.warning}`}>` carrying the same sentence. All four screens shipped only
the hover half. There is no `Tooltip` component in `components/atoms` or `components/molecules`, so the visible
span is the canonical answer.
_Fix directive:_ render `{!canEdit && <span className={`text-xs ${textColors.warning}`}>{t('common:permissions.readOnlyEditHint')}</span>}`
adjacent to each gated Save (or move the hint to an always-focusable wrapper with `aria-describedby`).

---

## MINOR

**m1 — Precedent misattribution in the `aa3fc1cc4` commit message.** `RolesPage.tsx:297` and `:308` are
*enabled* ghost icon-buttons whose `title` supplies the accessible name for an icon-only control — not a
disabled-with-reason precedent. The real precedent is `GoodsReceiptListPage.tsx:481-484`. Correct the record.

**m2 — NEW `ar` gap for the new key.** `common:permissions.readOnlyEditHint` exists in
`src/locales/en/common.json:1270` and `src/locales/fr/common.json:1287`; `src/locales/ar/common.json` has
`permissions.modules` + `permissions.actions` but not the new key. `ar` is a live registered locale
(`src/lib/i18n.ts:114-116`). Mitigating: `ar/common.json` already misses 123 of 1119 `en` keys, `fr` misses 0,
and there is no parity gate in `tools/`. _Fix directive:_ add the `ar` string or record the debt in the ar-gap ticket.

**m3 — Dead export.** `getOnboardingStatus` (`e2e/money-campaign/w2c-support.ts:262`) has no remaining caller
after the MTP-CFG-14 rewrite. Remove it.

**m4 — MTP-CFG-14 flake source.** The case inherits the describe-level `beforeEach` owner login
(`e2e/money-campaign/config-pricing.spec.ts:43-45`) and then immediately re-logs in as cashier at `:105`.
Observed 1 failure in 2 consecutive live runs, failing inside that redundant owner login
(`helpers.ts:55`, stuck on `/login` — throttle-shaped). _Fix directive:_ skip the redundant login for this case.

**m5 — Rule-17 exception undocumented.** `TopBar.test.tsx:38-59` asserts a CSS class (`/\bz-50\b/`), which
CLAUDE.md rule 17 discourages. Justified here (jsdom has no layout, so stacking is unassertable) and backed by
two live E2E behaviour assertions that I re-ran green. _Fix directive:_ add a one-line note in the test
recording the rule-17 exception and pointing at AUTH-09/I18N-04a as the behavioural cover.

**m6 — Out of scope, adjacent: `InventorySettings.tsx:162` calls `api.patch('/company', settings)`, a route that
does not exist.** Exhaustive grep of every `routes.php` finds no `Route::patch('company'…)`/`put('company'…)`;
the API base is `/api/v1` (`src/lib/api.ts:124`). The inventory-margin half of the Save button this lane just
gated fires a dead request. Pre-existing — ticket separately.

**m7 — Out of scope, same defect class:** `TaxSettingsPage.tsx:117` (`useDeleteTaxConfiguration`) drives
tax-configuration CRUD gated by `can:taxation.tax_configurations.manage`
(`apps/api/app/Modules/Taxation/routes.php:20-32`), with no FE affordance gate. Different permission, so outside
the F1 ruling — but worth a follow-up ticket.

---

## Verified correct (no finding)

- **z-50 convention:** `TopBar.tsx:132` and `:172` now match the sibling overlays byte-for-byte in class shape —
  `ViewScopePicker.tsx:58`, `CompanySelector.tsx:69`, `QuickCreateButton.tsx:129` all carry `z-50` on the same
  `absolute … rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg` container.
- **RequirePermission usage:** `routes/index.tsx:2350-2359` matches sibling settings routes exactly
  (`:2295-2346`), wrapper outside `SuspenseWrapper`. Redirect target `/dashboard` is itself ungated
  (`routes/index.tsx:502-508`), so MTP-CFG-14's FE assertion is sound.
- **Permission wiring:** `permissionsMap.generated.ts:242` `settings.update: ['admin']` matches
  `RolesAndPermissionsSeeder.php:549` (manager holds only `settings.view`/`settings.manage`).
  `usePermissions` (`src/hooks/usePermissions.ts`) has no memoization and re-reads the auth store each render —
  no staleness. `Button` spreads `...props` (`Button.tsx:58`), so `title` reaches the DOM.
- **ReceiptSettingsTab inside CompanyPage:** it calls `usePermissions()` itself (no prop/context dependency) and
  is rendered as a sibling branch at `CompanyPage.tsx:458-459`, outside CompanyPage's `<form>` (`:650`-`:972`) —
  no nested-form hazard; its own `<form>` is at `ReceiptSettingsTab.tsx:227`.
- **Rule 18 tokens:** no hardcoded colors introduced. The only className delta in the batch is the literal
  `z-50`; the four settings diffs add no classes. No interpolated variant/opacity onto a token anywhere.
- **Rule 11 i18n:** every new user-facing string goes through `t()`; namespace prefix `common:` is valid in all
  four components' `useTranslation` arrays. No raw i18n keys used as strings.
- **Data conventions:** no new queries; no `apiGet`/`apiPost` double-unwrap; the touched Dashboard query keeps
  `tenantScopedKey(['onboarding-status'])` (`Dashboard.tsx:93`); `audit:keys` reports 0 new / 0 stale.
- **E2E honesty:** the two flipped specs assert real behaviour, not tautologies — AUTH-09 requires an unforced
  click to reach `/login`; I18N-04a requires the heading to actually change to `/balance g.n.rale/i`, and that fr
  string exists (`locales/fr/common.json:213` "Balance générale", `locales/fr/finance.json:160` "Balance generale"
  — the regex tolerates both). Timeouts are consistent: all three touched specs set describe-level
  `test.setTimeout(60_000)` (`auth-session.spec.ts:14`, `i18n-fr.spec.ts:31`, `config-pricing.spec.ts:42`),
  matching the suite's convention of explicit per-describe budgets.

## Housekeeping

During this review the working tree acquired uncommitted modifications from a concurrent session
(`apps/web/e2e/money-campaign/return-notes.spec.ts`, `w2c-support.ts`, and four `features/documents` files).
They are outside this gate's scope — confirm they are not swept into the promotion. All temporary mutations I
applied (TopBar `z-50` revert, `|| !canEdit` removal x4) were restored and verified absent from `git status`.
