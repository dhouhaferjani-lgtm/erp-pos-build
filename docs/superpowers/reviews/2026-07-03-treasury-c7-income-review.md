# Treasury C7 — Income Recording (BE+FE) — Adversarial Review

**Branch:** `feat/treasury-c7-income` | **Commits:** 3278aa1cc (BE), 92d610302 (FE)
**Base:** origin/post-demo (633054a95) | **Reviewer:** treasury-reviewer | **Date:** 2026-07-03

## VERDICT: PASS (gate only — human merges)

Contract fully satisfied. No blockers, no majors. gl_account_id trap correctly avoided,
money-as-strings end-to-end, single-post idempotency enforced, atomic transaction, currency
passed explicitly. Only minor/nit-level notes below, all mirroring the pre-existing Expense flow.

## What was verified (file:line)

- **gl_account_id trap — CORRECT.** `GeneralLedgerService::createFromIncome` debits the
  receiving repository's `gl_account_id` (GeneralLedgerService.php:2345-2350), never `account_id`.
  Falls back to Cash/Bank system account by repo type, then Cash. Credit leg = class-7 revenue
  (`income_account_id` scoped/pinned by tenant+company, else `SystemAccountPurpose::ProductRevenue`,
  :2327-2338). Balanced entry (debit=credit=`income->total`, :2376-2395). Currency passed
  explicitly `(string) $income->currency` (:2400). Test asserts debit hits gl_account_id
  (IncomePostTest.php:91-96).
- **Inflow ADDS to balance.** `RepositoryInflowService::applyInflow` (pre-existing, bound in
  TreasuryServiceProvider.php:56-57) `bcadd`s with resolver scale + explicit currency, lockForUpdate.
  `IncomeService::post` calls it only when `is_received===true && payment_repository_id!==null`
  (IncomeService.php:140-148), amount/currency as strings.
- **Single-post / no double-increment.** `IncomeService::post` guards `status!==Draft` (throws)
  (:123-125); controller returns 422 on already-posted (IncomeController.php:194-198).
  Both GL + inflow inside one `DB::transaction` (:127). Test covers re-post 422 + no double
  increment (IncomePostTest.php:109-143).
- **Money regex ceiling.** IncomeRequest.php:68 — `numeric` + `min:0.01` + `/^-?\d+(\.\d{1,3})?$/`.
  Test asserts 4-decimal rejection (IncomeStoreTest.php:78-87).
- **income_account_id restricted to Revenue.** ScopedExists tenant+company + `where('type','revenue')`
  (IncomeRequest.php:51-55). Test asserts non-revenue rejected (IncomeStoreTest.php:94-110).
- **Append-only enum.** `DocumentType::Income` appended last with getPrefix/label arms
  (DocumentType.php). All existing `match($doc->type)` sites (DocumentPolicy.php:48-120) use
  `default =>`, so no UnhandledMatchError risk.
- **Idempotency.** `idempotency_key` uuid, tenant-scoped `unique` (migration :35), checked in
  create (IncomeService.php:38-47). Test asserts single row on double-create (IncomeStoreTest.php:117-138).
- **Routes middleware.** `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`
  + per-verb `can:income.{view,create,update,delete,post}` (routes.php:20-27). Permissions seeded
  (RolesAndPermissionsSeeder) + FE role map (usePermissions.ts).
- **No float on money.** BE resource returns `total`/`currency` as strings (IncomeResource.php:31-32).
  FE uses `<MoneyInput>` (strings) + `bccomp` validation, zero parseFloat/Number in
  `apps/web/src/features/income/**`. `api.get`/`api.post` single-unwrap (`data.data`), list keeps
  `{data,meta}` (incomeApi.ts). Income account select filtered to `type=revenue`
  (IncomeFormFields.tsx:46). FE routes gated by RequirePermission.
- **Tests:** RefreshDatabase + real models + RolesAndPermissionsSeeder, no trivial asserts, no
  faked payloads.

## Findings

- [nit] IncomeController.php:194 — `post` guards only `status === Posted` for its 422, while the
  service guards `status !== Draft`. A non-Draft/non-Posted status (e.g. Cancelled) would slip past
  the controller and hit the service's `RuntimeException` → 500 instead of 422. Unreachable today
  (income only goes Draft→Posted), but tightening the controller to `!== Draft` would match Expense.

- [minor] GeneralLedgerService.php:2376-2395 / IncomeService.php:140 — when `is_received=false`, the
  GL still books `Dr cash/bank · Cr revenue` but the treasury repository balance is NOT incremented,
  so the GL cash account and the treasury sub-ledger diverge (an un-received income should arguably
  debit a receivable). This is an EXACT mirror of the pre-existing Expense flow (is_paid), so it is
  consistent with the C7 "mirror Expense" contract; flagging only so it is a conscious accounting
  decision, not a regression.

- [minor] IncomeService.php:162-179 — `generateIncomeNumber` derives the next `INC-YYYY-NNNNNN` by
  `orderByDesc(document_number)` without a locked sequence; concurrent posts could collide on
  document_number. Same pattern as Expense; low risk at demo scale.

- [nit] IncomeService.php:38-47 — idempotency lookup runs before the transaction; two truly-concurrent
  identical creates could both pass the check, and the second insert would 500 on the unique
  constraint rather than returning the existing doc. Acceptable edge for this scope.

## One-line
Ship-ready as a gate PASS; optionally tighten the controller post-guard to `!== Draft` before merge.

---

## Independent orchestrator gate (second treasury-reviewer instance) — VERDICT: PASS-WITH-NITS

Independent of the implementer-run gate above; verified against code with citations. All nine axes PASS: createFromIncome debits repository `gl_account_id` (tenant+company pinned) / credits class-7 with explicit currency and no float; RepositoryInflow exactly-once (422 + no double-increment both asserted); DocumentType::Income append-only with every external match carrying a default arm; per-verb `can:income.*` + seeder + gated FE routes; server-side Revenue-type account restriction + money regex; `!== Draft` post-guard sound (no cancel/void hole); FE money strings end-to-end, i18n en/fr/ar key parity (39 leaf keys); `income_metadata` migration correctly under `migrations/tenant/` with unique idempotency key; module boundaries via Shared/Contracts + public services.

Non-blocking minors (on record): (1) `is_received=false` books Dr Cash/Cr Revenue but skips the treasury increment — exact mirror of Expense `is_paid`; follow-up on GL roadmap: debit a receivable instead; (2) idempotency pre-check outside the transaction — concurrent duplicate key surfaces 500 instead of returning the existing document (client-UUID, low probability); (3) journal_lines decimal(15,2) DB vs decimal:3 model — pre-existing repo-wide drift, not introduced by C7.

Merged into `feat/treasury-cash-movements-income` after a clean 3-way with origin/post-demo (`2556d2b5d` multiloc); merged-state verification: 31 BE tests / 127 assertions + 6 FE tests + typecheck, all green.
