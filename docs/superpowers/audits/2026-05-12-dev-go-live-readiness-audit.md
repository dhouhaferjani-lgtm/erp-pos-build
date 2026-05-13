# Dev Go-Live Readiness Audit

Date: 2026-05-12  
Audited branch: `dev`  
Audited worktree: `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`  
Audited commit: `70ea2bcc` (`docs(pos): Opus final audit of go-live PRs #1-#4 (ACCEPT)`)  

Note: `/Users/houssamr/Projects/syneriva/apps/erp` was not on `dev`; it was on `feat/tenant-isolation-sweep-execution`. The actual `dev` branch is checked out in `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`, so this audit is based on that worktree.

## Executive Verdict

The application is **not ready to go live as-is**.

The recent POS go-live work is in much better shape than the broader ERP. POS-specific unit coverage passes, the web and POS typechecks pass, and the latest POS final audit in `docs/superpowers/reviews/2026-05-12-pos-go-live-opus-final-audit.md` accepted the four POS PRs with no P0/P1 blockers.

However, a live release should not proceed until the blockers below are fixed or explicitly risk-accepted by leadership. The main blockers are tracked secrets, critical dependency advisories, failing backend/static architecture gates, failing web tests, and known tenant-isolation gaps in production controllers.

For a **narrow, single-tenant POS pilot**, go-live may be possible after fixing the security blockers, restoring the required release gates, completing the POS operational checklist, and formally risk-accepting non-POS ERP gaps. For a **general multi-tenant ERP launch**, the current `dev` branch is not ready.

## Blockers

### P0 - Tracked Secret Material

`apps/api/.env.bak` is tracked by git and contains real-looking secret material, including application key material, database/Redis credentials, a Sentry DSN, Reverb credentials, and a platform API key. This must be treated as compromised.

Required before go-live:

- Remove the file from the repository.
- Rotate every value that appears in it, even if some are thought to be non-production.
- Purge the secrets from git history before pushing a public or shared production release branch.
- Verify production CORS is not using `*` with credentialed requests.

### P0 - Critical Composer Advisory

`composer audit --no-interaction` failed with a critical advisory for `dedoc/scramble`:

- Package: `dedoc/scramble`
- Installed: `0.13.20`
- Advisory: CVE-2026-44262 / GHSA-4rm2-28vj-fj39
- Impact: remote code execution through validation-rule evaluation
- Fixed range reported by the advisory: upgrade past the affected `0.13.x` range

This is a release blocker, especially because Scramble is an API documentation/introspection package and should not remain vulnerable in a deployed environment.

The same audit also reports `qossmic/deptrac` as abandoned, with `deptrac/deptrac` suggested as replacement.

### P0 - JavaScript Dependency Audit Fails

`pnpm audit --audit-level moderate` failed with 44 advisories:

- 1 low
- 21 moderate
- 22 high

High-impact examples include advisories affecting `react-router`, `rollup`, `axios`, and `minimatch`. The React Router advisories are especially important because they include XSS/open-redirect and SSR-related XSS classes of issues.

Required before go-live:

- Upgrade the affected packages.
- Re-run the audit at the release threshold.
- Document any remaining advisories with explicit production impact analysis.

### P0 - Release Gates Are Not Green

The required verification gates are not in a deployable state.

Backend static analysis failed:

- `./vendor/bin/phpstan analyse --memory-limit=1G`
- Result: failed with 5 errors in `apps/api/app/Application/Sweep/InventoryService.php`
- Root issue: `Opis\JsonSchema\Validator` and `Opis\JsonSchema\Errors\ErrorFormatter` are referenced but unavailable in the installed vendor set.

Backend tests did not pass:

- `composer test`
- Result: failed/timeout
- It had already failed three `Tests\Unit\Application\Sweep\InventoryYamlSchemaTest` tests before Composer hit its 300-second process timeout.

Web tests failed:

- `pnpm --filter @autoerp/web test`
- Result: failed
- Summary: 7 test files failed, 43 tests failed, 1963 passed, 1 skipped
- Failing areas included pricing, workshop bundle authoring, technician detail authoring, and bundle component form modal behavior.

Dependency boundary analysis failed:

- `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-cache`
- Result: failed
- Summary: 980 violations, 6731 uncovered, 673 allowed

The project guidelines say CI parity means running build, lint, test, typecheck, and PHPStan before pushing. That bar is not currently met.

## High-Risk Findings

### P1 - Known Tenant-Isolation Gaps Exist In Production Controllers

The codebase contains explicit `#[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP...')]` annotations. This is good from an auditability standpoint, but the annotated gaps are not acceptable for a broad multi-tenant launch.

Notable examples:

- `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php`: product/product-image route model binding is not tenant-scoped; update/delete/download/reorder paths require product-image alignment checks.
- `apps/api/app/Modules/Communication/Presentation/Controllers/DocumentEmailController.php`: document route model binding is not tenant-scoped; cross-tenant document email/PDF generation is possible if an ID is known.
- `apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php`: document and additional-cost route model binding gaps can disclose or mutate cross-tenant document cost state.
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`: user role assignment/removal uses unscoped user lookup paths.
- `apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOfferController.php`: a global `purchase_hub:offers` cache key can leak one tenant's cached offers to another.

These may be acceptable only for a tightly scoped single-tenant POS pilot where the affected routes are not exposed to operators. They are blockers for a general multi-tenant ERP launch.

### P1 - Hexagonal Boundaries Are Documented But Not Enforced

The documented architecture says modules should communicate through Shared Contracts, Events, public service classes, and generated frontend types. In practice, direct module-to-module imports are common across Domain, Application, and Presentation layers.

The `deptrac` result confirms this structurally: 980 dependency violations. Examples from the violation output include:

- Accounting domain/application code depending directly on Partner, Tenant, Identity, Treasury, and Accounting application services.
- Treasury domain code depending on application DTOs.
- Vehicle domain code depending directly on Tenant and Partner.
- POS application/domain code depending directly on Voucher, Company, Inventory, and Identity internals.

The `deptrac.yaml` coverage is also stale. It does not model several current modules such as POS, Voucher, Taxation, Billing, Marketplace, PlatformIntegration, BatchExpiry, Uom, and Coupon, and the Presentation layer is effectively unrestricted. This means the architecture checker is both failing and incomplete.

Verdict: the codebase is layered and modular Laravel, but it is **not strictly hexagonal in the enforced sense**.

### P1 - Service Locator Usage Leaks Into Domain/Application Code

The project conventions require constructor injection. There are still direct `app(...)` lookups in request/rule/domain-adjacent code, and at least one domain model reaches into the container:

- `apps/api/app/Modules/Document/Domain/Document.php`: `recalculateTotals()` resolves `TaxCalculationService` through `app(...)`.
- Several Form Requests and validation rules resolve services through `app(...)`.

This weakens testability, makes dependencies implicit, and breaks the intended separation between domain behavior and framework infrastructure.

### P1 - Release Branch/Deployment State Needs Clarification

The POS final audit says the local dev state was ahead of remote at the time of that review. This audit ran against local `dev` at `70ea2bcc`. Before go-live, confirm the exact commit being deployed, push state, tag, and environment configuration. The answer to "are we ready?" changes materially if production is deploying a different commit than the audited one.

## Maintainability Findings

### Large Files That Should Be Refactored

These files are large enough to slow future maintenance and code review. They are not all launch blockers, but they should be split deliberately after the immediate release work.

Highest-priority refactor candidates:

- `apps/web/src/routes/index.tsx` - 2562 lines
- `apps/pos/src/lib/sync/syncService.ts` - 1897 lines
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` - 1460 lines
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` - 1336 lines
- `apps/pos/src/pages/HomePage.tsx` - 1276 lines
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php` - 1056 lines
- `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php` - 1045 lines
- `apps/api/app/Modules/Report/Application/Services/ReportGenerationService.php` - 1032 lines
- `apps/web/src/components/organisms/AdvancedPaymentsModal.tsx` - 982 lines
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` - 933 lines
- `apps/api/app/Listeners/DomainEventSubscriber.php` - 894 lines
- `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php` - 886 lines
- `apps/pos/src/stores/paymentStore.ts` - 858 lines
- `apps/web/src/components/organisms/RecordPaymentModal.tsx` - 831 lines
- `apps/web/src/features/settings/pages/UsersPage.tsx` - 824 lines

Suggested direction:

- Split `routes/index.tsx` by feature route modules.
- Split POS sync into pull, push, conflict/reconciliation, scheduler, and persistence adapters.
- Split accounting/POS services by command use case instead of one service owning many flows.
- Move controller orchestration into application command/query services where controllers are above 500 lines.
- Split large modals/pages into form state hooks, presentational sections, and API mutation adapters.

### Lint Warning Debt Is Very High

`pnpm --filter @autoerp/web lint` completed with no errors but produced 11167 warnings. This reduces the value of linting as a signal. Many warnings appear to be Tailwind color-token/style warnings, but the volume makes real issues easy to miss.

`pnpm --filter @autoerp/pos lint` passed with 41 warnings.

Recommended:

- Decide which warning families are intentional.
- Codify exceptions centrally.
- Drive the warning count down until new warnings become meaningful in review.

### Console Logging In Production Paths

There are `console.log` and verbose console statements in POS auth, sync, terminal, and realtime paths. Some are useful while stabilizing offline behavior, but production logging should be structured and scrubbed. Avoid logging tokens, tenant identifiers, receipt payloads, or operator/customer data.

### TODO Debt In Critical Areas

The codebase has TODOs in areas that are close to go-live relevance, including POS sync backlog behavior, receipt push batching, voucher issuance defaults, and accounting report hierarchy. Not every TODO is a blocker, but each TODO in fiscal, payment, receipt, voucher, tenant isolation, or accounting code should be reviewed before launch.

## Security Posture Positives

The project has several good foundations:

- Most module route groups use `api`, `auth:sanctum`, `SetPermissionsTeam`, and `EnforceTokenTenantClaim`.
- Broadcast authorization has tenant/team middleware and tenant/company checks.
- Channel callbacks include tenant/company scoping and delegate to user access helpers.
- The API uses Sanctum for web auth and bearer token flows for POS.
- `SecurityHeaders` adds `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and production HSTS.
- POS fiscal-chain and offline sync behavior have substantial regression coverage.
- The codebase has explicit tenant-isolation annotations and sweep tooling, which is better than silent unknown risk.

These positives are real, but they do not offset the tracked secrets, dependency advisories, failing gates, or known isolation gaps.

## Security Hardening Before Public Launch

Recommended before any public launch:

- Add a production Content Security Policy. The `SecurityHeaders` middleware does not currently set CSP even though the class comment references one.
- Verify production CORS origins are explicit and never `*` when credentials are enabled.
- Disable public debug, route, API documentation, and introspection surfaces in production unless protected by strong auth.
- Confirm `APP_DEBUG=false`, secure cookies, HTTPS-only session behavior, trusted proxies, and HSTS behavior behind the real load balancer.
- Review all file upload/download endpoints for tenant scoping, MIME validation, size limits, storage visibility, and signed URL behavior.
- Confirm rate limiting on login, password reset, POS activation, manager PIN, and document/email sending endpoints.
- Confirm audit logs for privileged events: role assignment, discount override, receipt void/refund, Z-report close, fiscal document edits, tenant/company switching, and API token issuance.

## Verification Results

| Check | Result | Notes |
| --- | --- | --- |
| `git branch --show-current` | Pass | `dev` |
| `git rev-parse --short HEAD` | Pass | `70ea2bcc` |
| `pnpm --filter @autoerp/web typecheck` | Pass | No type errors |
| `pnpm --filter @autoerp/pos typecheck` | Pass | No type errors |
| `pnpm --filter @autoerp/pos lint` | Pass with warnings | 0 errors, 41 warnings |
| `pnpm --filter @autoerp/web lint` | Pass with warnings | 0 errors, 11167 warnings |
| `pnpm --filter @autoerp/pos test` | Pass | 143 files, 1274 tests passed |
| `pnpm --filter @autoerp/web build` | Pass with warnings | Build succeeded; large chunk warnings above 500 kB |
| `pnpm --filter @autoerp/web test` | Fail | 7 files failed, 43 tests failed |
| `composer audit --no-interaction` | Fail | Critical `dedoc/scramble` advisory; abandoned `qossmic/deptrac` |
| `pnpm audit --audit-level moderate` | Fail | 44 advisories |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | Fail | 5 missing `Opis\JsonSchema` class/method errors |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-cache` | Fail | 980 violations |
| `composer test` | Fail/timeout | Sweep inventory schema tests failed before Composer's 300s timeout |

## Go-Live Recommendation

Do not go live with the full application from the current `dev` state.

Minimum required before a narrow POS pilot:

1. Remove and rotate tracked secrets.
2. Patch the critical Composer advisory and high-risk JS advisories.
3. Restore release gates that apply to the deployed artifact: audits, build, typecheck, POS tests, backend smoke/PHPStan or a documented exception.
4. Fill the POS runbook placeholders noted by the final POS audit.
5. Complete the Phase 3 operational prep from the POS audit: terminal/device profile finalization, restore drill, sync backlog measurement, Tunisia legal pack confirmation, and live terminal smoke pass.
6. Explicitly risk-accept the known non-POS tenant-isolation gaps if the launch scope excludes those routes.

Minimum required before broad multi-tenant ERP go-live:

1. Fix all known tenant-isolation gaps in production controllers.
2. Bring `deptrac` coverage up to date for current modules and reduce violations to an accepted baseline.
3. Make backend tests, web tests, PHPStan, dependency audits, and build green.
4. Reduce lint warnings or split them into intentional style warnings vs actionable code warnings.
5. Refactor the largest files that sit on critical business paths, especially accounting, POS receipt, POS sync, inventory counting, document, and payment controllers/services.

## Bottom Line

The project is close enough that an incremental launch strategy is reasonable, but only after fixing the security blockers and restoring the release gates. The architecture has good intent and documentation, but it is not currently enforcing the hexagonal boundaries described in the project guidelines. Treat the first production launch as a narrow, controlled pilot rather than a broad ERP release.
