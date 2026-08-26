# Ticket: L4 web follow-ups — one locale source, the surviving EUR defaults, and the lying store stubs

**Filed by:** the L4 currency-blind-emission fix lane, at the request of the WEB merge gate
(`docs/superpowers/reviews/2026-08-05-l4-web-gate.md`, verdict APPROVE-WITH-FIXES).
**Date:** 2026-08-05 · **Branch that raised it:** `fix/l4-currency-emission`
**Severity:** P2/P3 — **none of it is launch-blocking for tenant #1**, whose company locale is `fr` and whose
currency is `TND`, so every item below is either latent or cosmetic on that tenant.

L4 made money formatting currency-driven in `apps/web/src/lib/format.ts` and fixed the two call sites the tickets
named (W-6 D6's `FinanceWidget`, W-7 F-7's `DocumentTotals`). The gate's blocker (C1, the un-flipped
`withholding.spec.ts` tripwire) and its two MUST items (I5 defensive read, the comment corrections) landed in the
lane. What is left is recorded here.

---

## I1 (P2) — two locale-resolution sources in one viewport

`src/hooks/useCurrency.ts:49` · `src/features/finance/pages/reportPageUtils.ts:30-36` · `src/lib/format.ts`

`useCurrency` resolves the locale from the **currency** (`getLocale(currency)`, e.g. `TND → fr-TN`), which is what
`lib/format`'s own docblock states as the contract. `formatReportCurrency` — used by the four StatCards on
`/finance/overview`, the very page D6 named — resolves it from **`company.locale`**:

```ts
// reportPageUtils.ts:30-36
const locale = company ? company.locale.replace('_', '-') : undefined
return formatCurrency(amount, { currency: company?.currency ?? 'EUR', ...(locale ? { locale } : {}) })
```

They coincide for every seeded company (all French locales; `Intl` renders `fr`, `fr-FR` and `fr-TN` identically for
grouping and decimal mark), which is why `MTP-GL-27` cannot see it. They **diverge** for any non-French locale — an
Italian EUR company (`it_IT` → `1.000,00 EUR` on the StatCards vs `fr-FR` `1 000,00 EUR` on the widget tiles), or an
Arabic-locale tenant. **Italy is a declared target market** in `CLAUDE.md`. This is F-7's own failure mode, latent,
on the page D6 named.

**Fix:** pick ONE source. Either route the widget through `formatReportCurrency(value, currentCompany)`, or make
`useCurrency` honour `company.locale` and delete `formatReportCurrency`'s divergent path. Until then the claim "the
widget formats the way its StatCard sibling does" must not be recorded as closed — the in-code comment at
`FinanceWidget.tsx` now says so explicitly.

Distinct from the API-side `docs/superpowers/tickets/2026-08-05-l4-mixed-currency-report-scale.md`, which is about
the emission *scale*, not the web locale source.

## I3 (P2) — the D6 root pattern survives in `lib/decimal.ts`, and it is LIVE

`src/lib/decimal.ts:179-183` still declares
`formatCurrency(amount, includeCurrency = true, currency: string = 'EUR', scale?)` — the identical hardcoded-EUR
default D6 is about. W-7 F-7's ticket says *"Same family as W-6's D6 … different helper. A fix should sweep both."*
The lane swept `lib/format`, not `lib/decimal`.

**Live consequence:** `src/features/inventory-counting/components/ReconciliationTable.tsx:226`
`formatCurrency(item.opening_unit_cost, false)` → EUR default → **scale 2 on a TND opening unit cost**, i.e. the
millime is truncated on the inventory-reconciliation surface. (`includeCurrency: false`, so the visible defect is the
scale, not a wrong label.) The gate checked every caller of that helper: this is the **only** one that omits the
currency.

**Not fixed in-lane** — deliberately, per scope discipline: `lib/decimal` is a different helper on a different
surface from the two the L4 tickets name. It is a one-line fix either way (give it the same `activeCurrency()`
default, or pass the currency at the call site).

## I5 residual (P3) — four `useCompanyStore` stubs are still lying doubles

The lane made `lib/format`'s store read defensive
(`useCompanyStore.getState?.()?.getCurrentCompany?.()?.currency ?? FALLBACK_CURRENCY`), so none of these can crash
the formatter any more. They remain **dishonest** test doubles: they omit methods the production store exposes, so
they silently diverge from the thing they stand in for.

| Stub | Missing |
|---|---|
| `src/features/expenses/hooks/usePayExpense.test.tsx:33-36` | `getState` |
| `src/features/owner-dashboard/components/__tests__/DueThisWeekWidget.test.tsx:10-12` | `getState` |
| `src/features/owner-dashboard/hooks/__tests__/useOwnerReports.test.ts:15-18` | `getState`, `getCurrentCompany` |
| `src/features/treasury/statements/StatementReconciliationChips.test.tsx:14` | `getState` |

**This is not theoretical.** `usePayExpense.test.tsx` and `useOwnerReports.test.ts` **already fail on `dev`**, and the
error is exactly this class — `TypeError: useCompanyStore.getState is not a function`, thrown from
`src/stores/viewScopeStore.ts:99` (a *different* consumer of the same missing method), reached via
`features/locations/hooks/useViewScope.ts`. Both files are in this branch's pre-existing red set, confirmed by a
stash-and-rerun against the base commit. Making the four stubs faithful — the shape the lane already applied to
`PriceInputWithMargin.test.tsx:44-60` — very likely fixes two red suites as a side effect.

## I2 residual (P2) — `DocumentTotals` renders the COMPANY's currency, not the document's

`Document.currency` exists (`src/types/document.ts:62`), but all four detail pages pass
`currency={currentCompany?.currency ?? 'EUR'}` (`InvoiceDetailPage.tsx:475`, `QuoteDetailPage.tsx:350`,
`SalesOrderDetailPage.tsx:446`, `CreditNoteDetailPage.tsx:313`). A cross-currency document (a EUR invoice under a TND
company) renders in the TND scale with `TND` appended to a EUR total.

**Pre-existing and unchanged by L4** — the old `getDecimals(currency)` read the same prop. The lane corrected the
comment rather than the behaviour, because switching those four props alone is *not* the fix: the outstanding
callout, the payment-history section and the payment summary on the same pages also read
`currentCompany?.currency`, so a partial switch would put two currencies in one viewport — D6's own failure mode,
newly introduced. This needs one page-wide decision (does a document render in its own currency, and if so what
happens to the payment surfaces attached to it), not four line edits.

## N3 (P3) — amend the D3 wording in the W-6 ticket

`docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md:17` states D3 as *"render at scale 2 …, not the report
scale 4 every other report uses"*; the body (`:245-249`) also names the TND currency scale of 3. L4 closed it at the
**currency** scale (3), which is what W-8 F-3 (P1) requires, but P&L and balance sheet still emit at the fixed report
scale 4 — so the *sibling-report* inconsistency D3 names is **reshaped, not closed**. The tripwire flip at
`finance-reports.spec.ts:475-489` discloses this honestly in-code, but the ticket is the source of truth and should
be amended: D3 closed at the currency scale; the 3-vs-4 cross-report gap re-recorded as its own item.

## N4 (P3) — two assertions pin the FALLBACK path, not a company-driven render

`src/features/treasury/components/ToleranceSettingsDisplay.test.tsx:98,142` (`'0,50 max'`, `'10,00 max'`) are correct
for the new behaviour, but no company is seeded, so they exercise the EUR/fr-FR *fallback*. A seeded-TND case would
be the stronger pin and would catch a regression in `activeCurrency()` itself.

## N5 (P3) — a hardcoded `$` next to a French-grouped number

`src/features/inventory/components/pricing/PriceInputWithMargin.tsx:137,174` render `${formatNumber(v, decimals)}` —
a literal dollar sign with company-derived decimals. Pre-existing, but L4's locale change turns `$1,234.57` into
`$1 234,57` for a TND company: a mixed shape more obviously wrong than before. Same at
`src/components/organisms/ProductPricingCard/ProductPricingCard.tsx:62` (which additionally pins `'en-US'`
explicitly). One-line fix each: use `useCurrency().format`.

## N6 (P3) — money typed as `number`

`src/features/documents/components/DocumentTotals.tsx:18` `balanceDue?: number`, compared at `:90` and formatted at
`:162`. Rule 19 says money is a string end to end. Pre-existing, adjacent to the edited lines.

## Recorded, not owned by this ticket

~20 pre-existing red web test files on `dev` (query-key / URL-param drift from `de0f49a1f "Phase 4.0.0: Emit
location buckets from report DTOs"`, plus a `CompanyConfigProvider` wrapper break in `treasury.test.tsx`). Measured
by the gate: base `7d8e6c861` = 22 failed files / 48 failed tests; this branch = 20 / 46, identical failing-file set
modulo three parallelism flakes that pass in isolation on both. Worth its own ticket.

## N7 (P3) — the proforma box's closure risk under the pre-existing company-scale formatting (C-F0w fiscal gate r1 F-6)

The page-wide currency issue this ticket already tracks (N6 above / the `DocumentTotals.tsx:78-88` docblock:
company currency formats a document that may carry a different one) has a new wrinkle since C-F0w. `ProformaPresenter`
and `ProformaGrossAmountResolver` scale every proforma figure at the **document's** currency
(`ProformaPresenter.php:40-41`, `ProformaGrossAmountResolver.php:159`), while `InvoiceDetailPage.tsx:437,706` and
`CreditNoteDetailPage.tsx:155,354` format them at the **company's** (`currentCompany?.currency ?? 'EUR'` inside
`displayLineAmount`, and the `currency` prop handed to `DocumentTotals`).

The proforma box's rows are supposed to **close** (Σ gross lines + stamp duty − discount = estimated total). Two
independent `Big.js` half-up roundings — one at the document's scale server-side, one at the (possibly coarser)
company scale client-side — can leave a residual the page never explains, on a box whose entire point is to be a
trustworthy PDF mirror. Not a defect today (no case observed), but worth watching once mixed-currency documents are
common.

Also note the codebase now holds BOTH conventions: this lane fixed the same class of bug one level down —
`CreditNoteDetail.tsx:41-42` now formats at `creditNote.currency` (was `parseFloat(amount).toFixed(companyDecimals)`,
a truncated millime on TND) — while the two live proforma pages still format at the company's currency. A future
page-wide fix should reconcile both call sites, not just one.

**No code change requested here** — fixing only the proforma path would put two currencies in one viewport
(company-currency definitive figures beside document-currency proforma figures on the same page), the exact failure
mode N6/D6 already describe. Fix page-wide or not at all.

Gate record: `docs/superpowers/reviews/2026-08-26-sc-f0w-gate-r1-fiscal.md` F-6.

## References

- Gate record: `docs/superpowers/reviews/2026-08-05-l4-web-gate.md` (findings I1–I5, N1–N6; Q1/Q2/Q3 rulings).
- API sibling gate + its ticket: `docs/superpowers/reviews/2026-08-05-l4-api-precision-gate.md`,
  `docs/superpowers/tickets/2026-08-05-l4-mixed-currency-report-scale.md`.
- Source tickets: `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` (D6, D3),
  `docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md` (F-7).
- Lane spec: `docs/handoff/PLAN-p0-fix-lanes-pre-production-2026-08-05.md` §2 L4.
