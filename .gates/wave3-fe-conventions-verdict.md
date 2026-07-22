# Wave 3 FE Conventions Verdict — round 2 (`feat/multi-location` @ e1c471f0f)

> Controller-run 2026-07-22. Reviewer: frontend-conventions-reviewer (Opus). Round-1 context: `.gates/gate-3b-verdict.md` FE findings.

Worktree `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`. Reviewed the FE portion of `git diff origin/dev...e1c471f0f`. All commands run from `apps/web` in the worktree.

## Verification evidence (re-run, not trusted)
- `pnpm typecheck` — **GREEN** (`tsc --noEmit`, no output). The round-1 RED is fixed.
- `pnpm lint` — **0 errors**, 6467 pre-existing warnings. `audit:keys` (tanstack) 0 new; `audit:design-system` 746 acknowledged / **0 new**; eslint-rules RuleTester 10/10. Note: lint has **no i18n-completeness gate** (`audit:keys` is the tanstack-key audit), so the missing-key finding below is invisible to CI.
- `pnpm vitest run` on the 6 touched test files — **41 passed / 41**. Hung workers killed (`pkill -f 'node (vitest'` → 0 remaining).

## Round-1 FE finding disposition
1. **[FIXED] Typecheck RED (`TreasuryOverviewPage.test.tsx:261`).** The `upcomingLine` factory now sets `location_id: null` (TreasuryOverviewPage.test.tsx:275), and `generated.d.ts:275` regenerated `UpcomingPaymentLineData.location_id: string | null` from the transformer. `tsc` clean.
2. **[FIXED] Tasks 10 & 12 — bucket rendering + caveats + view-scope.**
   - `buckets_by_location` now rendered: AgedPayablesPage.tsx:79-91, AgedReceivablesPage.tsx:80-92, TreasuryOverviewPage.tsx:280-294, InstrumentListPage.tsx:340-349.
   - `finance:reports.defaultAttributedCaveat` rendered on all three finance surfaces (AgedPayablesPage.tsx:81, AgedReceivablesPage.tsx:82, TreasuryOverviewPage.tsx:282); `treasury:instruments.originGrainCaveat` rendered at InstrumentListPage.tsx:340.
   - `FinanceHubPage` adopts `useViewScope` (FinanceHubPage.tsx:167) and propagates scope into deep links via `scopedHref` (FinanceHubPage.tsx:169-174), asserted by FinanceHubPage.test.tsx:91-97.
   - FE tests added for every Wave 3 surface and are **meaningful** (assert `Store A` bucket + caveat, not smoke): AgedPayablesPage.test.tsx:90-96, AgedReceivablesPage.test.tsx:90-96, TreasuryOverviewPage.test.tsx:215-216, InstrumentListPage.test.tsx:185-190, CashPositionWidget.test.tsx:97-104, FinanceHubPage.test.tsx:90-97.
3. **[FIXED] CashPositionWidget double-render.** By-location is now its own labelled section with `border-t` divider and header (CashPositionWidget.tsx:62-77), not stacked into the by-type list; test asserts the separate section (CashPositionWidget.test.tsx:97-104) and that `250.000` now appears twice by design (CashPositionWidget.test.tsx:87).
4. **[PARTIAL→see MAJOR] ar `instruments.unattributed`.** Now present (ar/treasury.json new `instruments` block). But a *new* mirror-image gap was introduced (below).

## New findings on touched FE code

- **[MAJOR] CashPositionWidget.tsx:65 — `t('cashWidget.byLocation')` (ns `treasury`) has no key in `en` or `fr`; only `ar` was added.** Verified: `en/treasury.json` and `fr/treasury.json` `cashWidget.byLocation` → `undefined`; `ar/treasury.json` → present. In the default/fallback locale (`en`) and in `fr` the section header renders the raw literal `cashWidget.byLocation`. This is exactly the i18n-completeness class the round-1 review flagged, inverted. The FE test masks it because i18next returns the key in test mode (`getByText('cashWidget.byLocation')` passes regardless). **Fix:** add `cashWidget.byLocation` (e.g. "By location" / "Par emplacement") to `en/treasury.json` and `fr/treasury.json`.

- **[MINOR] en/fr/ar `treasury.json` `instruments.byLocation` is a dead key.** Added in all three locales but no code references `instruments.byLocation` (the location grid aria-labels with `instruments.location`). **Fix:** drop the unused key or wire it as the grid header.

- **[MINOR] AgedPayablesPage.tsx:79 / AgedReceivablesPage.tsx:80 — bucket `<section>` uses bare `border` (no border-color token), inconsistent with the sibling surfaces.** TreasuryOverviewPage.tsx:281 and CashPositionWidget use `borderColors.light`; the two aged-report sections use `className="mb-6 rounded-lg border p-4"`. Not a lint/audit error (design-system audit reports 0 new) and visually harmless, but inconsistent with the token convention for the same UI element. **Fix:** apply `borderColors.light` to match.

- **[MINOR] types.ts:340 / InstrumentListPage.tsx:90 — `buckets_by_location` shapes are hand-declared FE types, not transformer-generated.** `UpcomingPaymentsData` is augmented with an inline `UpcomingLocationBucket`, and `MaturityResponse.meta.buckets_by_location` is a local interface, because the backend DTOs for these two endpoints don't emit the buckets into `generated.d.ts`. Handled defensively as optional and clearly marked FRONTEND-ONLY, so acceptable, but it means these two payload shapes have no generated-type guard. **Fix (backend, out of FE scope):** add the bucket arrays to the source DTOs so `typescript:transform` covers them.

## Conventions confirmed clean
- No `any` in touched code; query shapes typed. No `parseFloat`/`Number()` on money — money renders via `formatReportCurrency` (AgedPayablesPage.tsx:30, TreasuryOverviewPage.tsx:171) and `formatCurrency` (InstrumentListPage.tsx:179, CashPositionWidget.tsx via `formatCurrency`).
- TanStack keys are location-scoped so scope changes refetch: `locationScopedKey([...], scope)` in useAgedPayables.ts:17, useAgedReceivables.ts:17, useUpcomingPayments.ts:15, InstrumentListPage.tsx:134,153. `effectiveLocationIds` sent as `location_ids[]` query params, not headers (api.ts:192-193,209-210,228; InstrumentListPage.tsx:142,161).
- `apiGet` single-unwrap preserved (api.ts:227 for upcoming-payments); paginated instrument list uses raw `api.get` correctly (InstrumentListPage.tsx:145).
- `generated.d.ts` diff is transformer-shaped (new `LocationReportBucketData`, `location_id` on `UpcomingPaymentLineData` and PO line, `buckets_by_location` on aged DTOs) — no hand-edit anomalies.
- All other new user-facing keys present in en/fr/ar: `finance:reports.locationBreakdown`, `finance:reports.defaultAttributedCaveat`, `treasury:instruments.{location,unattributed,originGrainCaveat}`, `cashWidget.unattributed`.

## Blocking item
One MAJOR: the `cashWidget.byLocation` header renders as a raw untranslated key in `en` and `fr` (CashPositionWidget.tsx:65). It is a genuine user-facing defect in the primary locale, in the exact i18n-completeness category this round was asked to close, and CI lint does not catch it. Trivial to fix (two locale entries). Everything else is FIXED and green.

VERDICT: REJECT
