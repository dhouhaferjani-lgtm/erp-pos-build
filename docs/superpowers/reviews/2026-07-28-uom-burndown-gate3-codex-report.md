# Gate 3 report — UoM quantity-display baseline burn-down

## Scope and branch

- Plan: `docs/handoff/CODEX-uom-baseline-burndown-2026-07-22.md`
- Branch: `chore/uom-baseline-burndown`
- Gate 2 controller approval: `46d9dd6ac`
- Wave 3 implementation tip: `2746f89ef`
- Review range: `46d9dd6ac..2746f89ef`, 5 commits
- Scope: final-wave Parts B, C, and D only; no merge or deployment action was taken.

## Wave 3 commits

- `b59186cc0` — `Phase 1.2.32: Resolve presentation quantity defaults`
- `f2bc7f04b` — `Phase 1.2.33: Scale pending customer balances`
- `38df24bcc` — `Phase 1.2.34: Scale Z-report variance defaults`
- `1b5a5e57e` — `Phase 1.2.35: Retire dead POS sync reads`
- `2746f89ef` — `Phase 1.2.36: Test hardcoded step guards`

## Part B — backend precision ratchet

- `ReceiptController` response-only `returned_quantity` defaults now use `QuantityScale::formatForUnit()` and retain the established scale-4 fallback.
- `DiscountPolicyController` and `StandaloneReceiptController` quantity defaults use `QuantityScale::formatForUnit()` without changing DTO shape or the zero/one semantic value.
- Gate 2 MINOR-1 is folded in: `DocumentAdditionalCostController` and `OrderLineResource` safely resolve nullable UoM precision and fall back to 4. Nullable scalar intermediates avoid PHPStan's `nullsafe.neverNull` false-positive while preserving the requested wire behavior.
- `PosPendingCustomerController` resolves the company currency through `getScaleSafe(..., 3)` and writes strict zero balances at that currency's scale. The write remains outside the fiscal chain and no Partner shape changed.
- `ZReportSyncController` resolves currency before constructing the missing-variance default, formats the zero at the currency scale, and passes it through the existing `VarianceAmount` and `CashCountRecorded` path. No event field, ordering, value object, or event shape changed.
- All nine `precision.fixedScaleQuantityLiteral` entries were removed with their fixes. The `# uom-display-precision ratchet` comment block is gone.
- No migration, signed receipt field, stored receipt snapshot, fiscal hash, or fiscal event class changed.

## Part C — dead POS sync reads

- Retired `GET /pos/sync/pull` and `GET /pos/sync/menu`, their dead controller methods/dependencies, and obsolete endpoint tests.
- Added a route-retirement feature test that pins both reads to 404.
- Current-tree searches found no `sync/pull` or `sync/menu` callers in `apps/pos` or `apps/web`; `apps/mobile` is absent.
- `git log -S'sync/pull'` and `git log -S'sync/menu'` found no historical callers across `apps/pos`, `apps/web`, or `apps/mobile`.
- Supported clients use `/products` and `/active-menu`.
- `POST /pos/shifts/{id}/sync-close` remains routed to `SyncController::syncCloseShift`, with its existing device-authority coverage retained.
- The proof above is also recorded in commit `1b5a5e57e`'s message.

## Part D — guard regression tests

- Added adjacent RuleTester suites for the POS and web copies of `no-hardcoded-step`.
- Each suite covers 6 valid and 3 invalid cases, including string literals, expression-wrapped strings, case-insensitive attributes, and `.5` input.
- Both suites are chained into their app's `test:eslint-rules`; both rules remain registered in their ESLint configurations.

## Acceptance evidence

- Quantity audit: `0 total (0 baselined, 0 new, 0 stale baseline entries)`.
- `apps/web/tools/quantity-display-baseline.json`: exactly `[]` (3 bytes including newline).
- PHPStan ratchet: no `uom-display-precision ratchet` comment and no `precision.fixedScaleQuantityLiteral` entry remain.
- Full PHPStan level-8 application run: 2,637/2,637 files, zero errors.
- Targeted API verification by path for the Wave 3 backend surface: 128 tests, 525 assertions passed. Existing non-failing environment/file warnings remain.
- Ticket-scoped web Vitest: 18 files, 100 tests passed.
- Ticket-scoped POS Vitest: 7 files, 101 tests passed. Existing network-fixture and React `act(...)` stderr remains non-failing.
- Web lint: exit 0, 0 errors; existing warnings remain. Query-key audit: 0 acknowledged/new/stale. Design audit: 743 acknowledged, 0 new/stale. Web typecheck passed.
- POS lint: exit 0, 0 errors; existing warnings remain. POS typecheck passed.
- Web RuleTester chain passed, including `no-hardcoded-step` 6 valid/3 invalid. POS RuleTester chain passed, including `no-hardcoded-step` 6 valid/3 invalid.
- Gate-complete preflight sequence passed with `APP_ENV=testing`, its supported path selectors for the required by-path backend/frontend suites, and Pint limited to Wave 3 PHP files. It included and passed Pint, full PHPStan, API tests, type generation (454 types, zero drift), permission-map generation (zero drift), web typecheck/lint/audits, both POS rule suites, route-manifest drift, ticket Vitest, fiscal-v3 fixture parity (2 files, 29 tests), and the section 14.3 chokepoint gate.
- `git diff --check` passed and the implementation worktree was clean before this report.

## Pre-existing unscoped-suite debt

The unscoped preflight diagnostic was also attempted so existing repository debt was not hidden:

- Repo-wide Pint stopped on 29 files untouched by `46d9dd6ac..2746f89ef`. Every changed Wave 3 PHP file passes Pint.
- The first unscoped web Vitest run reported 21 failed files / 47 failed tests. The exact deterministic failure set was then replayed on both the Wave 3 tip and detached Gate 2 controller tip `46d9dd6ac`: both produced the same 20 failed files / 46 failed tests. The additional `PartnerForm` failure did not reproduce on either tip and its targeted replay passed.
- The identical A/B set includes the controller-excluded `StockByLocationPage`, the two inventory `tenantScope` assertions, and `useOwnerReports`, plus other Gate-2-tip test-harness/import debt in expense, locale, shared-singleton/offset metadata, finance/treasury, POS reporting/analytics, replenishment, supplier-invoice, and POS-page suites.
- No file in that failure set was changed to chase the debt, and Wave 3 introduces zero deterministic frontend test failures relative to the approved Gate 2 tip.

The path-scoped preflight above is the green ticket gate and follows the brief's explicit PHPUnit-by-path rule. The unscoped A/B evidence is retained for the controller because unrelated repository debt prevents representing the global suite as clean.

## Internal review

An independent read-only review inspected all 20 changed files against the handoff and ticket. It reported zero Critical, Important, or Minor findings and assessed the range as ready for Gate 3 external review. Its independent API replay passed 106 tests / 487 assertions; the primary verification above supplies the completed higher-memory full PHPStan and preflight evidence.

## Gate status

**IMPLEMENTATION COMPLETE — HARD STOP.** Wave 3 is ready for the controller's final external Gate 3 review. This report does not self-approve the gate. No merge, push, deployment, migration, or Wave 4 work has started.
