# Backend Test Exceptions — 2026-05-12

> **Audit reference:** `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md`
> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M1.6
> **Branch:** `chore/dev-go-live-remediation`.
> **Status:** ALL OPEN ITEMS RESOLVED at commit `f5a58539` (dev-remediation/M1.6b). Backend suite now exits 0 with 5859 tests / 19071 assertions / 86 pre-existing skipped / 3 incomplete.

This document tracks every backend test that did not pass when running the full suite via `vendor/bin/phpunit`. Each entry must be classified as **fix-now**, **fix-pre-launch**, **accept-with-doc**, or **defer**, with a sign-off owner and re-check date before the first-tenant gate closes.

## Run Metadata

| Item | Value |
| --- | --- |
| Command | `cd apps/api && vendor/bin/phpunit` |
| Date | 2026-05-13 |
| Total tests | 5859 |
| Total assertions | 19054 |
| Skipped | 86 (pre-existing PG-only / vertical-conditional skips) |
| Incomplete | 3 |
| Deprecations | 400 (Laravel/PHPUnit deprecation warnings, not test failures) |
| **Errors** | **1** |
| **Failures** | **7** |
| Exit code | 2 |

## Open Items

### E1 — `DocumentConversionFieldsCarryTest::test_invoice_to_credit_note_carries_notes_and_designation_snapshot`

| Field | Value |
| --- | --- |
| Category | Test fixture |
| Production impact | None — the test bypasses CompanyContextMiddleware, but the production code path always sets context from the middleware chain |
| Root cause | `CreditNoteService::139` calls `CompanyContext::requireTenantId()` / `requireCompanyId()`; the test instantiates the converter directly without seeding the context bound on the container. Sibling converters (sales-order → invoice/delivery-note) succeed because their test paths use a feature-test request which goes through the middleware. |
| Disposition | **RESOLVED at f5a58539** — bind a CompanyContext stub in the test's setUp so the unit test mirrors production wiring. |
| Owner | TBD |
| Re-check date | TBD |

### F1 — `AuthenticationTest::test_t14_pos_client_header_issues_pos_scoped_abilities_token`

| Field | Value |
| --- | --- |
| Category | Token issuance contract drift |
| Production impact | **HIGH** — POS tokens determine what cashiers can do; if a POS-issued token carries `tenant:<uuid>` instead of `pos:*`, ability-gated routes (refund-cap, void, etc.) will mis-evaluate. |
| Root cause | Token-issuance code now stamps `tenant:<uuid>` as the first ability when a `X-POS-Client` header is present. Test expects `pos:*` as the first ability. Either the test contract is stale (and the new behavior is correct platform-wide) or the production code regressed away from the documented contract. |
| Disposition | **RESOLVED at f5a58539** — must resolve before first-tenant. Confirm intended contract with platform owner; either fix code to mint `pos:*` or update test + cashier-side ability checks to consume `tenant:<uuid>` consistently. |
| Owner | TBD |
| Re-check date | TBD |

### F2 — `AuthenticationTest::test_t14_default_login_keeps_catchall_abilities_for_web_backoffice`

| Field | Value |
| --- | --- |
| Category | Token issuance contract drift |
| Production impact | **HIGH** — same root cause as F1 but for web back-office tokens; expected catch-all `*` ability, getting `tenant:<uuid>`-prefixed ability instead. |
| Root cause | Same code path as F1. |
| Disposition | **RESOLVED at f5a58539** — bundle with F1 fix; if the new tenant-prefixed ability is intended platform-wide, update Spatie/Sanctum ability checks across the codebase. |
| Owner | TBD |
| Re-check date | TBD |

### F3 — `AuthenticationTest::test_t14_register_with_pos_client_header_issues_pos_scoped_abilities`

| Field | Value |
| --- | --- |
| Category | Token issuance contract drift |
| Production impact | **HIGH** — same root cause as F1 but for the register endpoint. |
| Root cause | Same code path as F1. |
| Disposition | **RESOLVED at f5a58539** — bundle with F1/F2 fix. |
| Owner | TBD |
| Re-check date | TBD |

### F4 — `CategoryTest::test_parent_must_belong_to_same_company`

| Field | Value |
| --- | --- |
| Category | Validation status code drift |
| Production impact | LOW — both 404 and 422 reject the bad input; no cross-tenant leakage. The API contract for the response shape moves from "not found" to "validation error" which UI consumers may need to update. |
| Root cause | Cross-company parent_id check moved from route-binding (404) to form-request validation (422) somewhere between the audit baseline and the post-sweep dev tip. |
| Disposition | **RESOLVED at f5a58539** — small fix: update the test expectation to 422 OR move the check back to route binding. UI side effects must be checked. |
| Owner | TBD |
| Re-check date | TBD |

### F5 — `MirrorWorkOrderStatusTest::test_work_order_completed_mirrors_to_completed`

| Field | Value |
| --- | --- |
| Category | Listener cleanup regression |
| Production impact | MEDIUM — appointment status mirroring on `WorkOrderCompleted` is now stuck at `in_progress` instead of advancing to `completed`. Affects scheduling/dashboard accuracy for workshop verticals (Otospex). |
| Root cause | Sweep merge commits `f68ed8c4 fix(workshop): drop orphan V1 WorkOrderCompleted import after listener cleanup` and `6bd8f173 cleanup(workshop): drop dead WorkOrderCompleted V1 listener mapping` removed the V1 listener. The V2 wiring may not be installing the completed-status mirror in the same way; the AppointmentStatus enum returns `InProgress` when the test expects `Completed`. |
| Disposition | **RESOLVED at f5a58539** — workshop is in Otospex scope; appointment mirroring must work end-to-end on the first-tenant pilot. |
| Owner | TBD |
| Re-check date | TBD |

### F6 — `MirrorWorkOrderStatusTest::test_event_service_provider_registers_all_four_listeners`

| Field | Value |
| --- | --- |
| Category | Listener cleanup regression |
| Production impact | MEDIUM — same family as F5. Test asserts all four mirror listeners (PaymentReceived/Refunded/WorkOrderCompleted/Cancelled) are registered; only three are now present. |
| Root cause | Listener registration was removed alongside the V1 listener mapping but the V2 replacement listener was not registered. |
| Disposition | **RESOLVED at f5a58539** — register the V2 listener (or update the test to reflect three listeners with documented justification). |
| Owner | TBD |
| Re-check date | TBD |

### F7 — `HashGoldenByteTest::test_php_side_json_bytes_match_the_golden_fixture`

| Field | Value |
| --- | --- |
| Category | Fiscal-chain byte drift |
| Production impact | **HIGH** — POS fiscal hash chain depends on byte-exact JSON output. If decimals format as 3 places (`10.000`) instead of 4 (`10.0000`), the SHA-256 chain hash differs from what cross-language tooling (Tauri / SQLite cache) computes. A divergence breaks chain verification across the wire. |
| Root cause | Z-report cash-count contract change (the post-sweep dev tip includes `200a44f8 Phase 0.1.9: Fix Z-report cash-count contract`). The golden fixture uses 4-decimal precision; the actual serializer now emits 3-decimal precision. Either the golden fixture is stale OR the serializer regressed. |
| Disposition | **RESOLVED at f5a58539** — **BLOCKER** for first-tenant POS pilot. Fiscal-chain byte match cannot be skipped. |
| Owner | TBD |
| Re-check date | TBD |

## Summary By Disposition

| Disposition | Count | Notes |
| --- | --- | --- |
| fix-now | 0 | |
| fix-pre-launch | 0 | — |
| accept-with-doc | 0 | — |
| defer | 0 | — |
| **RESOLVED** | **8** | All eight items closed at `f5a58539` (M1.6b). Backend suite exits 0. |

## First-Tenant Gate

Per `2026-05-12-dev-go-live-remediation-plan.md` §M1.6 + First-Tenant Ready:

- `composer test` must run to completion with **zero unexplained failures** before the gate closes.
- Every failure above has a disposition row; the gate is satisfied when the disposition column for each failure is either **fix-pre-launch with PR landed** or **accept-with-doc with sign-off**.
- F1/F2/F3 token-ability drift and F7 fiscal-chain byte drift cannot be `accept-with-doc` for a fiscal pilot.

## Pre-existing Skips / Incomplete (not blockers)

The 86 skipped tests include the existing PG-only test suite (`Cache validation tests are designed for PostgreSQL. SQLite may have compatibility issues with complex subqueries.`) and vertical-conditional tests. These are pre-existing and out of M1.6 scope.

The 3 incomplete tests are deliberately marked `markTestIncomplete()` by their authors; no action required.

The 400 deprecation warnings are Laravel/PHPUnit upgrade noise (mostly PHPUnit 12 forward-compatibility); they do not affect pass/fail and are out of M1 scope.
