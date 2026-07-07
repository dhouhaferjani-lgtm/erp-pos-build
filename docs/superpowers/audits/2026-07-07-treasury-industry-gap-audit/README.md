# Treasury Module — Industry-Standard Gap Audit

> **Date:** 2026-07-07 · **Mode:** READ-ONLY audit (no code changed, no implementation)
> **Verified against:** `dev` @ `d4784174f` (includes expense cutoff, `gl_account_id` canonicalization `53ec7e1c8`, deposit-pipeline hardening `ace29699d`)
> **Question asked:** *Is treasury on par with industry standards for (a) tracking expenses properly, (b) a clear overview of what's coming in and going out, and (c) instrument lifecycle — e.g. for checks and bank drafts, seeing what should clear when and its exact status?*
> **Predecessor:** `docs/superpowers/audits/2026-06-27-treasury-payments-audit/` (this audit re-verified its findings against current code; several are now FIXED — see §7)
> **Method:** 3 parallel code-exploration passes (instruments / expenses / cash visibility) + 1 industry benchmark research pass (Sage 100 MdP, Odoo, ERPNext, Tally, Xero/QBO, Tunisian ERPs). Load-bearing claims (POS balance gap, instrument GL absence, B2B balance correctness) verified firsthand.

---

## 0. TL;DR — verdict

**Not on par yet — but the distance is smaller than it looks, and it's concentrated in three structural gaps.** The data model is genuinely good (the switch-based payment-method design, the 9-state instrument enum, category→GL mapping, the new Treasury Overview page). What's missing is the *operational* layer competitors are judged on:

1. **Instruments are accounting-invisible and have no échéancier.** The check/traite state machine exists and the detail page has working deposit/clear/bounce/transfer buttons — but transitions post **zero GL entries**, there is **no maturity view/alerts** ("what clears when" is unanswerable), **POS-taken checks never enter the register at all**, and the register list page is broken by an API field mismatch. In FR/TN/Gulf markets, *the instrument portfolio is the yardstick a treasury module is judged by* (Sage 100 Moyens de Paiement, Dux-ERP, Tally PDC). This is the single biggest gap vs the user's stated need.
2. **The cash-position headline number is wrong by construction for POS tenants.** POS sales — the primary retail inflow — post GL and create Payment rows but **never increment `payment_repositories.balance`**. The Treasury Overview "Total Cash" cards read that column. B2B payments, expenses, and income all move it correctly; POS bridges don't. GL-derived reports stay correct while the balance cards drift lower every sale.
3. **Expense tracking works for the demo loop but stops at "log it."** No VAT/input-tax split (booked 100% gross — real money lost in TN/FR where TVA déductible is recoverable), no unpaid/AP path (worse: an **unpaid expense still credits Cash in the GL** — an accounting error), no recurring expenses, no supplier link, no expense analytics, no check-paid expenses.

Industry table-stakes we already meet: aged AR/AP, a 30-day in/out forecast from invoice due dates, payments list/allocation/refund, category→GL mapping with UI, receipt attachments, idempotent expense capture. That's a solid floor — the gaps above are what separate "records payments" from "manages treasury."

---

## 1. Scorecard vs industry baseline

Baseline = what Sage 100 MdP / Odoo / ERPNext / Tally / Xero / QBO / Tunisian SMB ERPs treat as table-stakes for this segment and region.

| Capability | Industry baseline | AutoERP today | Verdict |
|---|---|---|---|
| **Instrument register (checks/traites/PDC)** | Status-tracked portfolio, filterable by maturity/counterparty | Model+enum+transitions exist; list page broken (field mismatch); no maturity filter | 🟠 PARTIAL |
| **Échéancier / maturity view + alerts** | Calendar/report of what clears when; 7–14-day pre-maturity alerts (Gulf PDC standard) | **Nothing** — `maturingOn()` scope exists, zero callers; no job, no endpoint, no UI | 🔴 ABSENT |
| **Instrument GL integration (413/5112–5114 flow)** | Statuses map 1:1 to transit accounts; bounce reverses receivable + fees | Transitions are status-only; events have **no listeners**; no transit accounts referenced anywhere | 🔴 ABSENT |
| **POS-taken checks enter the register** | All instruments in one portfolio regardless of capture point | POS uses its own `instrument_type/serial` on receipt payments; **never creates a `PaymentInstrument`** | 🔴 ABSENT |
| **Remise en banque (batch deposit slip)** | Batch deposit producing slip + GL (Sage, Odoo check-deposit) | Single-instrument deposit only | 🔴 ABSENT |
| **Impayé/bounce handling with fees** | Reopen receivable, book bank fees, optionally re-bill customer | Bounce = status+reason+timestamp only; no GL, no fees, no receivable reopen | 🟠 PARTIAL |
| **Cash position across registers/banks/safes** | Real-time consolidated, trustworthy | Overview page exists, but sums a balance column **POS never updates** | 🟠 PARTIAL (wrong data) |
| **Repository movements ledger (auditable in/out per till/bank)** | Drill from balance to its movements | **No ledger table**; `RepositoryBalanceChanged` event has no listener; balance is a bare running column | 🔴 ABSENT |
| **Cash in/out report (encaissements/décaissements)** | Categorized period report | `CashMovementsReportService` exists and is trustworthy (GL-derived) — **but no FE page consumes it** | 🟠 PARTIAL (backend-only) |
| **Cash-flow forecast (30–90d)** | From AR/AP due dates **+ instrument maturities** (regional requirement) | `UpcomingPaymentsService` covers invoices+unpaid expenses; **omits instrument maturities** | 🟠 PARTIAL |
| **Inter-repository cash transfer (drawer→bank)** | Standard operation with GL | **No endpoint at all** | 🔴 ABSENT |
| **Bank reconciliation** | OFX/CSV/CAMT import + auto-match + rules | Manual tick-off of internally-recorded payments vs a typed statement balance; no import, no matching | 🟠 PARTIAL |
| **Expense capture (log→categorize→attach→post)** | Baseline | Works E2E incl. treasury decrement + idempotency + landed-cost capitalization | 🟢 MEETS |
| **Expense VAT recovery** | Per-expense VAT rate/amount split (TVA déductible 4366) | Booked 100% gross; no tax field anywhere in the path | 🔴 ABSENT |
| **Unpaid expense → AP liability** | `is_paid=false` books a payable, ages in AP | GL **credits Cash unconditionally** even when unpaid (no liability, ledger↔treasury divergence) | 🔴 ABSENT + bug |
| **Recurring expenses** | Baseline (QBO/Odoo) | Nothing | 🔴 ABSENT |
| **Expense approval workflow** | Submit→approve (Odoo baseline) | Draft→Posted only, permission-gated | 🟡 MINOR |
| **Supplier link on expenses** | Partner-linked for reporting/1-click AP | Free-text `vendor_name`; GL hardcodes `partner_id => null` | 🟠 PARTIAL |
| **Expense analytics (by category/period, budget-vs-actual)** | Baseline in accounting suites | Only generic P&L via class-6; no expense report endpoint, no charts, no export | 🔴 ABSENT |
| **Aged receivables / payables** | Baseline | Implemented with FE pages | 🟢 MEETS |
| **Payments list/allocate/refund/reverse** | Baseline | Implemented (refund balance/GL gap noted in prior audit — see §7) | 🟢 MOSTLY |
| **Multi-currency repositories** | Differentiator at this segment | No currency column on repositories; mixing would corrupt balance silently | 🟡 DEFER (guard needed) |

---

## 2. Deep-dive A — Payment instruments (the user's headline: "what clears when, exact status")

### What exists (better than expected)
- **Table + model:** `payment_instruments` (`migrations/tenant/2025_11_30_120000_create_treasury_tables.php:89-137`) with reference, drawer, amount, `maturity_date` (indexed), bank info, status timestamps. Scopes `pending()`, `maturingOn()` (`PaymentInstrument.php:194-218`).
- **Status enum:** `InstrumentStatus` — 9 states (`Received, InTransit, Deposited, Clearing, Cleared, Bounced, Expired, Cancelled, Collected`) with transition guards (`Enums/InstrumentStatus.php:7-63`).
- **Transitions:** `POST /payment-instruments/{id}/deposit|clear|bounce|transfer` implemented with enum guards + domain events (`PaymentInstrumentController.php:140-346`); deposit validates target is a `bank_account`.
- **Detail UI:** `InstrumentDetailPage.tsx` has the full action set (deposit/clear/bounce/transfer modals, `:143-182, 260-304`) and a derived timeline.
- **Web capture:** choosing a `has_maturity` method in `PaymentForm.tsx:616-631` creates the instrument then links it to the payment.

### The gaps (why it fails the stated need)
| # | Gap | Evidence | Industry contrast |
|---|---|---|---|
| **I1** | **No GL on any transition.** Events `InstrumentDeposited/Cleared/Bounced/Transferred` have **zero production listeners**; `EventServiceProvider` has no Treasury mapping; no checks-receivable/`effet`/transit account exists in code. Deposit/clear/bounce move a string. | grep across `app/` — only tests assert dispatch | PCG flow is standardized: Dr 413 on acceptance; Dr 5113/5114 on remise (encaissement/escompte); Dr 512+627+44566 on credit; bounce → back to 413 (or 416 douteux) + fees. Sage/Odoo/Tally all post per-status. |
| **I2** | **No échéancier: nothing answers "what clears when."** No scheduled job, no `/maturing-instruments`, `/check-register`, `/pdc-report` endpoints (docs promise them; none exist), no maturity filter on the list, no calendar, no alerts. | `Treasury/Presentation/Console/` has only `AuditDiscountsCommand`; no scheduler entry | Tally ships 4 dedicated PDC reports + notional-bank balances; Gulf PDC practice = 7–14-day pre-maturity alerts; Tunisian ERPs sell échéanciers client/fournisseur as core. |
| **I3** | **POS checks orphaned.** POS records `instrument_type/instrument_serial` on `pos_receipt_payments` for fiscal hashing but never creates a Treasury `PaymentInstrument`. A parapharmacy taking a check at the till has NO way to later deposit/clear/bounce it. | POS enum `PaymentInstrumentKind`; no bridge creates instruments | Single portfolio regardless of capture point is assumed everywhere. |
| **I4** | **Register list page broken.** `InstrumentListPage.tsx:20-33` reads `instrument_number`/`type`/`partner_name`/`repository_name`/`issue_date`; API returns `reference`/`partner:{name}`/`repository:{name}`/`received_date` and no `type` (`PaymentInstrumentController.php:351-395`). Columns render blank; `data.meta.total` read but no `meta` returned. | field-by-field mismatch | — (plain bug) |
| **I5** | **No instrument movement history.** No movements table (docs describe `InstrumentMovement`; never built). Transfer overwrites `repository_id` — custody trail lost. Intermediate states (`in_transit`, `clearing`) unrecorded. | `PaymentInstrumentController.php:326-328` | Custody/audit trail is standard for physical instruments. |
| **I6** | **No batch remise en banque** (deposit slip covering N checks → one bank credit + slip document + GL). Deposit is one-at-a-time. | routes | Sage MdP centerpiece; Odoo `account_check_deposit`. |
| **I7** | **Bounce is cosmetic** — no receivable reopen, no fee booking, no re-billing. | `bounce()` sets status/reason/timestamp only | Impayé handling with fees is table-stakes. |
| **I8** | **Instrument creation is FE-orchestrated only** — `POST /payments` with a check method and no `instrument_id` silently yields a payment with no instrument (API consumers, future mobile). Backend never auto-creates. | `PaymentController.php:439` stores the id only | — |
| **I9** | Detail page statuses hardcoded English (rule 11) and only 5 of 9 statuses styled. | `InstrumentDetailPage.tsx:101-107` | — |
| **I10** | **Escompte (discounting) not modeled** — no encaissement-vs-escompte distinction, no 5114/6616 path. Common TN/FR financing practice. | — | Sage MdP does both directions incl. LCR files. |

### Payment-method switches: partially dead config
`has_maturity` is live (drives instrument creation + POS deferred classification). `is_physical`/`requires_third_party` are FE-only. **`is_push`, `has_deducted_fees` (+ fee columns), `is_restricted` are dead** — persisted, editable, never read. Card-fee accounting (Dr Bank 98 / Dr Fees 2 / Cr Customer 100 per the module docs) does not exist.

---

## 3. Deep-dive B — Cash visibility ("what's coming in and going out")

### What exists (the read layer is genuinely decent)
- **Treasury Overview page** (`finance/pages/TreasuryOverviewPage.tsx`, nav "Treasury Overview"): Total Cash + per-type cards, assets/liabilities/AR/AP from GL, **Money-In/Money-Out 30-day forecast panels**, revenue-vs-expenses chart.
- **Forecast backend:** `UpcomingPaymentsService` — inflows from open customer invoices, outflows from open supplier invoices + unpaid expenses, aging buckets, `total_in/total_out/net`.
- **Historical in/out ledger:** `CashMovementsReportService` — unions payments + cash-account journal lines, tags in/out by debit/credit, dedupes. GL-derived → trustworthy. Endpoint `GET /reports/cash-movements`.
- **Aged AR/AP** with FE pages. **Payments list** with search.

### The gaps
| # | Gap | Evidence |
|---|---|---|
| **C1** | **POS sales never increment `repository.balance`** (verified firsthand: zero balance mutation in `TreasuryReceiptBridge`; same for Deposit/AccountPayment bridges). B2B `PaymentController.php:581-607` does it correctly (lockForUpdate + bcmath, in↑/out↓). Expense/Income/VendorRefund flows do too. **Only the POS bridges — the highest-volume flow — skip it**, so the Overview "Total Cash" cards understate cash for every POS tenant, more each day. The `ace29699d` hardening fixed GL+Payment robustness, not this. |
| **C2** | **No repository movements ledger.** Balance is one running column; `RepositoryBalanceChanged` fires into the void (no listener, nothing persisted). No drill-down from a till balance to the movements composing it → unauditable, undebuggable drift (C1 was only findable by code-reading). |
| **C3** | **The trustworthy in/out report has no UI.** Nothing in `apps/web/src` consumes `/reports/cash-movements`. |
| **C4** | **No inter-repository transfer.** "Moved 5,000 TND from register to bank" cannot be recorded (no endpoint, no GL, no slip). Combined with I6, physical cash/check banking is unmodelable. |
| **C5** | **Forecast omits instrument maturities.** A drawer full of dated traites — exactly the regional working-capital instrument — contributes nothing to Money-In. Regional échéancier must include portfolio maturities. |
| **C6** | **Bank reconciliation is a manual tick-off** of internally-recorded payments against a hand-typed statement balance (`BankReconciliationService.php:78-192`). No statement import (OFX/CSV/CAMT), no `bank_statements` table, no auto-match, no rules. Industry auto-match baseline is 60–95% (Odoo). Also: since instruments post no GL, checks can't be "cleared at reconciliation" the way ERPNext/Odoo close their 511x outstanding accounts. |
| **C7** | **Cash-position aggregation is client-side only** (FE sums repository list); no `/treasury/cash-position` endpoint (docs promise one). Minor, but blocks mobile/API parity and any server-side history/snapshotting. |
| **C8** | **No currency column on repositories**; inflow/outflow bcmath would silently mix currencies; FE sums across repos with no currency guard. Fine while single-currency; needs at least a guard. |
| **C9** | **Main dashboard has no cash widget** — owner's first treasury question ("how much cash is in each shop / bank right now?") answered only via Finance→Treasury Overview, and with C1-corrupted data. |

**Spine status update (vs 2026-06-27 F-series):** supplier payment, customer payment, expense post/refund, income, vendor refund now all do {balance + GL + payment row} — real progress. Remaining spine holes: POS bridges (C1), instruments (I1), POS returns (prior F3 — no return bridge, unchanged), treasury refunds (prior F4, unchanged), inter-repo transfers (C4).

---

## 4. Deep-dive C — Expense tracking

### What exists
The 2026-06-28 cutoff is live and works E2E: log → categorize (hierarchical categories with GL mapping + UI) → attach receipt → post → balanced JE (`GeneralLedgerService::createFromExpense`) + treasury decrement (`ExpenseService.php:190` → `RepositoryOutflowService`, bcmath) + idempotency key. Beyond the cutoff, **landed-cost Phase 1** shipped (expense capitalizable into inventory WAC with reversal). Categories seeded (TN parapharmacy, PCG-TN class-6).

### The gaps
| # | Gap | Detail |
|---|---|---|
| **E1** | **No VAT/input-tax split** — no tax field in request/metadata/GL; booked 100% gross. In TN/FR, recoverable TVA déductible (4366/44566) is being expensed → **overstated costs, unclaimed VAT, real money**. Industry: per-expense rate+amount is baseline. |
| **E2** | **Unpaid expense accounting is wrong** — `is_paid=false` correctly skips the treasury decrement but `createFromExpense` (`GeneralLedgerService.php:2318-2327`) **credits Cash/Bank unconditionally**: no AP liability, GL cash ≠ treasury balance on every unpaid expense. This is a correctness bug, not just a missing feature. No expense AP aging (aged payables covers supplier invoices only). |
| **E3** | **No recurring expenses** (rent, salaries, subscriptions — the most predictable outflows, also feeding the forecast). QBO/Odoo baseline. |
| **E4** | **No supplier link** — free-text `vendor_name`; GL hardcodes `partner_id => null` (`:2311`) though `documents.partner_id` exists. Blocks per-supplier expense reporting and any future expense→AP bridge. |
| **E5** | **No expense analytics** — no by-category/by-period endpoint, no budget-vs-actual, no export, no charts. Only the generic P&L via class-6. Owner "track expenses properly" = at minimum category×month breakdown. |
| **E6** | **No multi-line / category split** — one total, one category. |
| **E7** | **No deferred/instrument payment of an expense** — `payment_method_id` captured but posting always does an immediate `bcsub`; an expense paid by check clears the till instantly instead of at clearing (ties to I1). |
| **E8** | **No approval workflow** (draft→posted only). Minor at current team size; baseline in Odoo. |
| **E9** | **Siloed outflows** — expense list = `type=Expense` docs only; supplier-invoice payments, POS paid-outs, treasury refunds never appear in any unified outflow view (only in GL). The `/reports/cash-movements` endpoint is the natural unifier (see C3). |
| **E10** | FE list: no export/summary tiles; `date_to` filter exists in the backend but the UI only renders date-from. TN-only category seed. |

---

## 5. What already meets the bar (don't rebuild)

- Aged AR / AP with UI · 30-day in/out forecast service + Overview panels · payments record/allocate/refund/reverse + search · category→GL mapping UI · receipt attachments (SOURCE_DOCUMENT role) · idempotent expense create · landed-cost capitalization · B2B payment path does the full {balance+GL+subledger} triple with locking and bcmath · instrument state machine + detail/action UI · `CashMovementsReportService` (needs only a UI) · switch-based payment-method model (needs the dead switches wired, not redesigned).

---

## 6. Prioritized gap register (recommendation — NOT scheduled work)

Ordering optimizes for the owner's three stated needs. Effort: S <½d, M 1–2d, L 3d+.

### P0 — correctness (books are wrong today)
| ID | Fix | Gaps | Effort |
|---|---|---|---|
| **G1** | POS bridges apply repository inflow (or: derive Overview balances from GL and demote the column) — pick ONE source of truth for cash position | C1, C9 | M |
| **G2** | Unpaid expense posts Cr AP-liability (not Cash); payment event later moves cash | E2 | S–M |
| **G3** | Fix `InstrumentListPage` field contract (register currently renders blank) | I4 | S |

### P1 — the instrument portfolio (the user's headline; regional table-stakes)
| ID | Fix | Gaps | Effort |
|---|---|---|---|
| **G4** | Échéancier: maturity-filterable register + `/maturing-instruments` + upcoming-maturity panel on Treasury Overview + scheduled pre-maturity alert | I2 | M |
| **G5** | Instrument GL: listeners on the 4 transition events posting the 413/5112–5114 flow (receipt → remise → clear → bounce-with-fees); seed transit accounts in TN/FR CoA | I1, I7 | M–L |
| **G6** | POS check tenders create Treasury instruments (bridge on SALE_RECEIPT deferred tender lines) | I3 | M |
| **G7** | Backend-enforced instrument creation for `has_maturity` payments (stop trusting FE two-call orchestration) | I8 | S–M |
| **G8** | Instrument maturities feed `UpcomingPaymentsService` Money-In | C5 | S |
| **G9** | Batch remise en banque (deposit slip: N instruments → slip doc + one bank movement + GL) | I6, C4-partial | M–L |
| **G10** | Instrument movement/custody history table | I5 | S–M |

### P2 — cash overview completeness
| ID | Fix | Gaps | Effort |
|---|---|---|---|
| **G11** | Repository movements ledger (persist `RepositoryBalanceChanged` or a movements table); drill-down from balance | C2 | M |
| **G12** | FE page for `/reports/cash-movements` (this is also the unified outflow view, E9) | C3, E9 | S–M |
| **G13** | Inter-repository cash transfer endpoint + GL (drawer→bank, drawer→safe) | C4 | M |
| **G14** | Server-side `/treasury/cash-position` endpoint | C7 | S |
| **G15** | Bank statement import (CSV first, OFX next) + simple auto-match on amount/date/reference | C6 | L |

### P3 — expense depth
| ID | Fix | Gaps | Effort |
|---|---|---|---|
| **G16** | VAT split on expenses (rate/amount → 4366/44566) | E1 | M |
| **G17** | Supplier link (partner_id) on expenses | E4 | S |
| **G18** | Expense analytics: by-category×period endpoint + summary tiles/export on list page | E5, E10 | M |
| **G19** | Recurring expense templates (also feeds forecast) | E3 | M |
| **G20** | Expense payable-by-instrument (after G5) | E7 | M |

### Defer (post-launch / differentiators)
Escompte + 5114/6616 (I10) · endorsement · LCR CFONB file generation · multi-currency repositories (add a mixing **guard** now, S) · approval workflow (E8) · multi-line expenses (E6) · fee-deduction switch wiring (`has_deducted_fees`) · budget-vs-actual · bank feeds/EBICS · OCR receipt extraction.

---

## 7. Status of 2026-06-27 findings (re-verified)

| Prior finding | Status now |
|---|---|
| E1–E5 expense authz/500/permissions | ✅ FIXED (cutoff merged; `can:expenses.*` routes, attachments live) |
| F5/BCUT-3 expense skips treasury balance | ✅ FIXED (`ExpenseService.php:190`) |
| F7/D8 `account_id` vs `gl_account_id` | ✅ FIXED (`53ec7e1c8` canonical column + `ace29699d` backfill) |
| E8 categories unseeded / no GL picker | ✅ FIXED (seeder + UI) — TN-only seed |
| W7/C6 bank rec no import | ❌ OPEN (demo-blockers fixed `1dc52ab5f`, still manual-only) |
| F3 POS returns no GL · F4 treasury refunds move nothing · F16 MultiPayment no GL | ❌ OPEN |
| F10/D12 no live cash position | 🟠 PARTIAL — Treasury Overview page now exists, but reads the POS-corrupted balance column (C1) |
| E6 no VAT · E7 no AP path | ❌ OPEN (E7 now understood as an active accounting bug, §4 E2) |
| F1/F2/F15 tolerance/cash-rounding | ❌ OPEN (out of this audit's scope; tracked in prior audit) |

---

## Appendix — sources (industry benchmark)

Tally PDC reports & notional bank · ERPNext PDC docs + open issues #6946/#6785 (PDC dashboards recognized as a gap even there) · Odoo checks/outstanding-accounts + OCA `account_check_deposit`, `account_banking_fr_lcr`, `l10n_latam_check` · Sage 100 Moyens de Paiement (remises encaissement/escompte, LCR, EBICS, impayés) · PCG effets flow (413/5112/5113/5114/416/627/6616/44566; Compteo, Comptazine, compta-online) · Xero short-term cash flow (7/30/90d) · QBO Cash Flow Planner (90d) · Agicap/Pennylane (differentiator tier) · Dux-ERP/Megasoft (Tunisian baseline: portefeuille de traites, échéanciers, impayés, traite printing) · EasyCheckPro GCC PDC guide (alert windows). Full URLs in the research agent transcript.
