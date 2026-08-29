# Adversarial merge-gate register — Session H Phase 1, lane `h1-cleanup`, **M2** (round 1)

**Diff reviewed:** `ed9f69551..1d51bb4f8` (3 commits, 14 files) — brief §2 M2 (`a4′`), lens **frontend-conventions**. No amending ruling supplied.

**Independent verification run (worktree `.worktrees/h1-cleanup/apps/web`):** `pnpm exec tsc --noEmit` → clean; `pnpm vitest run src/features/partners` → 11 files / 114 tests pass; `pnpm exec eslint src/features/partners` → 0 errors, 23 warnings (2 material — see #1); `pnpm audit:i18n:local` → OK.

---

### 1. **P1 — CONFIRMED** — `CreditLimitWarning` was wired **without** the brief's mandatory rule-19 conversion
`apps/web/src/features/partners/components/B2BFieldsSection.tsx:179-182` (wiring) · `apps/web/src/features/partners/components/CreditLimitWarning.tsx:22,23,25,29,30,61,65`

The M2 section carries an explicit amendment: *"BEFORE wiring it, convert it to the shared decimal-safe helpers (`apps/web/src/lib/decimal`) and add boundary tests … If that conversion is not small, wire nothing and record it in `owes_parent`."* Neither branch was taken — the component was wired **unchanged** (`git diff ed9f69551..HEAD` does not touch `CreditLimitWarning.tsx`), and `owes_parent` was not written in `docs/handoff/progress/session-h-phase1.progress.yaml`.

The component still does `parseFloat(creditLimit)` / `parseFloat(outstandingBalance)` (`:22-23`), float comparison `outstanding >= limit` (`:30`) and float division `(outstanding / limit) * 100` (`:29`) on `decimal(*,3)` money — CLAUDE.md rule 19. The repo's own guard confirms it: `pnpm exec eslint` emits `precision/no-parsefloat-on-money` at `CreditLimitWarning.tsx:23` and `:61`. Those two lines were dormant warnings before M2 (zero importers); M2 put them on the render path.

**Failure scenario:** PharmaBio Tunisie (TND, 3-decimal). Partner `credit_limit = '1000.000'`, `receivable_balance = '1000.000'` → both parse to the same double, `isExceeded` true — fine. But the same code path is one float rounding away from the wrong side at millime granularity, and the conversion the brief demanded (`bccomp` at `apps/web/src/lib/decimal.ts:115`) is a two-line change — the "not small, wire nothing" escape hatch does not apply.

### 2. **P2 — CONFIRMED** — TND balances are rendered rounded to 2 decimals, with no currency
`apps/web/src/features/partners/components/CreditLimitWarning.tsx:61-68`

`toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })` on a 3-decimal currency, and no currency symbol/code at all. `formatCurrency` exists at `apps/web/src/lib/formatCurrency.ts:19` and `apps/web/src/lib/decimal.ts:179`, and `PartnerDetailPage.tsx:473` already uses a currency-aware formatter for the very same balance.

**Failure scenario:** partner with `receivable_balance = '1200.125'` TND and `credit_limit = '1000.500'` renders *"Outstanding: 1,200.13 / Limit: 1,000.50 (120% used)"* — both figures wrong at the millime, and neither labelled TND, inside a credit-control alert.

### 3. **P2 — CONFIRMED** — required boundary tests for the wired warning are absent
`apps/web/src/features/partners/PartnerForm.test.tsx:213-224`

The brief names three: *equal-to-limit, one millime over, 3-decimal TND*. Exactly one test exists (`credit_limit '100.000'` vs `receivable_balance '125.000'` → "Credit limit exceeded") — the easy case, 25% over. None of the three named cases is covered, so the invariant that actually motivated the amendment is untested.

### 4. **P2 — CONFIRMED** — the warning compares against gross `receivable_balance`, ignoring `credit_balance`
`apps/web/src/features/partners/PartnerForm.tsx:701`

`outstandingBalance={partner?.receivable_balance ?? null}`. This repo has a canonical net-exposure helper — `getNetBalance()` at `apps/web/src/features/partners/partnerNetBalance.ts:11-29` (`bcsub(receivable, credit)`) — and `PartnerDetailPage.tsx:482,540` explicitly nets `credit_balance` against `receivable_balance`, with a comment warning about exactly this trap.

**Failure scenario:** customer with `credit_limit '1000.000'`, `receivable_balance '1200.000'`, `credit_balance '1500.000'` (customer is in credit after an advance). The B2B form shows a red **"Credit limit exceeded"** alert although net exposure is −300.000 — a false block signal on a prepaying customer, and it disagrees with the balance the detail page shows for the same partner.

### 5. **P2 — CONFIRMED** — no red-first evidence for M2
`a861f7dbe` (M2.1) ships implementation + all five new/updated tests in a single commit; commit bodies are empty (`git log --format=%B` → subject only); there is no M2 report and no red-run record in `docs/handoff/progress/session-h-phase1.progress.yaml:37-45` (`fix_rounds: 0`, `verdict: null`). Brief §1 requires TDD red-first; the standing gate requires each behavioural change to have a test *shown failing before the fix*. Nothing in the commits or artifacts demonstrates that. (Tests do pass now — that is not the same claim.)

### 6. **P2 — CONFIRMED** — Arabic: `Nature` is indistinguishable from `Type`, label *and* error
`apps/web/src/locales/ar/sales.json` — `partners.type` = `"النوع"`, new `partners.nature.label` = `"النوع"`; `partners.validation.typeRequired` = `"النوع مطلوب"`, new `partners.validation.natureRequired` = `"النوع مطلوب"` (byte-identical).

**Failure scenario:** an `ar` user on `/sales/customers/new` sees two adjacent required selects both labelled **النوع**; submitting empty shows **النوع مطلوب** with no way to tell which one failed, and `getByLabel`-style disambiguation is impossible for a screen reader too. The *label* string was prescribed by the brief (escalate if the owner wants it kept); the *duplicated validation message* was authored here and was not.

### 7. **P3 — CONFIRMED** — the renamed field kept the old placeholder
`apps/web/src/features/partners/PartnerForm.tsx:546` still renders `t('sales:partners.b2b.selectCategory')` = "Select category" / "Sélectionner une catégorie". Visible in the milestone's own evidence: `.playwright-mcp/session-h/m2/m2-legacy-company-visible.png` shows a field labelled **Nature** whose placeholder reads **Select category**.

### 8. **P3 — CONFIRMED** — `showB2BFields` ignores `customer_category === ''`
`apps/web/src/features/partners/PartnerForm.tsx:257-258`. On the edit form of a legacy NULL partner carrying B2B data, choosing the blank placeholder sets `''` (not `null`), so the block hides — but RHF keeps the values (`shouldUnregister` defaults false) and `onSubmit` (`:427-447`) still posts `vat_number` / `credit_limit` / `company_legal_name`. Hidden-but-submitted; data is not lost, so cosmetic rather than corrupting.

### 9. **P3 — CONFIRMED** — the supplier default leaks onto the supplier *edit* route
`apps/web/src/features/partners/PartnerForm.tsx:136,220`. `isSupplierContext` is `pathname.includes('/purchases/suppliers')`, which also matches `/purchases/suppliers/:id/edit`, so `defaultValues.customer_category = 'business'` applies there. Normally overwritten by `reset(partner)` (`:339-342`); if the detail query errors or `hasTenantScope` is false, the form sits showing **Company** for a partner stored as NULL/individual, and a save would persist it.

### 10. **P3 — CONFIRMED** — the committed screenshots do not show the thing under test
Both PNGs in `.playwright-mcp/session-h/m2/` are **1280×720** despite `fullPage: true` (`m2-partner-nature.spec.ts:135-138,145-148`) — the shell uses an inner scroll container, so "full page" is one viewport. Neither image contains the B2B section, i.e. neither the *visible* nor the *hidden* claim is visually evidenced. The Playwright `toBeVisible()` / `toBeHidden()` assertions carry the real proof; the artifacts required by brief §0.1 do not.

### 11. **P3 — CONFIRMED** — no AR strings for the newly-surfaced credit-limit alert
`ar/sales.json` has **no `partners.b2b` block at all**, so `creditLimitExceeded` / `creditLimitApproaching` / `creditLimitUsage` resolve through `fallbackLng: 'en'` (`apps/web/src/lib/i18n.ts:482`). Not a raw-key bug, but M2 makes these strings user-visible for the first time and brief §1 asks for AR keys where `ar/*.json` exists.

### 12. **P3 — CONFIRMED, non-regressive** — out-of-scope drive-bys
`partnerDetailInvalidationPredicate` (`apps/web/src/features/partners/_invalidation.ts:9-26`) and the M1 e2e helper extraction (`e2e/session-h/helpers.ts`, `m1-data-shape-drift.spec.ts`) are not in M2's stated scope. I verified the predicate is not a regression: every partner-detail key in the app is `tenantScopedKey(['partner', id])` → length 4 (`PartnerForm.tsx:278`, `PartnerDetailPage.tsx:173`, `PartnerPicker.tsx:145`), all matched; the length-2 prefix invalidations elsewhere (`RecordDepositModal.tsx:105`, `usePartnerBalanceRealtime.ts:40`, `AddVehicleModal.tsx:159`) are separate call sites and untouched.

### 13. **P3 — CONFIRMED** — the browser gate mutates demo data with no cleanup
`m2-partner-nature.spec.ts:88,102-114` creates a real partner (`Session H M2 Individual ${Date.now()}`) in PharmaBio Tunisie on every run and never deletes it, while `beforeAll` paginates **all** customer partners (`:39-56`). Unbounded demo-data growth and a spec that gets slower each execution.

---

## Bypasses I tried that FAILED (no defect found)

- **Submit the create form with no nature:** selecting the blank placeholder yields `''`, `null` if untouched — the zod refine at `PartnerForm.tsx:165-168` rejects both. Vitest `PartnerForm.test.tsx:146-155` confirms `apiPost` is never called. No bypass.
- **Force a 422 by posting `customer_category: null` on a legacy edit:** `CreatePartnerRequest.php:69` and `UpdatePartnerRequest.php:138` are both `nullable` + `Enum`, and `onSubmit` normalises `'' → null` at `PartnerForm.tsx:429`. No bypass.
- **Break the "walk-in stays hidden" invariant via a DB default:** `credit_limit` is `nullable()` with no default (`apps/api/database/migrations/tenant/2026_03_11_600000_add_b2b_fields_to_partners.php:18`), so a walk-in has all four signals NULL → hidden. (Residual only: a stored `'0.000'` would count as a signal, but the brief's wording is literally "non-empty" — not a finding.)
- **Find a downstream semantic change from defaulting new suppliers to `business`:** `Partner::isB2B()` (`Partner.php:229-232`) has **no callers**, and `FiscalPayloadConstraintValidator.php:1943` only requires `business` for `b2b_facture_draft_requested`. Sealed-payload shape unchanged (`customer_category` remains an optional nullable string, `:707/1732/1958`). Shape-neutrality (§1) holds. No defect.
- **Type / test / lint breakage:** `tsc --noEmit` clean, 114/114 vitest pass, eslint 0 errors. No defect beyond #1.

## Verification I could NOT perform
The M2 Playwright gate could not be re-executed independently: the worktree dev server on **:5174 is down** (`curl` → `000`; :5173 and :8010 are up but serve the *main* checkout, i.e. not this code), and test 1 writes to the demo tenant, which is outside a read-only review. The gate is therefore accepted on the committed spec + the two screenshots only — see finding #10 for what those screenshots do and do not prove.

## Gate summary
The milestone's core behaviour — nature required on create, supplier default, NULL+signal heuristic — is implemented as specified, typed, tested and browser-gated. It fails on the one part of M2 that the brief singled out with an explicit amendment: `CreditLimitWarning` was wired with its float money math intact and without the named boundary tests, taking neither of the two paths the brief allowed. Findings 1–4 all live in that one component and can be fixed together in one round.

VERDICT: CHANGES-REQUIRED
