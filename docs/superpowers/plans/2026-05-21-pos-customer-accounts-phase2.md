# POS Customer Accounts Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Phase 2 On-Account Payment + Customer Attach on the Phase 1 fiscal event engine.

**Architecture:** `ACCOUNT_PAYMENT` is authored and sealed on the Tauri POS, synced through `/api/v1/pos/sync/fiscal-events`, parsed from canonical bytes, and projected through POS-core plus an optional Treasury bridge. POS-core is standalone; Treasury `Payment` + FIFO allocation are projector-gated by `ModuleActivationResolver` and never called by the engine, parser, ingest path, or POS-core projection.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, PHPUnit, PHPStan L8, Pint, Tauri POS, TypeScript, SQLite, Vitest, ESLint.

---

## Non-Negotiable Gates

Phase 1.5 task 2 remains blocked on accountant confirmation for strict TN matricule fiscal and FR SIRET validation. Do not mark Phase 2 customer-facing deployment-ready until that task is implemented, reviewed, and pushed.

For every task:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2/apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Partner
./vendor/bin/pint --test app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Partner tests/Feature/Fiscal tests/Feature/POS tests/Unit/Fiscal

cd /Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2/apps/pos
pnpm test
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

Stage explicit files only. Never use `git add -A`.

## File Map

Create server payload/reader files:

- `apps/api/app/Modules/Fiscal/Domain/DTOs/AccountPaymentPayload.php` — raw parsed DTO matching the canonical ACCOUNT_PAYMENT payload.
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentView.php` — typed canonical view for projections/exports.
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentCustomerDTO.php`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentPaymentDTO.php`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentBalanceSnapshotDTO.php`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentStalenessDTO.php`
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php`.

Create POS payload/customer files:

- `apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts`.
- Modify `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`.
- Modify `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` validation for `ACCOUNT_PAYMENT`.
- Modify `apps/pos/src/lib/db/migrations.ts`.
- Create `apps/pos/src/lib/db/repositories/customerRepository.ts`.
- Create `apps/pos/src/lib/db/repositories/pendingCustomerRepository.ts`.
- Create `apps/pos/src/lib/customer/customerTypes.ts`.
- Create `apps/pos/src/lib/customer/customerSyncService.ts`.
- Create `apps/pos/src/lib/customer/pendingCustomerSyncService.ts`.
- Create `apps/pos/src/lib/offline/accountPaymentService.ts`.

Create server customer sync files:

- `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php`.
- `apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php`.
- `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php`.
- `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerAliasResource.php`.
- `apps/api/app/Modules/POS/Domain/PosCustomerAlias.php`.
- `apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php`.
- Modify `apps/api/app/Modules/POS/routes.php`.

Create projection files:

- `apps/api/app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php`.
- `apps/api/app/Modules/POS/Domain/AccountPaymentReceipt.php`.
- `apps/api/database/migrations/2026_05_21_130000_create_pos_account_payment_receipts_table.php`.
- Modify `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`.
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php`.
- Modify `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`.

Create allocation refactor files:

- `apps/api/app/Modules/Treasury/Application/DTOs/ApplyPaymentAllocationCommand.php`.
- Modify `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`.

Create POS UI files:

- `apps/pos/src/components/customers/CustomerAttachPanel.tsx`.
- `apps/pos/src/components/customers/CustomerSearchInput.tsx`.
- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx`.
- Integrate with `apps/pos/src/stores/paymentStore.ts` and `apps/pos/src/pages/HomePage.tsx` checkout surfaces.

## Task 1: ACCOUNT_PAYMENT Payload Contract And Drift Gates

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/AccountPaymentPayload.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentView.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentCustomerDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentPaymentDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentBalanceSnapshotDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentStalenessDTO.php`
- Create: `apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`
- Modify: `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php`
- Test: `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`
- Test: `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- Test: `apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`
- Test: `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/AccountPaymentPayload.test.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/accountPaymentCanonicalParity.test.ts`

- [ ] **Step 1: Write failing registry tests**

Add PHP test cases:

```php
public function test_account_payment_is_implemented_at_version_one(): void
{
    $registry = new FiscalEventPayloadRegistry();

    self::assertTrue($registry->isImplemented(FiscalEventType::ACCOUNT_PAYMENT));
    self::assertSame(1, $registry->eventVersionFor(FiscalEventType::ACCOUNT_PAYMENT));
    self::assertSame(AccountPaymentPayload::class, $registry->dtoClassFor(FiscalEventType::ACCOUNT_PAYMENT));
}
```

Add TS test cases:

```ts
it('implements ACCOUNT_PAYMENT at version 1 without changing server-only types', () => {
  const registry = new FiscalEventPayloadRegistry();
  expect(registry.isImplemented('ACCOUNT_PAYMENT')).toBe(true);
  expect(registry.eventVersionFor('ACCOUNT_PAYMENT')).toBe(1);
  expect(registry.serverOnlyTypes()).toEqual([
    'TERMINAL_REGISTRY_SNAPSHOT',
    'COMPANY_DAY_CLOSURE_MANIFEST',
  ]);
});
```

- [ ] **Step 2: Run failing registry tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_payment

cd ../pos
pnpm test -- FiscalEventPayloadRegistry.test.ts
```

Expected: PHP throws `FiscalEventTypeNotImplemented`; TS throws `FiscalEventTypeNotImplementedError`.

- [ ] **Step 3: Add payload DTOs and registry entries**

PHP registry entry:

```php
FiscalEventType::ACCOUNT_PAYMENT->value => [AccountPaymentPayload::class, 1],
```

TS implemented set entry:

```ts
const PHASE_1_IMPLEMENTED = [
  'SALE_RECEIPT',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'TERMINAL_REGISTRY_SNAPSHOT',
  'ACCOUNT_PAYMENT',
] as const satisfies readonly FiscalEventTypeValue[];
```

Use `AccountPaymentPayload` with exactly these top-level fields:

```php
[
    'account_payment_uuid',
    'business_date',
    'cashier_id',
    'cashier_name',
    'currency_code',
    'currency_scale',
    'customer',
    'event_time_device',
    'local_balance_snapshot',
    'notes',
    'payment',
    'receipt_type_code',
    'references',
    'regime_extensions',
    'seller',
    'shift_id',
    'staleness',
    'terminal_id',
    'training_flag',
    'treasury_allocation_policy',
]
```

- [ ] **Step 4: Write failing validator, strict-parser, canonical-reader, and parity tests**

Add positive fixtures for:

- synced customer, fresh balance, cash payment;
- pending customer, stale balance, card payment;
- foreign currency payment with `foreign_currency_code` and `foreign_currency_amount`;
- nullable `references` and populated `references`.

Add negative tests for:

- missing customer block;
- `customer_sync_status` not in `synced|pending_create`;
- amount `0.00` when `training_flag=false`;
- stale reason missing when stale flag is true;
- foreign currency amount without code;
- seller tax number invalid under current universal validator.

Add strict parser and canonical reader tests:

```php
public function test_strict_parser_accepts_account_payment_canonical_envelope(): void;
public function test_strict_parser_rejects_account_payment_extra_payload_key(): void;
public function test_canonical_reader_returns_account_payment_view(): void;
```

Add TS/PHP golden parity fixture:

```ts
it('encodes the golden ACCOUNT_PAYMENT fixture to the PHP-locked canonical bytes', () => {
  expect(encodeFiscalCanonicalPayload(goldenAccountPaymentPayload())).toBe(goldenAccountPaymentCanonicalBytes);
});
```

- [ ] **Step 5: Implement validator branch**

In `FiscalPayloadConstraintValidator`, route by event type and enforce:

```php
if ($payload['receipt_type_code'] !== 'ACCOUNT_PAYMENT') {
    throw new RuntimeException('payload_account_payment_receipt_type_invalid:must be ACCOUNT_PAYMENT');
}
```

Use existing money, UUID, ISO date, ISO timestamp, seller, and address helpers where possible. Add only private helpers that are reused by at least two fields or make a discriminated union explicit.

- [ ] **Step 6: Implement strict parser and canonical reader support**

`StrictCanonicalParser` must accept `ACCOUNT_PAYMENT` only after the registry DTO, exact key-set validator, and per-event constraints all pass. `CanonicalPayloadReader` adds:

```php
public function forAccountPayment(FiscalEvent $event): AccountPaymentView
```

It throws `InvalidArgumentException` when called for any event type other than `ACCOUNT_PAYMENT`, or when `payload` is null.

- [ ] **Step 7: Run Task 1 tests and commit**

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php
pnpm test -- FiscalEventPayloadRegistry.test.ts AccountPaymentPayload.test.ts accountPaymentCanonicalParity.test.ts
```

Commit:

```bash
git add apps/api/app/Modules/Fiscal/Domain/DTOs/AccountPaymentPayload.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentView.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentCustomerDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentPaymentDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentBalanceSnapshotDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountPaymentStalenessDTO.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php \
  apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php \
  apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php \
  apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php \
  apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php \
  apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php \
  apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php \
  apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts \
  apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts \
  apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts \
  apps/pos/src/lib/fiscal/__tests__/AccountPaymentPayload.test.ts \
  apps/pos/src/lib/fiscal/__tests__/accountPaymentCanonicalParity.test.ts
git commit -m "Phase 2.1.1: Add account payment payload contract"
```

## Task 2: POS Customer Mirror Schema And Repository

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts`
- Create: `apps/pos/src/lib/customer/customerTypes.ts`
- Create: `apps/pos/src/lib/db/repositories/customerRepository.ts`
- Test: `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts`

- [ ] **Step 1: Write failing SQLite repository tests**

```ts
it('upserts customers by tenant/company/id and refuses company drift', async () => {
  await upsertCustomer(db, customer({ tenant_id: 'tenant-a', company_id: 'company-a', id: 'cust-1' }));
  await expect(upsertCustomer(db, customer({ tenant_id: 'tenant-a', company_id: 'company-b', id: 'cust-1' })))
    .rejects.toThrow(/customer company drift/i);
});

it('searches active customers by name phone and tax number within tenant/company', async () => {
  await upsertCustomer(db, customer({ tenant_id: 'tenant-a', company_id: 'company-a', id: 'cust-1', name: 'Mariam Ben Ali', phone: '+21611111111' }));
  await upsertCustomer(db, customer({ tenant_id: 'tenant-a', company_id: 'company-b', id: 'cust-2', name: 'Mariam Other', phone: '+21622222222' }));
  const rows = await searchCustomers(db, { tenantId: 'tenant-a', companyId: 'company-a', query: 'mariam' });
  expect(rows.map((r) => r.id)).toEqual(['cust-1']);
});
```

- [ ] **Step 2: Add SQLite migration**

Use the next migration version after the current highest entry:

```sql
CREATE TABLE IF NOT EXISTS customers (
  id TEXT NOT NULL,
  tenant_id TEXT NOT NULL,
  company_id TEXT NOT NULL,
  name TEXT NOT NULL,
  phone TEXT,
  email TEXT,
  tax_number TEXT,
  customer_category TEXT,
  receivable_balance TEXT NOT NULL DEFAULT '0.0000',
  credit_balance TEXT NOT NULL DEFAULT '0.0000',
  balance_updated_at TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  sync_version TEXT,
  updated_at TEXT,
  synced_at TEXT NOT NULL,
  PRIMARY KEY (tenant_id, company_id, id)
);
CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(tenant_id, company_id, name);
CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(tenant_id, company_id, phone);
CREATE INDEX IF NOT EXISTS idx_customers_tax_number ON customers(tenant_id, company_id, tax_number);
```

- [ ] **Step 3: Implement repository**

Exports:

```ts
export async function upsertCustomer(db: Database, input: CustomerMirrorRow): Promise<void>;
export async function searchCustomers(db: Database, input: CustomerSearchInput): Promise<CustomerMirrorRow[]>;
export async function getCustomerById(db: Database, tenantId: string, companyId: string, id: string): Promise<CustomerMirrorRow | null>;
export function isBalanceStale(row: CustomerMirrorRow, now: Date, thresholdMinutes: number): boolean;
```

Reject missing `tenant_id`, `company_id`, or `id`. Reject local drift when the same `id` exists under another company.

- [ ] **Step 4: Run tests and commit**

```bash
pnpm test -- customerRepository.test.ts
git add apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/customer/customerTypes.ts apps/pos/src/lib/db/repositories/customerRepository.ts apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts
git commit -m "Phase 2.2.1: Add POS customer mirror repository"
```

## Task 3: Server Customer Pull Endpoint

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php`
- Create: `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php`
- Modify: `apps/api/app/Modules/POS/routes.php`
- Test: `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php`

- [ ] **Step 1: Write failing API sync tests**

```php
public function test_pos_customer_sync_returns_only_current_tenant_company_customers(): void;
public function test_pos_customer_sync_filters_by_updated_since_cursor(): void;
public function test_pos_customer_sync_includes_balance_snapshot_fields(): void;
```

- [ ] **Step 2: Implement API pull endpoint**

Route inside the existing POS auth/middleware group:

```php
Route::get('/pos/customers/sync', [PosCustomerSyncController::class, 'index'])
    ->name('pos.customers.sync');
```

Controller query:

```php
Partner::query()
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId)
    ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
    ->when($updatedSince, fn (Builder $q) => $q->where('updated_at', '>', $updatedSince))
    ->orderBy('updated_at')
    ->limit($limit);
```

- [ ] **Step 3: Run tests and commit**

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php
git add apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php apps/api/app/Modules/POS/routes.php apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php
git commit -m "Phase 2.3.1: Add POS customer pull endpoint"
```

## Task 4: POS Customer Pull Sync And Cursor

**Files:**
- Create: `apps/pos/src/lib/customer/customerSyncService.ts`
- Test: `apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts`

- [ ] **Step 1: Write failing sync service tests**

```ts
it('pulls customers, validates tenant/company, upserts rows, and stores the cursor', async () => {});
it('fails loudly when the server returns a row for another tenant or company', async () => {});
```

- [ ] **Step 2: Implement pull sync**

`customerSyncService.ts` fetches `/api/v1/pos/customers/sync?updated_since=<cursor>`, validates every row against the active `tenantId` and `companyId`, calls `upsertCustomer()`, and stores `sync_metadata` key `customers.updated_since`.

- [ ] **Step 3: Run tests and commit**

```bash
pnpm test -- customerSyncService.test.ts
git add apps/pos/src/lib/customer/customerSyncService.ts apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts
git commit -m "Phase 2.4.1: Sync POS customer mirror"
```

## Task 5: Pending Customer Create And Alias Reconciliation

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts`
- Create: `apps/pos/src/lib/db/repositories/pendingCustomerRepository.ts`
- Create: `apps/pos/src/lib/customer/pendingCustomerSyncService.ts`
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php`
- Create: `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerAliasResource.php`
- Create: `apps/api/app/Modules/POS/Domain/PosCustomerAlias.php`
- Create: `apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php`
- Modify: `apps/api/app/Modules/POS/routes.php`
- Test: `apps/pos/src/lib/db/repositories/__tests__/pendingCustomerRepository.test.ts`
- Test: `apps/pos/src/lib/customer/__tests__/pendingCustomerSyncService.test.ts`
- Test: `apps/api/tests/Feature/POS/PosPendingCustomerControllerTest.php`

- [ ] **Step 1: Write failing alias tests**

```ts
it('stores pending customer outbox rows and resolves client id aliases', async () => {});
it('blocks account payment authoring when a client id has a conflicting server alias', async () => {});
```

```php
public function test_pending_customer_create_returns_tenant_company_scoped_alias(): void;
public function test_pending_customer_create_is_idempotent_by_client_customer_uuid(): void;
public function test_pending_customer_create_rejects_cross_company_alias_conflict(): void;
public function test_treasury_alias_lookup_can_resolve_server_partner_id_after_replay(): void;
```

- [ ] **Step 2: Add local tables**

```sql
CREATE TABLE IF NOT EXISTS pending_customer_outbox (
  client_customer_uuid TEXT NOT NULL,
  tenant_id TEXT NOT NULL,
  company_id TEXT NOT NULL,
  name TEXT NOT NULL,
  phone TEXT,
  email TEXT,
  status TEXT NOT NULL DEFAULT 'pending',
  sync_error TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  PRIMARY KEY (tenant_id, company_id, client_customer_uuid)
);

CREATE TABLE IF NOT EXISTS customer_aliases (
  tenant_id TEXT NOT NULL,
  company_id TEXT NOT NULL,
  client_customer_uuid TEXT NOT NULL,
  server_partner_id TEXT NOT NULL,
  resolved_at TEXT NOT NULL,
  PRIMARY KEY (tenant_id, company_id, client_customer_uuid)
);
```

- [ ] **Step 3: Add server alias persistence**

Migration:

```php
Schema::create('pos_customer_aliases', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');
    $table->uuid('client_customer_uuid');
    $table->uuid('server_partner_id');
    $table->timestampsTz();

    $table->unique(['tenant_id', 'company_id', 'client_customer_uuid'], 'pos_customer_aliases_client_unique');
    $table->index(['tenant_id', 'company_id', 'server_partner_id'], 'pos_customer_aliases_partner_idx');
});
```

`PosCustomerAlias` is POS-owned alias metadata. It belongs to a server `Partner`, but the alias row is the durable replay target for fiscal events whose sealed customer block used a local client UUID. Never infer alias state from mutable Partner fields.

- [ ] **Step 4: Implement server create endpoint**

Route:

```php
Route::post('/pos/customers/pending', [PosPendingCustomerController::class, 'store'])
    ->name('pos.customers.pending.store');
```

The controller first looks up `PosCustomerAlias` by `(tenant_id, company_id, client_customer_uuid)`. If present, it returns the existing `server_partner_id` after verifying the Partner still exists in the same tenant/company. If absent, it creates a tenant/company-scoped `Partner`, creates the alias row in the same transaction, and returns:

```php
[
    'client_customer_uuid' => $clientCustomerUuid,
    'server_partner_id' => $partner->id,
    'tenant_id' => $tenantId,
    'company_id' => $companyId,
]
```

- [ ] **Step 5: Implement POS push and alias persistence**

`pendingCustomerSyncService.ts` pushes pending rows, validates response tenant/company, writes `customer_aliases`, and marks outbox rows `resolved`. A server response that maps one client UUID to a different server partner than the local alias is a stale-alias conflict and blocks future authoring.

- [ ] **Step 6: Run tests and commit**

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/POS/PosPendingCustomerControllerTest.php
pnpm test -- pendingCustomerRepository.test.ts pendingCustomerSyncService.test.ts
git add apps/pos/src/lib/db/migrations.ts \
  apps/pos/src/lib/db/repositories/pendingCustomerRepository.ts \
  apps/pos/src/lib/customer/pendingCustomerSyncService.ts \
  apps/api/app/Modules/POS/Domain/PosCustomerAlias.php \
  apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php \
  apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php \
  apps/api/app/Modules/POS/Presentation/Resources/PosCustomerAliasResource.php \
  apps/api/app/Modules/POS/routes.php \
  apps/pos/src/lib/db/repositories/__tests__/pendingCustomerRepository.test.ts \
  apps/pos/src/lib/customer/__tests__/pendingCustomerSyncService.test.ts \
  apps/api/tests/Feature/POS/PosPendingCustomerControllerTest.php
git commit -m "Phase 2.5.1: Reconcile pending POS customers"
```

## Task 6: Customer Search/Create/Attach UX

**Files:**
- Create: `apps/pos/src/components/customers/CustomerAttachPanel.tsx`
- Create: `apps/pos/src/components/customers/CustomerSearchInput.tsx`
- Create: `apps/pos/src/components/customers/CustomerBalanceBadge.tsx`
- Modify: `apps/pos/src/stores/paymentStore.ts`
- Modify: `apps/pos/src/pages/HomePage.tsx`
- Test: component tests beside new components.

- [ ] **Step 1: Write failing UI tests**

```ts
it('searches customers and attaches the selected row to checkout state', async () => {});
it('shows stale balance state when balance_updated_at exceeds threshold', async () => {});
it('creates a pending local customer with deterministic client id and outbox row', async () => {});
it('blocks attach when tenant/company are missing', async () => {});
it('detaches the selected customer before seal', async () => {});
```

- [ ] **Step 2: Implement UI components**

`CustomerBalanceBadge` receives `receivableBalance`, `creditBalance`, `balanceUpdatedAt`, and `stale`. `CustomerAttachPanel` writes selection through `paymentStore.ts`, not ad hoc global state.

- [ ] **Step 3: Run tests and commit**

```bash
pnpm test -- CustomerAttachPanel CustomerSearchInput CustomerBalanceBadge paymentStore
git add apps/pos/src/components/customers/CustomerAttachPanel.tsx \
  apps/pos/src/components/customers/CustomerSearchInput.tsx \
  apps/pos/src/components/customers/CustomerBalanceBadge.tsx \
  apps/pos/src/stores/paymentStore.ts \
  apps/pos/src/pages/HomePage.tsx \
  apps/pos/src/components/customers/CustomerAttachPanel.test.tsx \
  apps/pos/src/components/customers/CustomerSearchInput.test.tsx \
  apps/pos/src/components/customers/CustomerBalanceBadge.test.tsx
git commit -m "Phase 2.6.1: Add POS customer attach flow"
```

## Task 7: Device ACCOUNT_PAYMENT Authoring And Printable

**Files:**
- Create: `apps/pos/src/lib/offline/accountPaymentService.ts`
- Modify: `apps/pos/src/lib/buildReceiptData.ts`
- Modify: `apps/pos/src/lib/printing.ts`
- Modify: `apps/pos/src/components/pos/CheckoutSuccessModal.tsx`
- Modify: `apps/pos/src/stores/paymentStore.ts`
- Test: `apps/pos/src/lib/offline/__tests__/accountPaymentService.test.ts`

- [ ] **Step 1: Write failing authoring tests**

```ts
it('appends ACCOUNT_PAYMENT through FiscalEventEngine and returns printable data', async () => {});
it('rejects zero amount outside training mode', async () => {});
it('records stale balance metadata in canonical payload', async () => {});
it('rejects a customer row from another company', async () => {});
it('rejects stale alias conflicts before sealing', async () => {});
```

- [ ] **Step 2: Implement payload builder and service**

`buildAccountPaymentPayload(input)` must produce the exact top-level keys in the spec and format money with `bcformat`.

```ts
const engine = await getFiscalEventEngine(input.companyId);
const appendResult = await engine.append({
  event_type: 'ACCOUNT_PAYMENT',
  tenant_id: input.tenantId,
  company_id: input.companyId,
  terminal_id: input.terminalId,
  operator_id: input.operatorId,
  event_time_device: input.eventTimeDevice,
  business_date: input.businessDate,
  payload,
});
```

Persist printable data from the sealed payload and append result. Do not call Treasury APIs.

- [ ] **Step 3: Run tests and commit**

```bash
pnpm test -- accountPaymentService.test.ts buildReceiptData
git add apps/pos/src/lib/offline/accountPaymentService.ts \
  apps/pos/src/lib/buildReceiptData.ts \
  apps/pos/src/lib/printing.ts \
  apps/pos/src/components/pos/CheckoutSuccessModal.tsx \
  apps/pos/src/stores/paymentStore.ts \
  apps/pos/src/lib/offline/__tests__/accountPaymentService.test.ts
git commit -m "Phase 2.7.1: Author account payments on device"
```

## Task 8: Server Parser And POS-Core ACCOUNT_PAYMENT Receipt Projection

**Files:**
- Create: `apps/api/app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php`
- Create: `apps/api/app/Modules/POS/Domain/AccountPaymentReceipt.php`
- Create migration: `apps/api/database/migrations/2026_05_21_130000_create_pos_account_payment_receipts_table.php`
- Modify: `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- Test: `apps/api/tests/Feature/Fiscal/AccountPaymentProjectionTest.php`
- Test: `apps/api/tests/Feature/Fiscal/AccountPaymentD16Test.php`

- [ ] **Step 1: Write failing projection tests**

Tests:

```php
public function test_account_payment_projects_printable_receipt_from_payload(): void;
public function test_projection_is_idempotent_by_fiscal_event_id(): void;
public function test_projection_fails_loud_on_missing_customer_snapshot(): void;
public function test_pos_only_projection_does_not_require_treasury(): void;
```

- [ ] **Step 2: Add projection table**

Columns:

```php
$table->uuid('id')->primary();
$table->uuid('tenant_id');
$table->uuid('company_id');
$table->uuid('fiscal_event_id')->unique();
$table->string('account_payment_uuid');
$table->string('customer_id');
$table->string('customer_name');
$table->string('amount', 32);
$table->string('currency_code', 3);
$table->jsonb('payload_snapshot');
$table->timestampsTz();
```

- [ ] **Step 3: Implement projector**

Projector contract:

```php
public function name(): string { return 'pos_core_account_payment_receipt'; }
public function handlesEventType(FiscalEventType $type): bool { return $type === FiscalEventType::ACCOUNT_PAYMENT; }
public function requiresModule(): ?string { return null; }
public function priority(): int { return 50; }
```

Read only parsed canonical payload; do not query Partner/Treasury/Accounting/Contact/B2B.

- [ ] **Step 4: Add D16 static guard**

Guard forbidden imports and calls in `AccountPaymentReceiptProjection.php`:

```php
use App\Modules\Treasury\
use App\Modules\Accounting\
use App\Modules\Partner\
use App\Modules\Customer\
use App\Modules\Contact\
use App\Modules\B2B\
app(
App::make(
resolve(
```

- [ ] **Step 5: Run tests and commit**

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountPaymentProjectionTest.php tests/Feature/Fiscal/AccountPaymentD16Test.php
git add apps/api/app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php \
  apps/api/app/Modules/POS/Domain/AccountPaymentReceipt.php \
  apps/api/database/migrations/2026_05_21_130000_create_pos_account_payment_receipts_table.php \
  apps/api/app/Modules/POS/Providers/POSServiceProvider.php \
  apps/api/tests/Feature/Fiscal/AccountPaymentProjectionTest.php \
  apps/api/tests/Feature/Fiscal/AccountPaymentD16Test.php
git commit -m "Phase 2.8.1: Project account payment receipts"
```

## Task 9: PaymentAllocationService Command DTO Refactor

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/ApplyPaymentAllocationCommand.php`
- Modify: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`
- Modify existing callers to use the command wrapper or keep backward-compatible method delegating into command method.
- Test: existing Treasury allocation tests plus new replay-context tests.

- [ ] **Step 1: Write failing replay-context test**

```php
public function test_apply_allocation_from_command_uses_explicit_tenant_company_actor(): void;
public function test_apply_allocation_from_command_rejects_cross_company_payment(): void;
```

- [ ] **Step 2: Add command DTO**

```php
final readonly class ApplyPaymentAllocationCommand
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $paymentId,
        public AllocationMethod $allocationMethod,
        public ?string $actorUserId,
        public string $source,
        public ?array $manualAllocations = null,
    ) {}
}
```

- [ ] **Step 3: Implement command method**

Add:

```php
public function applyAllocationFromCommand(ApplyPaymentAllocationCommand $command): array
```

It must not call `Auth::user()`. It scopes `Payment`, `Document`, and allocation writes by `$command->tenantId` and `$command->companyId`.

- [ ] **Step 4: Keep controller compatibility**

Existing `applyAllocation(string $paymentId, ...)` can construct the command from `CompanyContext` and current user, then delegate to `applyAllocationFromCommand()`. This preserves existing API behavior while giving fiscal replay an explicit context API.

- [ ] **Step 5: Run tests and commit**

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Treasury/PaymentAllocationServiceTest.php
git add apps/api/app/Modules/Treasury/Application/DTOs/ApplyPaymentAllocationCommand.php \
  apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php \
  apps/api/tests/Feature/Treasury/PaymentAllocationServiceTest.php
git commit -m "Phase 2.9.1: Add explicit allocation command"
```

## Task 10: Treasury ACCOUNT_PAYMENT Bridge

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php`
- Modify: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- Test: `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php`

- [ ] **Step 1: Write failing bridge tests**

Tests:

```php
public function test_treasury_bridge_creates_pos_payment_with_fiscal_event_id(): void;
public function test_bridge_is_idempotent_on_retry(): void;
public function test_bridge_fails_loud_on_conflicting_fiscal_event_id_payment(): void;
public function test_bridge_fails_loud_when_partner_is_missing(): void;
public function test_bridge_rejects_cross_company_partner(): void;
public function test_bridge_dead_letters_when_pending_customer_has_no_alias(): void;
public function test_bridge_resolves_pending_customer_alias_before_payment_creation(): void;
public function test_bridge_fails_loud_when_payment_method_missing(): void;
public function test_bridge_fails_loud_when_repository_missing(): void;
public function test_bridge_fails_loud_when_allocation_throws(): void;
public function test_bridge_invokes_fifo_allocation_command(): void;
public function test_bridge_sets_created_by_from_resolved_operator(): void;
```

- [ ] **Step 2: Implement bridge contract**

```php
public function name(): string { return 'treasury_account_payment_bridge'; }
public function handlesEventType(FiscalEventType $type): bool { return $type === FiscalEventType::ACCOUNT_PAYMENT; }
public function requiresModule(): ?string { return 'Treasury'; }
public function priority(): int { return 150; }
```

- [ ] **Step 3: Implement scoped Payment creation**

Every lookup includes tenant/company scope. Payment fields:

```php
[
    'tenant_id' => $event->tenant_id,
    'company_id' => $event->company_id,
    'partner_id' => $customerId,
    'payment_method_id' => $resolvedPaymentMethodId,
    'repository_id' => $resolvedRepositoryId,
    'amount' => $payload->payment->amount,
    'currency' => $payload->currencyCode,
    'payment_date' => $payload->businessDate,
    'payment_type' => PaymentType::Receipt,
    'origin' => PaymentOrigin::Pos,
    'fiscal_event_id' => $event->id,
    'created_by' => $resolvedActorUserId,
]
```

Resolve the payment customer before Payment creation. If `payload.customer.customer_id` is a pending client UUID, resolve it through the server `PosCustomerAlias` table by `(tenant_id, company_id, client_customer_uuid)`, then verify the resulting Partner exists in the same tenant/company. Missing alias or cross-company alias target throws a typed projection exception.

Resolve `payment_method_id` from `payload.payment.method_code` and `repository_id` from `payload.payment.repository_id`, both scoped by tenant/company where the table carries company scope. Resolve `$resolvedActorUserId` from the sealed cashier/operator identity by tenant/company-scoped user lookup. If no matching user exists, keep `created_by` null and include the unresolved sealed operator identifier in projection metadata; never call `Auth::user()` from the bridge. Missing method/repository throws a typed projection exception. A pre-existing `Payment` for the same `fiscal_event_id` with different tenant, company, amount, customer, method, repository, or created-by actor throws a typed idempotency-conflict exception.

- [ ] **Step 4: Invoke FIFO allocation**

Use `ApplyPaymentAllocationCommand` with `AllocationMethod::FIFO`, explicit tenant/company, actor from payload cashier/operator if resolvable, and `source='fiscal_event:ACCOUNT_PAYMENT'`.

- [ ] **Step 5: Register projector and run tests**

Tag it in Treasury service provider with `FiscalEventProjector::class`.

Commit:

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php
git add apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php \
  apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php \
  apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php
git commit -m "Phase 2.10.1: Bridge account payments to Treasury"
```

## Task 11: End-To-End Closure Tests And Roadmap Update

**Files:**
- Create: `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php`
- Modify: `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`
- Modify handoff/memory only after implementation and reviews approve.

- [ ] **Step 1: Write full-flow test**

Test verifies:

1. Device-style ACCOUNT_PAYMENT canonical bytes are produced.
2. Event is synced through `/api/v1/pos/sync/fiscal-events`.
3. Server stores exact canonical bytes.
4. Strict parser marks parsed.
5. POS-core receipt projection exists.
6. Treasury-active bridge creates Payment and FIFO allocation.
7. POS-only resolver skips Treasury bridge while keeping printable projection.
8. Canonical bytes are byte-equivalent at every read point.

- [ ] **Step 2: Add roadmap status**

Update Phase 2 section to show shipped task list. If Phase 1.5 tax-number validation is still unresolved, the roadmap must explicitly state: `Phase 2 implementation complete, but NOT customer-facing deployment-ready until Phase 1.5 per-country tax-number validation is implemented, reviewed, and pushed.`

- [ ] **Step 3: Run full verification**

Run every gate listed at the top. Include any new ACCOUNT_PAYMENT drift gate introduced in Task 1.

- [ ] **Step 4: Commit**

```bash
git add apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php \
  docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md
git commit -m "Phase 2.11.1: Verify account payment full flow"
```

## Review Workflow Per Task

After each task commit:

1. Write Codex self-review to `docs/superpowers/reviews/2026-05-21-task-01-codex-review.md`, incrementing the two-digit task number for later tasks.
2. Dispatch Opus-equivalent second-pass review to `docs/superpowers/reviews/2026-05-21-task-01-opus-review.md`, incrementing the two-digit task number for later tasks.
3. If either review finds BLOCKER/REQUEST-CHANGES, fix in R2 commit, then write R2 Codex review and get R2 Opus-equivalent review.
4. Commit review files after both approve.
5. Push before moving to the next task.

Review axes:

- Cross-tenant FK safety.
- Fail-loud vs silent downgrade.
- Dead-path rebuild.
- Discriminated-union matrix completeness.
- Contract drift between TS/PHP/docs.
- Per-method skip only.
- Skip-citation accuracy.
- No `app()`, `App::make()`, or `resolve()`.
- Constructor injection only.
- D16 bounded-module guard.
- Phase 1.5 tax-number launch gate remains visible.
