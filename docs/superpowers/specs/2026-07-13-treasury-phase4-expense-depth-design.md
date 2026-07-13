# Treasury Phase ④ — Expense Depth — Design Spec (Rev 2)

**Date:** 2026-07-13
**Status:** Rev 2.1 — Rev 2 reconciled the 4-lane adversarial review (`docs/superpowers/specs/reviews/2026-07-13-treasury-phase4-expense-depth-spec-review.md`; all lanes APPROVE-WITH-FIXES, every accepted disposition folded in). Rev 2.1 (2026-07-13, post plan-review) carries two factual corrections from the two-lane PLAN review (`…-plan-review-w1-money-path-fable.md` / `…-plan-review-w2-w4-opus.md`), marked ⟦Rev 2.1⟧ in §5.3. The plan (Rev 2) is normative where they conflict.
**Mandate:** `docs/handoff/HANDOFF-treasury-phase4-spec-kickoff-2026-07-13.md`
**Predecessors:** Phase ① spine, Phase ② instruments, Phase ③ cash visibility — all shipped on origin/dev (Phase ③ merge `05674a586`; ui-gaps pay dialog `a2c0968f2`; banks-tug polish `bb44387f1` = base tip)

---

## 1. Goal

Give the expense module accounting depth: recoverable-VAT split (the register's headline real-money item) **wired through to the VAT declaration**, a real supplier link, category×period analytics with export, and recurring expense templates feeding notifications and the cash forecast. Defer outbound (supplier-direction) instruments to a dedicated post-④ session, shipping only direction guards.

## 2. Ground-truth corrections to the gap register (verified 2026-07-13; re-verified by the Fable review lane)

The 2026-07-07 register is stale on three items — Phase ④ scope is built on the verified state:

| Register claim | Verified reality |
|---|---|
| **E2/G2 (P0):** `createFromExpense` credits Cash unconditionally on unpaid expenses | **FIXED** (Wave D): `GeneralLedgerService.php:3338` branches on `is_paid` — unpaid books `Cr SupplierPayable` with `partner_id` on the AP line; `ExpenseService::settle` (`ExpenseService.php:290`) pays down AP with spine lock order, idempotency short-circuit (`:settlement` leg), and linked-cost rejection. **No P0 lead item in Phase ④.** |
| **E4/G17:** GL hardcodes `partner_id => null` | Part-stale: the AP credit line uses `$expense->partner_id` (`GeneralLedgerService.php:3382`). What's missing is upstream: `ExpenseRequest` has no `partner_id` field and the FE vendor entry is free-text only. G17 = create-flow wiring + picker, not GL surgery. |
| **E7/G20:** "expense payable-by-instrument (after G5)" | Much bigger than billed: outbound instruments are **decorative**. `direction` + `Outbound` exist and the deferred-supplier branch creates Outbound instruments (`PaymentController.php:761`), but the money path never branches on direction — GL posts `Cr Bank` immediately (`:972-993`), the repository movement fires immediately (`:1093-1107`), no `ChecksToPay`/`EffetsPayable` purpose, no outbound clearing flow, no direction guards, alerts inbound-only. |

Additional verified facts the design relies on (corrected per review):

- `SystemAccountPurpose::VatDeductible` is **already seeded in all three charts** (Generic/FR/TN) as `4456 "TVA déductible"` — no CoA seeding wave. ⚠️ TN numbering flag: §11 EC1.
- **The VAT declaration report reads `document_tax_details`, not GL:** `EloquentVatDataRepository.php:29-39` already has an INPUT branch for `d.type='expense'` summing `document_tax_details` rows — which nothing writes for expenses today. W1 must write them or the declaration shows zero input VAT while 4456 fills (§5.3).
- **Generic expenses have NO reversal path:** `ExpenseService::reverse` (`ExpenseService.php:432-441`) throws for every non-linked-cost expense; only linked-cost reversal exists. Pre-existing, unchanged by this phase (§5.3, §12).
- **`ExpenseService::create` ignores `document_date`** (`:84` uses `payment_date ?? now()`) though `ExpenseRequest:76` accepts it — W1 fixes the passthrough (W2 depends on it).
- **Unpaid posted expenses already feed the forecast:** `UpcomingPaymentsService::openUnpaidExpenses` (`:117-133`, wired at `:39`), due = `COALESCE(due_date, document_date)`, posted-only. §6.4 builds on it instead of re-adding it.
- Backend `date_to` filter already works on `GET /expenses`; only the FE control is missing. `ExpenseListPage.tsx:66` already passes it through.
- No expense analytics endpoint exists. **Streamed-download exemplars DO exist** (`ImportController.php:527-530` streamed CSV; `WithholdingCertificateController.php:278,314`) — W3 copies them; the missing piece is expense-specific, not the pattern.
- No reusable recurrence engine exists (the `Scheduling` module is workshop appointments). `treasury:instrument-maturity-alerts` + `TreasuryAlertRecipients` + `TreasuryAlertNotification` are the alerting exemplar; the maturity command deliberately runs **in-process** (no `runInBackground`) so the scheduler observes exit codes (`console.php:65-69`), and gates on **company timezone** (`InstrumentMaturityAlertsCommand.php:98`).
- `expense_metadata.idempotency_key` is unique, and `ExpenseService::create` dedups via **SELECT-then-return short-circuit** (`:61-70`), returning the existing Document silently — callers distinguish creation via `wasRecentlyCreated` (§6.2).

## 3. Owner decisions (locked 2026-07-13)

| # | Decision | Choice |
|---|---|---|
| D1 | G16 VAT model | **Expense-level VAT + deductible-%** — `vat_rate` (suggested from Taxation configs), `vat_amount`, `vat_deductible_percent` (default 100); split-at-entry posting. No coefficient engine; no document lines (E6 deferred). |
| D2 | G19 recurring semantics | **Draft-ahead + reminder** — daily command materializes a DRAFT expense `lead_days` before due date, idempotent, bell notification with deep link. Auto-post = later per-template toggle, out of scope. |
| D3 | G18 export | **Real server-side streamed CSV**, permission-gated, following the existing streamed-download exemplars, designed to generalize. |
| D4 | G20 outbound instruments | **Defer + guards + handoff** — Phase ④ ships only direction guards; dedicated outbound-instruments handoff at phase end, recommended to fold into / precede Phase ⑤ bank import. Rationale: M–L size, low urgency (cash understated not overstated — outstanding-checks item, not corruption), hard collision if parallel. |

## 4. Wave structure

| Wave | Content | Review lane |
|---|---|---|
| **W1 — money path** | VAT split + tax-detail write + supplier link: metadata columns, service-level validation, posting change, `document_date` passthrough, FE form fields | treasury-reviewer **Fable-tier** |
| **W2 — recurring** | Template table + CRUD, generation command, notification, forecast feed | Opus + tenancy-authz-reviewer |
| **W3 — analytics + export** | Analytics endpoint + FE page + list tiles + `date_to` UI + streamed CSV | Opus |
| **W4 — hardening + polish** | Instrument direction guards (incl. remittance), i18n sweep, typescript:transform, invalidation keys | Opus |

W1 before W2 (templates carry the VAT trio; W2 needs the `document_date` passthrough) and before W3 (analytics reads net/VAT columns). W4 independent.

## 5. Wave 1 — VAT split + supplier link (G16 + G17)

### 5.1 Data model

`expense_metadata` gains (additive migration):
- `vat_rate` `decimal(5,2)` nullable — percent, NOT currency-scaled (rule 19 regex `/^\d+(\.\d{1,2})?$/`)
- `vat_deductible_percent` `decimal(5,2)` **plain nullable — NO DB default** (a column default would stamp VAT-less rows); the service applies the conditional default: `vat_amount` present and percent absent → `100.00`

`documents` columns reused as intended (no migration): expense with VAT ⇒ `tax_amount` = VAT amount, `subtotal` = net (HT), `total` = TTC. VAT-less expenses keep `subtotal = total`, `tax_amount` null (backward compatible with all existing rows).

**Semantics: the user enters `total` (TTC — receipt reality) + `vat_amount` (from the receipt) + optional rate.** `vat_amount` is authoritative (invoice rounding differs from rate×net); rate is informational. FE computes a default `vat_amount` from the selected rate; field stays editable. `ExpenseService::create/update` computes `subtotal = total − vat_amount` with bcmath (subtotal derived, never user-supplied).

**`document_date` passthrough (W1, required by W2):** `create()` honors a supplied `document_date` (falling back to `payment_date ?? now()` as today). `ExpenseRequest` already validates it.

### 5.2 Validation — split between FormRequest and service

`ExpenseRequest` (format layer):
- `partner_id`: nullable uuid, `ScopedExists::tenantAndCompany('partners', …)` (pattern: the request's other scoped FKs).
- `vat_amount`: nullable, numeric, money regex `/^\d+(\.\d{1,3})?$/`.
- `vat_rate`: nullable, numeric, percent regex `/^\d+(\.\d{1,2})?$/`, max 100.
- `vat_deductible_percent`: nullable, percent regex, 0–100.

**Service layer (cross-field rules on EFFECTIVE MERGED values — review MP-M2):** `update()` merges payload over stored values (`ExpenseService.php:132-150`), so request-only cross-field checks are bypassable (stored `vat_amount=15` + payload `total=10` → negative net). `ExpenseService::create/update` therefore enforce, post-merge, with bccomp (never floats):
1. `vat_amount < total` (else DomainException → 422);
2. **`expense_kind=linked_cost` rejects the VAT trio** (checked against the STORED kind on update — `expense_kind` is immutable in `update()`): landed-cost capitalization consumes `total`; splitting recoverable VAT out of capitalized cost would modify the WAC path (inventory-costing lane). v1 restriction; linked-cost VAT is a follow-up (§12);
3. deductible-% default application (§5.1).

### 5.3 Posting design (`GeneralLedgerService::createFromExpense`)

All arithmetic bcmath at `scale+2` intermediates ⟦Rev 2.1: was "scale+1" — the Fable plan-review lane verified scale+1 would truncate the ×percent product; the plan's scale+2 is normative⟧. **Rounding mode for the deductible split: `CurrencyScale::bcround`** (half-up, away from zero) at the currency scale — the documented GL-posting-boundary helper (NC 01 §62; the review called it "bcroundHalfUp" but the method's actual name is `bcround`), per review MP-M1. Scale always via `getScale($expense->currency)`; **the in-class `$this->scale()` helper (`GeneralLedgerService.php:58`) is the forbidden no-arg form and is BANNED inside `createFromExpense`** (throws in console/queue contexts — rule 20).

With VAT present (`tax_amount` non-null and > 0):

```
net                = subtotal                                     (already total − vat_amount)
deductible_vat     = bcroundHalfUp(vat_amount × vat_deductible_percent / 100, scale)
non_deductible_vat = vat_amount − deductible_vat                  (remainder method: lines sum exactly)

Dr  expense account (category-mapped or GeneralExpense)   net + non_deductible_vat
Dr  VatDeductible (4456)                                   deductible_vat        [line omitted if 0]
Cr  SupplierPayable (unpaid, partner_id) | Cash/Bank (paid)   total
```

Balanced by construction. Without VAT: current 2-line entry unchanged. `settle()` **untouched** (verified: debits/credits `total`, never recomputes from subtotal — `ExpenseService.php:384,398`).

**VAT-declaration wiring (review MP-H1 — required, or the feature is invisible where it's declared):** at expense post, W1 writes a `document_tax_details` row with **`tax_amount` = deductible VAT** (the declarable amount — keeps GL 4456 ≡ declared input VAT) and `tax_base` = subtotal, so `EloquentVatDataRepository`'s existing INPUT branch (`:29-39`) picks expenses up. ⟦Rev 2.1 CORRECTION (Fable plan-review F2): this claim was STALE — `document_tax_details.tax_base/tax_amount` are ALREADY `decimal(15,3)` on dev via `2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php:22-25`. NO widening migration ships in W1; adding one would create a `down()` that fights the March migration. The original text is preserved struck-through for audit:⟧ ~~⚠️ Schema fix required first: `document_tax_details.tax_base/tax_amount` are `decimal(15,2)` columns (create migration) while the model casts claim `decimal:3` — a 3dp TND amount would silently lose millimes. W1 ships a safe widening migration to `decimal(15,3)` (2dp→3dp, no data loss).~~ Partial-deductibility base treatment = EC5 (§11). The write happens in the same posting transaction; reversal is N/A (below).

**Correction path (review MP-BLOCKER):** generic expenses have **no reversal path** today (`ExpenseService::reverse` rejects non-linked-cost — pre-existing, unchanged). VAT correction v1 = edit/delete the DRAFT before post; a posted generic expense is corrected the way it is today (not at all — generic expense reversal is deferred, §12). The Rev 1 claim that `/expenses/{id}/reverse` mirrors VAT lines generically was FALSE and is withdrawn; no test asserts it.

**Supplier-balance side effect (review MP-M3 — intended outcome, stated):** with `partner_id` set, the unpaid-expense AP line rolls into the supplier's `payable_balance` via the posted-JE listener → `PartnerBalanceService::refreshPartnerBalance`. This is correct accounting and a deliberate W1 outcome (per-supplier expense visibility on statements); settle() nets it back with the same partner. Tested explicitly (§9).

Blocked-VAT rules (FR véhicules/carburant/gifts; TN Code TVA art. 10) and TN art. 9 prorata are expressed via `vat_deductible_percent` — no rules engine. FE helper hint; category-level defaults deferred (§12).

### 5.4 Frontend (W1 slice)

- `ExpenseFormFields`: **`<PartnerPicker>` molecule** (`@/components/molecules/pickers`, `partnerType="supplier"` — review FE-M1: `usePartners` does not exist; never hand-roll a parallel picker). Selecting a partner auto-fills `vendor_name` from `PartnerPickerValue.name` (editable snapshot); free-text `vendor_name` retained as fallback.
- VAT block: rate select (company-country Taxation configs, percentage type) + `<MoneyInput>` for `vat_amount` (string payloads — the form already emits strings) + **`<QuantityInput decimalPlaces={2}>` for both percent fields** (review FE-M2: the loyalty precedent's `decimalPlaces={4}` would 422 against the 2dp regex).
- Validation approach: **inline-RHF `validate` callbacks, consistent with the existing form** (review FE-L2; zod migration out of scope).
- `ExpenseDetailPage`: partner link + net / VAT (rate, deductible-%) / total breakdown.
- Types via `php artisan typescript:transform` (rule 7); Phase-③ follow-ups also owes a transform — whichever lands second rebases (§14).

## 6. Wave 2 — Recurring expense templates (G19)

### 6.1 Data model

New tenant table `expense_recurrence_templates`:

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id`, `company_id` | FKs | standard scoping |
| `name` | string | e.g. "Loyer local Tunis" |
| `expense_category_id`, `partner_id`, `payment_method_id`, `payment_repository_id` | FK nullable (set null) | defaults for the generated draft |
| `vendor_name` | string nullable | |
| `amount` | decimal(15,3) | TTC, mirrors expense `total` |
| `vat_rate`, `vat_deductible_percent` | as W1 | + `vat_amount` decimal(15,3) nullable (templates are not documents; the trio lives here whole) |
| `notes` | text nullable | copied to generated draft |
| `frequency` | string enum `RecurrenceFrequency`: `monthly` \| `quarterly` \| `yearly` | |
| `start_date` | date | anchors day-of-month |
| `end_date` | date nullable | inclusive; null = open-ended |
| `lead_days` | smallint default 3 | draft materializes `due − lead_days` |
| `status` | string enum `RecurrenceStatus`: `active` \| `paused` \| `ended` | pause ≠ end |
| `next_due_date` | date | cursor |
| `created_by` | FK users | |

`expense_metadata` gains `recurrence_template_id` (uuid FK nullable, set null) for linkage and forecast/analytics grouping.

**Cursor semantics:**
- Advance from **`start_date` origin** (never from the previous occurrence) with no-overflow month math (day-31 clamps, no drift). `ended` when `next_due_date > end_date`.
- **Edit recompute (review RA-M5):** editing `frequency` or `start_date` recomputes `next_due_date` (first occurrence ≥ today from the new origin/cadence). Already-materialized drafts are independent documents — template edits never retro-modify them.
- **Resume (review RA-H2):** resuming a paused template rolls `next_due_date` forward to the first occurrence ≥ today. **No backfill of missed periods.** Per-run processing is single-occurrence per template by design (documented; the roll-forward rule makes catch-up loops unnecessary).

### 6.2 Generation command

`expenses:generate-recurring` — daily in `routes/console.php` (~05:30, `withoutOverlapping`, **in-process — NO `runInBackground`**, so the scheduler observes partial-failure exit codes; exemplar `console.php:65-69`). Iterates per (tenant, company) — the maturity-alerts `forEachTenant` → per-company pattern — and gates on **company timezone**: `next_due_date − lead_days <= CarbonImmutable::today($company->timezone)` (review RA-M4).

Per qualifying active template, **atomically in one transaction (review RA-H1)**:
1. Create the DRAFT via `ExpenseService::create` with **explicit `company_id = $template->company_id`** (review TA-M2 — no context in console; the scan is company-partitioned so a company-A template can never stamp company B), `document_date = next_due_date` (W1 passthrough; `payment_date` stays null — review RA-M1), `is_paid = false`, the template's field set, and `idempotency_key = "recurring:{template_id}:{period_key}"` (`2026-07` monthly / `2026-Q3` quarterly / `2026` yearly, derived from `next_due_date`, never now()). `create()`'s SELECT-then-return short-circuit + the unique constraint are the dedup backstops; **`$doc->wasRecentlyCreated` distinguishes fresh creation from a replayed period.** Note the format asymmetry (review RA-L2): the API contract for `idempotency_key` is `uuid`; the command calls the service directly so the prefixed key is legal — documented, collision-free.
2. Advance `next_due_date` (origin-anchored); mark `ended` if past `end_date`.
3. **After commit, and only if `wasRecentlyCreated`** (review RA-H1 — a crash-replay run that short-circuits must advance the cursor without re-notifying): `Notification::send($recipients, TreasuryAlertNotification(alertType: 'expense.recurring.generated', data: [name, amount, due date, deep_link: "/expenses/{id}"]))` with recipients from `TreasuryAlertRecipients::forCompany($tenantId, $companyId, 'expenses.post')` — the tenant-team-id + membership + registrar-flush exemplar, copied not reinvented. Trade-off (review TA-L1, accepted): `expenses.post` targets who can ACT on the draft; a template author holding only create/update is not notified.

Rules 19/20: amounts pass as strings; any scale resolution passes the expense currency explicitly.

### 6.3 Recurring CRUD (BE + FE)

- Routes in Expense module `routes.php` (standard middleware stack): `GET/POST /expense-recurrences`, `GET/PUT/DELETE /expense-recurrences/{id}`, `POST /expense-recurrences/{id}/pause`, `POST /expense-recurrences/{id}/resume`.
- FormRequest: **all four FKs validated with `ScopedExists::tenantAndCompany(...)`** mirroring `ExpenseRequest.php:53-68` (review TA-M1 — an unscoped FK flows into every generated draft); CRUD queries filter `company_id` via `CompanyContext`.
- Permissions + grants: §8.5 table. Pause/resume gate on `update`.
- FE: `RecurringExpensesPage` (list: name, frequency, next due, amount, status, pause/resume) + form reusing the W1 field set + template chip on `ExpenseDetailPage`. All text via `t()`; **notification locale keys NESTED** (`types → expense → recurring → generated` in en/fr/ar `notifications.json` — review FE-L1: flat dotted keys don't resolve), `KNOWN_TYPES` + `displayMessage()` extension in `NotificationPanel.tsx`; tenant-scoped query keys; `_invalidation.ts` bare-literal prefixes.

### 6.4 Forecast feed (rewritten per review RA-H3)

`openUnpaidExpenses` already feeds posted unpaid expenses into Money-Out (due = `COALESCE(due_date, document_date)`). The genuinely new pieces, designed so a materialized-unposted draft never vanishes during the lead window:

1. **Materialized recurring drafts** (status Draft, `recurrence_template_id` NOT NULL) surface as concrete upcoming outflows at `document_date`.
2. **Template projection** covers only periods **after the cursor** (`next_due_date` onward within the horizon) — projection only, no materialization on read.
3. Posted unpaid expenses keep flowing via the existing feed.

No dedup join needed: cursor advance partitions (1) and (2) — a period is either materialized (feed 1) or still ahead of the cursor (feed 2); posting moves it from feed 1 to feed 3.

## 7. Wave 3 — Analytics + export (G18)

### 7.1 Analytics endpoint

`GET /expenses/analytics` (Expense module routes, `can:expenses.view`), params `date_from`, `date_to` (default last 6 full months), optional `category_id`, optional **`status` (default `posted`)** — the status contract that keeps tiles/list parity (review RA-M2/FE-H1). Response (all money as strings):

```
{
  tiles: { total, count, unpaid_total, mom_delta_percent },
  by_category: [ { category_id, name, total, share_percent } ],
  matrix:      [ { category_id, name, months: { "2026-02": "…", … } } ],
  top_vendors: [ { partner_id|null, vendor_name, total } ]
}
```

**Company scoping is mandatory on the analytics query and EVERY sub-aggregate** (review TA-H2): `->where('company_id', $companyContext->requireCompanyId())` — db-per-tenant isolates tenants, not companies; an unscoped SUM leaks sibling-company spend/vendors/VAT. Aggregations are **single GROUP BY queries** (no per-row work); plan verifies index coverage on `documents(company_id, type, status, document_date)` and adds a composite index if absent (review RA-L4). Legacy caveat: pre-W1 rows carry `subtotal == total` — net figures include embedded VAT for those; analytics page carries a caption (review MP-L4).

### 7.2 FE

- `ExpenseListPage`: **`DateRangeFilter`** replacing the lone bare date input (adds `date_to`, matches the cited G12 pattern — review FE-L3); summary tiles row fed by the analytics endpoint passing the list's `status`/`category_id`/date filters. **`search` is never passed to analytics; when a search is active the tiles show a caption** ("totals ignore text search") — review FE-H1's contradiction fix.
- New `ExpenseAnalyticsPage` (route + nav per conventions 02): category×month matrix (scrollable, RTL-checked), top vendors, monthly trend; page template: `CashMovementsReportPage.tsx`; design tokens throughout.

### 7.3 Streamed CSV export

- `GET /expenses/export` (same filters as index; permission `expenses.export`): streamed download following the existing exemplars (`ImportController.php:527-530`, `WithholdingCertificateController.php:278,314`), cursor iteration, **company-scoped cursor** (review TA-H2), UTF-8 BOM; columns: document_number, document_date, partner/vendor, category, status, is_paid, subtotal (net), vat_amount, vat_deductible_percent, total, currency, receipt_number. Money = stored decimal strings; floats never touch the pipeline.
- **Known ceiling (review RA-M6):** synchronous streaming bounds memory, not execution time — very large filtered sets remain subject to PHP/proxy timeouts. Accepted for v1; queued export is the named follow-up (§12).
- FE: export buttons on list + analytics pages, gated `hasPermission('expenses.export')`, using the **authenticated-blob pattern** (`api.get(..., {responseType:'blob'})` → object-URL download; exemplar `PayrollExportPage.tsx:53-60` — review FE-M3: a bare `<a href>` loses the Bearer header on reverse-proxy deployments).

## 8. Wave 4 — Hardening + polish

- **Instrument direction guards (D4), FIVE points** (review MP-H2 added the fifth): `InstrumentLifecycleService::custodyTransfer/deposit/clear/bounce` AND **`InstrumentRemittanceService::assertEligible`** throw `DomainException` on `direction === Outbound` — the remittance routes are directly HTTP-reachable and an Outbound instrument in `Received` is otherwise eligible for an inbound collection slip (wrong GL at `remit()`, before `clear()` would ever fire). `receive()` and `cancel()` stay direction-neutral (the deferred-supplier branch depends on `receive()`). Tests per guard point.
- `php artisan typescript:transform` after all DTO changes; check against the Phase-③ follow-ups transform owe (§14).
- i18n completeness sweep (en/fr/ar) for all new keys; RTL check on the analytics matrix.
- `_invalidation.ts` updates for new mutations.

## 8.5 Permissions & role grants (review TA-H1/TA-H3 — load-bearing, not an afterthought)

| Permission | admin | manager | accountant | cashier | operator | viewer |
|---|---|---|---|---|---|---|
| `expense-recurrences.view` | ✓ (all) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `expense-recurrences.create/update/delete` | ✓ | ✓ | ✓ | — | — | — |
| `expenses.export` | ✓ | ✓ | ✓ | — | — | — |

Mirrors the `expense-categories.*` precedent (full CRUD manager/accountant, view-only cashier/operator/viewer; admin holds all via `syncPermissions(Permission::all())`). **A permission granted to no role is a silent 403 for every non-admin — the grant table above is normative.**

**FE permission map owe (review TA-H3):** `apps/web/src/hooks/usePermissions.ts` is a hand-authored PERMISSIONS→roles map that has already broken nav twice; this phase MUST register the new permissions there with the same grants (or regenerate the map if the replenishment-followups generator has landed first — §14). Deny-path authz tests: a role lacking the perm gets 403 (BE) and sees no button/nav (FE).

## 9. Testing strategy (TDD)

- **W1 (money path):** VAT split line math incl. remainder method + **`bcroundHalfUp`** edge cases (TND 3dp vs EUR 2dp; 1-millime/1-cent totals; 0%/100% deductible; deductible-VAT line omitted at 0); unpaid+VAT → AP credit `total` with partner; paid+VAT → cash credit; **service-level merged validation** (stored vat + shrunken total → 422; VAT trio on stored linked_cost → 422); `document_tax_details` row written at post with deductible amount; **supplier `payable_balance` includes total after post, returns after settle** (MP-M3); settle-after-VAT byte-identical to today; **NO reverse test on generic expenses** (no path exists — MP-BLOCKER); reconcile checks #1–#4 green over a VAT fixture **with `repository.gl_account_id` set to the purpose-resolved account so the authoritative branch is exercised** (Fable-lane note).
- **W2:** origin-anchored cursor incl. day-31 clamp; **atomic create+cursor** (crash-replay run advances cursor, sends NO duplicate notification — `wasRecentlyCreated` gate); resume rolls forward, no backfill; edit recompute; company-timezone gate at month boundary; **explicit company stamping** (two companies, one tenant — template A never generates for company B); notification recipients permission-filtered; forecast: materialized-unposted draft visible in feed 1, period absent from projection, posting moves it to the existing unpaid feed.
- **W3:** aggregation string-equality against fixtures; month bucketing at period edges; **two-companies-one-tenant leak test on analytics AND export** (TA-H2); status param parity with list; export streams the full filtered set with BOM via blob download.
- **W4:** all five guard points reject Outbound.
- **Authz:** deny-path tests per §8.5.
- Suites by path only; FE Vitest per feature; valid UUIDs for FK fixtures.

## 10. Deploy owes (stack on existing owes)

1. `tenants:migrate` — expense_metadata columns + `expense_recurrence_templates` (stacks with Phase ③ / banks / location-hierarchy owes).
2. Perm reseed + `permission:cache-reset` — `expenses.export`, `expense-recurrences.*` (tenant-blind cache bug).
3. **FE permission map** updated in the same merge (§8.5 — not a deploy step, a merge-completeness check).
4. Scheduler picks up `expenses:generate-recurring` automatically — verify once on staging.
5. No CoA action: `VatDeductible` (4456) seeded in all charts and present on the 5 staging tenants (Phase ② chart-seed remediation). Plan adds a verification step, not a backfill.
6. No Horizon change: `TreasuryAlertNotification` is database-channel, not queued (verified).

## 11. Expert-comptable decision points (flagged, defaults chosen)

| # | Point | Default shipped |
|---|---|---|
| EC1 | **TN VAT account numbering** — charts seed `4456` (FR-family); TN practitioners expect 4366x; OECT NC 01 mandates no digits. | Ship as-is; accounts are tenant-editable rows. Surface in onboarding notes. |
| EC2 | **FR 44566 vs 44562** (opex vs immobilisations, CA3 lines 20/19) | Single `VatDeductible` purpose = opex. Capex via expense module out of scope (linked-cost VAT deferred). |
| EC3 | **Prorata / partial deductibility policy** | Manual `vat_deductible_percent` per expense (covers FR coefficient d'admission and TN art. 9 prorata). Category-level defaults deferred. |
| EC4 | **TN booking mechanic for partial deductibility** | FR split-at-entry applied by analogy; confirm with expert-comptable before launch. |
| EC5 | **VAT-declaration base for partially-deductible expenses** (review MP-H1) — `document_tax_details.tax_amount` = deductible VAT keeps the declared amount right; whether `tax_base` should be prorated for partial deductibility is a declaration-presentation question. | `tax_base` = full subtotal, `tax_amount` = deductible VAT. Confirm with expert-comptable. |

## 12. Explicitly out of scope / deferred

- **G20 outbound instrument lifecycle** → dedicated handoff at phase end, fold into / precede Phase ⑤ bank import. Includes: `ChecksToPay`/`EffetsPayable` purposes + seeding + brownfield backfill, direction-aware `PaymentController` deferred-supplier rework, outbound clearing action, outbound maturity alerts, reconcile-check review, expense-pay-by-instrument UI.
- **Generic expense reversal** (review MP-BLOCKER — pre-existing gap, now explicit): posted generic expenses have no reversal/void path; designing one (JE mirror incl. VAT + AP-or-cash contra + movement leg semantics) is its own money-path task.
- E6 multi-line expenses; E8 approvals; budget-vs-actual; YoY variance.
- Linked-cost VAT (capitalize net of recoverable taxes — WAC path, inventory-costing lane).
- Category-level default `vat_deductible_percent`; per-template auto-post toggle; weekly frequency.
- **Queued export** for very large sets (review RA-M6); counting-report export unhide / repo-wide export generalization.
- Expense OCR ingestion (`DocumentKind` has no Expense).
- Zod migration of the expense form (stays inline-RHF).

## 13. Standing invariants (handoff §6 — non-negotiable)

Every cash movement through the Phase-① port with a JE (`postEntryNow` / `SynchronousInTransaction` in-transaction, as `post()` does today); global lock order instrument→documents→GL advisory→repository (verified upheld in post and settle); precision rule 19 incl. FormRequest regex ceilings (VAT percent NOT currency-scaled); rule 20 in console contexts (banned no-arg scale helpers named in §5.3); reconcile checks #1–#4 green; fiscal perimeter untouched; `journal_entries(source_type,source_id)` non-unique — no new JE source types in this phase.

## 14. Interlocks

- Base: dev tip ≥ `bb44387f1` (banks-tug polish in; `PayExpenseDialog` settled).
- `chore/treasury-phase3-followups` (dispatch pending): no expense-file overlap; both owe `typescript:transform` — second-to-land re-runs it.
- `feat/design-system-unification` gate 4: re-check `git log origin/dev -- apps/web/src/features/expenses` before FE waves.
- `CODEX-replenishment-followups` (dispatch pending): `usePermissions.ts` collision — whoever lands second reconciles (hand-map update vs generated map, §8.5).

## 15. Review plan (per handoff process contract)

- ✅ Spec adversarial review DONE (4 lanes, all APPROVE-WITH-FIXES) → `docs/superpowers/specs/reviews/2026-07-13-treasury-phase4-expense-depth-spec-review.md`; Rev 2 = this document.
- Next: writing-plans → plan review (Fable on W1, Opus rest) → Codex brief with autonomous `claude -p` gates (Opus standard, Fable escalation only on money-path BLOCKER/HIGH) → **STOP at owner dispatch gate**.
