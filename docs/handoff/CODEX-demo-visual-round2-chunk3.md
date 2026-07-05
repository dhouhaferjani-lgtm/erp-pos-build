# CODEX HANDOVER — Demo-fix Round 2, Chunk 3 (i18n + currency, 4 findings)

> Hand this whole file to Codex desktop. Work ONE finding AT A TIME, TDD where testable, with a
> mandatory Opus review after each. These are demo-credibility issues on a Tunisia/TND/French +
> Arabic tenant — pervasive but mostly mechanical.

## Environment
- **Worktree/branch:** `/Users/houssamr/Projects/syneriva/apps/erp.demo-fixes` on
  `fix/demo-visual-round2` (holds chunks 1–2). Frontend-only (`apps/web`). Work here, do not push.

## Global rules + Opus gate — IDENTICAL to Chunk 1/2
Apply the "Global rules" + "Opus self-review gate" from
`docs/handoff/CODEX-demo-visual-round2-chunk1.md` verbatim. Key ones here: all user-facing text via
`t()`; **never `parseFloat`/`Number()` on money** — use the canonical `formatCurrency`/
`formatQuantity`; design tokens for colors; tests by path only; one commit per finding (Conventional
Commits, no push); scope discipline. Frontend tests: `cd apps/web && npx vitest run src/<path>`,
then `pnpm typecheck` + `pnpm lint`. Opus gate command per finding same as Chunk 1 (add lens: "money
display consolidated on ONE formatter; no i18n key rendered raw; RTL/Arabic strings resolve").

---

## Finding 1 — 🔴🌐 Unify currency + number formatting app-wide (frontend) — DO THIS FIRST
The single most pervasive issue. Symptoms: "TND" vs "DT"; comma-decimal vs dot; **EUR (€) leak** in
invoice Related-Documents chain; Anglo format on Trial Balance (`6,607.60`); `TND 0.000` on chart
of accounts; the **same invoice** mixing "15,600 DT" (lines) and "15.600 TND" (totals).
**ROOT CAUSE — there are THREE `formatCurrency` implementations** (worse than first thought):
`apps/web/src/lib/format.ts:21` (Intl.NumberFormat, locale-aware), `apps/web/src/lib/decimal.ts:178`
(Big.js, no locale), and `apps/web/src/lib/formatCurrency.ts:18` (+`formatCurrencyCompact:56`).
Screens mix them → the TND/DT, comma/dot, and EUR inconsistencies.
**Fix:** pick ONE canonical locale-aware formatter (recommend the `format.ts` Intl-based one; decide
the TND display: "TND" suffix, French comma-decimal, 3-dp millimes). Consolidate: make the other two
modules re-export/delegate to the canonical one (or replace call sites), and grep every call site of
all three to route through it. Kill any hardcoded `€`/`DT`/`$` literals in money rendering. Keep
`decimal.ts` bcmath *math* (bcadd/etc.) — only its *formatter* consolidates.
**Test:** a formatting unit test asserting TND renders identically ("15,600 TND" style) from what
were previously the divergent call paths; a regression test that the invoice Related-Documents chard
no longer emits `€`. This is large — commit in coherent steps if needed, but land as the "#21" unit.

## Finding 2 — 🌐 Localize Tunisia labels (frontend)
On a TN tenant: **"VAT Number"/"Tax ID / VAT Number" → "Matricule Fiscal"**; registration-number
placeholder `123 456 789 RCS Paris` → RNE/Registre de Commerce; phone placeholder `+33 …` → `+216`;
postal `75001` → TN; **timezone default `Europe/Paris` → `Africa/Tunis`**; add a **gouvernorat** field
to TN addresses. **Seam:** the company form (`/settings/company`), customer/partner form
(`/sales/customers/new`), and the timezone default. Gate the TN-specific labels/placeholders on the
company country (don't hardcode for all tenants). Use `t()` keys (add TN variants), not literals.
**Test:** with country=TN the VAT label resolves to "Matricule Fiscal" and the timezone default is
`Africa/Tunis`; a non-TN tenant is unchanged.

## Finding 3 — 🌐 Raw i18n keys rendered across the app (frontend)
Missing translation keys render literally. Catalogued (EN mode): the **entire `openingBalances.*`
namespace** (`/settings/opening-balances`), `actions.back` (invoice detail), `common.actions`→
"COMMON.ACTIONS" (POS terminals), `fields.total:` (repositories header), `TABLE.ACTIONS` (journal
entries), `PURCHASEORDERS.RECEIVED` + `documents.supplier` (PO detail), `viewAll` (counting),
`parapharmacy_metadata.requires_consultation` (product checkbox aria), plus **key-returns-object
errors** `invoices.paymentHistory` and `ACTIONS (EN)` (pos/tables). **Fix:** add the missing keys to
the EN/FR/AR locale files (`apps/web/src/locales/{en,fr,ar}/*.json`) under the right namespaces; for
the two "returned an object instead of string" errors, the code references a namespace/object path
where a leaf string key is expected — fix the `t()` call to a leaf key (and add it). Verify the
i18n namespace is registered (editing `i18n.ts` touches 3 places: import, resources, ns array).
**Test:** render the affected components and assert no raw-key text (e.g. `getByText` finds the
translated label, not `openingBalances.progress.title`); the `paymentHistory` section renders without
the object error.

## Finding 4 — 🌐 Arabic coverage gaps (frontend)
RTL layout works, but English leaks into AR content: "172 **customer** المجموع", "إضافة
**Customer**", "**Has outstanding balance**" filter, "**BALANCE**" column header; the AR `<title>`
falls back to English ("Partners"); header control aria-labels stay English. **Fix:** add the
missing AR translations (including pluralized/interpolated strings and the column header), and make
the document `<title>` + header aria-labels use `t()` so they localize. **Test:** in AR locale the
customers page header/filter/column strings resolve to Arabic (no English leak) and the page title
uses the translated string.

---

## Final report (to orchestrator)
Per finding: root cause (1 line), files changed, test commands + pass output, Opus verdict (SHIP),
commit SHA (do not push). For #21 list the formatter call-site consolidation (which of the 3 impls
became the canonical one and how the others delegate). Flag out-of-scope observations. After Chunk 3
the orchestrator verifies + merges; that closes the web demo-fix round (Theme 6 cleanup #25–31 is
optional/lower-priority and can be a separate small pass).
