# Phase 1.5.3 R2 Opus Second-Pass Adversarial Review

**Commits reviewed:**

- `250150211` — `Phase 1.5.3: Add fiscal quarantine parse assist`
- `67036d499` — `Phase 1.5.3: Complete quarantine resolution assist`

**Prior Opus review:** `docs/superpowers/reviews/2026-05-21-phase-1-5-3-opus-review.md`

**Verdict:** REQUEST-CHANGES

## Findings

### 1. Extra-field defects are reintroduced by the correction UI, making that parse-failure class unresolvable from the page

**Severity:** Request changes

The R2 frontend treats every `payload.*` defect as an editable value (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:31-34`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:212-218`). That is wrong for `payload_extra_field` defects.

The backend parser intentionally omits non-contract keys from `parsed` by copying only `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` (`apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:73-83`) while reporting extras as `payload.<extra>` defects (`apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:101-103`). For an envelope with an extra payload key, the UI initializes that defect edit from `result.parsed`, so the value is `undefined` and becomes an empty string (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:206-217`). On submit, `buildCorrectedPayload()` applies every edit back into the cloned parsed payload (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:231-240`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:261-270`), so the corrected payload reintroduces the extra key as `""`.

That payload then correctly fails the existing service validator for an extra field. The backend is fail-loud, but the new operator workflow cannot actually resolve a parse failure caused by an extra canonical payload key, even though the parser already produced the right corrected base payload by omitting the extra. Fix by not creating/applying correction edits for `payload_extra_field` defects, or by modeling them as removals and asserting the submitted payload does not include the extra key. Add a frontend test for this case.

## Prior Findings Status

- **Audit logging:** Fixed. `QuarantineBestEffortParseController` logs `fiscal.quarantine.best_effort_parse_invoked` for successful quarantine-table and in-table pre-fill responses (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:45-53`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:93-101`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:141-162`). Tests now assert both source shapes (`apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php:69-98`, `apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php:124-177`).
- **Frontend resolution-assist workflow:** Partially fixed. The page now renders correction fields and submits through `resolveParseFailure()` for in-table `fiscal_events` only (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:43-49`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:185-197`; `apps/web/src/features/compliance/api/quarantineResolutionApi.ts:33-42`). The extra-field handling above keeps this from being complete.

## Checks That Passed

- **Tenant isolation before resolve:** The new controller loads `FiscalEvent` by both `id` and authenticated `tenant_id` before validating the request body or invoking `ParseFailureResolutionService::resolve()` (`apps/api/app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php:23-56`). Cross-tenant attempts return 404 in focused coverage (`apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php:215-223`).
- **No quarantine-table resolution path:** The resolve route is `/fiscal/events/{id}/resolve-parse-failure` and only queries `fiscal_events` (`apps/api/app/Modules/Fiscal/routes.php:32-33`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php:35-47`). The UI disables submit for `source === 'fiscal_event_quarantine'` or null `fiscal_event_id` (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:179-192`).
- **No validation bypass:** The controller only validates request shape, then delegates to the existing injected service (`apps/api/app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php:49-56`). The service still validates through DTO + `FiscalPayloadConstraintValidator` before mutating the fiscal row (`apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:145-152`, `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:291-330`).
- **Cross-tenant FK safety:** I found no new projection lookup or FK write outside the existing resolution service. The new public resolve path gates by tenant first.
- **Fail-loud behavior:** Parse defects remain explicit, non-parse-failure pre-fill returns `409`, invalid corrected payload returns `422`, and service precondition failures return `409`.
- **Dead path:** Backend route, frontend API wrapper, page submit handler, and focused tests exercise the R2 path.
- **Contract drift:** Best-effort parsing still derives payload keys from `FiscalPayloadConstraintValidator::PAYLOAD_KEYS`; no forked SALE_RECEIPT contract found in production code.
- **Constructor injection only:** No production `app()` / `App::make()` / Laravel `resolve()` service-location call found in touched production code. The `$this->resolver->resolve(...)` hit is a method call on the injected service.
- **D16 bounded-module guard:** No hard Treasury, Customer, B2B, or Accounting dependency was introduced in the touched production files.
- **Skips:** No new skips or skip citations found in the touched focused tests.

## Verification Commands Run

- `./vendor/bin/phpunit tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` from `apps/api` — passed: 10 tests, 54 assertions.
- `pnpm --filter @autoerp/web test -- src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` — passed: 1 file, 3 tests.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php` from `apps/api` — passed with no errors.
- `pnpm --filter @autoerp/web typecheck` — passed.
- `rg -n "app\(|App::make|resolve\(" apps/api/app/Modules/Fiscal apps/web/src/features/compliance apps/web/src/routes/index.tsx apps/web/src/hooks/usePermissions.ts` — no production service-location hit in touched code.
- `rg -n "markTestSkipped|->skip\(|skip\(" apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php apps/api/tests/Unit/Fiscal/BestEffortPayloadParserTest.php apps/web/src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` — no hits.
