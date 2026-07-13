# Treasury Phase ④ — Waves 2–4 Plan Review (Opus lane, adversarial)

> Independent plan review, lane 2 of 2 (owner tiering: Opus standard tier). Reviewer: treasury-reviewer agent on `claude-opus-4-8`, tenancy/authz + FE-conventions dimensions folded in. Companion lane: `2026-07-13-treasury-phase4-plan-review-w1-money-path-fable.md`.

**Scope reviewed:** Tasks 7–17 (W2 recurring templates, W3 analytics + CSV export, W4 outbound guards + closeout), plus the tenancy/authz and FE-conventions dimensions. Wave 1 (Tasks 1–6) reviewed only where a W2–W4 task consumes a W1 contract.
**Base commit:** dev HEAD `6e95f309e` (verified against working tree).
**Docs read in full:** plan `docs/superpowers/plans/2026-07-13-treasury-phase4-expense-depth.md`; spec Rev 2 `docs/superpowers/specs/2026-07-13-treasury-phase4-expense-depth-design.md`; spec adversarial review `docs/superpowers/specs/reviews/2026-07-13-treasury-phase4-expense-depth-spec-review.md`.

---

## Verified accurate (ground truth for the executor)

All anchors and contracts below were opened and confirmed on the current tip. The executor may rely on these without re-checking:

- **`InstrumentMaturityAlertsCommand.php:41-90`** — `executeCommand()` → `forEachTenant(closure)` → inner `Company::where('tenant_id')->orderBy('id')->get()` per-company loop with `try/catch` error isolation, exactly the shape the plan says to copy for Task 10. `alertCompany()` uses `CarbonImmutable::today($company->timezone)` at `:98`. Confirmed verbatim-copyable.
- **`TenantScopedCommand.php:150-187`** — `forEachTenant` iterates **tenants** (`Tenant::all()`), and for db-per-tenant calls `tenancy()->initialize($tenant)` (`:161-163`); it does **NOT** bind `CompanyContext`. Continue-on-throw per-tenant isolation confirmed. The plan's assumption that the command does its own per-company loop is correct.
- **`routes/console.php:65-69`** — `treasury:instrument-maturity-alerts` scheduled in-process (`dailyAt('06:30')->withoutOverlapping()`, no `runInBackground`). Correct exemplar for the Task 10 schedule block.
- **`TreasuryAlertRecipients::forCompany(string $tenantId, string $companyId, string $permission = 'treasury.manage')`** (`:23-27`) — three-arg signature with team-id set/flush + active-company-membership filter + `->permission($permission)`. Task 10's `forCompany($tenantId, $companyId, 'expenses.post')` call matches. Confirmed W2 exemplar.
- **`TreasuryAlertNotification`** (`:14-30`) — constructor is `(string $alertType, array $data)`; `via()` returns `['database']` ONLY (no queue). Therefore the plan's claim "`HorizonQueueCoverageTest` untouched — database channel, no queue" is **TRUE**.
- **`UpcomingPaymentsService.php:38-45`** — `$outgoing` concats `openUnpaidExpenses` at `:39`; **`:117-133`** `openUnpaidExpenses` is `type=Expense`, `status=Posted`, `total>0`, `whereHas expenseMetadata is_paid=false`, due `COALESCE(due_date, document_date) <= windowEnd`. Task 11 correctly builds on this.
- **`ExpenseRequest.php:53-68`** — `ScopedExists::tenantAndCompany('expense_categories'|'payment_methods'|'payment_repositories', $tenantId, $companyId)` exemplar confirmed; `idempotency_key` already validated as `uuid` (`:77`), confirming the RA-L2 note that the command's prefixed key legally bypasses the FormRequest (service called directly).
- **`ExpenseService::create` idempotency short-circuit (`:61-70`)** — on hit, returns `Document::whereKey(...)->firstOrFail()->load('expenseMetadata')` (a **re-query** ⇒ `wasRecentlyCreated === false`); on miss, returns the `Document::create(...)` instance (`:78-88`, `wasRecentlyCreated === true`). The load-bearing `wasRecentlyCreated` contract in Task 10 **holds**.
- **`InstrumentLifecycleService`** guard points confirmed: `custodyTransfer` `findOrFail` `:135`, `deposit` `:175`, `clear` `:194`, `bounce` `:316`. `receive()` `:66` and `cancel()` `:583` exist and are separate. `InstrumentRemittanceService::assertEligible` `:265` (first check is the Received/re-presentable branch — correct insertion point).
- **`InstrumentDirection`** enum has `Inbound`/`Outbound`; **`ExpenseKind`** = `Generic='generic'`/`LinkedCost='linked_cost'` (matches Task 3 code). Manual registration `PaymentInstrumentController::store` accepts `direction=outbound` (`:171`) and passes it to `receive()` (`:186-190`) — **Task 16's Outbound-creation test dependency holds**, and `receive()` must stay direction-neutral.
- **`expense_metadata`**: `idempotency_key` string(128) `nullable` + `unique('expense_metadata_idempotency_unique')` (`2026_06_27_120000_add_idempotency_key…`); `vendor_name` present in base table. Task 10's `"recurring:{uuid}:{period_key}"` key (~54 chars) fits 128. Task 1's "after vendor_name" is legal.
- **Seeder anchors**: catalog `expense-categories.*` block `:186-189`; manager grant `:470`; accountant grant `:690`; admin `syncPermissions(Permission::all())` `:445`. `expense-categories.view` granted to cashier (`:553`), viewer (`:593`), operator (`:659`) — so §8.5's view-for-cashier/operator/viewer precedent is real and Task 9's "cashier GET 200 / POST 403" deny-path is achievable. `expenses.export` is **not** currently seeded (Task 9 adds it) — confirmed.
- **`usePermissions.ts:4`** — hand-authored `PERMISSIONS: Record<perm, role[]>` map; Task 9/12's FE-map edit shape is correct.
- **FE components exist with stated props**: `DateRangeFilter` at `src/components/ui/filters/DateRangeFilter.tsx` with `{label, fromValue?, toValue?, onFromChange, onToChange}` (exact match to Task 15); `PartnerPicker` at `molecules/pickers` with `partnerType?` + `PartnerPickerValue.name`; `CashMovementsReportPage.tsx` exists (W3 template); `PayrollExportPage` blob pattern (`api.*(…, {responseType:'blob'})` → Blob → objectURL → click → revoke) confirmed. `ImportController` `streamDownload` exemplar confirmed.
- **`NotificationPanel.tsx`** (`src/features/notifications/components/`) — `KNOWN_TYPES` Set (`:19`), `displayMessage` switch on `notification.type` with a `default → t('messages.generic')` (`:33-52`), title via `t('types.${notification.type}')` (`:126`). `notifications.json` uses **nested** `types.treasury.instrument.maturity_alert` (i18next default `.` keySeparator). Task 12's nested-key requirement (FE-L1) is correct, and the stored `notification.type` will equal the `alertType` string `'expense.recurring.generated'` (via `databaseType()`), resolving to `types.expense.recurring.generated`.
- **`ExpenseListPage.tsx`** — `date_to`/`search` already threaded through the filter reducer (`:66`); lone `date_from` input at `:136-143` (Task 15 replaces it). **`ExpenseController::index`** filter block (`:52-83`) is a clean sequence of `if ($request->filled(...))` mutations on `$query` with company scope at `:45` — cleanly extractable into the shared `ExpenseIndexQuery` (Task 14).
- **Route-order trap** — `routes.php:21` group already registers static `expenses/linkable-invoices|linkable-operations` BEFORE `expenses/{id}` (`:27`); Task 13/14 placing `analytics`/`export` above `{id}` is correct and matches precedent.
- **`documents` indexes** — only `(tenant_id, type, status)` and `(tenant_id, document_date)` exist; **no** `company_id`-leading composite. The Task 13 `pg_indexes` check will correctly find no equivalent and add `documents(company_id, type, status, document_date)`. The step is justified, not busywork.
- **`Company`** has a `timezone` attribute (fillable `:223`), so Task 10's `CarbonImmutable::today($company->timezone)` is valid.
- **RecurrenceCursor origin-anchoring** — the Task 8 test `start 2026-01-31 monthly → 2026-02-28, 2026-03-31` is the correct discriminating case: `next(2026-01-31, monthly, current=2026-02-28) → 2026-03-31` proves origin anchoring (result day 31 despite current day 28), which current-anchored math would get wrong (2026-03-28). Cursor is date-only ⇒ DST/TZ-agnostic; the only TZ touchpoint is the command's `today($company->timezone)`. Design is sound.

---

## Findings

### 1. [BLOCKER] `ExpenseService::create` is not console-safe — Task 10 cannot call it as written
**Evidence:** `ExpenseService.php:72` — `$companyCurrency = $this->companyContext->requireCompany()->currency;` runs **unconditionally at the top of `create()`**, before any branching. `CompanyContext::requireCompany()` (`CompanyContext.php:100-110`) calls `requireCompanyId()` which **throws** `RuntimeException('No company context set…')` when unbound (`:46-53`). `TenantScopedCommand::forEachTenant` (`:150-187`) never binds `CompanyContext` (the maturity exemplar operates directly on models and never calls a context-dependent service). Task 10's per-company loop passes `'company_id' => $template->company_id` in `$data` but `create()` derives **currency** from the context, not from `$data`, and ignores any explicit currency.

**Why it matters:** Following the plan literally, `expenses:generate-recurring` throws on the **first** template of the run, for **every** company — the entire W2 generation mechanism (D2's headline deliverable) is dead, and every Task 10 test fails at the first `create()`. This is precisely a W1↔W2 contract mismatch: Task 10 consumes the Task 3 `create()` contract, but Task 3's interface/implementation does **not** make currency resolution console-explicit, and the plan's own Global Constraint ("Rule 20: no CompanyContext in commands — pass company_id/currency explicitly") is violated by the very method it tells Task 10 to call.

**Fix:** Add to **Task 3 (W1/Fable lane)** a required change: `create()` must resolve currency from the explicit `$data['company_id']` (load `Company::findOrFail($data['company_id'])->currency`) or accept an explicit `$data['currency']`, and `prepareLinkedCost`'s currency argument must derive from the same source — eliminating the `requireCompany()` call for the console path. Then Task 10 passes `company_id` (already planned) and the console path works with no `CompanyContext`. Cross-reference this dependency explicitly in both Task 3 and Task 10 so the Fable lane doesn't ship a create() that only works under HTTP. (Do **not** "just bind `setCompanyId` in the command" — that re-introduces the rule-20 pattern the plan bans and leaves the HTTP/console split inconsistent.)

### 2. [HIGH] Task 10 never defines the `User` passed to `ExpenseService::create($data, $user)` — tenant stamping is unspecified
**Evidence:** `ExpenseService::create` stamps `'tenant_id' => $user->tenant_id` on the Document (`:79`). Task 10's code block calls `$this->expenseService->create([...], $systemUser)` inside a closure whose `use (…)` list captures `$user` — two different identifiers, and **neither is ever resolved** anywhere in the task. The command has `$template->tenant_id` and `$template->created_by` available, but the plan gives no instruction on how to obtain a `User` model.

**Why it matters:** If `$user` is null or belongs to the wrong tenant, generated drafts get a wrong/empty `tenant_id`, corrupting tenant isolation in a db-per-tenant DB (the row's `tenant_id` would mismatch the DB). This is a correctness + isolation defect, and it's an unspecified contract the executor will guess at.

**Fix:** Specify in Task 10 that the command resolves the acting user as `User::query()->where('tenant_id', $template->tenant_id)->whereKey($template->created_by)->first()` (falling back to a deterministic system/admin user in that tenant if `created_by` is inactive/absent), and pass exactly that to `create()`. Add a test asserting the generated `documents.tenant_id` equals `$template->tenant_id`. Fix the `$systemUser`/`$user` identifier inconsistency in the code block.

### 3. [MEDIUM] Analytics `share_percent` has no divide-by-zero guard specified
**Evidence:** Spec §7.1 `by_category[].share_percent`; plan Task 13 specifies `mom_delta_percent = null when previous = 0` but says nothing about `share_percent` when the grand total is `'0'` (an all-zero or empty filtered period — reachable when a category filter + narrow date range returns no posted spend). bcmath `bcdiv($x, '0', …)` triggers a division-by-zero warning/error.

**Why it matters:** A legitimately empty filter window would 500 the analytics endpoint (and thus the list tiles). Low blast radius but real.

**Fix:** Task 13: define `share_percent = grandTotal == '0' ? '0.00' : bcround(bcmul(bcdiv(catTotal, grandTotal, scale+4), '100', scale+4), 2)`. Add a Step-1 test: empty/zero-total window returns tiles with `total:'0.00'`, `mom_delta_percent: null`, and `by_category: []` (or zero shares) without error.

### 4. [MEDIUM] Lead-days pre-filter magic `60` is silently coupled to the FormRequest `lead_days` max
**Evidence:** Task 10 pre-filters `whereDate('next_due_date', '<=', $today->addDays(/* max lead */ 60))` then post-filters on actual `lead_days`. Correctness depends entirely on Task 9's FormRequest capping `lead_days` at 60. The two `60`s live in different files with no shared constant.

**Why it matters:** If a later change raises the FormRequest max (or a template is seeded/imported with `lead_days > 60`), the pre-filter silently drops templates whose materialization date is due but whose `next_due_date` is beyond `today+60` — a template that should generate never does, with no error. Correct today, fragile tomorrow.

**Fix:** Define one constant (e.g. `ExpenseRecurrenceTemplate::MAX_LEAD_DAYS = 60`) used by BOTH the FormRequest `max` rule and the command pre-filter. Cheap, kills the drift class.

### 5. [LOW] Forecast partition has an untested gap when a materialized draft is deleted
**Evidence:** Spec §6.4 / Task 11 partition drafts (feed 1) vs projection (feed 2) via cursor position: once a draft is materialized the cursor advances past that period, so feed 2 no longer projects it. Drafts are user-deletable (`ExpenseController::destroy`, `routes.php:29`). If the user deletes a generated draft, the period is gone from feed 1 AND already behind the cursor for feed 2.

**Why it matters:** The period silently disappears from the 30-day forecast even though the recurring obligation conceptually still exists. Arguably acceptable (explicit user deletion), but the plan's tests don't acknowledge it, so behavior is undefined-by-omission.

**Fix:** Either accept and document the semantics ("deleting a generated draft removes that period from the forecast; it will not regenerate — the cursor has advanced"), or add a Task 11 test pinning the chosen behavior. No code change required if documented.

### 6. [LOW] Sixth instrument mutation entry point (`updateDetails`) not addressed in Task 16
**Evidence:** `InstrumentLifecycleService::updateDetails` (`:646`) mutates an instrument and is a public method; Task 16 guards only the five collection-lifecycle points and leaves `receive()`/`cancel()` neutral. `updateDetails` is neither guarded nor explicitly declared neutral.

**Why it matters:** Spec §12 defers outbound editing; leaving `updateDetails` open for Outbound is almost certainly correct (you must be able to edit an outbound instrument's reference/amount), but the plan doesn't state the decision, so a future reviewer can't tell if the omission was intentional.

**Fix:** One sentence in Task 16: `updateDetails` (and `receive`/`cancel`) intentionally stay direction-neutral; only the five collection actions gain guards. No code change.

### 7. [LOW] Notification payload → i18n interpolation key mapping unspecified in Task 12
**Evidence:** `displayMessage` (`NotificationPanel.tsx:37-52`) manually maps snake_case `notification.data` fields to camelCase interpolation vars (e.g. `receivedCount` from `received_due_count`). Task 10 emits `data: {template_name, amount, currency, due_date, deep_link}`; Task 12 says "add a `displayMessage` case" but doesn't state the interpolation contract.

**Why it matters:** Without a stated mapping, the new `messages.expense.recurring.generated` template placeholders and the `displayMessage` case can drift, yielding `{{templateName}}`-style literal leakage in the bell.

**Fix:** Task 12: pin the interpolation contract (e.g. `t('messages.expense.recurring.generated', { name: data.template_name, amount: formatCurrency(data.amount, data.currency) })`) and add a Vitest assertion that the rendered message contains the template name + formatted amount (not raw placeholders).

---

## Test-adequacy notes (high-value cases to add)

- **Task 10:** assert generated `documents.tenant_id === $template->tenant_id` (ties to Finding 2); assert the day-31 template generates a draft with `document_date` = the clamped due date (origin-anchored), not a drifted one.
- **Task 13:** empty/zero-total window (Finding 3); a pre-W1 legacy row (`subtotal == total`, `tax_amount` null) is summed without error and the legacy-net caption path is exercised.
- **Task 14:** a row whose `document_date` sits exactly on `date_to` is included in the export (boundary), matching `index()`'s `<=` semantics — proves the shared `ExpenseIndexQuery` didn't flip an inequality.
- **Task 11:** the draft-deletion semantics (Finding 5), whichever way it's decided.

Everything else in the W2–W4 plan (cursor math design, company-partitioned scans, `wasRecentlyCreated` replay gate, notification-after-commit ordering, five-point guard set, route-order handling, permission grant table vs §8.5, nested notification keys, `tenantScopedKey`/blob-export/`DateRangeFilter` FE conventions) is verified consistent with the code and the spec.

---

**VERDICT: CHANGES-REQUIRED**

**What to fix before merge:** Finding 1 (make `ExpenseService::create` console-safe by resolving currency from explicit `company_id` in Task 3, or the entire recurring-generation command dies on first call) and Finding 2 (specify the acting `User` so generated drafts stamp the correct `tenant_id`); the rest are MEDIUM/LOW hardening the executor should fold in.
