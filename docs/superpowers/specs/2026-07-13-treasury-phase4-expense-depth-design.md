# Treasury Phase ④ — Expense Depth — Design Spec (Rev 1)

**Date:** 2026-07-13
**Status:** Rev 1 — owner scope decisions locked (4-question batch + G20 follow-up, 2026-07-13); pending adversarial review → Rev 2
**Mandate:** `docs/handoff/HANDOFF-treasury-phase4-spec-kickoff-2026-07-13.md`
**Predecessors:** Phase ① spine, Phase ② instruments, Phase ③ cash visibility — all shipped on origin/dev (Phase ③ merge `05674a586`; ui-gaps pay dialog `a2c0968f2`; banks-tug polish `bb44387f1` = base tip)

---

## 1. Goal

Give the expense module accounting depth: recoverable-VAT split (the register's headline real-money item), a real supplier link, category×period analytics with export, and recurring expense templates feeding notifications and the cash forecast. Defer outbound (supplier-direction) instruments to a dedicated post-④ session, shipping only direction guards.

## 2. Ground-truth corrections to the gap register (verified 2026-07-13)

The 2026-07-07 register is stale on three items — Phase ④ scope is built on the verified state, not the register:

| Register claim | Verified reality |
|---|---|
| **E2/G2 (P0):** `createFromExpense` credits Cash unconditionally on unpaid expenses | **FIXED** (Wave D): `GeneralLedgerService.php:3338` branches on `is_paid` — unpaid books `Cr SupplierPayable` with `partner_id` on the AP line; `ExpenseService::settle` (`ExpenseService.php:290`) pays down AP with spine lock order, idempotency short-circuit (`:settlement` leg), and linked-cost rejection. **No P0 lead item in Phase ④.** |
| **E4/G17:** GL hardcodes `partner_id => null` | Part-stale: the AP credit line uses `$expense->partner_id` (`GeneralLedgerService.php:3382`). What's missing is upstream: `ExpenseRequest` has no `partner_id` field and the FE vendor entry is free-text only, so the value is always null in practice. G17 = create-flow wiring + picker, not GL surgery. |
| **E7/G20:** "expense payable-by-instrument (after G5)" | Much bigger than billed: outbound instruments are **decorative**. `direction` column + `Outbound` enum exist and the deferred-supplier payment branch even creates Outbound instruments (`PaymentController.php:761`), but the money path never branches on direction — GL posts `Cr Bank` immediately (`:972-993`), the repository movement fires immediately (`:1093-1107`), there is no `ChecksToPay`/`EffetsPayable` account purpose, no outbound clearing flow, no direction guards in `InstrumentLifecycleService`, and maturity alerts filter inbound-only. |

Other verified facts the design relies on:
- `SystemAccountPurpose::VatDeductible` exists and is **already seeded in all three charts** (Generic/FR/TN) as account `4456 "TVA déductible"` — no CoA seeding wave needed. ⚠️ TN numbering flag: practitioner sources expect the 4366x family; see §11.
- Backend `date_to` filter already works on `GET /expenses` (`ExpenseController.php:70-72`); only the FE control is missing.
- No expense analytics endpoint exists anywhere (Expense/Dashboard/Reports controllers checked). No export endpoint exists repo-wide.
- No reusable recurrence engine exists (the `Scheduling` module is workshop appointments). `routes/console.php` holds 11 scheduled commands; `treasury:instrument-maturity-alerts` + `TreasuryAlertRecipients` + `TreasuryAlertNotification` are the alerting exemplar to copy.
- `expense_metadata.idempotency_key` is already unique (`2026_06_27_120000` migration) — recurring generation reuses it as the hard idempotency backstop.

## 3. Owner decisions (locked 2026-07-13)

| # | Decision | Choice |
|---|---|---|
| D1 | G16 VAT model | **Expense-level VAT + deductible-%** — `vat_rate` (suggested from Taxation configs), `vat_amount`, `vat_deductible_percent` (default 100); split-at-entry posting. No coefficient engine; no document lines (E6 stays deferred). |
| D2 | G19 recurring semantics | **Draft-ahead + reminder** — daily command materializes a DRAFT expense `lead_days` before due date, idempotent, bell notification with deep link. Auto-post = possible later per-template toggle, out of scope. |
| D3 | G18 export | **Real server-side streamed CSV**, permission-gated, designed to generalize — ends the `REPORT_EXPORT_ENABLED` stopgap pattern for this page. |
| D4 | G20 outbound instruments | **Defer + guards + handoff** — Phase ④ ships only lifecycle direction guards; a dedicated outbound-instruments handoff is written at phase end, recommended to fold into / immediately precede Phase ⑤ bank import. Rationale: M–L size, low urgency (cash understated not overstated between issuance and clearing — classic outstanding-checks item, not corruption), hard collision if parallel (shared `GeneralLedgerService`/`ExpenseService::settle`/`PayExpenseDialog`/purpose enums). |

## 4. Wave structure

| Wave | Content | Review lane |
|---|---|---|
| **W1 — money path** | VAT split + supplier link: metadata columns, request validation, posting change in `createFromExpense`, FE form fields | treasury-reviewer **Fable-tier** (owner tiering: financial spine) |
| **W2 — recurring** | Template table + CRUD, generation command, notification, forecast feed | Opus + tenancy-authz-reviewer (new permissions + notification type) |
| **W3 — analytics + export** | Analytics endpoint + FE page + list tiles + `date_to` UI + streamed CSV | Opus |
| **W4 — hardening + polish** | Instrument direction guards, i18n sweep, typescript:transform, invalidation keys | Opus |

W1 must land before W2 (templates carry the VAT trio) and before W3 (analytics reads net/VAT columns). W4 is independent.

## 5. Wave 1 — VAT split + supplier link (G16 + G17)

### 5.1 Data model

`expense_metadata` gains (additive migration):
- `vat_rate` `decimal(5,2)` nullable — percent, NOT currency-scaled (precision rule 19: regex `/^\d+(\.\d{1,2})?$/`)
- `vat_deductible_percent` `decimal(5,2)` nullable, default `100.00` when VAT present — percent
- (no `vat_amount` column here — see below)

`documents` columns are reused as intended (no migration): for an expense with VAT, `tax_amount` = VAT amount, `subtotal` = net (HT), `total` = TTC. Today `ExpenseService::create` sets `subtotal = total`; that stays true for VAT-less expenses (backward compatible — existing rows are already in that shape, `tax_amount` null).

**Semantics: the user enters `total` (TTC, what they paid — receipt reality) + `vat_amount` (from the receipt) + optional rate.** `vat_amount` is authoritative (invoice rounding differs from rate×net); rate is informational/suggested. FE computes a default `vat_amount` from the selected rate but the field stays editable. `ExpenseService::create/update` computes `subtotal = total − vat_amount` with bcmath at the currency scale (subtotal is derived, never user-supplied).

### 5.2 Validation (`ExpenseRequest`)

- `partner_id`: nullable uuid, tenant+company-scoped `exists` on partners (same pattern as the request's other scoped FKs). Coexists with `vendor_name` (retained as free text / display snapshot).
- `vat_amount`: nullable, numeric, money regex `/^\d+(\.\d{1,3})?$/`, must be `< total` (bccomp, not float).
- `vat_rate`: nullable, numeric, percent regex `/^\d+(\.\d{1,2})?$/`, max 100.
- `vat_deductible_percent`: nullable (defaults 100 when `vat_amount` present), percent regex, 0–100.
- **`expense_kind=linked_cost` rejects the VAT trio** (validation error): landed-cost capitalization currently consumes `total`; splitting recoverable VAT out of capitalized cost (PCG: capitalize net of recoverable taxes) would modify the WAC path and drag in the inventory-costing review lane. Explicit v1 restriction, documented in the API error; linked-cost VAT is a flagged follow-up (§12).

### 5.3 Posting design (`GeneralLedgerService::createFromExpense`)

All arithmetic bcmath at `scale+1` intermediates, rounded once at the currency scale via `CurrencyScale::bcformatStrict` with `getScale($expense->currency)` (rule 19; console/queue-safe per rule 20 — never no-arg `getScale()`).

With VAT present (`tax_amount` non-null and > 0):

```
net                = subtotal            (already total − vat_amount)
deductible_vat     = round(vat_amount × vat_deductible_percent / 100)   — rounded once at currency scale
non_deductible_vat = vat_amount − deductible_vat                        — remainder method: lines always sum exactly

Dr  expense account (category-mapped or GeneralExpense)   net + non_deductible_vat
Dr  VatDeductible (4456)                                   deductible_vat        [only if > 0]
Cr  SupplierPayable (unpaid, partner_id) | Cash/Bank (paid)   total
```

Balanced by construction (remainder method). Without VAT: current 2-line entry unchanged. `settle()` is **untouched**: AP was credited with `total`, settlement still moves `total` from AP to Cash/Bank.

Reversal (`/expenses/{id}/reverse`) mirrors the JE lines generically — the VAT line reverses with the entry; plan must verify with a test (post-with-VAT → reverse → VatDeductible nets to zero).

The FR/TN blocked-VAT rules (véhicules de tourisme 0%, FR fuel 80% VP / 100% VU, gifts €73, TN Code TVA art. 10 absolute exclusions) and the TN art. 9 prorata are all expressed by the user through `vat_deductible_percent` — no rules engine. FE shows a short helper hint; category-level default deductible-% is a flagged follow-up (§12).

### 5.4 Frontend (W1 slice)

- `ExpenseFormFields`: partner picker (reuse the supplier-invoice create page's `usePartners` pattern) with free-text `vendor_name` fallback — selecting a partner auto-fills `vendor_name` (editable snapshot). VAT block: rate select (company-country Taxation configs, percentage type) + `<MoneyInput>` for `vat_amount` (string payloads, no parseFloat — rule 19) + deductible-% input (default 100, `<QuantityInput>`-style string emit or a dedicated percent input; never `Number()`).
- `ExpenseDetailPage`: show partner (link), net / VAT (rate, deductible-%) / total breakdown.
- `PayExpenseDialog`: totals already `formatCurrency` (banks-tug polish) — no change beyond displaying VAT-inclusive total (which it already does, total is unchanged).
- Types via `php artisan typescript:transform` after DTO changes (rule 7) — note Phase-③ follow-ups brief also owes a transform run; whichever lands second rebases (§14).

## 6. Wave 2 — Recurring expense templates (G19)

### 6.1 Data model

New tenant table `expense_recurrence_templates`:

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id`, `company_id` | FKs | standard scoping |
| `name` | string | e.g. "Loyer local Tunis" |
| `expense_category_id` | FK nullable (set null) | |
| `partner_id` | FK nullable (set null) | |
| `vendor_name` | string nullable | |
| `amount` | decimal(15,3) | TTC, mirrors expense `total` |
| `vat_rate`, `vat_amount`, `vat_deductible_percent` | as W1 | same validation regexes |
| `payment_method_id`, `payment_repository_id` | FK nullable | defaults for the generated draft |
| `notes` | text nullable | copied to generated expense |
| `frequency` | string enum: `monthly` \| `quarterly` \| `yearly` | PHP enum `RecurrenceFrequency` (rule: enums for type columns) |
| `start_date` | date | anchors day-of-month |
| `end_date` | date nullable | inclusive; null = open-ended |
| `lead_days` | smallint default 3 | draft materializes `due − lead_days` |
| `status` | string enum: `active` \| `paused` \| `ended` | `RecurrenceStatus` enum; pause ≠ end (QBO semantics) |
| `next_due_date` | date | precomputed cursor |
| `created_by` | FK users | |

`expense_metadata` gains `recurrence_template_id` (uuid FK nullable, set null) for linkage/reporting ("generated from template X", analytics grouping).

**Next-date computation:** advance from **`start_date` origin** (Odoo lesson — never from the previous occurrence) using `Carbon::addMonthsNoOverflow`-family so day-31 anchors clamp instead of drifting (no Jan 31 → Mar 3 bugs). `ended` is set when `next_due_date > end_date`.

### 6.2 Generation command

`expenses:generate-recurring` — daily in `routes/console.php` (~05:30, `withoutOverlapping`, `runInBackground`; pattern: `treasury:instrument-maturity-alerts`). Per active template where `next_due_date − lead_days <= today`:

1. Create a **DRAFT** expense through `ExpenseService::create` with `document_date = next_due_date`, `is_paid = false`, the template's field set, and **`idempotency_key = "recurring:{template_id}:{period_key}"`** where `period_key` derives from `next_due_date` (`2026-07` monthly / `2026-Q3` quarterly / `2026` yearly). The existing unique constraint on `expense_metadata.idempotency_key` is the hard double-generation backstop (industry pattern: unique on template+period; deterministic from the due date, not now(), so a delayed cron still lands on the same key). A unique-violation on retry = already generated → advance cursor, no error.
2. Advance `next_due_date`; mark `ended` if past `end_date`.
3. Notify: `Notification::send($recipients, TreasuryAlertNotification(alertType: 'expense.recurring.generated', data: [template name, amount, due date, deep_link: "/expenses/{id}"]))` with recipients from **`TreasuryAlertRecipients::forCompany($tenantId, $companyId, 'expenses.post')`** — copy the tenant-team-id + membership-filter + registrar-flush exemplar, do not reinvent. One notification per generated draft.

**Rules 19/20 in console context:** amounts pass through as strings; any scale resolution passes the template's expense currency explicitly (`getScale($currency)` — no-arg `getScale()` throws in console). Command loops companies via the same per-company iteration the maturity-alerts command uses; seeder-trap does not apply (command, not seeder) but the runForMultiple lesson does — never depend on an injected Company.

### 6.3 Recurring CRUD (BE + FE)

- Routes in Expense module `routes.php` (standard middleware stack): `GET/POST /expense-recurrences`, `GET/PUT/DELETE /expense-recurrences/{id}`, `POST /expense-recurrences/{id}/pause`, `POST /expense-recurrences/{id}/resume`.
- New permissions: `expense-recurrences.view/create/update/delete` (precedent: `expense-categories.*`); pause/resume gate on `update`. Seeded in `RolesAndPermissionsSeeder` → **deploy owes perm reseed + `permission:cache-reset`** (tenant-blind cache bug).
- FE: `RecurringExpensesPage` under the expenses feature (list: name, frequency, next due, amount, status, pause/resume) + form dialog/page reusing the W1 form-field set + template link chip on `ExpenseDetailPage` when `recurrence_template_id` set. All text via `t()`; new i18n keys in the existing `expenses` namespace; tenant-scoped query keys.
- Notification FE: `types.expense.recurring.generated` + `messages.…` in `locales/{en,fr,ar}/notifications.json`, add to `KNOWN_TYPES` + `displayMessage()` switch in `NotificationPanel.tsx`.

### 6.4 Forecast feed

`UpcomingPaymentsService` Money-Out gains two feeds:
1. **Projected recurring occurrences**: active templates' due dates within the horizon (computed from `next_due_date` + frequency — projection only, no materialization on read).
2. **Unpaid posted expenses** (generic kind, `is_paid=false`): due = `payment_date ?? document_date`. (This also closes the register's "no expense AP aging in upcoming payments" side note.)
Dedup rule: once a recurring draft is materialized AND posted unpaid, it appears via feed 2, so feed 1 must exclude periods whose draft already exists (join on `recurrence_template_id` + period).

## 7. Wave 3 — Analytics + export (G18)

### 7.1 Analytics endpoint

`GET /expenses/analytics` (Expense module routes, `can:expenses.view`), params `date_from`, `date_to` (default: last 6 full months), optional `category_id`. Response (all money as strings):

```
{
  tiles: { total, count, unpaid_total, mom_delta_percent },   // current vs previous period
  by_category: [ { category_id, name, total, share_percent } ],
  matrix:      [ { category_id, name, months: { "2026-02": "…", … } } ],   // category×month
  top_vendors: [ { partner_id|null, vendor_name, total } ]     // group by partner_id when set, else vendor_name
}
```

Aggregation over posted expenses (`type=Expense`, status posted), SQL SUM on decimal columns returned as strings (no float casts — PHPStan guards). Research baseline: top vendors + category breakdown + monthly trend are the three table-stakes widgets (QBO/Odoo/Zoho); budget-vs-actual and YoY stay deferred (§12).

### 7.2 FE

- `ExpenseListPage`: add the missing **`date_to`** input (backend already supports it — E10), summary tiles row (total, unpaid, MoM delta) fed by the analytics endpoint with the list's current filters.
- New `ExpenseAnalyticsPage` (route + nav per conventions 02): category×month matrix (scrollable table), top-vendors list, monthly trend — using the design-token system; page pattern: the G12 cash-movements report page.
- Tenant-scoped query keys; `formatCurrency` everywhere.

### 7.3 Streamed CSV export

- `GET /expenses/export` (same filters as index; **new permission `expenses.export`**): Laravel `streamDownload` with cursor iteration (no full hydration), UTF-8 BOM, columns: document_number, document_date, partner/vendor, category, status, is_paid, subtotal (net), vat_amount, vat_deductible_percent, total, currency, receipt_number. Money values emitted as the stored decimal strings — floats never touch the pipeline.
- Built as a small reusable exporter (module-local in v1; promotion to a shared report-export facility is the follow-up — this endpoint deliberately becomes the pattern-setter that ends the `REPORT_EXPORT_ENABLED` stopgap era, per D3). The counting report's hidden buttons are NOT unhidden in this phase (its `/report/export` shape differs); noted as follow-up.
- FE: export button on list + analytics pages, gated `hasPermission('expenses.export')`, triggers browser download with the active filters.

## 8. Wave 4 — Hardening + polish

- **Instrument direction guards (D4):** `InstrumentLifecycleService::custodyTransfer/deposit/clear/bounce` throw `DomainException` when `direction === Outbound` (inbound semantics must not silently apply to outbound instruments). `cancel()` stays direction-neutral. Tests per action. This is the entire G20 footprint in Phase ④.
- `php artisan typescript:transform` after all DTO changes; verify against the Phase-③ follow-ups transform owe (§14).
- i18n completeness sweep (en/fr/ar) for all new keys; RTL check on the analytics matrix.
- `_invalidation.ts` updates for new mutations (bare literal prefixes per the invalidation sweep convention).

## 9. Testing strategy (TDD per repo rule 2)

- **W1 (money path, the critical tests):** posting unit tests — VAT split line math incl. remainder method (sum always equals total; adversarial cases: 1-millime/1-cent totals, 0% and 100% deductible, deductible rounding at 3dp TN vs 2dp FR); unpaid+VAT → AP credit total with partner; paid+VAT → cash credit; settle-after-VAT unchanged; reverse nets VatDeductible to zero; linked_cost + VAT trio rejected (422); reconcile checks #1–#4 stay green over a VAT-posted fixture.
- **W2:** cursor advance incl. day-31 clamp across month lengths; idempotency (double command run → one draft; delayed run → correct period_key); pause/resume/end; notification recipients (permission-filtered, company-membership-filtered); forecast dedup (materialized period excluded from projection).
- **W3:** analytics aggregation against seeded fixtures (string equality on totals); matrix month bucketing at period edges; export streams full filtered set (not one page) with BOM.
- **W4:** each lifecycle action rejects Outbound.
- Suites run **by path** (never full suite); FE Vitest per feature; valid UUIDs for all FK fixtures.

## 10. Deploy owes (stack on existing owes)

1. `tenants:migrate` — expense_metadata columns + `expense_recurrence_templates` (stacks with Phase ③ / banks / location-hierarchy owes).
2. Perm reseed + `permission:cache-reset` — `expenses.export`, `expense-recurrences.*`.
3. Scheduler: `expenses:generate-recurring` is picked up automatically by the existing scheduler container — verify once on staging.
4. No CoA action: `VatDeductible` (4456) already seeded in all three charts and present on the 5 staging tenants (Phase ② chart-seed remediation ran full charts). Plan adds a verification step, not a backfill.

## 11. Expert-comptable decision points (flagged, defaults chosen)

| # | Point | Default shipped |
|---|---|---|
| EC1 | **TN VAT account numbering** — charts seed `4456 TVA déductible` (FR-family); TN practitioner sources expect the 4366x family, and OECT's NC 01 does not mandate digits. | Ship as-is; accounts are tenant-editable rows (admin can renumber). Surface in onboarding notes. |
| EC2 | **FR 44566 vs 44562 split** (opex vs immobilisations, CA3 lines 20/19) | Single `VatDeductible` purpose = opex (44566-equivalent). Capex through the expense module is out of scope (linked-cost VAT deferred, §13); the split becomes relevant with a fixed-assets module. |
| EC3 | **Prorata / partial deductibility policy** | Manual `vat_deductible_percent` per expense (covers FR coefficient d'admission cases and TN art. 9 prorata alike). No stored per-tenant prorata; category-level defaults deferred. |
| EC4 | **TN booking mechanic for partial deductibility** | FR split-at-entry pattern applied to TN by analogy (research found no contradicting NCT text); confirm with expert-comptable before launch. |

## 12. Explicitly out of scope / deferred

- **G20 outbound instrument lifecycle** → dedicated handoff at phase end (`HANDOFF-outbound-instruments-…`), recommended to fold into / precede Phase ⑤ bank import ("our check cleared" is statement-driven). Includes: `ChecksToPay`/`EffetsPayable` purposes + seeding + brownfield backfill, direction-aware `PaymentController` deferred-supplier rework, outbound clearing action, outbound maturity alerts, reconcile-check review, expense-pay-by-instrument UI.
- E6 multi-line expenses / per-line category-VAT split (D1 keeps expense-level).
- E8 approval workflow; budget-vs-actual; YoY variance.
- Linked-cost VAT (capitalize net of recoverable taxes — touches WAC; needs inventory-costing lane).
- Category-level default `vat_deductible_percent`; per-template auto-post toggle; recurring weekly frequency.
- Counting-report export unhide / repo-wide `/report/export` generalization (this phase only sets the pattern).
- Expense OCR ingestion (`DocumentKind` has no Expense; supplier-invoice-only today).

## 13. Standing invariants (restated from handoff §6 — non-negotiable)

Every cash movement through the Phase-① port with a JE (`postEntryNow`, never afterCommit in transaction contexts); global lock order instrument→documents→GL advisory→repository; precision rule 19 incl. FormRequest regex ceilings (VAT percent NOT currency-scaled); rule 20 in console/queue contexts; reconcile checks #1–#4 green; fiscal perimeter untouched; `journal_entries(source_type,source_id)` non-unique — the expense source type keeps its existing behavior (this phase adds no new JE source types; recurring drafts post as ordinary `expense` entries only when the user posts them).

## 14. Interlocks

- Base branch: dev tip ≥ `bb44387f1` (banks-tug polish included — `PayExpenseDialog` settled).
- `chore/treasury-phase3-followups` (dispatch pending): no expense-file overlap, but both owe `typescript:transform` — whichever merges second re-runs it.
- `feat/design-system-unification` gate 4: re-check `git log origin/dev -- apps/web/src/features/expenses` before FE waves (its PageHeader pass may touch expense pages).
- `CODEX-replenishment-followups` (dispatch pending): potential `usePermissions.ts` collision if its permission-map generator lands while this phase adds permissions — sequence: whoever lands second regenerates.

## 15. Review plan (per handoff process contract)

- Spec adversarial review → `docs/superpowers/specs/reviews/` — **treasury-reviewer Fable-tier** on §5 posting/VAT/GL (owner tiering: financial spine, mandated in handoff); Opus lanes for recurring/analytics/FE; **tenancy-authz-reviewer** for new permissions + notification surface.
- Reconcile Rev 2 → writing-plans → plan review (Fable on W1, Opus rest) → Codex brief with autonomous `claude -p` gates (Opus standard, Fable escalation only on money-path BLOCKER/HIGH) → **STOP at owner dispatch gate**.
