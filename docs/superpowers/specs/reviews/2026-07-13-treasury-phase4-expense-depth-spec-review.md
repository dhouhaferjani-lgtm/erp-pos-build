# Treasury Phase ④ Expense Depth — Spec Rev 1 Adversarial Review (4 lanes, 2026-07-13)

Spec under review: `docs/superpowers/specs/2026-07-13-treasury-phase4-expense-depth-design.md` (Rev 1, commit `a4ecd9718`).
Lanes per handoff §process-contract: money-path = treasury-reviewer **Fable-tier** (owner tiering mandate); recurring/analytics = treasury-reviewer Opus; tenancy/authz = tenancy-authz-reviewer; FE = frontend-conventions-reviewer.

## Verdict summary

| Lane | Verdict | BLOCKER | HIGH | MEDIUM | LOW |
|---|---|---|---|---|---|
| Money path (Fable) | APPROVE-WITH-FIXES | 1 | 2 | 3 | 4 |
| Recurring + analytics (Opus) | APPROVE-WITH-FIXES | 0 | 3 | 6 | 4 |
| Tenancy/authz | APPROVE-WITH-FIXES | 0 | 3 | 2 | 2 |
| Frontend conventions | APPROVE-WITH-FIXES | 0 | 1 | 3 | 3 |

All §2 register corrections in the spec were independently re-verified by the Fable lane (full evidence table in its report) — E2 fixed, G17 part-stale, G20 decorative-outbound claims all CONFIRMED with file:line.

## Findings and Rev 2 dispositions

### Money-path lane (Fable-tier)

| # | Finding | Disposition in Rev 2 |
|---|---|---|
| **MP-BLOCKER** | §5.3 reversal claim is fiction: `ExpenseService::reverse` (`ExpenseService.php:432-441`) throws for every non-linked-cost expense; only the linked-cost reversal exists. The §9 "reverse nets VatDeductible to zero" test is unimplementable; §5.2's linked-cost VAT ban means VAT-carrying expenses are exactly the unreversible ones. | **ACCEPTED — scope OUT.** Generic-expense reversal does not exist today (no regression); Rev 2 removes the claim + test, states "VAT correction v1 = delete draft before post; posted generic expenses have no reversal path (pre-existing)", adds generic expense reversal to §12 deferred. |
| **MP-H1** | `documents.tax_amount` never reaches the VAT declaration: `EloquentVatDataRepository.php:29-39` INPUT branch for `d.type='expense'` sums `document_tax_details`, which nothing writes for expenses → GL 4456 fills while the declaration shows zero input VAT from expenses. `is_recoverable` in that report resolves from `tax_configurations`, which cannot represent per-expense partial deductibility. | **ACCEPTED — scope IN.** W1 writes a `document_tax_details` row at expense post with `tax_amount` = **deductible** VAT (the declarable amount, keeping GL 4456 ≡ declared input VAT), `tax_base` = subtotal. Partial-deductibility base treatment flagged EC5. |
| **MP-H2** | §8 guard set incomplete: `InstrumentRemittanceService::assertEligible` (`:265-280`) doesn't check direction; remittance routes are directly HTTP-reachable, so an Outbound instrument in `Received` is eligible for an inbound collection slip → wrong GL at `remit()`, before guarded `clear()` ever fires. | **ACCEPTED.** Fifth guard point added: `assertEligible` rejects Outbound, with test. `receive()` stays unguarded (deferred-supplier branch depends on it). |
| MP-M1 | Rounding mode unpinned: `bcformatStrict` truncates; `CurrencyScale::bcroundHalfUp` is the documented GL-posting-boundary helper (NC 01 §62). 1-millime cases diverge (0.0005 → 0 vs 0.001). | **ACCEPTED.** Rev 2 pins **`bcroundHalfUp`** for the deductible-VAT split (codebase posting-boundary convention; ≤1 ULP materiality; remainder method keeps balance either way). |
| MP-M2 | FormRequest-only cross-field rules break on `update()` partial-merge (`ExpenseService.php:132-150`): stored `vat_amount=15` + payload `total=10` passes request validation → negative net. Linked-cost trio ban can't see stored `expense_kind` either. | **ACCEPTED.** Cross-field rules (`vat_amount < total`, linked-cost ban, deductible presence) run in the SERVICE against effective merged values; FormRequest keeps format/regex ceilings. |
| MP-M3 | Wiring `partner_id` rolls unpaid-expense AP into supplier `payable_balance` (`PartnerBalanceService.php:303-355` via posted-JE listener) — correct but unstated user-visible behavior change on supplier statements. | **ACCEPTED.** Stated as intended W1 outcome + test (post unpaid w/ partner → balance includes total; settle → returns). |
| MP-L1 | `$this->scale()` (`GeneralLedgerService.php:58`) is the forbidden no-arg `getScale()` — implementer trap inside `createFromExpense`. | **ACCEPTED.** Named as banned in spec + Codex brief. |
| MP-L2 | `vat_deductible_percent` DB default 100 would stamp VAT-less rows. | **ACCEPTED.** Plain nullable column; conditional default at service boundary. |
| MP-L3 | `create()` ignores `document_date` (`ExpenseService.php:84` uses `payment_date ?? now()`); W2 needs the passthrough and it's W1's file. | **ACCEPTED.** `document_date` passthrough sequenced into W1. |
| MP-L4 | Legacy rows (`subtotal == total`) overstate "net" in W3 analytics; no other reader assumes `subtotal == total` (verified). | **ACCEPTED.** Analytics caption for pre-VAT rows. |
| MP-note | Reconcile #1–#4 verified compatible with the 3-line entry; fixture should set `repository.gl_account_id` so the authoritative branch is exercised. §13 invariants verified consistent. | Carried into §9 test notes. |

### Recurring + analytics lane (Opus)

| # | Finding | Disposition in Rev 2 |
|---|---|---|
| **RA-H1** | Idempotency model mismatch: `ExpenseService::create` dedups via SELECT-then-return (`:61-70`), not unique-violation; command can't distinguish created vs pre-existing; crash between create and cursor-advance re-notifies next run. | **ACCEPTED.** Rev 2: draft-create + cursor-advance atomic in one transaction; notification sent after commit, gated on `wasRecentlyCreated`. |
| **RA-H2** | Pause→resume with past-due cursor undefined: as written, drips back-dated drafts one per daily run. | **ACCEPTED.** Resume rolls `next_due_date` forward to first occurrence ≥ today; **no backfill**; per-run processing is single-occurrence by design (documented). |
| **RA-H3** | Forecast feed 2 already exists (`UpcomingPaymentsService::openUnpaidExpenses` `:117-133`, wired at `:39`); spec's §2/§6.4 stale. Real gap is the opposite: materialized-unposted drafts vanish from the forecast during the lead window (posted-only filter + advanced cursor). | **ACCEPTED.** §6.4 rewritten: (i) materialized recurring **drafts** (status Draft, `recurrence_template_id` not null) surface as concrete upcoming outflows; (ii) template projection covers only periods after the cursor; (iii) posted unpaid flows via the existing feed. No dedup join needed — cursor advance partitions (i)/(ii). |
| RA-M1 | `document_date = next_due_date` unreachable via current `create()`; `payment_date` abuse is semantically wrong on unpaid drafts and feeds JE `entry_date`. | **ACCEPTED** (= MP-L3). Passthrough in W1; command passes `document_date = next_due_date`, `payment_date` stays null. |
| RA-M2 | Analytics/list status parity: `index()` never defaults to posted; tiles fed by posted-only analytics contradict a draft-filtered list. (= FE-H1.) | **ACCEPTED.** Unified contract: analytics accepts `status` (default posted) + `category_id` + date range; tiles pass the list's status/category/date filters; `search` is never passed and the tiles show a caption when a search is active. |
| RA-M3 | `runInBackground` contradicts the cited exemplar — maturity-alerts deliberately runs in-process to surface partial-failure exit codes (`console.php:65-69`). | **ACCEPTED.** Dropped; in-process like the exemplar. |
| RA-M4 | Timezone unpinned on the `next_due_date − lead_days <= today` gate; exemplar uses `CarbonImmutable::today($company->timezone)`. | **ACCEPTED.** Company-timezone comparison mandated. |
| RA-M5 | Template edits must recompute `next_due_date`; snapshot semantics for already-materialized drafts unstated. | **ACCEPTED.** Recompute on `frequency`/`start_date` edit; generated drafts are independent documents — template edits never retro-modify them. |
| RA-M6 | Synchronous streamed CSV has execution-time/proxy-timeout ceiling on very large sets. | **ACCEPTED.** Ceiling acknowledged; queued export named as the follow-up path (§12). |
| RA-L1 | "§2: no export endpoint exists repo-wide" is FALSE — `ImportController.php:527-530` + `WithholdingCertificateController.php:278,314` already stream downloads. | **ACCEPTED.** §2 corrected; these become the cited exemplars (strengthens the plan). |
| RA-L2 | `idempotency_key` API contract is `uuid` while the command writes `recurring:{uuid}:{period}` (bypasses FormRequest) — allowed but asymmetric. | **ACCEPTED.** Documented in §6.2; no collision possible (verified). |
| RA-L3 | Feed-2 due field is `COALESCE(due_date, document_date)` in code, not `payment_date ?? document_date`. | **ACCEPTED.** Spec text matched to code. |
| RA-L4 | Analytics/export full-scan risk; no index named; N+1 not excluded. | **ACCEPTED.** Composite index on `documents(company_id, type, status, document_date)` (verify existing indexes first in plan); aggregations mandated single-query GROUP BY. |

### Tenancy/authz lane

| # | Finding | Disposition in Rev 2 |
|---|---|---|
| **TA-H1** | New perms seeded but role grants unspecified → silent 403 for all non-admins. | **ACCEPTED.** Explicit grant table added: `expense-recurrences.*` full → manager/accountant (admin gets all); `.view` → cashier/viewer/operator (mirrors `expense-categories.*`); `expenses.export` → manager/accountant only. Deny-path authz tests mandated. |
| **TA-H2** | Analytics/export/aggregates not specified to scope `company_id` — db-per-tenant isolates tenants, NOT companies; unscoped SUM leaks sibling-company spend. | **ACCEPTED.** `->where('company_id', $companyContext->requireCompanyId())` mandated on the analytics query, export cursor, and every sub-aggregate; two-companies-one-tenant leak test mandated. |
| **TA-H3** | FE permission map (`usePermissions.ts`) is hand-authored and already broke nav twice; spec never requires registering the new perms there → dead FE gating even after correct reseed. | **ACCEPTED.** Explicit owe added: update `usePermissions.ts` (or regenerate the map if the generator brief landed first) with the same grants as the seeder. |
| TA-M1 | Recurrence CRUD FK validation must use `ScopedExists::tenantAndCompany` (mirror `ExpenseRequest.php:53-68`) or cross-company FKs flow into every generated draft. | **ACCEPTED.** Mandated for all four FKs + company-filtered CRUD queries. |
| TA-M2 | Generation command must stamp `company_id = $template->company_id` explicitly (no context in console) and partition the scan per (tenant, company). | **ACCEPTED.** Stated explicitly in §6.2. |
| TA-L1 | `expenses.post` recipient filter silences the template author (operator holds create/update, not post). | **ACCEPTED as trade-off, kept.** `expenses.post` = action-owner filter; trade-off documented in §6.2. |
| TA-L2 | Pre-existing: `/expenses/{id}/post` route has no `can:` middleware (controller uses `Gate::authorize('post', …)` — likely covered by policy). | **NOTED, not a spec change.** Plan adds a verification step that the policy enforces `expenses.post`; if not, one-line hardening ticket. |

### Frontend lane

| # | Finding | Disposition in Rev 2 |
|---|---|---|
| **FE-H1** | Tiles/list filter-vocabulary divergence → headline tile can contradict the visible list. | **ACCEPTED** (= RA-M2 unified contract above). |
| FE-M1 | `usePartners` hook doesn't exist; canonical reuse target is the `PartnerPicker` molecule (`@/components/molecules/pickers`, `partnerType="supplier"`, `PartnerPickerValue.name` → `vendor_name` autofill). | **ACCEPTED.** §5.4 corrected to `PartnerPicker`. |
| FE-M2 | Percent inputs: no dedicated component; loyalty precedent uses `decimalPlaces={4}` which would 422 against the 2dp backend regex. | **ACCEPTED.** `<QuantityInput decimalPlaces={2}>` pinned for both percent fields. |
| FE-M3 | Export via bare link loses the Bearer header on reverse-proxy deployments. | **ACCEPTED.** Authenticated-blob pattern mandated (exemplar `PayrollExportPage.tsx:53-60`). |
| FE-L1 | Notification locale keys must be NESTED (i18next `keySeparator: '.'`), not flat dotted strings. | **ACCEPTED.** Noted in §6.3. |
| FE-L2 | Expense form is inline-RHF (no zod); spec silent on validation approach for new fields. | **ACCEPTED.** Decision: inline-RHF consistent with the existing file; zod migration out of scope. |
| FE-L3 | Adopt `DateRangeFilter` for from+to on the list (matches the cited G12 pattern) instead of a second bare `<Input>`. | **ACCEPTED.** |

## Cleared attacks (no action)
Route middleware stack correct for new endpoints; Expense module needs no `module:` gating (universal across verticals); `recurrence_template_id` cross-tenant leak impossible (physical isolation); permission naming matches seeder conventions; `TreasuryAlertNotification` is database-channel, not queued → no Horizon queue entry needed (rule-20 §10 claim verified); reconcile checks #1–#4 compatible with the 3-line VAT entry; settle() verified total-based (never recomputes from subtotal); update-after-post impossible (Draft-only guard); `deep_link` handled generically by `NotificationPanel.openNotification`; period-key collisions with user-supplied uuid idempotency keys impossible.

## Outcome

**All four lanes: APPROVE-WITH-FIXES → Rev 2 reconciles every ACCEPTED disposition above.** No finding was rejected; two were downgraded to documentation/plan-verification items (TA-L1 kept-as-trade-off, TA-L2 plan verification). Rev 2 committed as the same spec file (header updated); plan-writing proceeds from Rev 2.
