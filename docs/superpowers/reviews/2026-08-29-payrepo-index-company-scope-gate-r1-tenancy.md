# Payment-repository index company scope — adversarial gate r1

Date: 2026-08-29  
Lens: tenancy/authz + Treasury  
Lane: `fix/payrepo-index-company-scope`

## Reviewed state

- Target worktree: `.worktrees/payrepo-index-company-scope`.
- Prompt base: `dev` at `0cbaa1457`.
- Actual reviewed state was **clean and committed**, not dirty/uncommitted: branch `fix/payrepo-index-company-scope`, HEAD `0a29c564d`. `git status --short`, `git diff`, and `git ls-files --others --exclude-standard` were empty. The HEAD commit contains only the controller predicate, the new regression class, and the Treasury manifest raise.
- The broader `0cbaa1457...HEAD` range also contains pre-existing/intermediate documentation commits; they are not part of this micro-lane gate. Source remained read-only. This review is the only file written.

## Verdict

**APPROVED.** The list leak is closed with the active `CompanyContext` company id, all requested Treasury read/list surfaces were independently re-audited without finding another cross-company leak, the regression is base-red for the correct reason, and the prescribed SQLite/PostgreSQL/manifest checks pass. The tenant-only repository-code validators remain a real write-side follow-up, but they do not block tomorrow's G-3c second-company creation journey because that journey uses the production provisioner directly and the live database unique key is company-scoped.

## 1. Index scope and parity

- `PaymentRepositoryController::index()` obtains `$companyId` from `CompanyContext::requireCompanyId()` and the tenant from `CompanyContext::requireCompany()`; it does not accept or read a request/query company selector (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:33-41`). The new `where('company_id', $companyId)` is therefore bound to the middleware-validated active company.
- The repository ownership predicate is the same tenant+company shape as `show()` (`PaymentRepositoryController.php:51-62`), `balance()` (`:317-327`), and the repository gate in `transactions()` (`:344-353`). `transactions()` then reads payments by that already-authorized repository id and tenant (`:355-362`).
- The movements drill-down applies the same repository gate and additionally scopes the movement query itself by tenant, company, and repository (`apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryMovementController.php:36-65`).
- No behavior changes for a valid single-company tenant: all repository rows already have non-null `company_id` (`apps/api/database/migrations/tenant/2025_11_30_134000_make_company_id_required.php:59-62`), so the added predicate retains that company's existing rows and removes only sibling-company rows.

## 2. Independent Treasury read/list census

No requested leak was found.

| Surface / endpoint | Company-scope evidence |
|---|---|
| `GET /payment-repositories` | Active context tenant+company at `PaymentRepositoryController.php:33-44`. |
| `GET /payment-repositories/{id}` | Active context tenant+company before `findOrFail` at `PaymentRepositoryController.php:51-62`. |
| `GET /payment-repositories/{id}/balance` | Active context tenant+company before `findOrFail` at `PaymentRepositoryController.php:317-327`. |
| `GET /payment-repositories/{id}/transactions` | Repository is tenant+company gated at `PaymentRepositoryController.php:344-353`; only that repository id is used for the payment list at `:355-362`. |
| `GET /payment-repositories/{id}/movements` | Repository and movement rows are both tenant+company scoped at `RepositoryMovementController.php:36-65`. Adjustment rows are exposed only through this ledger using source type `adjustment` (`apps/api/app/Modules/Treasury/Domain/Enums/MovementSourceType.php:7-18`). There is no separate adjustment READ/LIST route; the only adjustment route is the company-gated repository write `POST /payment-repositories/{repository}/adjustments` (`apps/api/app/Modules/Treasury/Presentation/routes.php:96-100`). |
| `GET /treasury/cash-position` | Repository aggregation is tenant+company scoped at `CashPositionController.php:59-96`; optional flow totals join through repositories and repeat tenant+company scope at `:184-202`. Location scope is applied in both paths. |
| `GET /payment-methods` and `GET /payment-methods/{id}` (including `default_repository_id` routing) | Methods are tenant+company scoped at `PaymentMethodController.php:31-59`; routing is emitted at `:306-325`, and the write validator accepts only an active-company repository at `:122-126` / `:246-250`. |
| `GET /bank-statements` and `GET /bank-statements/{id}` | Index is tenant+company scoped at `BankStatementController.php:30-49`; show uses the tenant+company `findStatement()` gate at `:161-171` before loading lines/allocations. |
| `GET /bank-statement-lines/{id}/suggestions` | The controller first resolves the line through a statement subquery constrained by active tenant+company (`StatementLineController.php:146-158`). Candidate movements are then constrained by statement tenant, company, and repository (`StatementSuggestionService.php:196-200`, `:449-476`). |
| `GET /bank-statement-targets/{type}/{id}/lines` | Provenance is constrained through company-scoped statements at `StatementLineController.php:44-60`. |
| `GET /statement-import-profiles` and `GET /statement-import-profiles/{id}` | Index and show/find are tenant+company scoped at `StatementProfileController.php:22-37` and `:107-117`; returned repository routing is at `:121-135`. |

Repository-bearing adjacent Treasury reads were also checked: payment instruments and events (`PaymentInstrumentController.php:44-80`, `:118-151`, `:484-494`), maturing instruments (`MaturingInstrumentsController.php:32-69`), remittances (`InstrumentRemittanceController.php:33-56`, `:204-215`), and payment index/show (`PaymentController.php:259-331`) all bind their parent query to the active tenant and company before returning repository or movement identifiers.

### Canonical consumers / no alternate list

- The web Treasury repository list and shared hooks use only `GET /payment-repositories`, with tenant+company cache keys (`apps/web/src/features/treasury/RepositoryListPage.tsx:89-96`; `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:27-38`). Repository detail uses the canonical show endpoint (`usePaymentRepositories.ts:61-72`).
- Cash position and movement pages use the scoped server endpoints, not a client-side tenant-wide repository sum/list (`apps/web/src/features/treasury/hooks/useCashPosition.ts:58-74`; `apps/web/src/features/treasury/hooks/useRepositoryMovements.ts:75-92`). Bank-statement pages use `/bank-statements`, `/statement-import-profiles`, statement suggestions, and the canonical repository-movements endpoint (`apps/web/src/features/treasury/statements/api.ts:179-245`).
- Web POS drawer/payment pickers use the canonical repository endpoint through `fetchPaymentRepositories()` (`apps/web/src/features/pos/api/paymentRepositoryApi.ts:27-28`) and a tenant-scoped query key (`apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:176-189`).
- Desktop POS uses only the same canonical endpoint (`apps/pos/src/api/paymentApi.ts:4-9`) and its sync pull caches exactly `/payment-methods` plus `/payment-repositories` (`apps/pos/src/lib/sync/syncService.ts:1243-1261`). Repository searches found no live `/treasury/payment-repositories` or other alternate HTTP list.
- Shift open itself has no drawer/repository picker: the web contract posts only `terminal_code` and `opening_cash` (`apps/web/src/features/pos/api/shiftApi.ts:16-19`, `:93-98`). Terminal acquisition verifies a usable drawer with explicit terminal tenant, company, and location (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:414-435`). Thus shift-open does not bypass the canonical company-scoped list with a second leaking repository endpoint.

## 3. Regression quality and base-red proof

- One tenant and two companies are created at `apps/api/tests/Feature/Treasury/PaymentRepositoryCompanyScopeTest.php:32-52`; the same user receives membership in both at `:54-59`.
- Each company gets its own MAIN location and cash-purpose account, then the test invokes the production `CompanyPaymentRepositoryProvisionerInterface` at `:61-77`. The production service creates company-owned `CASH-01` and `SAFE-01` (`apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php:32-70`, `:108-125`). Company rows themselves are test factories, but repository creation is the real G-3c provisioning port, not raw repository insertion.
- The test first proves both companies independently own exactly `CASH-01` and `SAFE-01` (`PaymentRepositoryCompanyScopeTest.php:80-90`), then proves A-only and B-only HTTP listings by both count and canonicalized ids (`:92-110`), and proves company B receives 404 when showing company A's repository (`:112-115`).
- It is genuinely RED on base `0cbaa1457`: base `index()` filtered only `tenant_id`, so the company-A request would return all four repositories and fail the first `assertJsonCount(2, 'data')` at `PaymentRepositoryCompanyScopeTest.php:92-96`. The cross-company show assertion alone was already green on base because `show()` already had the company predicate; the list assertion is what kills the exact regression.

## 4. Single-company behavior and the deferred write-side uniqueness defect

- The change is read-only and adds only a narrowing predicate. Valid single-company tenants continue to return their full repository set.
- The tenant-only validators are unchanged: store uses `unique(payment_repositories.code) WHERE tenant_id = active tenant` at `PaymentRepositoryController.php:75-81`; update repeats it at `:165-173`. These rules are wider than Treasury ownership and can incorrectly return 422 when a user manually POSTs a code in company B that already exists in company A. A whole-form PATCH in B that resends an unchanged duplicate-across-companies code can also 422 because only the current B row is ignored while the A row remains a tenant-wide match.
- The database does **not** enforce that faulty tenant-wide uniqueness. Migration `2025_12_30_195300_fix_multi_company_unique_constraints.php:31-35` drops `(tenant_id, code)` and creates `(company_id, code)` on `payment_repositories`. The new PostgreSQL regression also proves both companies can persist the same two codes.
- Tomorrow's "create 2nd company" G-3c flow will **not** receive that cross-company 422: `CompanyController::store()` calls the production provisioner directly after creating the company's chart/account and location (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:172-196`). The provisioner uses company-scoped existence checks and `forceCreate`, bypassing controller validation (`PaymentRepositoryProvisioningService.php:58-70`, `:84-89`), while the database company-scoped key permits the second `CASH-01`/`SAFE-01`. The validator defect remains important for later manual repository create/edit flows and should be fixed to tenant+company parity, but it is not a blocker for this scoped read fix or tomorrow's automatic provisioning journey.

## 5. Command evidence

All commands ran from `apps/api`. PostgreSQL was used only through the mandated `autoerp_test_j3` Artisan test prefix.

| Leg | Result |
|---|---|
| `php artisan test tests/Feature/Treasury/PaymentRepositoryCompanyScopeTest.php` | **PASS** — 1 test. |
| `php artisan test tests/Feature/Treasury/PaymentRepositoryTest.php` | **PASS** — 25 tests, 84 assertions. |
| `DB_DATABASE=autoerp_test_j3 DB_CENTRAL_DATABASE=autoerp_test_j3 php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRepositoryCompanyScopeTest.php` | First attempt failed before the test body at schema reset with PostgreSQL `SQLSTATE[53200] out of shared memory / max_locks_per_transaction`; the identical retry **PASS** — 1 test, 9 assertions. This was infrastructure/transient, not an assertion failure. |
| `DB_DATABASE=autoerp_test_j3 DB_CENTRAL_DATABASE=autoerp_test_j3 php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRepositoryTest.php` | **PASS** — 25 tests, 85 assertions. |
| `php tools/feature-lane-manifest-check.php` | **PASS** — 1,478 Feature classes / 74 groups; every group disposed, lanes present, filters anchored and uniquely matched. Existing parked-lane and one-class coverage-debt warnings remain informational. |

The manifest raise is accurate: Treasury `classes` moves 122 → 123 and names this regression (`apps/api/tests/feature-lane-manifest.json:1012-1017`).

GATEVERDICT: APPROVED
