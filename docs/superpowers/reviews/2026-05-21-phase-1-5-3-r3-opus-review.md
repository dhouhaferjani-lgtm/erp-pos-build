# Phase 1.5.3 R3 Opus Second-Pass Adversarial Review

**Commits reviewed:**

- `250150211` — `Phase 1.5.3: Add fiscal quarantine parse assist`
- `67036d499` — `Phase 1.5.3: Complete quarantine resolution assist`
- `c328446c3` — `Phase 1.5.3: Preserve extra-field quarantine corrections`

**Prior R2 Opus review:** `docs/superpowers/reviews/2026-05-21-phase-1-5-3-r2-opus-review.md`

**Verdict:** APPROVE

## Findings

None.

## R2 Finding Status

- **Extra-field defects:** Fixed. `payload_extra_field` defects remain visible in the defect list (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:138-151`) but are excluded from editable correction fields by `isEditablePayloadDefect()` (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:31-34`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:221-223`). `initialDefectEdits()` now uses the same predicate, so extra fields are not added to `defectEdits` and `buildCorrectedPayload()` does not reapply them (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:212-218`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:235-245`).
- **Submitted payload omits extra key:** Covered by the R3 frontend regression test for `payload.legacy_hash`; it asserts the defect is displayed, no correction input is rendered, and the submitted payload excludes `legacy_hash` (`apps/web/src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx:98-135`).
- **Required/invalid payload fields remain editable:** The predicate only excludes `payload_extra_field`, so other `payload.*` defects still render correction inputs and flow into `buildCorrectedPayload()` (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:159-175`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:221-223`). Existing focused coverage still exercises editing `payload.seller.tax_number` and submitting the corrected value (`apps/web/src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx:56-96`).

## Rechecked Prior Fixes

- **Audit logging:** Still present for both successful pre-fill sources via `fiscal.quarantine.best_effort_parse_invoked` (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:45-53`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:93-101`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:141-162`).
- **Frontend submit via existing boundary:** The page still calls `resolveParseFailure()` only for in-table `fiscal_events` with non-null `fiscal_event_id` (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:43-49`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:185-197`; `apps/web/src/features/compliance/api/quarantineResolutionApi.ts:33-42`).
- **Tenant-scoped resolve controller:** `ParseFailureResolutionController` loads by `id` and authenticated `tenant_id` before invoking the injected resolution service (`apps/api/app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php:23-56`).
- **No quarantine-table resolution path:** The resolve route targets `/fiscal/events/{id}/resolve-parse-failure`, queries only `fiscal_events`, and the UI disables submit for `fiscal_event_quarantine` parse results (`apps/api/app/Modules/Fiscal/routes.php:32-33`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php:35-47`, `apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:179-192`).
- **Validation still authoritative:** The controller delegates to `ParseFailureResolutionService::resolve()`; the existing service still validates DTO shape and `FiscalPayloadConstraintValidator` constraints before mutation (`apps/api/app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php:49-56`, `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:145-152`, `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:291-330`).

## Standing Pattern Checks

- **Cross-tenant safety:** Parse and resolve lookups remain scoped by authenticated `tenant_id`; no new projection lookup or FK write was introduced by R3.
- **Fail-loud behavior:** Extra fields are omitted rather than silently accepted; remaining invalid corrected payloads still fail through the existing 422 path.
- **Dead path:** The backend route, frontend API wrapper, page submit handler, and focused tests exercise the combined path; R3 adds coverage for the exact extra-field regression.
- **Contract drift:** The backend parser still derives allowed payload keys from `FiscalPayloadConstraintValidator::PAYLOAD_KEYS`; the UI now matches that behavior by keeping non-contract keys omitted.
- **Constructor injection only:** No production `app()` / `App::make()` / Laravel service-location `resolve()` call found in touched production code. The `$this->resolver->resolve(...)` hit is a method call on the injected service.
- **D16 bounded-module guard:** No hard Treasury, Customer, B2B, or Accounting dependency found in the touched production files.
- **Skips:** No new skips or skip citations found in the focused touched tests.

## Verification Commands Run

- `pnpm --filter @autoerp/web test -- src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` — passed: 1 file, 4 tests.
- `pnpm --filter @autoerp/web typecheck` — passed.
- `./vendor/bin/phpunit tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` from `apps/api` — passed: 10 tests, 54 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Presentation/Controllers/ParseFailureResolutionController.php app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php` from `apps/api` — passed with no errors.
- `pnpm --filter @autoerp/web exec eslint src/features/compliance/pages/QuarantineResolveAssistPage.tsx src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` — exited 0 with warnings only.
- `rg -n "app\(|App::make|resolve\(" apps/api/app/Modules/Fiscal apps/web/src/features/compliance apps/web/src/routes/index.tsx apps/web/src/hooks/usePermissions.ts` — no production service-location hit in touched code.
- `rg -n "markTestSkipped|->skip\(|skip\(" apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php apps/api/tests/Unit/Fiscal/BestEffortPayloadParserTest.php apps/web/src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` — no hits.
