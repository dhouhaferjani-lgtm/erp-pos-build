# Opus adversarial round-2 review — api.pos-stabilization cluster

Review date: 2026-05-05
Branch tip reviewed: 5a74481c
Reviewer: opus

Verdict: APPROVE
Commit reviewed: 5a74481c

## Summary

All three round-1 findings closed cleanly with the canonical
`ScopedExists::tenantAndCompany` + constructor-injected `CompanyContext`
pattern. Treasury Finding-14 invariant now holds end-to-end on all 3
remediated surfaces: tenant_id AND company_id are derived from
authenticated CompanyContext, never from request input. Test scaffold
consistency preserved (lazy seedCompositeItem / seedPosFloor helpers
mirror the existing class convention; no ad-hoc seed paths bypassing
setUp).

Gates green: PosStabilizationTenantIsolationTest 40/170, full
tests/Feature/POS suite 559/1909, PHPStan clean, Pint pass,
sweep:inventory:verify-history 1409 events / 299 callsites / 0 problems.
Cluster running totals: 41/41 callsites at under_review.

## Round-1 finding closure

- Finding 1 (VoucherLedgerPushService.027 untrusted-input Terminal lookup):
  CLOSED with evidence (commit 2b314480). Path (a) executed correctly:
  - Service tier — `VoucherLedgerPushService` constructor now injects
    `private readonly CompanyContext $companyContext`. Terminal lookup
    leads with `where('tenant_id', $company->tenant_id)
    ->where('company_id', $company->id)->where('id',
    $requestingTerminalId)` — anchor pinned from authenticated context
    BEFORE the request-body terminal_id is consulted.
  - Voucher SELECT now derives `tenant_id` from the verified terminal
    (which itself was loaded under company-pinned scope), so the
    tenant predicate is a real defense-in-depth guard, not just a
    tighter error message. Treasury Finding-14 invariant satisfied.
  - Validator tier (Opus's recommended bonus) — `VoucherLedgerSyncRequest`
    constructor-injects CompanyContext and adds
    `ScopedExists::tenantAndCompany('pos_terminals', ...)` on
    `entries.*.terminal_id` so cross-tenant payloads 422 BEFORE the
    service runs. Belt-and-braces.
  - Caller path verified: `VoucherSyncController::pushVoucherLedger`
    is the sole call site (grep'd app/, tests/, routes/) and reaches
    the service through an HTTP route under `auth:sanctum` +
    `SetPermissionsTeam` middleware. CompanyContext is always
    populated. No queue/CLI bypass exists today.
  - Regression tests: `test_voucher_ledger_sync_refuses_cross_tenant_terminal_id_via_validator`
    (validator denial) + `test_voucher_ledger_push_service_rejects_cross_tenant_terminal_via_company_context`
    (service-tier denial bypassing validator) + structural-SQL
    invariant test updated to set CompanyContext explicitly.
    Voucher balance assertion (50.00000) confirms no foreign mutation.

- Finding 2 (composite_items in StoreReceiptRequest): CLOSED with evidence
  (commit af79d7c3). `lines.*.composite_item_id` rule replaced
  `'exists:composite_items,id'` with
  `ScopedExists::tenantAndCompany('composite_items', $tenantId,
  $companyId)` — same scope as the sibling `lines.*.product_id`. Reuses
  the CompanyContext already resolved at the top of `rules()`. Service
  tier closed too: `ReceiptSyncService::syncSingleReceipt` line 250
  scopes `CompositeItem::find` by the anchoring terminal's tenant +
  company. Regression test
  `test_store_receipt_refuses_cross_tenant_composite_item_id_on_lines`
  has both denial (cross-tenant 422) and same-tenant control branches.

- Finding 3 (pos_floors in Create/UpdateTableRequest): CLOSED with
  evidence (commit d91c0d3a). Both FormRequests constructor-inject
  CompanyContext. `floor_id` rule replaced
  `'exists:pos_floors,id'` with `ScopedExists::tenantAndCompany(
  'pos_floors', $company->tenant_id, $company->id)`. Two regression
  tests pin POST and PATCH paths.

- Finding 4 (ReceiptSyncService:132 minor pre-existing): annotated/tracked
  via inline comment in commit af79d7c3 referencing round-1 finding +
  `docs/superpowers/audits/2026-05-04-bare-where-scanner-gap.md`. Audit
  file exists.

## New findings (round 2)

None of REQUEST-CHANGES severity. Three observations recorded for the
broader scanner-blind-spot tracker (NOT closure-blocking for this
cluster):

1. **MEDIUM (post-cluster tracking) — `SyncReceiptsRequest.php:59`
   `composite_item_id` and `:58` `product_id` keep `['nullable',
   'uuid']` (no scope).** Reachable via `POST /api/v1/pos/receipts/sync`
   (the offline-batch sync endpoint). The service-tier
   `ReceiptSyncService::syncSingleReceipt` does scope the
   downstream `CompositeItem::find` (line 250, fixed this round) and
   `Product::find` (line 230, fixed in Group 3 .025), so the foreign
   sellable snapshot is scrubbed to "Unknown Product". HOWEVER the
   raw `composite_item_id` UUID from the payload is persisted verbatim
   onto `pos_receipt_lines.composite_item_id` (line 289), which has a
   bare FK to `composite_items` with no tenant/company guard —
   tenant-A's receipt line ends up with tenant-B's composite_item_id.
   Same root cause as the StoreReceiptRequest gap closed this round.
   Symmetric fix: scope both `receipts.*.lines.*.product_id` and
   `receipts.*.lines.*.composite_item_id` in SyncReceiptsRequest by
   the resolved terminal's tenant + company (or the authenticated
   CompanyContext). Track with the scanner-blind-spot follow-up
   tracker; not in inventory.

2. **MEDIUM (post-cluster tracking) — `ReceiptSyncService.php:128`
   bare `Receipt::where('idempotency_key', ...)` lookup.** The
   anchor (`$payload->idempotencyKey`) is untrusted client input. A
   tenant-A user can submit a tenant-B receipt's idempotency_key and
   the service returns the foreign Receipt's `fiscal_hash`,
   `terminalLastHash`, and `terminalHashSequence` via the duplicate-
   echo branch. The unscoped Terminal lookup at line 141 (Round-1
   Finding 4) merely amplifies this — the actual cross-tenant info
   leak originates at the Receipt::where on line 128. The annotation
   added in commit af79d7c3 only references line 141; line 128 is the
   real root cause. Pre-existing, NOT introduced by this sweep, but
   the annotation as-written understates the surface area. Track in
   the scanner-blind-spot follow-up; consider scoping the
   idempotency_key lookup by `where('company_id',
   $this->companyContext->requireCompanyId())` to make
   idempotency_key collisions tenant-scoped (clients should not
   re-use idempotency_keys across tenants anyway).

3. **MEDIUM (post-cluster tracking) — `TableManagementService::releaseTable`
   and `::assignOrderToTable` use unscoped `Table::lockForUpdate()
   ->findOrFail($tableId)`.** `releaseTable` is reachable via
   `POST /api/v1/pos/tables/{id}/release`. A tenant-A cashier can pass
   tenant-B's table id and release a foreign-tenant table from
   occupancy. Pre-existing, not in inventory, scanner blind spot of
   the same shape as Group 3 / round-2. The sibling `setTableStatus`,
   `deleteTable`, `updateTable`, `updateFloor`, `deleteFloor` all
   correctly company-scope before `findOrFail` — only the two
   transactional methods (`releaseTable`, `assignOrderToTable`) are
   gaps. Track with scanner-blind-spot follow-up.

All three new observations are STRUCTURALLY-SIMILAR pre-existing gaps
that the scanner missed. Per the established round-4 sub-15a precedent
(commit d7184178), tracking in the scanner-blind-spot follow-up is the
correct cluster-discipline disposition; closing them now would expand
scope beyond the round-1 findings the cluster opted to remediate.

## Audit exhaustiveness

- `vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php`
  → 40 tests / 170 assertions / 0 failures (was 35/150 round-1; +5
  tests for round-2 closures).
- `vendor/bin/phpunit tests/Feature/POS` → 559 tests / 1909 assertions /
  0 failures (was 554/1889 round-1; full POS suite stays green).
- `vendor/bin/phpstan analyse` on `app/Modules/POS` + the test file →
  No errors.
- `vendor/bin/pint --test` on the same surface → pass.
- `php artisan sweep:inventory:verify-history` → 1409 events / 299
  callsites / 0 problems (was 1390/296 round-1; +3 stub callsites).
- POS surface diff `dev..HEAD`: 25 files in
  `apps/api/app/Modules/POS/` and `apps/api/tests/Feature/POS/`. All
  files are members of the api.pos-stabilization cluster work — no
  parallel-session WIP leak.
- Inventory entries .039/.040/.041 each carry stable_key, fix_commit,
  regression_test, full pending → claimed → in_progress → under_review
  history with checksums. Severity=high, scope=tenant_and_company,
  expected_fix narratives are accurate.
- Hostile sweep of sibling FormRequests (CreateFloorRequest,
  UpdateFloorRequest, SetTableStatusRequest, BulkCreateTableRequest)
  — none carry foreign IDs that need scoping.
- Hostile sweep of all `CompositeItem::find|where|query` calls —
  catalog HTTP controllers all company-scope; only POS sync paths
  had gaps. The validator path (StoreReceiptRequest) closed; the
  syncBatch path (SyncReceiptsRequest) is the new finding tracked
  for the scanner-blind-spot follow-up.

## Confidence

HIGH on the three round-1 closures. I read each fix commit
(`git show <SHA>`) end-to-end, traced the controller→service→DB path
for each, and confirmed the canonical pattern matches the schema each
table actually carries (T+C for composite_items / pos_floors /
pos_terminals).

HIGH on Finding 1 specifically: I traced
`VoucherSyncController::pushVoucherLedger` → `VoucherLedgerSyncRequest::rules`
→ `pushService->push`, confirmed CompanyContext is constructor-injected
into both the FormRequest and the service, and confirmed the Terminal
SELECT now leads with both predicates from authenticated context. The
test
`test_voucher_ledger_push_service_rejects_cross_tenant_terminal_via_company_context`
specifically constructs a programmatic caller bypassing the validator
and asserts the service-tier guard alone closes the leak.

HIGH on the gates: re-ran phpunit (40 + 559), phpstan, pint,
verify-history myself; all green and on the same numbers as the
remediation session reported.

MEDIUM on the three new tracked findings: they are real pre-existing
gaps with the same scanner-blind-spot pattern. Whether they need to be
opened as separate inventory entries NOW or rolled into the broader
followup tracker (per d7184178 precedent) is an owner decision.
Logging them as observations not REQUEST-CHANGES because (a) round-1
verdict already accepted scanner-blind-spot tracking as a valid
disposition for in-cluster gaps, (b) all three are pre-existing not
introduced by this sweep, and (c) closing them here would re-open
scope that this round was specifically chartered to close.
