# Phase 1.5.3 Opus Second-Pass Adversarial Review

**Commit reviewed:** `250150211` (`Phase 1.5.3: Add fiscal quarantine parse assist`)

**Verdict:** REQUEST-CHANGES

## Findings

### 1. Missing required audit log for pre-fill invocations

**Severity:** Request changes

The Phase 1.5.3 handoff requires every pre-fill response to be logged for forensic reconstruction: `Log::info('fiscal.quarantine.best_effort_parse_invoked', [...])` (`docs/superpowers/coordination/2026-05-21-codex-handover-phase-1-5-and-phase-3.md:190-196`). The new controller returns both quarantine-table and in-table parse-assist responses without any audit emission (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:40-51`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:80-89`), and `rg -F best_effort_parse_invoked` found no implementation or test coverage.

This is not just observability polish: this endpoint exposes operator-facing forensic parse material for failed fiscal events, so the audit requirement is part of the recovery contract. Add a structured `Log::info` on successful pre-fill responses, with tenant/user/source/event identifiers and enough defect/result metadata for reconstruction, and cover it with `Log::spy()`/`shouldHaveReceived()`.

### 2. Frontend page is parse-only, not the specified resolution assist workflow

**Severity:** Request changes

The handoff describes the admin UI as rendering parsed fields and defects as editable inputs, then submitting the amended payload via the existing resolution boundary (`docs/superpowers/coordination/2026-05-21-codex-handover-phase-1-5-and-phase-3.md:193-195`, `docs/superpowers/coordination/2026-05-21-codex-handover-phase-1-5-and-phase-3.md:205`). The implemented page only has an ID form and a parse button (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:23-65`), renders parsed JSON in a read-only textarea (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:97-102`), and displays defects as static list items (`apps/web/src/features/compliance/pages/QuarantineResolveAssistPage.tsx:105-119`). The API wrapper exposes only `bestEffortParseQuarantine()` (`apps/web/src/features/compliance/api/quarantineResolutionApi.ts:18-25`), and the component tests assert only parsing/display (`apps/web/src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx:18-50`).

This leaves operators with no implemented path to amend broken fields from the assist page or submit the corrected payload through `ParseFailureResolutionService::resolve()`. It does avoid inventing an unsafe quarantine-table resolution path, but it does so by omitting the resolution-assist half of the requested UX. The fix should keep the existing boundary intact: allow submit only for `source === 'fiscal_events'` / non-null `fiscal_event_id`, use the existing resolution service/API path if one exists or add a bounded controller for it, and keep `fiscal_event_quarantine` rows parse-assist-only.

## Checks That Passed

- **Cross-tenant safety:** Both lookup paths are scoped by authenticated `tenant_id` before returning anything (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:35-38`, `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:54-57`). No projection lookup or FK write was added.
- **Raw envelope requirement:** The quarantine path prefers `raw_envelope.payload.canonical_bytes` and then `raw_envelope.canonical_bytes` before falling back to the legacy `canonical_bytes` column (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:103-113`).
- **Fail-loud behavior:** Strict parse failure is surfaced as an explicit defect before best-effort extraction continues (`apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:22-36`), invalid JSON returns an explicit defect (`apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:38-44`), and non-parse-failure `fiscal_events` rows return `409` (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:68-77`).
- **Contract reuse:** The parser derives expected payload fields from `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` and validates through the existing registry/constraint validator (`apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:73-83`, `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:112-137`). I did not find a forked SALE_RECEIPT 27-key contract in production code.
- **Dead-path guard:** The backend route is live (`apps/api/app/Modules/Fiscal/routes.php:25-30`), the frontend route is protected by `fiscal.events.resolve_quarantine` (`apps/web/src/routes/index.tsx:1916-1925`), and focused tests exercise the parser/controller/page paths.
- **Resolution boundary:** The new endpoint does not mutate fiscal state and returns `fiscal_event_id: null` for `fiscal_event_quarantine` rows (`apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php:44-50`).
- **Constructor injection:** Touched production PHP uses constructor injection; no production `app()`, `App::make`, or `resolve()` service-location call was found.
- **Skips:** No new `markTestSkipped` / skip citations found in the touched test files.
- **D16 bounded-module guard:** No hard Treasury, Customer, B2B, or Accounting dependency was introduced by the touched production files.

## Audit-Trail Notes

- The Codex self-review overstates coverage at `docs/superpowers/reviews/2026-05-21-phase-1-5-3-codex-review.md:33`: the quarantine-table feature test stores the same bytes in `canonical_bytes` and `raw_envelope.payload.canonical_bytes` (`apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php:135-136`), so it does not prove raw-envelope preference. The implementation currently does prefer `raw_envelope`, but the test would pass if that preference regressed to the column fallback.
- The self-review also lists non-parse-failure conflict among covered result variants (`docs/superpowers/reviews/2026-05-21-phase-1-5-3-codex-review.md:27`), but the new controller feature test file has no `409 NOT_PARSE_FAILURE` case. The code path exists; coverage metadata is the part that is inaccurate.

## Verification Commands Run

- `./vendor/bin/phpunit tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` from `apps/api` - passed: 7 tests, 28 assertions.
- `pnpm --filter @autoerp/web test -- src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` - passed: 1 file, 2 tests.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php app/Modules/Fiscal/Presentation/Resources/BestEffortParseResource.php` from `apps/api` - passed with no errors.
- `rg -n "app\(|App::make|resolve\(" apps/api/app/Modules/Fiscal apps/web/src/features/compliance apps/web/src/routes/index.tsx apps/web/src/hooks/usePermissions.ts` - no new production service-locator usage in touched code; hits were comments or existing service names.
- `rg -n -F "best_effort_parse_invoked" apps/api/app/Modules/Fiscal apps/api/tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` - no hits.
