# Adversarial gate r1 — Session E I-1 follow-ups (treasury lens)

**Scope:** `fix/session-e-i1-followups`, range `6cb764179..f7fa0fb4f` (one commit, 16 files).  
**Worktree:** `.worktrees/i1-followups`, clean at `f7fa0fb4f`.  
**Context read:** `docs/superpowers/reviews/2026-08-27-session-d-i1-cash-tender-gate-r1-fiscal.md`, `docs/handoff/LEDGER.md` row D-T9-4, and CLAUDE.md rules 3/9/11/13/14/19.  
**Posture:** HIGH-effort, adversarial, read-only apart from this requested review. Tests used only dedicated PostgreSQL 16 throwaway databases on `127.0.0.1:5433`; all were dropped and confirmed absent.

## Findings — 0 Critical, 2 Important

### [IMPORTANT] The new per-company default sets are returned tenant-wide, so a second company receives both companies' indistinguishable methods

**Diff anchor:** `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:169-172`.  
**Interacting live path:** `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:31-45,302-323`.

This diff makes the standard payment-method set ordinary for every additional company by calling `PaymentMethodSeeder::run($company)`. That seeder correctly writes each row with the new company's `company_id` (`PaymentMethodSeeder.php:59-64`), so one tenant with two companies now normally owns two `CASH` rows, two `CARD` rows, and so on.

The canonical list endpoint is not company-scoped. `PaymentMethodController::index()` resolves `$companyId` at `:33` but queries only `tenant_id` at `:37-41`. Its serializer omits `company_id` (`:302-323`), so the caller cannot filter the duplicate rows itself. This contradicts the repository's existing declared invariant that Treasury payment methods are company-scoped (`apps/api/tests/Feature/Treasury/TreasuryCompanyIsolationTest.php:31-58`). That suite covers show/update but has no index test (`:189-212`). The lane's new company test inspects rows directly by `company_id` (`CreateCompanyTest.php:110-117`) and therefore cannot see the endpoint failure.

This is operational, not merely a disclosure concern:

- Web payment entry consumes `/payment-methods` as the active company's list (`apps/web/src/features/treasury/PaymentForm.tsx:440-450`).
- POS consumes the same endpoint (`apps/pos/src/api/paymentApi.ts:4-6`) and selects the first active flagged cash row (`apps/pos/src/stores/paymentStore.ts:991-997,1541-1553`). With two identical-position/name rows, which company's UUID is first is unspecified.
- The online receipt writer correctly rejects a foreign-company UUID (`StoreReceiptPaymentsRequest.php:100-118`; defense in depth at `ReceiptPaymentService.php:263-272`). Thus selecting the sibling's row turns an otherwise valid cash checkout into a 422 rather than corrupting fiscal data. The fail-closed writer keeps this below Critical, but second-company Treasury/POS behavior is unreliable immediately after the new provisioning succeeds.

The missing `company_id` predicate predates this commit, but this diff newly turns the conflicting duplicate standard sets into the default creation path. That interaction is introduced by this lane and is in scope.

**Required repair:** scope `PaymentMethodController::index()` by both tenant and current company, and add a same-tenant/two-company index regression that asserts only the active company's rows are returned. The store/update uniqueness rules at `PaymentMethodController.php:73-79,193-205` are also tenant-scoped while the database invariant is `unique(company_id, code)`; align them in the same repair so a sibling's standard code does not block a valid company-local create/rename.

### [IMPORTANT] The new RED regression converts a monetary DTO string to float, violating the explicit precision contract

**File:** `apps/api/tests/Feature/Accounting/SalesReportServicePaymentBreakdownTest.php:93-100`.

The new mixed-case regression ends with:

```php
$this->assertSame(15.0, (float) $row->amount);
```

`PaymentMethodBreakdownData::$amount` is deliberately a string (`PaymentMethodBreakdownData.php:13-18`). CLAUDE.md rule 19 says never let a float touch money (`CLAUDE.md:71-79`), and the precision reference explicitly names a `(float)` cast as unlicensed (`docs/architecture/precision-contract.md:61-65`). This line is newly added by this commit. Existing float assertions elsewhere in the old test are debt outside this diff; they do not license adding another one.

The targeted PHPStan run was green because it analyzed the touched production files, not this test assertion. The regression should compare the emitted monetary string exactly (or use a bcmath/string assertion), preserving the boundary contract it is meant to protect.

**Required repair:** remove the cast and assert the exact scale-correct string returned by the fixture.

## Requested verification

### 1. `SalesReportService` cash predicate — implementation verified; RED proof verified by execution

- The change-netting subquery still uses its existing `leftJoin('payment_methods', ...)` at `SalesReportService.php:332-334`; only the predicate changes to `payment_methods.is_cash_tender = true` at `:341`. It adds no query and creates no N+1.
- Positive flagged behavior remains pinned: the shared parent fixture marks `CASH` true (`InteractsWithOwnerReporting.php:67-73`), the sibling fixture now does the same (`SalesReportServicePaymentBreakdownTest.php:224-230`), and their expected net amounts remain asserted at `:263-275`.
- The new negative fixture is the exact brownfield shape requested: code `Cash`, flag false (`:62-70`), tender `15.000`, change `5.000` (`:71-91`), expected to remain `15` (`:93-100`).
- RED was not inferred. In an isolated disposable worktree I restored only the old `UPPER(pos_receipt_payments.payment_method_code) = 'CASH'` predicate and ran this one test on throwaway PostgreSQL. It failed exactly at `:99`: **actual `10.0`, expected `15.0`**. The current predicate passes it.

Production logic is correct. The only blocker in this section is the test's new float cast described above.

### 2. Second-company payment-method defaults — seeding mechanics verified; downstream list contract blocks approval

- Constructor injection is used exactly as required: `private readonly PaymentMethodSeeder` at `CompanyController.php:47-56`; the call is inside the company-creation transaction at `:78-187`.
- The seeder is self-guarding by tenant+company (`PaymentMethodSeeder.php:48-55`) and still keys `updateOrCreate` by tenant+company+code (`:59-64`), so a re-run cannot duplicate codes. The explicit idempotency and preserve-existing-set tests are at `PaymentMethodSeederCountryDefaultsTest.php:87-129`.
- Country selection comes from the persisted new company's `country_code`, not a controller literal or the first company's country (`PaymentMethodSeeder.php:57,74-80`). TN, FR, and generic coverage runs through the same seeder (`PaymentMethodSeederCountryDefaultsTest.php:44-84`).
- The first-company registration path calls the same seeder (`TenantInitializationService.php:104-105,285-289`), so a fresh empty first company and a fresh empty additional company receive definitions from one authority. Fresh provisioning behavior is unchanged. The new guard intentionally changes only re-runs against a nonempty configured set; normal registration failure compensation drops the partial tenant database.
- The company endpoint test verifies a nonempty FR set, a canonical `CASH`, and exactly one flagged row (`CreateCompanyTest.php:99-117`). It does not verify exact set parity or the live list endpoint.

The seeding itself satisfies the requested country/idempotency/DI properties. Approval is blocked because the live endpoint returns the newly duplicated sets tenant-wide (Important #1).

### 3. `AddPaymentMethodModal` flag, i18n, and refusal envelope — verified

- `is_cash_tender` is in the form type/defaults/capability list (`AddPaymentMethodModal.tsx:35-49,57-74,103-118`) and is sent in the create payload at `:145-162`.
- The two frontend map keys exactly equal the backend enum values (`AddPaymentMethodModal.tsx:76-87`; `CashTenderInvariantRefusalCode.php:52-56`). Rendering reads `response.data.error.code` and maps known codes to translations (`AddPaymentMethodModal.tsx:308-323`).
- Backend refusals are `{error:{code,message,payment_method_code}}` with HTTP 422 (`PaymentMethodController.php:441-449`). `isApiError()` accepts that actual envelope because it checks for `data.error` (`apps/web/src/lib/api.ts:51-57`). The modal test's synthetic response includes a superfluous `meta`; the backend does not, but the extra test field does not mask the runtime branch.
- English and French keys are complete at `apps/web/src/locales/{en,fr}/treasury.json:596,622,630-631`; Arabic is complete at `apps/web/src/locales/ar/treasury.json:849,875,883-884`. All three JSON files parse.
- Vitest pins default false, selected true, and both refusal codes (`AddPaymentMethodModal.test.tsx:58-137`).

No new Critical/Important here.

### 4. Draft typed failure and autosave state — verified; no fabricated-ID consumer found

- `DraftController` no longer imports or calls `Str::uuid()`. The blanket failure arm logs, returns HTTP 500, and emits only `{error:{code:'DRAFT_AUTO_SAVE_FAILED',message}}` (`DraftController.php:205-217`); it contains neither `draft_id` nor `saved_at`. The backend regression forces a real model-create failure and asserts the typed envelope plus both missing success fields (`AutoSaveRouteHardeningTest.php:1264-1294`).
- `useDraftAutoSave` reads the raw success body only after a fulfilled request (`useDraftAutoSave.ts:158-172`). A rejection is converted with the shared `getErrorMessage`, sets `autosaveFailed`, stores `lastError`, clears pending/saving, and calls `onError` (`:188-199`). It does not set `draftId`, `lastSavedAt`, or call `onSuccess` on that branch.
- The focused hook regression asserts the server message, `draftId === null`, `lastSavedAt === null`, no `onSuccess`, and an `onError` call (`useDraftAutoSave.state.test.tsx:31-70`).
- Repo-wide consumer search found one production hook consumer: `DocumentForm.tsx:276-285`. Its form-reset callback is success-only (`:266-271`), its effective document id is `id || draftId || ''` (`:495-498`), and `autosaveFailed` feeds the unsaved-changes guard (`:293-307`). No consumer depended on a fabricated UUID. The hook exposes `lastError`; `DocumentForm` currently uses the failure bit for guarding rather than rendering the server prose inline, which is not a fake saved state.

No new Critical/Important here.

## Rules 3/9/11/13/14/19 check

- **3 strict typing:** no new PHP `mixed` dependency surface or TypeScript `any`; targeted PHPStan and `tsc --noEmit` are green.
- **9 enums:** backend cash refusal codes remain a backed enum. `DRAFT_AUTO_SAVE_FAILED` is an API error code, not a persisted status/type/code column.
- **11 frontend strings:** the new modal label, explanation, and refusal prose all use en/fr/ar translation keys.
- **13 constructor injection:** the new seeder dependency is constructor-injected `private readonly`.
- **14 API handling:** the modal uses `apiPost`'s unwrapped success value; the autosave hook deliberately uses raw `api.post` because that legacy endpoint returns an unwrapped success body, as documented at `useDraftAutoSave.ts:151-163`. No double unwrap was introduced.
- **19 precision:** production report math remains string/bcmath. The newly added test cast is a violation and is blocking (Important #2).

## Evidence executed

| Check | Result |
|---|---|
| Range/worktree hygiene | One commit, 16 files; `git diff --check` clean; lane worktree clean at end |
| Four touched backend classes on PostgreSQL 16 throwaway `autoerp_test_i1_followups_gate_r1` | **OK — 57 tests, 257 assertions** |
| Executed RED proof with only old cash predicate restored, PostgreSQL throwaway `autoerp_test_i1_followups_red` | **Expected failure — 1 test, actual 10.0 vs expected 15.0** |
| Focused frontend tests | **OK — 2 files, 12 tests** |
| Targeted PHPStan on four changed production PHP files, throwaway `autoerp_test_i1_followups_phpstan` | **OK — no errors** |
| Pint `--test` on eight touched PHP/test files | **pass** |
| Web `pnpm typecheck` | **pass** |
| Targeted ESLint on four changed TS/TSX files | **0 errors, 6 warnings** (pre-existing-style compiler/hook warnings; none blocks this diff) |
| en/fr/ar treasury JSON parse | **pass** |
| Throwaway database cleanup | all three database names confirmed absent |

GATEVERDICT: CHANGES
BLOCKING: Scope the payment-method index and uniqueness validation to the current company, with a same-tenant/two-company regression, before making duplicate default sets the normal second-company state.
BLOCKING: Remove the newly added float cast from the mixed-case SalesReport regression and assert the monetary string without violating rule 19.
