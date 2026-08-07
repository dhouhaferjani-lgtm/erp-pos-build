# GATE RECORD — R2-K-prev (deposit seal-before-resolve PREVENTION), tenancy/authz axis

**Round 1. Verdict: APPROVE-WITH-FIXES.**
**Branch:** `fix/r2k-prev-deposit-preflight` @ `fb590b977` (worktree `../erp.fix-r2kprev`), 2 commits on `264e6c483`.
**Lane test:** `./vendor/bin/phpunit tests/Feature/Partner/RecordCustomerDepositTest.php` → **OK (19 tests, 113 assertions)**, run by reviewer.
**Method:** every claim verified by reading the file and/or executing a probe in the worktree (probes deleted; tree clean).

Owned axes (membership-preflight correctness, authz reachability) are **CLEAN**; the implementer's central claim is **PROVEN, not merely plausible**. Two defects found while verifying — one a regression introduced by this diff, one a completeness overclaim that leaves the lane's headline defect class still open — must be closed or explicitly re-scoped before merge.

## AXIS-BY-AXIS

### Axis 1 — Membership preflight correctness: **PASS**
`DepositReferenceResolutionService.php:132-140`.
- **`companyId` cannot be smuggled via payload.** `refusalFor(companyId: $partner->company_id)` (`RecordCustomerDepositService.php:180`); `$partner` loaded in `PartnerDepositController.php:39-42` scoped to `CompanyContext::requireCompany()` (`:35`). Request body carries no company field; `RecordDepositRequest::rules()` independently pins scope (`:73`). `CompanyContext::$currentCompanyId` written only by `CompanyContextMiddleware::handle()` (`CompanyContextMiddleware.php:77`) after `userHasAccessToCompany()` (`CompanyContext.php:148-152`). An `X-Company-Id` for another company → 403, or (if active member there) 404 on the partner lookup. **No smuggle path.**
- **`actorUserId` from auth context, not input.** `PartnerDepositController.php:37` → `:71`. No `actor_user_id` in the request rules (`RecordDepositRequest.php:76-90`).
- **Tenant scoping airtight because it cannot be otherwise:** `user_company_memberships` has no `tenant_id` column (migration `2025_11_30_106000` lines 23-52); tenant-DB table under db-per-tenant → physical isolation; query byte-for-byte the bridge's (`TreasuryDepositBridge.php:432-436`).
- **`status` binding safe:** BackedEnum bind identical to `TreasuryDepositBridge.php:435`; no soft-delete semantics on enum or model.

### Axis 2 — Authz reachability for `POST /partners/{id}/deposits`: **CLAIM CONFIRMED**
"No standing non-member privilege path over HTTP" — proven on four legs:
1. **`can:` is company-blind:** `SetPermissionsTeam.php:23-31` sets `setPermissionsTeamId($user->tenant_id)` only.
2. **Real middleware stack** resolved via `Router::gatherRouteMiddleware()` for `partners.deposits.store`: EnsureFrontendRequestsAreStateful → ResolveTenancy → Authenticate:sanctum → SubstituteBindings → SecurityHeaders → SetLocale → **CompanyContextMiddleware** → SetPermissionsTeam → EnforceTokenTenantClaim → Authorize:payments.create. `CompanyContextMiddleware` (from `bootstrap/app.php:104-110`) sits after auth and before `can:` — priority sort does not reorder it out.
3. **No bypass registration:** one route file (`PartnerServiceProvider.php:19`); grep confirms only `app/Modules/Partner/routes.php:32,37` reach the controller/service. No console command or job reaches `RecordCustomerDepositService::record()`.
4. **Executed probe:** membership revoked, request re-issued: no header → **403 NO_COMPANY_ACCESS** (`CompanyContextMiddleware.php:57-64`); `X-Company-Id: <own company>` → **403 COMPANY_ACCESS_DENIED** (`:67-74`); both with `can('payments.create') === true` asserted and `FiscalEvent::count() === 0`.

Parity nit (not a defect on the HTTP path): the bridge's actor-`User`-row arm (`TreasuryDepositBridge.php:423-430`) is unmirrored — recorded as m-1 (mirror-drift ledger only; FK cascade + no SoftDeletes make it unreachable).

### Axis 3 — "Single middleware carries the whole company gate": **acceptable to merge; ticket-worthy**
`CompanyContextMiddleware` is the **only** company-membership gate in the request path; it is carried by the **`api` group** (`bootstrap/app.php:104-110`), so "rule-12 compliance" and "company gate present" are the same property — a route group registered without `'api'` silently loses the company gate while `can:` still passes. Undocumented. **Disposition:** ticket, not a merge blocker — this lane *improves* the posture (second, service-level membership assertion on the deposit path). Ticket: (a) document in `docs/conventions/03-AUTHORIZATION.md`; (b) CI test asserting every `api/v1/*` route (minus the `api/v1/admin*` exemption, `CompanyContextMiddleware.php:91-97`) has `CompanyContextMiddleware` in its gathered stack.

### Axis 4 — Test-harness honesty: **PASS, stronger than expected**
Probed: the **first** `TransactionBeginning` of the entire request is the sealing transaction (`RecordCustomerDepositService.php:79` via `PartnerDepositController.php:69`); the next nine are nested inside it or in the post-commit projection run. So in both mid-request probes (`RecordCustomerDepositTest.php:551-585`, `:643-679`) the mutation genuinely lands **after** the pre-transaction preflight, and only the in-transaction re-check at `:106` can catch it. `$fired` asserts are real guards.
`actingAs($user,'sanctum')` **does** run the full middleware chain here (revocation before request → 403 from `CompanyContextMiddleware`, proven live). What it does not exercise: real `CentralPersonalAccessToken` lookup + real tenant-DB switching (`phpunit.xml:41-46`: sqlite :memory:, `TENANCY_DB_PER_TENANT=false`). Pre-existing suite-wide limitation; conclusions hold because both tables are tenant-DB-local with no `tenant_id` predicate to get wrong.
**Gap:** the §65-80 answer is prose-only — see m-2.

### Axis 5 — Cross-module imports: **acceptable pre-merge, with a widened debt ticket**
Mirroring the bridge's predicate is right *for this commit* — but "the copies can't drift" is not settled: this very diff ships **three** drifts from the bridge (missing `User`-row check, missing checkpoint guard, frozen check applied where the bridge never applies it — m-1, I-2, I-1). Empirical drift, now. **Disposition:** don't force the `Shared/Contracts` port pre-merge; widen the debt entry in `2026-08-05-deposit-residual-seal-before-resolve-vectors.md` §90-105 to name the Company membership predicate alongside the Accounting one; port sketch `hasActiveCompanyMembership(companyId, userId)` + `activeAccountExists(tenantId, companyId, accountId)`.

## FINDINGS

### I-1 [Important] — NEW false refusal: maturity-tender (cheque/traite) deposits into a frozen repository are now rejected, though the bridge never checks the freeze on that path
`DepositReferenceResolutionService.php:151-153` (new). The freeze check is unconditional, but the movement port is **not** always called: `TreasuryDepositBridge.php:169-174` (`$shouldRecordMovement = false` on maturity leg) + `:217-219` (return before `record()` at `:249`). `HandlesMaturityTenderLeg::handles()` (`:33-37`) = `has_maturity && instrument_kind ∈ {Cheque, Effet}`; both `CHECK` and `TRAITE` are seeded active methods (`PaymentMethodSeeder.php:102-134`); `RecordDepositRequest.php:78-84` places no restriction on method code.
**Failure scenario:** drawer frozen for a cash count; back office records a customer cheque deposit → pre-fix: sealed, portfolio instrument, no cash movement, fine. Post-fix: 422 — a legitimate, fiscally-irrelevant operation blocked for the duration of every cash count. Self-indicting vs `:54-56` and `:146-150`.
**Fix:** gate `RepositoryFrozen` (and, for exactness, the pre-existing `RepositoryCurrencyMismatch` at `:142-144` — same over-refusal shape) on the deposit not being a maturity leg; promote the method `exists()` at `:82-87` to `->first()`. Red test: frozen repository + `CHECK` → **201**, receipt sealed, instrument created, no movement.

### I-2 [Important] — enumerated-parity overclaim: the movement port's **checkpoint** guard is not in the refusal set; the lane's headline defect class remains open on an ops-realistic path
`DepositReferenceResolutionService.php:29-37` (parity table, "Each case … maps to one invariant enforced post-seal today") + `RecordDepositRequest.php:32-36` ("all of which…").
Missing: `TreasuryDepositBridge.php:273` passes `allowBehindCheckpoint: ! $event->event_type->isServerOnly()` — **constant `false`** for `DEPOSIT_RECEIPT` → `TreasuryMovementService.php:99-103` calls `checkpointDisposition($repo, $occurredAt, false)` which throws `RepositoryCheckpointException` (`:873`) whenever `occurrenceDate <= checkpointDate`.
**Reachability (ops-realistic, same class as V1):** `occurredAt = now()` (`TreasuryMovementService.php:71`; bridge passes null at `:267`). `last_reconciled_at` set to end-of-day of a statement's `period_end` (`StatementCompletionService.php:257-264`, written `:167-171`); `period_end` has **no upper bound** (`ConfirmBankStatementRequest.php:23`). Reconcile a statement with `period_end` = today → every back-office deposit into that repository for the rest of the day passes the full new preflight, seals, then throws in the post-commit projection → **permanent hash-chained orphan receipt**. Precisely the D1/W-5c defect this lane exists to prevent.
**Fix (pick one before merge):** (a) add `RepositoryBehindCheckpoint` to the refusal enum mirroring `checkpointDisposition`'s predicate (same TOCTOU caveat); or (b) correct the docblocks (drop exhaustive/"all of which" language) and file the checkpoint gap as ticket residual V3. Shipping the current docblocks unamended is worse than the gap itself.

### m-1 [Minor] — preflight omits the bridge's actor-row existence/tenant check (`TreasuryDepositBridge.php:423-430`). Unreachable today (FK `ON DELETE CASCADE`; no SoftDeletes). Add the arm or record the deliberate omission in the parity table.

### m-2 [Minor] — the Axis-2 answer is prose-only; no regression test locks the 403. Add a DENY-path test: user **holds** `payments.create`, membership `Revoked` before the request → 403 (`NO_COMPANY_ACCESS` / `COMPANY_ACCESS_DENIED` with header), `assertNoDepositWasSealed()`.

### m-3 [Minor, PRE-EXISTING, out of lane — file separately] — `CompanyContextMiddleware.php:105-108` forwards raw `X-Company-Id` into a uuid-column query with no `Str::isUuid()` guard: 403 on SQLite (probed), SQLSTATE `22P02` → **500 on PostgreSQL** for any authenticated user on every `api/v1/*` route. SQLite-masked by `phpunit.xml:41`.

## VERDICT: **APPROVE-WITH-FIXES**
Fix I-1 (skip the new `RepositoryFrozen` refusal on the maturity-tender path, + red test), and close I-2 (checkpoint refusal or docblock re-scope + residual V3). m-1…m-3, the Axis-3 ticket, and the Axis-5 debt widening are follow-ups.
