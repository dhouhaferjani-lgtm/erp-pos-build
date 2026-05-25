# POS Charge-To-Account Phase 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship offline-first `ACCOUNT_CHARGE` so a B2C POS customer can buy now and pay later, with POS-core printable projection, optional Treasury AR posting, and optional web-B2B Facture draft routing.

**Architecture:** The Tauri POS authors and seals `ACCOUNT_CHARGE` through `FiscalEventEngine.append()` and syncs the sealed envelope through `/api/v1/pos/sync/fiscal-events`. Server ingest verifies canonical bytes, then POS-core projects an `ACCOUNT_CHARGE_RECEIPT` in every deployment while Treasury and Document/B2B bridges run only through `FiscalEventProjector` module activation. AR posting uses a new charge command path, not `ReceiptPaymentService` and not `createPOSPaymentEntry()`.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, PHPUnit, PHPStan L8, Pint, Tauri POS, TypeScript, SQLite, Vitest, ESLint.

---

## Spec And Review Gate

Spec gate is complete at commit `4510979d2`:

- Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- Codex reviews: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-codex-review.md`, `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-codex-review.md`, `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r3-codex-review.md`
- Opus reviews: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-opus-review.md`, `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-opus-review.md`, `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r3-opus-review.md`

Owner D8 question is surfaced in the spec. Proceed on the recommended lock unless the owner overrides: POS authors `ACCOUNT_CHARGE`; web Document/B2B consumes it and creates a Facture draft; POS does not author a Tax Invoice.

## Non-Negotiable Gates

For every implementation task:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3/apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Accounting app/Modules/Document app/Shared/Contracts
./vendor/bin/pint --test app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Accounting app/Modules/Document app/Shared/Contracts tests/Feature/Fiscal tests/Feature/POS tests/Unit/Fiscal tests/Unit/Treasury tests/Feature/Accounting

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3/apps/pos
pnpm test
pnpm typecheck
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

For docs-only tasks, run the relevant grep checks named in the task plus `git status --short`. Stage explicit files only. Never use `git add -A`.

Every implementation task must be followed by:

1. Codex self-adversarial review file.
2. Opus second-pass adversarial review file.
3. R2/R3 fixes and re-review if any finding is BLOCKER, REQUEST-CHANGES, or material minor.
4. Atomic commit of implementation plus approved review artifacts.

## File Map

Fiscal payload contract:

- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php`.
- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeView.php`.
- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCustomerDTO.php`.
- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeBalanceSnapshotDTO.php`.
- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCreditDecisionDTO.php`.
- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php`.
- Create `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTotalsDTO.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`.
- Modify `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php`.
- Create `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts`.
- Modify `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`.
- Modify `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`.

POS mirror, rules, and authoring:

- Modify `apps/pos/src/lib/db/migrations.ts`.
- Modify `apps/pos/src/lib/customer/customerTypes.ts`.
- Modify `apps/pos/src/lib/customer/customerSyncService.ts`.
- Modify `apps/pos/src/lib/db/repositories/customerRepository.ts`.
- Create `apps/pos/src/lib/accountCharge/creditRulesEngine.ts`.
- Create `apps/pos/src/lib/accountCharge/accountChargeService.ts`.
- Create `apps/pos/src/lib/accountCharge/accountChargePrintable.ts`.
- Create tests under `apps/pos/src/lib/accountCharge/__tests__/`.
- Modify checkout state and charge-to-account entry points: `apps/pos/src/stores/paymentStore.ts`, `apps/pos/src/components/customers/CustomerAttachPanel.tsx`, `apps/pos/src/components/customers/CustomerAttachPanel.test.tsx`, and `apps/pos/src/lib/offline/accountPaymentService.ts` only if Task 5 extracts shared customer snapshot helpers from the existing account-payment authoring path.

POS-core server projection:

- Create `apps/api/app/Modules/POS/Domain/AccountChargeReceipt.php`.
- Create migration `apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php`.
- Create `apps/api/app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php`.
- Modify `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`.

Treasury / Accounting bridge:

- Create `apps/api/app/Modules/Treasury/Application/DTOs/CreatePOSChargeJournalEntryCommand.php`.
- Modify `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`.
- Create `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php`.
- Modify `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`.

Document/B2B bridge:

- Create `apps/api/app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php`.
- Create `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php`.
- Create `apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php`.
- Modify `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`.

Tests:

- Add/update fiscal tests under `apps/api/tests/Unit/Fiscal/` and `apps/api/tests/Feature/Fiscal/`.
- Add/update POS feature tests under `apps/api/tests/Feature/POS/`.
- Add accounting tests under `apps/api/tests/Feature/Accounting/`.
- Add document bridge tests under `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`.
- Add POS Vitest tests under `apps/pos/src/lib/fiscal/__tests__/` and `apps/pos/src/lib/accountCharge/__tests__/`.

## Task 1: ACCOUNT_CHARGE Payload Registry And Canonical Drift Gate

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php`
- Create: `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`
- Modify: `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`
- Test: `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php`
- Test: `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts`

- [ ] **Step 1: Write failing registry tests**

PHP:

```php
public function test_account_charge_is_implemented_at_version_one(): void
{
    $registry = new FiscalEventPayloadRegistry;

    $this->assertTrue($registry->isImplemented(FiscalEventType::ACCOUNT_CHARGE));
    $this->assertSame(1, $registry->eventVersionFor(FiscalEventType::ACCOUNT_CHARGE));
    $this->assertSame(AccountChargePayload::class, $registry->dtoClassFor(FiscalEventType::ACCOUNT_CHARGE));
}
```

TS:

```ts
it('implements ACCOUNT_CHARGE at version 1 and keeps server-only types unchanged', () => {
  const registry = new FiscalEventPayloadRegistry();

  expect(registry.isImplemented('ACCOUNT_CHARGE')).toBe(true);
  expect(registry.eventVersionFor('ACCOUNT_CHARGE')).toBe(1);
  expect(registry.serverOnlyTypes()).toEqual([
    'TERMINAL_REGISTRY_SNAPSHOT',
    'COMPANY_DAY_CLOSURE_MANIFEST',
  ]);
});
```

- [ ] **Step 2: Run failing registry tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge

cd ../pos
pnpm test -- FiscalEventPayloadRegistry.test.ts
```

Expected: PHP and TS fail because `ACCOUNT_CHARGE` is reserved but not implemented.

- [ ] **Step 3: Add registry entries and payload skeletons**

PHP registry entry:

```php
FiscalEventType::ACCOUNT_CHARGE->value => [AccountChargePayload::class, 1],
```

TS implemented set includes `ACCOUNT_CHARGE`:

```ts
const PHASE_1_IMPLEMENTED = [
  'SALE_RECEIPT',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'TERMINAL_REGISTRY_SNAPSHOT',
  'ACCOUNT_PAYMENT',
  'ACCOUNT_CHARGE',
] as const satisfies readonly FiscalEventTypeValue[];
```

`AccountChargePayload` top-level keys must be exactly:

```php
[
    'account_charge_uuid',
    'business_date',
    'buyer',
    'cashier_id',
    'cashier_name',
    'charge_terms',
    'credit_decision',
    'currency_code',
    'currency_scale',
    'customer',
    'event_time_device',
    'invoice_classification',
    'line_items',
    'local_balance_snapshot',
    'notes',
    'print_profile',
    'receipt_type_code',
    'references',
    'regime_extensions',
    'seller',
    'shift_id',
    'staleness',
    'terminal_id',
    'totals',
    'training_flag',
    'transaction_discount_amount',
    'transaction_discount_reason',
    'vat_breakdown',
]
```

- [ ] **Step 4: Add golden TS payload and parity test**

Create `goldenAccountChargePayload()` with:

- `customer.customer_category: 'individual'`
- `buyer: null`
- one line with `product_id`, `non_collected_subtype: null`
- `transaction_discount_amount: '0.000'`
- `totals.subtotal: '100.000'`, `totals.vat_total: '19.000'`, `totals.total: '119.000'`, `totals.amount_charged_to_account: '119.000'`
- `local_balance_snapshot.receivable_balance_before: '300.000'`, `charge_amount: '119.000'`, `projected_receivable_balance_after: '419.000'`

Parity assertion:

```ts
it('encodes ACCOUNT_CHARGE golden payload to locked canonical bytes', () => {
  expect(encodeFiscalCanonicalPayload(goldenAccountChargePayload())).toBe(goldenAccountChargeCanonicalBytes);
});
```

- [ ] **Step 5: Run tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge

cd ../pos
pnpm test -- FiscalEventPayloadRegistry.test.ts AccountChargePayload.test.ts accountChargeCanonicalParity.test.ts
```

- [ ] **Step 6: Commit and review**

```bash
git add apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php \
  apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php \
  apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts \
  apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts \
  apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts \
  apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts \
  apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts
git commit -m "Phase 3.1.1: Add account charge payload registry"
```

Then write Codex and Opus review files for Task 1.

## Task 2: PHP Parser, Validator, And Canonical Reader

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeView.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCustomerDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeBalanceSnapshotDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCreditDecisionDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTotalsDTO.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`
- Test: `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- Test: `apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`

- [ ] **Step 1: Write failing parser/validator tests**

Add positive tests:

```php
public function test_strict_parser_accepts_account_charge_canonical_envelope(): void;
public function test_for_account_charge_returns_typed_view(): void;
public function test_account_charge_validator_accepts_discounted_b2b_buyer_payload(): void;
```

Add negative tests:

```php
public function test_account_charge_rejects_extra_top_level_key(): void;
public function test_account_charge_rejects_missing_required_top_level_key(): void;
public function test_account_charge_rejects_missing_nested_customer_key(): void;
public function test_account_charge_rejects_malformed_money_field(): void;
public function test_account_charge_rejects_malformed_vat_partition(): void;
public function test_account_charge_rejects_payments_key(): void;
public function test_account_charge_rejects_missing_product_id(): void;
public function test_account_charge_rejects_invalid_buyer_codice_fiscale(): void;
public function test_account_charge_rejects_limit_exceeded_production_event(): void;
public function test_account_charge_rejects_amount_balance_mismatch(): void;
public function test_account_charge_rejects_invalid_invoice_classification_for_non_business_customer(): void;
public function test_account_charge_accepts_synced_and_pending_customer_variants(): void;
public function test_account_charge_accepts_stale_and_fresh_mirror_variants(): void;
public function test_account_charge_accepts_credit_limit_present_and_absent_variants(): void;
public function test_account_charge_accepts_discount_present_and_absent_variants(): void;
public function test_account_charge_accepts_nullable_and_populated_buyer_and_references(): void;
public function test_account_charge_accepts_nullable_non_collected_subtype(): void;
public function test_account_charge_accepts_training_limit_exceeded_but_rejects_production_limit_exceeded(): void;
```

- [ ] **Step 2: Run failing PHP tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge
```

Expected: parser rejects unimplemented payload or reader has no `forAccountCharge()`.

- [ ] **Step 3: Implement exact key-set and invariants**

Validator must assert:

- no `payments` key;
- `buyer` null or exact SALE_RECEIPT buyer shape;
- `line_items[].product_id` non-empty string;
- `line_items[].non_collected_subtype` null or existing allowed enum;
- `totals.amount_charged_to_account === totals.total`;
- `local_balance_snapshot.charge_amount === totals.amount_charged_to_account`;
- `projected_receivable_balance_after = receivable_balance_before + charge_amount`;
- `projected_net_balance_after = projected_receivable_balance_after - projected_credit_balance_after`;
- `credit_decision.decision === 'approved'`;
- `credit_decision.limit_exceeded === false` when `training_flag=false`;
- VAT partition and discount-reason invariants mirror SALE_RECEIPT.

Keep every named test above explicit. A single positive fixture plus prose invariants is not enough for this event type; exact-key drift and discriminated variants must fail before implementation.

Failure prefixes must match the spec:

```php
'payload_account_charge_payments_forbidden'
'payload_account_charge_amount_mismatch'
'payload_account_charge_balance_mismatch'
'payload_account_charge_credit_decision_invalid'
'payload_account_charge_invoice_classification_mismatch'
```

- [ ] **Step 4: Implement canonical reader**

Add:

```php
public function forAccountCharge(FiscalEvent $event): AccountChargeView
{
    if ($event->event_type !== FiscalEventType::ACCOUNT_CHARGE) {
        throw new InvalidArgumentException('forAccountCharge called with event_type='.$event->event_type->value);
    }

    if ($event->payload === null) {
        throw new InvalidArgumentException('forAccountCharge called on fiscal_event_id='.$event->id.' with null payload');
    }

    $payload = AccountChargePayload::fromArray($event->payload);

    return AccountChargeView::fromPayload($payload);
}
```

Use existing DTO guard patterns; do not cast unknown values with `(string)`.

- [ ] **Step 5: Run focused and static gates**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalPayloadReaderTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal
./vendor/bin/pint --test app/Modules/Fiscal tests/Feature/Fiscal tests/Unit/Fiscal
```

- [ ] **Step 6: Commit and review**

```bash
git add apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeView.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCustomerDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeBalanceSnapshotDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeCreditDecisionDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTermsDTO.php \
  apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/AccountChargeTotalsDTO.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php \
  apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php \
  apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php \
  apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php \
  apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php \
  apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php
git commit -m "Phase 3.2.1: Validate account charge canonical payloads"
```

Then write Codex and Opus review files for Task 2.

## Task 3: POS Customer Mirror Credit Fields

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts`
- Modify: `apps/pos/src/lib/customer/customerTypes.ts`
- Modify: `apps/pos/src/lib/customer/customerSyncService.ts`
- Modify: `apps/pos/src/lib/db/repositories/customerRepository.ts`
- Test: `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts`
- Test: `apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php`
- Test: `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php`

- [ ] **Step 1: Write failing sync/mirror tests**

POS repository test:

```ts
it('upserts credit fields without overwriting tenant or company', async () => {
  await upsertCustomer(db, {
    id: '55555555-5555-4555-8555-555555555555',
    tenant_id: '11111111-1111-4111-8111-111111111111',
    company_id: '22222222-2222-4222-8222-222222222222',
    name: 'Mariam Ben Ali',
    customer_category: 'para-pharmacy',
    receivable_balance: '300.000',
    credit_balance: '0.000',
    credit_limit: '500.000',
    payment_terms_days: 15,
    is_active: true,
    charge_account_enabled: true,
    charge_policy_version: 'phase3-v1',
    balance_updated_at: '2026-05-21T10:10:00.000Z',
    updated_at: '2026-05-21T10:10:00.000Z',
  });

  const row = await findCustomerById(db, '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '55555555-5555-4555-8555-555555555555');
  expect(row?.credit_limit).toBe('500.000');
  expect(row?.charge_account_enabled).toBe(1);
});
```

Backend sync test:

```php
public function test_pos_customer_sync_includes_phase_three_credit_fields(): void
{
    $response = $this->getJson('/api/v1/pos/customers/sync');

    $response->assertOk()
        ->assertJsonPath('data.customers.0.credit_limit', '500.0000')
        ->assertJsonPath('data.customers.0.payment_terms_days', 15)
        ->assertJsonPath('data.customers.0.charge_account_enabled', true)
        ->assertJsonPath('data.customers.0.charge_policy_version', 'phase3-v1');
}
```

- [ ] **Step 2: Run failing tests**

```bash
cd apps/pos
pnpm test -- customerRepository.test.ts customerSyncService.test.ts

cd ../api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php --filter phase_three
```

- [ ] **Step 3: Add migration and resource fields**

SQLite migration adds nullable columns:

```sql
ALTER TABLE customers ADD COLUMN credit_limit TEXT;
ALTER TABLE customers ADD COLUMN payment_terms_days INTEGER;
ALTER TABLE customers ADD COLUMN charge_account_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE customers ADD COLUMN charge_policy_version TEXT;
```

Server resource maps:

```php
'credit_limit' => $this->formatMoney($partner->credit_limit),
'payment_terms_days' => $partner->payment_terms_days,
'charge_account_enabled' => $partner->is_active,
'charge_policy_version' => 'phase3-v1',
```

The server row remains tenant/company scoped in the existing query.

- [ ] **Step 4: Run focused tests and commit**

```bash
cd apps/pos
pnpm test -- customerRepository.test.ts customerSyncService.test.ts

cd ../api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/POS
./vendor/bin/pint --test app/Modules/POS tests/Feature/POS
```

```bash
git add apps/pos/src/lib/db/migrations.ts \
  apps/pos/src/lib/customer/customerTypes.ts \
  apps/pos/src/lib/customer/customerSyncService.ts \
  apps/pos/src/lib/db/repositories/customerRepository.ts \
  apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts \
  apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts \
  apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php \
  apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php \
  apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php
git commit -m "Phase 3.3.1: Mirror customer credit controls"
```

Then write Codex and Opus review files for Task 3.

## Task 4: Device Credit Rules Engine

**Files:**
- Create: `apps/pos/src/lib/accountCharge/creditRulesEngine.ts`
- Test: `apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts`

- [ ] **Step 1: Write failing rule matrix tests**

Cases:

```ts
it.each([
  ['inactive_customer', { is_active: false }, 'customer_inactive'],
  ['charge_disabled', { charge_account_enabled: false }, 'charge_account_disabled'],
  ['limit_exceeded', { credit_limit: '500.000', receivable_balance: '450.000', credit_balance: '0.000' }, 'credit_limit_exceeded'],
  ['hard_stale', { balance_updated_at: '2026-05-01T00:00:00.000Z' }, 'balance_snapshot_hard_stale'],
  ['wrong_company', { company_id: 'other-company' }, 'customer_company_mismatch'],
  ['ambiguous_alias', { customer_sync_status: 'pending_create', alias_candidates: ['alias-a', 'alias-b'] }, 'customer_alias_ambiguous'],
])('rejects %s', (_, overrides, expectedCode) => {
  const result = evaluateAccountChargeCreditDecision(makeInput(overrides));
  expect(result.ok).toBe(false);
  expect(result.error.code).toBe(expectedCode);
});
```

Positive case:

```ts
it('approves and records balance math inputs', () => {
  const result = evaluateAccountChargeCreditDecision(makeInput({
    receivable_balance: '300.000',
    credit_balance: '0.000',
    credit_limit: '500.000',
    chargeAmount: '119.000',
  }));

  expect(result).toMatchObject({
    ok: true,
    decision: {
      decision: 'approved',
      credit_available_before: '200.000',
      credit_available_after: '81.000',
      limit_exceeded: false,
    },
  });
});
```

- [ ] **Step 2: Run failing tests**

```bash
cd apps/pos
pnpm test -- creditRulesEngine.test.ts
```

- [ ] **Step 3: Implement deterministic rules**

Implement a discriminated union:

```ts
export type AccountChargeCreditDecisionResult =
  | { ok: true; decision: AccountChargeCreditDecision }
  | { ok: false; error: { code: AccountChargeRejectionCode; field?: string } };
```

Use string decimal helpers. Do not use JS floating point for money. The function accepts already-bcformatted strings and company `currency_scale`; use integer minor-unit conversion inside the rules engine.

- [ ] **Step 4: Run tests and commit**

```bash
cd apps/pos
pnpm test -- creditRulesEngine.test.ts
pnpm typecheck
pnpm lint
```

```bash
git add apps/pos/src/lib/accountCharge/creditRulesEngine.ts \
  apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts
git commit -m "Phase 3.4.1: Add account charge credit rules"
```

Then write Codex and Opus review files for Task 4.

## Task 5: Device ACCOUNT_CHARGE Authoring And Printable

**Files:**
- Create: `apps/pos/src/lib/accountCharge/accountChargeService.ts`
- Create: `apps/pos/src/lib/accountCharge/accountChargePrintable.ts`
- Test: `apps/pos/src/lib/accountCharge/__tests__/accountChargeService.test.ts`
- Test: `apps/pos/src/lib/accountCharge/__tests__/accountChargePrintable.test.ts`
- Modify: `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`

- [ ] **Step 1: Write failing authoring tests**

```ts
it('appends ACCOUNT_CHARGE through FiscalEventEngine and returns printable data', async () => {
  const engine = makeFiscalEventEngineWithGenesis();
  const result = await authorAccountCharge(makeAccountChargeInput({ engine }));

  expect(result.fiscalEvent.event_type).toBe('ACCOUNT_CHARGE');
  expect(result.printable.title).toBe('ACCOUNT CHARGE RECEIPT');
  expect(result.printable.amountChargedToAccount).toBe('119.000');
});

it('rejects any payment line before append', async () => {
  await expect(authorAccountCharge(makeAccountChargeInput({ payments: [{ amount: '119.000' }] })))
    .rejects
    .toThrow(/account_charge_payments_forbidden/);
});
```

- [ ] **Step 2: Run failing tests**

```bash
cd apps/pos
pnpm test -- accountChargeService.test.ts accountChargePrintable.test.ts FiscalEventEngine.test.ts
```

- [ ] **Step 3: Implement authoring**

`authorAccountCharge(input)` must:

- require selected customer;
- evaluate credit rules;
- build payload with exact `ACCOUNT_CHARGE` keys;
- call `FiscalEventEngine.append({ event_type: 'ACCOUNT_CHARGE', source_event_class: 'account_charge', source_event_id: input.accountChargeUuid, payload })`;
- write local printable state in the same SQLite transaction as append when repository support exists;
- never call `receiptApi.createReceipt()` or payment APIs.

`FiscalEventEngine.validateRequestPayload()` adds an `ACCOUNT_CHARGE` branch that rejects:

- non-object payload;
- missing required keys;
- `payments` key;
- non-string money fields;
- invalid `event_time_device` / `business_date`.

- [ ] **Step 4: Run tests and commit**

```bash
cd apps/pos
pnpm test -- accountChargeService.test.ts accountChargePrintable.test.ts FiscalEventEngine.test.ts
pnpm test -- accountChargeCanonicalParity.test.ts
pnpm typecheck
pnpm lint
```

```bash
git add apps/pos/src/lib/accountCharge/accountChargeService.ts \
  apps/pos/src/lib/accountCharge/accountChargePrintable.ts \
  apps/pos/src/lib/accountCharge/__tests__/accountChargeService.test.ts \
  apps/pos/src/lib/accountCharge/__tests__/accountChargePrintable.test.ts \
  apps/pos/src/lib/fiscal/FiscalEventEngine.ts \
  apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
git commit -m "Phase 3.5.1: Author account charges on device"
```

Then write Codex and Opus review files for Task 5.

## Task 6: POS-Core Account Charge Receipt Projection

**Files:**
- Create: `apps/api/app/Modules/POS/Domain/AccountChargeReceipt.php`
- Create: `apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php`
- Create: `apps/api/app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php`
- Modify: `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- Test: `apps/api/tests/Feature/Fiscal/AccountChargeProjectionTest.php`
- Test: `apps/api/tests/Feature/Fiscal/AccountChargeD16Test.php`

- [ ] **Step 1: Write failing projection tests**

```php
public function test_account_charge_projects_printable_receipt_from_canonical_payload(): void;
public function test_account_charge_projection_is_idempotent_by_fiscal_event_id(): void;
public function test_account_charge_pos_core_projection_has_no_forbidden_module_imports(): void;
```

D16 grep test:

```php
$source = file_get_contents(app_path('Modules/POS/Application/Projections/AccountChargeReceiptProjection.php'));
$this->assertStringNotContainsString('Modules\\Treasury', $source);
$this->assertStringNotContainsString('Modules\\Accounting', $source);
$this->assertStringNotContainsString('Modules\\Document', $source);
$this->assertStringNotContainsString('Modules\\Partner', $source);
$this->assertStringNotContainsString('Modules\\Customer', $source);
$this->assertStringNotContainsString('Modules\\Contact', $source);
$this->assertStringNotContainsString('Modules\\B2B', $source);
$this->assertStringNotContainsString('Modules\\Sales', $source);
```

- [ ] **Step 2: Run failing tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php
```

- [ ] **Step 3: Implement table, model, and projector**

Migration columns:

```php
$table->uuid('id')->primary();
$table->uuid('tenant_id');
$table->uuid('company_id');
$table->uuid('fiscal_event_id')->unique();
$table->string('account_charge_uuid');
$table->string('customer_id');
$table->string('customer_name');
$table->decimal('amount_charged', 15, 4);
$table->string('currency_code', 3);
$table->jsonb('payload_snapshot');
$table->timestamps();
```

Projector contract:

```php
public function name(): string { return 'pos_core_account_charge_receipt'; }
public function handlesEventType(FiscalEventType $type): bool { return $type === FiscalEventType::ACCOUNT_CHARGE; }
public function requiresModule(): ?string { return null; }
public function priority(): int { return 50; }
```

- [ ] **Step 4: Register provider tag and extend CI PG gate**

Add `AccountChargeReceiptProjection::class` to `POSServiceProvider` tagged `FiscalEventProjector::class`.

Extend `.github/workflows/ci.yml` PG merge-gate filter with `AccountChargeProjectionTest` in the same commit. This is mandatory because the task adds a `jsonb` projection table and a Fiscal feature projection test.

- [ ] **Step 5: Run tests and commit**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/POS app/Modules/Fiscal
./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/Fiscal
```

```bash
git add apps/api/app/Modules/POS/Domain/AccountChargeReceipt.php \
  apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php \
  apps/api/app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php \
  apps/api/app/Modules/POS/Providers/POSServiceProvider.php \
  apps/api/tests/Feature/Fiscal/AccountChargeProjectionTest.php \
  apps/api/tests/Feature/Fiscal/AccountChargeD16Test.php \
  .github/workflows/ci.yml
git commit -m "Phase 3.6.1: Project account charge receipts"
```

Then write Codex and Opus review files for Task 6.

## Task 7: AR GL Command And GeneralLedgerService Entry

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/CreatePOSChargeJournalEntryCommand.php`
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- Test: `apps/api/tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php`

- [ ] **Step 1: Write failing accounting tests**

Required tests:

```php
public function test_pos_charge_posts_ar_revenue_and_vat_with_partner(): void;
public function test_discounted_pos_charge_posts_sales_discount_and_balances(): void;
public function test_pos_charge_never_calls_create_pos_payment_entry(): void;
public function test_pos_charge_fails_loud_when_sales_discount_account_missing_for_discount(): void;
public function test_pos_charge_command_receives_canonical_vat_breakdown_and_line_summary(): void;
public function test_pos_charge_refreshes_partner_receivable_balance(): void;
```

Discounted expected lines:

- Debit CustomerReceivable: `119.000`, `partner_id = customer id`.
- Debit SalesDiscount: `5.000`.
- Credit ProductRevenue: `105.000`.
- Credit VatCollected: `19.000`.
- Debits `124.000`; credits `124.000`.

- [ ] **Step 2: Run failing accounting tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php
```

Expected: `createPOSChargeEntry()` does not exist.

- [ ] **Step 3: Implement command DTO**

DTO properties:

```php
public function __construct(
    public readonly string $tenantId,
    public readonly string $companyId,
    public readonly string $partnerId,
    public readonly string $fiscalEventId,
    public readonly string $accountChargeUuid,
    public readonly string $businessDate,
    public readonly string $currencyCode,
    public readonly int $currencyScale,
    public readonly string $subtotal,
    public readonly string $vatTotal,
    public readonly string $total,
    public readonly string $transactionDiscountAmount,
    public readonly array $vatBreakdown,
    public readonly array $lineVatSummary,
    public readonly ?string $actorUserId,
) {}
```

`vatBreakdown` is copied from the sealed canonical payload. `lineVatSummary` is a compact immutable summary with `product_id`, `vat_rate`, `tax_category_code`, `line_subtotal`, and `line_vat` for each payload line. These arrays are not recalculated from live product or tax tables.

- [ ] **Step 4: Implement `createPOSChargeEntry()`**

Use existing account lookup helpers:

- `CustomerReceivable`
- `ProductRevenue`
- `VatCollected` when VAT > 0
- `SalesDiscount` when discount > 0

Set:

- `source_type = 'pos_account_charge'`
- `source_id = fiscalEventId`
- `status = Draft`
- `description = 'POS Account Charge '.$accountChargeUuid`

Do not create `Payment`, `ReceiptPayment`, or call `createPOSPaymentEntry()`.

After the journal entry is persisted, call `PartnerBalanceService::refreshPartnerBalance($companyId, $partnerId)` through the existing `GeneralLedgerService` constructor dependency. This is mandatory, not best-effort: a refresh failure must fail the `createPOSChargeEntry()` call and bubble through the Treasury bridge projection. The test must assert either the cached `Partner::receivable_balance` / `balance_updated_at` change or the existing `PartnerBalanceUpdated` event is emitted for the charged partner.

- [ ] **Step 5: Run tests and commit**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting app/Modules/Treasury
./vendor/bin/pint --test app/Modules/Accounting app/Modules/Treasury tests/Feature/Accounting
```

```bash
git add apps/api/app/Modules/Treasury/Application/DTOs/CreatePOSChargeJournalEntryCommand.php \
  apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php \
  apps/api/tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php
git commit -m "Phase 3.7.1: Add POS account charge AR posting"
```

Then write Codex and Opus review files for Task 7.

## Task 8: Treasury Account Charge Bridge

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php`
- Modify: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- Test: `apps/api/tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php`
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Write failing bridge tests**

Required tests:

```php
public function test_treasury_account_charge_bridge_posts_ar_entry_once(): void;
public function test_bridge_is_gated_by_treasury_module(): void;
public function test_bridge_fails_loud_for_cross_company_customer(): void;
public function test_discounted_charge_posts_sales_discount_line(): void;
public function test_bridge_creates_no_treasury_payment_or_pos_payment_rows(): void;
public function test_retry_does_not_duplicate_journal_entry(): void;
public function test_bridge_bubbles_partner_balance_refresh_failure(): void;
```

- [ ] **Step 2: Run failing tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php
```

- [ ] **Step 3: Implement bridge**

Bridge contract:

```php
public function name(): string { return 'treasury_account_charge_bridge'; }
public function handlesEventType(FiscalEventType $type): bool { return $type === FiscalEventType::ACCOUNT_CHARGE; }
public function requiresModule(): string { return 'Treasury'; }
public function priority(): int { return 150; }
```

Inside transaction:

- acquire PG advisory lock on `$event->id.':treasury_account_charge_bridge'`;
- resolve customer by `tenant_id`, `company_id`, customer id or pending alias;
- assert no conflicting existing journal entry for `source_type='pos_account_charge'` and `source_id=$event->id`;
- copy `vat_breakdown` and `line_items` into `CreatePOSChargeJournalEntryCommand::$vatBreakdown` and `$lineVatSummary` from `CanonicalPayloadReader::forAccountCharge($event)`;
- call `GeneralLedgerService::createPOSChargeEntry($command)`;
- rely on `GeneralLedgerService::createPOSChargeEntry()` to refresh the Partner cached balance through `PartnerBalanceService::refreshPartnerBalance()` before the projection succeeds.

The bridge must not catch and downgrade Partner balance refresh failures. If refresh fails, the projection fails loud so the fiscal event can be retried without silently leaving stale receivable balances in the server mirror.

- [ ] **Step 4: Register and extend CI PG filter**

Tag `TreasuryAccountChargeBridge::class` in `TreasuryServiceProvider`.

Add `TreasuryAccountChargeBridgeTest` to `.github/workflows/ci.yml` PG fiscal test filter in the same commit.

- [ ] **Step 5: Run tests and commit**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Treasury app/Modules/Accounting app/Modules/Fiscal
./vendor/bin/pint --test app/Modules/Treasury app/Modules/Accounting tests/Feature/Fiscal
```

```bash
git add apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php \
  apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php \
  apps/api/tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php \
  .github/workflows/ci.yml
git commit -m "Phase 3.8.1: Bridge account charges to AR"
```

Then write Codex and Opus review files for Task 8.

## Task 9: Document/B2B Facture Draft Bridge

**Files:**
- Create: `apps/api/app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php`
- Create: `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php`
- Create: `apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php`
- Modify: `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`
- Test: `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Write failing bridge tests**

Required tests:

```php
public function test_business_customer_account_charge_creates_invoice_draft_when_document_module_active(): void;
public function test_individual_customer_account_charge_skips_document_bridge(): void;
public function test_document_bridge_is_idempotent_by_fiscal_event_id(): void;
public function test_document_bridge_fails_loud_for_cross_company_partner(): void;
public function test_document_bridge_does_not_post_or_tax_invoice_author_in_pos(): void;
```

- [ ] **Step 2: Run failing tests**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php
```

- [ ] **Step 3: Implement command and service**

`POSAccountChargeDraftService` creates a `Document` with:

- `type = DocumentType::Invoice`
- `status = DocumentStatus::Draft`
- `fiscal_status = FiscalStatus::Draft`
- `partner_id` resolved tenant/company scoped
- `source_document_id = null`
- `reference = 'POS-ACCOUNT-CHARGE:'.$eventId`
- `payload['fiscal_event_id'] = $eventId`
- lines created from sealed `line_items`
- totals copied from sealed payload

The service must not post the invoice and must not emit a Tax Invoice fiscal event.

- [ ] **Step 4: Implement projector**

Use module token `Sales` because `Vertical::defaultModules()` exposes the document/invoice operational surface as `Sales`, not `Document`. Add a test pinning `requiresModule() === 'Sales'`. If `DefaultModuleActivationResolver` reports `Sales` inactive, the bridge is excluded.

Bridge contract:

```php
public function name(): string { return 'document_account_charge_facture_bridge'; }
public function handlesEventType(FiscalEventType $type): bool { return $type === FiscalEventType::ACCOUNT_CHARGE; }
public function requiresModule(): string { return 'Sales'; }
public function priority(): int { return 170; }
```

It runs only for `customer.customer_category === 'business'` and `invoice_classification === 'b2b_facture_draft_requested'`.

- [ ] **Step 5: Register and extend CI PG filter**

Tag `DocumentAccountChargeFactureBridge::class` in `DocumentServiceProvider`.

Add `DocumentAccountChargeFactureBridgeTest` to `.github/workflows/ci.yml` PG fiscal test filter in the same commit.

- [ ] **Step 6: Run tests and commit**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Document app/Modules/Fiscal
./vendor/bin/pint --test app/Modules/Document tests/Feature/Fiscal
```

```bash
git add apps/api/app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php \
  apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php \
  apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php \
  apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php \
  apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php \
  .github/workflows/ci.yml
git commit -m "Phase 3.9.1: Route business account charges to facture drafts"
```

Then write Codex and Opus review files for Task 9.

## Task 10: Integration Matrix And Chokepoint Sentinel

**Files:**
- Create: `apps/api/tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`
- Create: `apps/pos/src/lib/accountCharge/__tests__/accountChargeFullFlow.test.ts`
- Create: `apps/api/scripts/check-accountCharge-chokepoints.sh`
- Modify: `.github/workflows/ci.yml`
- Test: existing Fiscal/POS suites

- [ ] **Step 1: Write failing full-flow tests**

Backend flow:

```php
public function test_account_charge_full_flow_projects_printable_and_ar(): void;
public function test_account_charge_pos_only_projects_printable_and_skips_bridges(): void;
public function test_account_charge_business_customer_creates_facture_draft_when_document_active(): void;
public function test_account_charge_rejects_server_authored_legacy_route_attempts(): void;
public function test_insufficient_credit_attempt_does_not_append_or_sync_fiscal_event(): void;
public function test_hard_stale_block_policy_attempt_does_not_append_or_sync_fiscal_event(): void;
```

Device flow:

```ts
it('authors account charge locally and syncs through fiscal events only', async () => {
  const result = await authorAccountCharge(makeAccountChargeInput());

  expect(result.fiscalEvent.event_type).toBe('ACCOUNT_CHARGE');
  expect(fetchMock).not.toHaveBeenCalledWith(expect.stringContaining('/pos/receipts'));
});

it('does not append or sync when credit limit is insufficient', async () => {
  const engine = makeSpyFiscalEventEngine();

  await expect(authorAccountCharge(makeAccountChargeInput({
    engine,
    customerOverrides: { credit_limit: '100.000', receivable_balance: '90.000' },
    total: '50.000',
  }))).rejects.toThrow(/credit_limit_exceeded/);

  expect(engine.append).not.toHaveBeenCalled();
  expect(fetchMock).not.toHaveBeenCalledWith(expect.stringContaining('/pos/sync/fiscal-events'));
});

it('does not append or sync when hard-stale policy blocks charging', async () => {
  const engine = makeSpyFiscalEventEngine();

  await expect(authorAccountCharge(makeAccountChargeInput({
    engine,
    policy: { stale_action: 'block' },
    customerOverrides: { balance_updated_at: '2026-05-01T00:00:00.000Z' },
  }))).rejects.toThrow(/balance_snapshot_hard_stale/);

  expect(engine.append).not.toHaveBeenCalled();
  expect(fetchMock).not.toHaveBeenCalledWith(expect.stringContaining('/pos/sync/fiscal-events'));
});
```

- [ ] **Step 2: Add chokepoint script**

Script asserts:

- no `ACCOUNT_CHARGE` authoring through `ReceiptCreationService::createReceipt`;
- no `ACCOUNT_CHARGE` authoring through `/pos/receipts` or `/pos/receipts/sync`;
- `FiscalEventPayloadRegistry` implements `ACCOUNT_CHARGE`;
- `FiscalEventEngine` has `ACCOUNT_CHARGE` validation;
- POS account-charge service calls `FiscalEventEngine.append`.

- [ ] **Step 3: Wire CI**

Add the script to the chokepoint gate job beside `check-saleReceipt-chokepoints.sh`.

- [ ] **Step 4: Run full gates and commit**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Accounting app/Modules/Document
./vendor/bin/pint --test app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Accounting app/Modules/Document tests/Feature/Fiscal

cd ../pos
pnpm test
pnpm typecheck
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/api/scripts/check-accountCharge-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

```bash
git add apps/api/tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php \
  apps/pos/src/lib/accountCharge/__tests__/accountChargeFullFlow.test.ts \
  apps/api/scripts/check-accountCharge-chokepoints.sh \
  .github/workflows/ci.yml
git commit -m "Phase 3.10.1: Verify account charge full flow"
```

Then write Codex and Opus review files for Task 10.

## Task 11: Roadmap, Handoff, Memory, PR

**Files:**
- Modify: `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`
- Modify: `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md`
- Modify: `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/project_pos_fiscal_event_engine.md`
- Add final review files under `docs/superpowers/reviews/`

- [ ] **Step 1: Update status docs**

Roadmap status must mark Phase 3 complete and list:

- `ACCOUNT_CHARGE` canonical contract.
- POS credit rules and authoring.
- POS-core receipt projection.
- Treasury AR bridge.
- Document/B2B Facture draft bridge.
- POS-only / Treasury-active / B2B-active full-flow verification.

Handoff §4 must include commits, review status, test counts, and new standing patterns.

- [ ] **Step 2: Run final verification**

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Accounting app/Modules/Document
./vendor/bin/pint --test app/Modules/Fiscal app/Modules/POS app/Modules/Treasury app/Modules/Accounting app/Modules/Document tests/Feature/Fiscal tests/Feature/POS tests/Unit/Fiscal tests/Feature/Accounting

cd ../pos
pnpm test
pnpm typecheck
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/api/scripts/check-accountCharge-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

- [ ] **Step 3: Commit, push, PR, auto-merge**

```bash
git add docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md \
  docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md \
  /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/project_pos_fiscal_event_engine.md
git commit -m "Phase 3.11.1: Close charge-to-account phase"
git push origin feat/fiscal-phase-3-charge-to-account
gh pr create --base dev --head feat/fiscal-phase-3-charge-to-account --title "Phase 3: POS charge-to-account" --body-file /tmp/phase3-pr-body.md
gh pr merge --auto --merge
```

The memory path above was discovered with `find /Users/houssamr/.claude/projects -path '*project_pos_fiscal_event_engine.md' -print`.
