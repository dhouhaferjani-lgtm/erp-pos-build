# Opus adversarial round-1 review — api.pos-stabilization cluster

Review date: 2026-05-05
Branch tip reviewed: 0b93c644
Reviewer: opus

Verdict: REQUEST-CHANGES
Commit reviewed: 0b93c644

## Summary

The cluster's 38 callsites are mechanically closed and all gates green:
PosStabilizationTenantIsolationTest 35/150, full POS suite 554/1889 OK,
Loyalty 39/144 OK, PHPStan clean, Pint clean, sweep:inventory:verify-history
1390 events / 296 callsites / 0 problems. The 4 pre-existing tests that
were rewritten are honest contract tightenings (controller-tier 403/200
denials replaced by validator-tier 422 denials — structurally stronger,
not weaker). Group 1a (7 callsites), Group 1b (16 callsites), Group 2
(2 callsites) and Group 4 (5 manual stubs) are correctly scoped per
canonical `ScopedExists` / `ScopedExists::tenant` / `ScopedExists::company`
and per the schema each table actually carries (loyalty is tenant-only;
locations are company-only; users are tenant-only; everything else T+C).

But Group 3 has one fix that is **partial in a way the commit message
underplays**: callsite .027 (VoucherLedgerPushService::push) introduced
a NEW unscoped `Terminal::query()->where('id', $requestingTerminalId)
->first()` whose anchor is a request-body field
(`$payload->terminalId`) — NOT authenticated context. The Voucher lookup
that follows then derives `tenant_id` from this attacker-controllable
terminal. Combined with the fact that `VoucherLedgerSyncRequest` only
declares `entries.*.terminal_id` as `['nullable', 'uuid']` (no `exists`,
no scope), a tenant-A authenticated cashier can still push a redemption
event against a tenant-B voucher by submitting (tenant-B-terminal-id,
tenant-B-voucher-id) as the payload pair — the new `tenant_id` predicate
on Voucher does NOT block this because the comparator was just lifted
from the same untrusted field. The downstream
`redeemable_at_terminal_id === $requestingTerminalId` guard does close
the leak (both sides are tenant-B) so the *security risk* is the same
as pre-fix; what's wrong is the commit's framing of "defense in depth
that aligns with the Treasury invariant" — the Treasury invariant
requires anchor from authenticated context, and the fix's anchor is
still untrusted. Inventory expected_scope was `tenant_and_company`; the
fix added only `tenant_id`, derived from untrusted input.

Two additional in-cluster blind spots the scanner missed (consistent
with prior round-4 sub-15a):

- `StoreReceiptRequest.php:60-63` keeps `'exists:composite_items,id'`
  bare. `composite_items` HAS both `tenant_id` and `company_id`. Same
  validator scopes products / partners / contacts T+C; the
  composite_item_id field on the same line shape is unprotected.
- `CreateTableRequest.php:19` and `UpdateTableRequest.php:19` keep
  `'exists:pos_floors,id'` bare. `pos_floors` HAS T+C. Both are reachable
  POST/PATCH /api/v1/pos/tables.

The commits' "not in inventory; deferred" comments are honest but the
two surfaces are structurally identical to neighbouring fields the
sweep just closed in the same FormRequest. They should be either
(a) closed in a follow-up commit, or (b) added to the scanner-blind-spot
follow-up tracker so they don't get lost.

## Per-group findings

- Group 1a (7 callsites): CLEAN. ClaimTerminalRequest, UpdateTerminalRequest,
  RequestTerminalRequest, CreateTerminalRequest, ReportController inline
  validates (.016/.017/.018) all use canonical ScopedExists::tenantAndCompany
  for pos_terminals (T+C) and ScopedExists::company for locations (C-only,
  matching schema). Constructor-injected CompanyContext private readonly.
  Structural-SQL-log invariant test pins both predicates appearing in the
  emitted exists() count query.

- Group 1b (16 callsites): CLEAN. AddOrderLineRequest, CreateOrderRequest,
  GenerateZReportRequest, OpenShiftRequest, StoreReceiptRequest,
  StoreReturnRequest, VerifyManagerPinRequest. Modifiers (.012) correctly
  scoped via `Rule::exists('modifiers','id')->where(closure)` subquery
  through modifier_groups (modifiers schema lacks tenant_id/company_id).
  Users (.031/.032/.033) correctly scoped via ScopedExists::tenant
  (users schema has no company_id). Composite blind spot
  (`exists:composite_items,id` line 61, schema HAS T+C) is real and
  documented but not actually scoped — see in-cluster blind spot below.

- Group 2 (2 callsites): CLEAN. TerminalController::getOrCreateWebTerminal
  validator now ScopedExists::company('locations', $companyId), and the
  follow-up Location::query()->where('company_id', $company->id)
  ->findOrFail($locationId) closes the post-validation lookup. Order-of-
  operations corrected: company is resolved before validate(). The
  internal-inconsistency write (terminal with foreign location_id) is
  closed.

- Group 3 (8 callsites — structural-pattern shortcut): MOSTLY CLEAN
  (.020/.021/.022/.023/.024/.025/.026), ONE FINDING on .027.
  - .020-.026 all use the canonical
    `Model::query()->where('tenant_id', X)->where('company_id', Y)->find`
    pattern; tenant/company are sourced from authenticated CompanyContext
    OR from the anchoring entity (Order, Terminal) which itself was
    loaded under company-scoped lock-for-update. Honest disclosure that
    the pattern test exercises the SHAPE not the call (acceptable since
    each callsite is readable in code and uses the same shape). Same-
    SQL-shape invariant test pins the future regression detector.
  - .027 (VoucherLedgerPushService::push) introduces a NEW unscoped
    Terminal lookup `Terminal::query()->where('id', $requestingTerminalId)
    ->first()` whose argument comes from request body. The subsequent
    Voucher::query()->where('tenant_id', $terminal->tenant_id) is
    therefore derived from untrusted input and is not equivalent to the
    Treasury invariant's "authenticated-context tenant pin". The
    downstream `redeemable_at_terminal_id === $requestingTerminalId`
    guard still closes the actual exploit, so security posture is
    unchanged from pre-fix — but the commit message's claim that this
    is a defense-in-depth that "aligns with the Treasury invariant for
    any service-tier Eloquent find reachable from an HTTP path" is not
    accurate. Either (a) scope Terminal lookup by CompanyContext like
    every other service-tier find in this cluster, or (b) drop the
    self-congratulatory framing and document the real shape: the tenant
    predicate is a tighter error message, not a tighter guard.

- Group 4 (5 manual stubs — LoyaltyPOSController): CLEAN. earn / redeem
  / previewEarning all pre-load Enrollment scoped via member.tenant_id
  and (in redeem) Reward scoped via program.tenant_id BEFORE delegating
  to the unscoped EarningProcessingService / RedemptionProcessingService.
  Mirrors the canonical pattern already present in self::rewards
  (line 141) using whereHas + whereRaw. Loyalty schema is tenant-only
  (loyalty_programs has tenant_id + company_ids JSON list; loyalty_members
  has tenant_id only) so tenant-only scope is correct. Service-tier
  manual stubs (.037 / .038) are structurally protected by the
  controller-tier guard — every HTTP path passes through the pre-load
  first.

## Test-honesty audit (4 updated pre-existing tests)

- ReceiptChainVerificationTest::enforces_company_ownership: HONEST.
  Pre-fix asserted `assertStatus(403) + error.code FORBIDDEN`. Post-fix
  asserts `assertUnprocessable() + error.errors.terminal_id` arr-key.
  Validator-tier 422 is structurally stronger than controller-tier 403:
  rejection happens earlier and surfaces a typed field error envelope.
  Cross-tenant terminal_id is still rejected.

- GenerateZReportEndToEndTest::cross_company_terminal_returns_422:
  HONEST. Same pattern as above — 403 (controller manual check) →
  422 (validator) + assertJsonPath('error.code', 'VALIDATION_ERROR') +
  assertArrayHasKey('terminal_id'). Tighter contract.

- GenerateZReportRequestValidationTest::cross_tenant_manager_returns_422:
  HONEST. Kept the 422 + manager_user_id error key assertion. Dropped
  the bespoke `'Manager must be in the same tenant.'` message string
  assertion because the new ScopedExists::tenant rule fires before the
  withValidator() callback — Laravel's default exists-rule message
  replaces the bespoke one. The security contract (cross-tenant denied
  with 422 + correct field key) is preserved. The typed_accessors_*
  tests switched from Symfony's static `Request::create()` factory to a
  direct `new GenerateZReportRequest($companyContext)` + `initialize()`
  helper because the FormRequest now constructor-injects CompanyContext;
  pure DI plumbing change, no contract weakening.

- ManagerPinControllerTest::cross_tenant_manager_returns_422: HONEST.
  Pre-fix: 200 OK + `data.valid=false` (the look-up-and-fail branch
  inside the controller). Post-fix: 422 + `error.errors.user_id`
  (validator denial). Cross-tenant manager_user_id never reaches the
  PIN comparison logic. Strict tightening.

## Audit exhaustiveness

- `php artisan sweep:inventory:verify-history` → 1390 events / 296
  callsites / 0 problems.
- `vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php`
  → 35 tests / 150 assertions / 0 failures.
- `vendor/bin/phpunit tests/Feature/POS` → 554 tests / 1889 assertions /
  0 failures (matches submitter's claim).
- `vendor/bin/phpunit tests/Feature/Loyalty` → 39 tests / 144 assertions /
  0 failures (no LoyaltyPOSController-guard regression).
- `vendor/bin/phpstan analyse` on POS + Loyalty + new test → No errors.
- `vendor/bin/pint --test` on POS + Loyalty + new test → pass.
- Test scaffold `setUp` correctly seeds two tenants × {Location, Terminal,
  Partner, Product, PaymentMethod, Contact, ModifierGroup, Modifier,
  Voucher} and the smoke test `test_setup_creates_per_tenant_resources_correctly`
  verifies all per-tenant resources are owned by the correct tenant +
  company on both sides + cross-tenant distinctness + actingAsForTenant
  authentication. Loyalty resources lazily seeded inside Group 4 tests
  per the file's class-level docblock convention.

## New findings (round 1)

1. **REQUEST-CHANGES — Group 3 .027 partial fix + framing.**
   `VoucherLedgerPushService::push` introduces an unscoped Terminal
   lookup whose argument is request-body input
   (`$payload->terminalId` from `entries.*.terminal_id` in
   `VoucherLedgerSyncRequest`, which only declares
   `['nullable', 'uuid']` — no exists, no scope). The Voucher SELECT
   then derives `tenant_id` from this untrusted terminal, not from
   authenticated context. The Treasury Finding-14 cluster invariant
   requires BOTH tenant_id AND company_id derived from authenticated
   anchor; this fix (a) uses only tenant_id and (b) sources it from
   untrusted input. Inventory expected `tenant_and_company`. The
   downstream `redeemable_at_terminal_id` guard still closes the actual
   exploit (so this is not a P0 leak), but the commit message claims
   alignment with the Treasury invariant that the implementation does
   not deliver. Two acceptable closures:
   (a) Scope the Terminal lookup by CompanyContext (`->where('tenant_id',
       $companyContextTenant)->where('company_id', $companyContextCompany)`)
       and/or add a ScopedExists rule on `entries.*.terminal_id` in
       VoucherLedgerSyncRequest; OR
   (b) Soften the commit/file-comment framing: the tenant predicate is
       a tighter error message (`voucher_not_found` instead of
       `voucher_not_for_this_terminal`), NOT a tighter security guard,
       and the actual cross-tenant denial is still owned by the
       redeemable_at_terminal_id check. Update file docblock on
       VoucherLedgerPushService accordingly.

2. **Scanner blind spot (req-changes for tracking) — composite_items
   in StoreReceiptRequest.** `lines.*.composite_item_id` keeps
   `'exists:composite_items,id'` bare. `composite_items` schema (see
   2026_02_19_100001 migration) HAS BOTH `tenant_id` and `company_id`
   columns. Same FormRequest scopes `lines.*.product_id` T+C. A
   tenant-A user can submit a tenant-B `composite_item_id` and the
   validator accepts it; downstream `ReceiptCreationService` and
   `ReceiptSyncService` resolve composite items via unscoped
   `CompositeItem::find` (the latter at line 235), so the foreign
   composite_item snapshot leaks into the receipt write. Either close
   in a follow-up commit on this branch, or explicitly add to the
   scanner-blind-spot followup tracker (commit d7184178) with a
   pointer to the same sub-15a category.

3. **Scanner blind spot (req-changes for tracking) — pos_floors in
   CreateTableRequest / UpdateTableRequest.** `floor_id` keeps
   `'exists:pos_floors,id'` bare. `pos_floors` HAS T+C. Same closure
   options as #2.

4. **Minor — pre-existing `Terminal::where('id',$existing->terminal_id)
   ->first()` at ReceiptSyncService.php:132** (echoing terminal state
   on idempotency hit) is unscoped. Pre-existing, not introduced by
   this sweep, but flagging because it sits one line away from the
   syncSingleReceipt body that does scope the find. Confirm/track in
   followup.

## Confidence

HIGH on the test-honesty audit: I read both pre-fix and post-fix
versions of all four updated tests via `git show <sha>` and confirmed
each contract change is a structurally stronger denial (validator-tier
422 replacing controller-tier 403/200), not a weaker one.

HIGH on Groups 1a/1b/2/4: each fix uses the canonical pattern that
matches the schema each table actually carries, every fix commit
reports green gates, and the cluster's invariant test (1390 events /
296 callsites / 0 problems) is satisfied.

HIGH on the .027 finding: I read the file in production state, traced
the call path through VoucherSyncController::pushVoucherLedger to the
service, and confirmed `$payload->terminalId` is the field validated
only as `['nullable', 'uuid']` — no exists, no scope. The terminal
lookup that the Group 3 commit introduced uses that untrusted value
verbatim. The redeemable_at_terminal_id guard does still close the
exploit (so this is REQUEST-CHANGES on framing/completeness, not
BLOCK on a real leak), and either closure I recommend would resolve
it cleanly.

MEDIUM on the scanner-blind-spot findings (#2/#3): they are real
in-cluster blind spots not in the inventory — they could be argued as
"not in inventory; deferred to followup" per the existing precedent of
the round-4 sub-15a tracking commit. Whether they need to be closed
NOW or tracked is an owner decision; either is acceptable. I'm logging
them as REQUEST-CHANGES because the cluster's inventory pretends
exhaustiveness for the POS surface and these two FormRequests sit
right next to fields the sweep just closed in the same files.
