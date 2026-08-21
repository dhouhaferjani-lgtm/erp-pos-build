# O-28 — "Today's Sales" is NET, EXCLUDING REFUNDS: surface census and scope decision

**Status:** audit COMPLETE; headline fixes LANDED on `fix/o28-todays-sales-net-labeled`.
**Ruling:** LEDGER row O-28 (owner, 2026-08-21) — *every "Today's Sales" headline figure is
NET, EXCLUDING REFUNDS, and must be LABELED accordingly.*
**Blind-count precondition:** ticket `2026-08-17-today-sales-blind-count-derivation.md`
resolved to **option 4** (accept the cross-navigation residual risk). Today's Sales is
therefore NOT a concealed surface, and none of the labelling below leaks a concealed figure.
Verified in code: `TodaySalesPanel.tsx` has no blind-count coupling, and
`docs/handoff/reviews/sv-stage1/M4-sv10-leak-audit.md` classifies `/sales` as an accepted
sibling path rather than a sealed one.

---

## 1. Surface census

Every surface that presents a "today's sales"-type headline, with derivation verdict and
label. Line numbers are as of `08e68ab41` unless the row says otherwise.

| # | Surface | Derivation | Verdict | Label |
|---|---|---|---|---|
| 1 | **POS `/sales` headline** — `apps/pos/src/components/pos/TodaySalesPanel.tsx` | client reduce over `fetchShiftReceipts` | ❌ **GROSS — FIXED**: now `gross − Σ\|return\|`, both arms filtered `!is_voided && !is_training`, intermediates at `decimals + 1` | `pos:reports.totalSales` "Total Sales" → `pos:reports.netSalesExclRefunds` "Net sales (excl. refunds)" (en+fr; the device has no ar tree) |
| 2 | **Owner dashboard headline KPI** — `apps/web/src/features/owner-dashboard/components/SalesSummaryCards.tsx` | bound `data.grossSales` + `delta.grossSalesPct` while the service's correct `netSales` went unused | ❌ **GROSS by presentation — FIXED**: binds `netSales` and a new `delta.netSalesPct` | `reports:ownerDashboard.kpi.totalSales` → `kpi.netSales` (en+fr+ar; the `reports` ar bundle is a straight swap, so the ar string is authored, not aliased) |
| 2b | Owner dashboard "Average basket" tile — same component | `averageBasket = gross / saleCount` (`OwnerSalesSummaryService:52`) | ⚠️ gross per sale, unchanged — a return is not a member of the population being averaged | `kpi.avgBasket` → "Average basket (gross)" (en+fr+ar), matching the POS `avgSaleTicket` treatment |
| 3 | `OwnerSalesSummaryService.php` (feeds #2) | `SUM(CASE receipt_type='sale' THEN total)`; returns via per-row `ABS()` **inside** the SUM; `netSales = gross − returns` | ✅ already correct — **extended** with `delta.netSalesPct` | — |
| 4 | Branch leaderboard today rows — `BranchLeaderboard.tsx` → `SalesReportService::salesByLocation` | `SUM(pos_receipts.total)` under a `receipt_type='sale'` filter | ⚠️ **GROSS BY SCOPE DECISION** (§2) — derivation unchanged, now **labelled** | `branchLeaderboard.title` → "Branch leaderboard (gross sales)" |
| 5 | Sales-trend chart "Today" series — `SalesTrendChart.tsx` | same `by-location` data as #4 | ⚠️ same | `salesTrend.title` → "Sales over time (gross)" |
| 6 | Sales-by-location chart — `SalesByLocationChart.tsx` | same `by-location` data as #4 | ⚠️ same | `salesByLocation.title` → "Sales by Location (gross)" |
| 7 | Top SKUs / revenue-by-category / owner payment breakdown — `SalesReportService` | sign-blind `SUM` + `receipt_type='sale'` filter | ⚠️ same class — and they ARE today-scoped: `OwnerDashboardPage` defaults `from: today, to: today` and feeds all four hooks the same `dateParams`. Derivation unchanged, now **labelled** | `topSkus.title` → "Top SKUs (gross)" + `topSkus.revenue` → "Revenue (gross)"; `revenueByCategory.title` → "Revenue by Category (gross)"; `paymentMethods.title` → "Payment Method Breakdown (gross)" + `paymentMethods.amount` → "Amount (gross)" |
| 8 | POS Analytics Gross/Net cards — `features/pos/organisms/Analytics/SalesSummaryCards.tsx` → `PosAnalyticsService::getSalesSummary` | full `netOfReturns()` per-row `-ABS` helper | ✅ net of refunds; default range is **MTD, not today** | "Gross Sales" / "Net Sales" — but see O-28-c |
| 9 | Generic Dashboard "Revenue" — `features/dashboard/Dashboard.tsx` → `DashboardController::stats` | `SUM(documents.total)` over posted/paid invoices, **month-to-date**; never touches `pos_receipts` | N/A — not a POS today figure | `common:dashboard.revenue` |
| 10 | Live sales feed — `LiveSalesReportService` | row-level, `receipt_type='sale'` filtered, no aggregate | N/A | — |
| 11 | Cash across stores — `CashAcrossStoresWidget.tsx` | `/treasury/cash-position` | N/A (cash, not sales) | — |
| 12 | POS X-Report / EOD preview / Z list — `reportApi.ts`, `endOfDayPreview.ts`, `zReportService.ts`, `ZReportListPage.tsx` | sale-only gross; refunds tracked separately as a positive `refunds_amount` | shift/fiscal reports, **already explicitly labelled "Gross Sales"** → correct as-is | unchanged |
| 13 | `apps/pos/src/pages/ReportsPage.tsx`, `ShiftClosurePage.tsx` | **hardcoded money literals**, no derivation at all | 🚫 owned by the pos-screens lane — see §3 | `reports.dashboard.sales` "Sales (period)" |

`erp-mobile` is **not present in this repository** (`apps/` = `api`, `pos`, `web`).

---

## 2. Scope decision: the headline is net, the breakdowns stay gross

The F-5 comment in `SalesReportService` originally justified sale-only breakdowns as
*"keeps the drill-downs consistent with the headline KPIs"*. O-28 makes that statement
false — the headline is now net and the breakdowns are gross. The comment has been amended
at all four sites to record the reasons that actually survive:

- a refund carries no location / SKU / category attribution that is safe to net against an
  arbitrary grouping key; and
- a sale-only sum is sign-era-proof without any `ABS` handling.

Because the two definitions now genuinely disagree, the three surfaces rendering
`by-location` data are labelled gross (rows 4–6). This is a **scope decision, not a
derivation ruling** — see O-28-a.

---

## 3. Cross-lane records

**pos-screens lane** (worktree `.worktrees/pos-real-screens`, branch
`fix/pos-mocked-manager-screens`) — `ReportsPage.tsx` and `ShiftClosurePage.tsx` render
**fabricated money literals** under `reports.dashboard.sales` "Sales (period)", and their
`today | shift | week` segment is inert. Both are manager-facing nav-rail destinations.
When they become real, the period headline must be net-excl-refunds and reuse the O-28
wording (`reports.netSalesExclRefunds`).

**C-2 lane** (worktree `.worktrees/z-sale-decomposition`) — two records:

1. *No new defect at the three C-2 defect sites.* `reportApi.ts:~490`,
   `endOfDayPreview.ts:~300` and `zReportService.ts:~905` are sale-only gross and are
   **correctly labelled "Gross Sales"**; no O-28 fix is owed there.
2. **`apps/pos/src/api/reportApi.ts` carries exactly FOUR hunks from this lane.** The C-2
   lane's M4 negative-proof / diff check must EXPECT all four; none is a C-2 defect site.
   Verbatim from `git diff dev -- apps/pos/src/api/reportApi.ts`:

   | Hunk | Location (post-image) | Change |
   |---|---|---|
   | 1 | `@@ -1,5 +1,5 @@` — the import line | `import { apiGet, apiPost }` → `import { apiGet, apiGetRaw, apiPost }` |
   | 2 | `@@ -129,6 +129,16 @@` — `interface ShiftReceipt` | adds `is_training?: boolean` + its docblock |
   | 3 | `@@ -315,10 +325,79 @@` — `fetchShiftReceipts` | page-size / max-page constants, `PaginatedEnvelope`, `ShiftReceiptsPaginationError`, the `apiGetRaw` loop and its two fail-loud guards |
   | 4 | `@@ -669,7 +748,16 @@` — the row mapper in `fetchLocalShiftReceipts` | projects `is_voided` / `is_training` from the row (record 3 below) |

   The three C-2 defect sites are the SALE-branch VAT decomposition at
   `reportApi.ts:~490` (pre-image; unchanged by this lane), `endOfDayPreview.ts:~300` and
   `zReportService.ts:~905`. Hunks 2 and 3 add 10 and 69 lines respectively, so everything
   below them shifts by **+79**: the C-2 SALE-branch site whose `const rate = line.tax_rate
   ?? '0'` sat at pre-image `:490` is at post-image `:569` — a pure offset, not an edit.
   (Verify with `git diff dev -- apps/pos/src/api/reportApi.ts`: the file has four `@@`
   hunks and none of them is inside `generateLocalXReport`'s decomposition loop.)
   A third file, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`, gains one
   optional interface field (`voided?: 0 | 1`) and nothing else.
3. *Offline training/void projection — **FIXED HERE** (authorised 2026-08-21 as a second
   surgical edit; previously recorded as fenced).* The mapper hardcoded `is_voided: false`
   and emitted no `is_training`, although the row query is `SELECT *` and `offline_receipts`
   carries BOTH columns (`is_training` on the `OfflineReceipt` interface; `voided` from
   migration 15 `add_voided_to_offline_receipts`). Offline, therefore, the panel's
   `isCounted` filter had nothing to act on, and every training or voided receipt counted as
   a real sale — the last path by which either could inflate the audited figure. The mapper
   now projects both from the row, and `voided?: 0 | 1` was added to `OfflineReceipt`
   (optional at the type level, exactly as `receipt_kind?` was handled, so pre-migration
   fixtures and call sites need no edits). This reuses the predicate pair the repository
   already relies on in `getUnsyncedReceiptLineBlobs` ("`voided = 0`: a sealed-then-voided
   receipt's sale was reversed. `is_training = 0`: training receipts never move real
   stock."). The SQL query itself is **unchanged** — filtering stays in the panel, so the
   offline list still SHOWS every row while only the money tiles exclude them. Red-first
   proof: all three of `['real','trained','voided']` survived the counted filter before the
   fix; only `['real']` survives after.

---

## 4. Open questions

**O-28-a — do the breakdowns follow the headline?** Branch leaderboard, trend chart,
sales-by-location, top SKUs, revenue-by-category and the owner payment breakdown all report
sale-only gross. **All six are labelled gross** (en/fr/ar) rather than converted — the
dashboard defaults to today, so every one of them is a today figure sitting beside a net
headline, and the label is what keeps the two readable together. Converting them to net is a
separate lane (DTO + 4 endpoints + 3 chart consumers) and reverses a recorded ruling (F-5),
so it needs the owner, not an implementer.

**O-28-b — "Net Sales" is overloaded.** X-Report, EOD preview and Z-report already use
"Net Sales" to mean *net of VAT* (`netSales += receipt.subtotal`). The O-28 label carries
the explicit "(excl. refunds)" parenthetical so it self-disambiguates, and the fiscal
screens were left alone. Recommend they become "Net sales (excl. VAT)" in a follow-up so
the two senses are never adjacent without qualification.

**O-28-c — inverted naming in POS Analytics.** `PosAnalyticsService::getSalesSummary` maps
`gross_sales ← SUM(subtotal)` (VAT-**exclusive**) and `net_sales ← SUM(total)`
(VAT-**inclusive**). Both are correctly net of refunds, so this is not an O-28 derivation
defect, but the two analytics cards are labelled backwards.

**O-28-d — "Today's Sales" is shift-scoped, not day-scoped.** Both the server endpoint
(`ShiftController::receipts`, windowed on `posted_at >= shift.opened_at`) and the offline
query window on the CURRENT SHIFT, not the calendar day. On a two-shift day the second
cashier's "Today's Sales" excludes the morning entirely. Either the title is wrong or the
window is — an owner call. The page title key is `pos:reports.todaySales`.

**O-28-e — the "Receipts" count tile still counts everything.** `receipts.length` includes
training and voided rows, which the money tiles now exclude. Left as-is because the table
below it also lists every row, so the count matches what the operator can see; flagged so
the inconsistency is a decision rather than an oversight.

**O-28-f — `grossSalesAbs` is dead payload.** `SalesSummaryDeltaData.grossSalesAbs` and
`salesCountAbs` are read by nothing (`StatCard`'s trend contract is a percentage plus a
label). This lane deliberately added **no** `netSalesAbs` companion for that reason, but did
not remove the pre-existing fields — that is a published-contract change for the OpenAPI
lane, not a side effect of O-28.

---

## 5. Related tickets

- `2026-08-17-today-sales-blind-count-derivation.md` — the option-4 precondition.
- `2026-08-01-positive-refund-total-consumers.md` — the `-ABS` per-row idiom reused here.
- LEDGER §G-1 evidence block — the verified-safe consumer sweep this audit builds on.
- LEDGER row C-2 — device Z/X/EOD SALE-branch decomposition.
