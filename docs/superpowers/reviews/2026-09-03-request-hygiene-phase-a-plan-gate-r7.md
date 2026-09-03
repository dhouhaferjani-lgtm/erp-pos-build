# Plan gate r7 — request hygiene Phase A

## Verdict: REJECT

Revision 7 genuinely fixes both r6 blockers and retains its non-blocking safeguards. The full rerun found three new blocking defects against the current `dev` baseline.

## Blocking findings

### 1. Tasks 11–13 — the idempotency hook moved, but the plan still creates and imports the obsolete path

**Evidence:** Task 11 still proposes creating `src/lib/hooks/useIdempotencyKey.ts` and its test at [plan:2356](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2356). Tasks 12 and 13 import/mock `@/lib/hooks/useIdempotencyKey` at [plan:2569](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2569) and [plan:3254](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3254).

Task 11 has now merged into `dev`. Its canonical implementation is [useIdempotencyKey.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/useIdempotencyKey.ts:10), and its stronger three-test suite imports from that location at [useIdempotencyKey.test.tsx:4](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx:4).

**What is wrong:** Task 11 no longer starts red. Following it literally creates a second implementation under `src/lib/hooks`; skipping it makes Tasks 12/13 fail module resolution. Either outcome violates the single-source hook contract.

**What the plan must say instead:** Mark Task 11 complete/verification-only on current `dev`, using:

- `apps/web/src/hooks/useIdempotencyKey.ts`
- `apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx`
- `@/hooks/useIdempotencyKey` in Tasks 12 and 13, including mocks

Remove the obsolete duplicate-file creation and module-not-found red step, and update dispatch/status language to the merged baseline.

### 2. Task 4 — `aggregate_id` is incorrectly narrowed to UUID

**Evidence:** The proposed rule is `['required_with:aggregate_type', 'uuid']` at [plan:1223](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1223). The database contract is a generic 100-character string at [create_audit_events_table.php:19](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_12_15_100000_create_audit_events_table.php:19). Existing domain fixtures use values such as `doc-123` at [AuditTrailTest.php:199](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/AuditTrailTest.php:199), and the aggregate regression calls the service directly at [AuditTrailTest.php:221](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/AuditTrailTest.php:221).

**What is wrong:** Valid historical/string aggregate keys become HTTP 422s. The planned suite can pass for the wrong reason: its existing positive aggregate test bypasses the new FormRequest, while the cross-tenant HTTP fixture uses a UUID.

**What the plan must say instead:** Preserve the storage/domain contract:

```php
'aggregate_id' => ['required_with:aggregate_type', 'string', 'max:100'],
```

Add a positive HTTP regression querying `aggregate_type=Document&aggregate_id=doc-123` and asserting the matching events. Retain the UUID cross-tenant test separately.

### 3. Task 2 — the prescribed test does not typecheck

**Evidence:** Step 5 explicitly adds `beforeEach` at [plan:710](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:710) and imports it at [plan:714](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:714), but provides no use. The web TypeScript configuration enables `noUnusedLocals` at [tsconfig.json:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/tsconfig.json:28).

**What is wrong:** The required `pnpm typecheck` fails after implementation even if the functional test passes. The shared hoisted mock state also remains susceptible to test-order leakage.

**What the plan must say instead:** Either omit the import or, preferably, prescribe:

```tsx
beforeEach(() => {
  apiGetMock.mockReset()
  queryCapture.current = null
})
```

The existing row assertions and new pagination regression then remain genuinely red before the page change and green afterward.

## Non-blocking findings

- Both r6 blockers are genuinely resolved in the revised text:
  - Task 9 now includes WebSocket check-only coverage, fatal `config:cache`, shell syntax/harness coverage, and an independent Reverb-container probe.
  - Task 13 catches any unique violation after rollback, re-reads by tenant/company/idempotency key, uses the exact generated number in the competing connection, and tests transaction levels 0 and 1.
- Every r6 non-blocking item is retained: complete payment Playwright metadata, bounded W8 semantics, mandatory whole-suite log verification, deferred RecordPaymentModal precision debt, honest Task 7 partial scope, fail-closed Redis caveat, corrected SplitPaymentModal anchors, and different-key transfer-number debt.
- Task 1’s behavior is correct, but [plan:141](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:141) incorrectly says queue processing goes through `TenancyResolver`. Worker execution calls `tenancy()->initialize()` directly at [QueueTenancyBootstrapper.php:82](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:82). It still emits the same events, so the test remains valid.
- Task 9’s live-state note is stale. The command, Redis default, four check-only branches, and WebSocket follow-up have merged into `dev`; the remaining real work is making normal-boot `config:cache` fatal. For example, WebSocket still suppresses failure at [entrypoint-websocket.sh:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:39). Rebase the residual work from current `dev`, not merely commit after `489d9fbc6`.
- Task 14 preserves ordinary debounce behavior and the unmount/reset generation guard. Its debounce regression only covers an idle promise tail; it does not prove coalescing when data changes repeatedly while an earlier request is physically in flight. Serialization prevents overlap and preserves final ordering, but multiple already-fired debounce jobs can queue. Add an in-flight debounce regression if “one queued latest snapshot” is intended.
- Task 6 should run the other DocumentLineEditor suites, especially [DocumentLineEditor.purchasePriceDefault.test.tsx:119](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/__tests__/DocumentLineEditor.purchasePriceDefault.test.tsx:119) and the component tenant-scope suite, not only the primary test file.
- Actionable line references are accurate apart from the live Task 9 shift noted above and the obsolete Task 11 paths. The corrected SplitPaymentModal anchors 42/73/122 are exact.

## Blast-radius table

| Task | Unlisted consumer path:line | Consequence |
|---|---|---|
| 1 | None beyond the three inventoried flush callers | Pre-resolved registrar and worker lifecycle are covered. |
| 2 | External POS/mobile clients — absent from checkout | Omitted `page` becomes bounded page one; promotion gate must remain binding. |
| 3 | [permissions.spec.ts:414](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/permissions.spec.ts:414) | Its unpaged payment request is authorization-only and remains compatible; external clients still require evidence. |
| 4 | [AuditTrailTest.php:221](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/AuditTrailTest.php:221) | Demonstrates non-UUID aggregate IDs, but bypasses HTTP validation and therefore masks the regression. |
| 5 | [DocumentComponents.tenantScope.test.tsx:185](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx:185) | Product-read expectation now waits through the 250 ms debounce. |
| 6 | [DocumentLineEditor.purchasePriceDefault.test.tsx:119](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/__tests__/DocumentLineEditor.purchasePriceDefault.test.tsx:119) | Shared pricing-context request timing changes; add the suite to verification. |
| 7 | [GoodsReceiptListPage.tenantScope.test.tsx:258](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx:258) | Root `stock-levels` invalidation still matches the new key. |
| 8 | None; DashboardLayout is the sole production host | Every authenticated route can retain stale active data for at most the cooldown after repeated reconnects. |
| 9 | [DispatchStockChangeToChannels.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Channel/Application/Listeners/DispatchStockChangeToChannels.php:38); [EnrichmentImagePersister.php:244](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Product/Application/Services/EnrichmentImagePersister.php:244) | Default-cache debounce state and distributed locks move to Redis and inherit its availability. |
| 10 | [ImportJobClaimConcurrencyTest.php:203](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Import/ImportJobClaimConcurrencyTest.php:203), among many log spies | Lazy-load warnings can change exact global log expectations; CI whole-suite gate is necessary. |
| 11 | None in production | The hook is already merged; Tasks 12/13 are its first intended consumers. |
| 12 | None beyond the three named RecordPaymentModal hosts | SplitPaymentModal has no active repository caller. |
| 13 | [ReplenishmentSettlementTest.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Replenishment/ReplenishmentSettlementTest.php:219); [TransferLineQueryServiceTest.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/TransferLineQueryServiceTest.php:39) | Direct service consumers should be added to focused verification. |
| 14 | None | DocumentForm is the sole production consumer. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | Conditional | Requires confirmed DB-per-tenant staging topology, migrations, queue drain, and worker proof. |
| 2 | Conditional | Does not edit ProductController/counting, but changes list contracts and visible totals; external-client gate applies. |
| 3 | Conditional | Mandatory pagination changes existing client behavior; external POS/mobile evidence is required. |
| 4 | Conditional | Directly edits DocumentController and changes audit response/validation contracts; coordinate with document lanes. |
| 5 | Conditional | Adds a visible 250 ms delay in documents, transfers, replenishment, and live counting. |
| 6 | No until WAIT clears | Directly edits DocumentLineEditor; post-PO merge/rebase remains mandatory. |
| 7 | Conditional | Edits the live stock-transfer page and only partially removes fan-out. |
| 8 | Conditional | Global authenticated-layout behavior; rapid reconnects can defer recovery for 30 seconds. |
| 9 | Conditional | Runtime boot behavior changes for every role; five-environment Redis proof is mandatory. |
| 10 | Conditional yes | Production behavior remains disabled, but staging/tests gain global warning output. |
| 11 | Yes, already merged | Isolated hook, no current production consumer. |
| 12 | Conditional | Touches all active back-office payment entry surfaces; avoid manual-test days and run all three browser probes. |
| 13 | Conditional | Changes stock-transfer and replenishment transaction behavior; real PostgreSQL and replenishment coverage are mandatory. |
| 14 | Conditional | Changes live DocumentForm autosave behavior and must rebase after the PO lane. |

## Things verified correct

- Task 1: `initializeCache()` re-reads both key and store on an already-resolved singleton at [PermissionRegistrar.php:67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/PermissionRegistrar.php:67). Stancl always emits `TenancyInitialized` from direct initialization at [Tenancy.php:52](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Tenancy.php:52); the compatibility test correctly uses the resolver and does not emit it.
- Tasks 2–3: FormRequest/controller signatures, aliases, wildcard escaping, stable order, mandatory pagination, Axios response shapes, W4/W5 traversal, and payment Playwright metadata are otherwise coherent.
- Task 4: Paired validation, date-span guard, translated key, payload opt-in, pagination, ordering, and document limit clamp are correct aside from the aggregate-ID type.
- Tasks 5–8: Hook signatures, test helpers, tenant-scoped keys, shared stock query, and epoch-zero reconnect sentinel match current code.
- Task 9: In this Laravel version `DatabaseStore` lacks `tags()`, while Redis and array stores are taggable; `method_exists($store, 'tags')` correctly distinguishes them. Unset `CACHE_STORE` intentionally becoming Redis can break ad-hoc infrastructure-free Artisan runs, and the plan appropriately pins PHPUnit and `types-drift`.
- Task 10: Laravel returns from the registered lazy-load violation callback and then continues relation loading at [HasAttributes.php:600](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:600). It does not throw when the handler logs.
- Task 12: Synchronous ref locks, success-only key rotation, pending-button state, decimal-string props, direct bcmath inputs, and the complete local caller inventory are correct once the hook import is fixed.
- Task 13: `UniqueConstraintViolationException` exits `DB::transaction()` only after rollback; top-level re-read runs at level 0, and savepoint rollback leaves the outer level-1 transaction usable. The revised exact dual-constraint fixture is sound.
- Task 14: Promise-tail identity cleanup, failure recovery, StrictMode reset, reset/unmount cancellation, draft-ID forwarding, and ordinary trailing debounce behavior are correct.
- No migration is proposed, so the migration self-guard rule is not implicated.