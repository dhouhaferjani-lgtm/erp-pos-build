<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Domain\DTOs\AccountStatusChangedPayload;
use App\Modules\Fiscal\Domain\DTOs\AccountChargePayload;
use App\Modules\Fiscal\Domain\DTOs\AccountPaymentPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainBreakDetectedPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainRestartPayload;
use App\Modules\Fiscal\Domain\DTOs\CompanyDayClosureManifestPayload;
use App\Modules\Fiscal\Domain\DTOs\OperatorApprovalGrantedPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideAccountStatusPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideCreditLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideDiscountLimitPayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideTenderTolerancePayload;
use App\Modules\Fiscal\Domain\DTOs\OverrideVoidOrReturnPayload;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\DTOs\TerminalRegistrySnapshotPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
use Tests\TestCase;

final class FiscalEventPayloadRegistryTest extends TestCase
{
    public function test_resolves_implemented_payload_dto_classes(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->assertSame(SaleReceiptPayload::class, $r->dtoClassFor(FiscalEventType::SALE_RECEIPT));
        $this->assertSame(ChainBreakDetectedPayload::class, $r->dtoClassFor(FiscalEventType::CHAIN_BREAK_DETECTED));
        $this->assertSame(ChainRestartPayload::class, $r->dtoClassFor(FiscalEventType::CHAIN_RESTART));
        $this->assertSame(TerminalRegistrySnapshotPayload::class, $r->dtoClassFor(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT));
        $this->assertSame(AccountPaymentPayload::class, $r->dtoClassFor(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertSame(AccountChargePayload::class, $r->dtoClassFor(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertSame(AccountStatusChangedPayload::class, $r->dtoClassFor(FiscalEventType::ACCOUNT_STATUS_CHANGED));
        $this->assertSame(OperatorApprovalGrantedPayload::class, $r->dtoClassFor(FiscalEventType::OPERATOR_APPROVAL_GRANTED));
        $this->assertSame(OverrideCreditLimitPayload::class, $r->dtoClassFor(FiscalEventType::OVERRIDE_CREDIT_LIMIT));
        $this->assertSame(OverrideAccountStatusPayload::class, $r->dtoClassFor(FiscalEventType::OVERRIDE_ACCOUNT_STATUS));
        $this->assertSame(OverrideDiscountLimitPayload::class, $r->dtoClassFor(FiscalEventType::OVERRIDE_DISCOUNT_LIMIT));
        $this->assertSame(OverrideTenderTolerancePayload::class, $r->dtoClassFor(FiscalEventType::OVERRIDE_TENDER_TOLERANCE));
        $this->assertSame(OverrideVoidOrReturnPayload::class, $r->dtoClassFor(FiscalEventType::OVERRIDE_VOID_OR_RETURN));
    }

    public function test_returns_event_version_one_for_implemented_types(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::SALE_RECEIPT));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::CHAIN_BREAK_DETECTED));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::CHAIN_RESTART));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::ACCOUNT_STATUS_CHANGED));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::OPERATOR_APPROVAL_GRANTED));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::OVERRIDE_CREDIT_LIMIT));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::OVERRIDE_ACCOUNT_STATUS));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::OVERRIDE_DISCOUNT_LIMIT));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::OVERRIDE_TENDER_TOLERANCE));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::OVERRIDE_VOID_OR_RETURN));
    }

    public function test_reserved_type_company_day_closure_manifest_throws(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->expectException(FiscalEventTypeNotImplemented::class);
        $r->dtoClassFor(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);
    }

    public function test_reserved_type_sale_void_throws(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->expectException(FiscalEventTypeNotImplemented::class);
        $r->dtoClassFor(FiscalEventType::SALE_VOID);
    }

    public function test_event_version_for_reserved_type_throws(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->expectException(FiscalEventTypeNotImplemented::class);
        $r->eventVersionFor(FiscalEventType::Z_REPORT);
    }

    public function test_is_implemented_helper_matches_phase1_set(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->assertTrue($r->isImplemented(FiscalEventType::SALE_RECEIPT));
        $this->assertTrue($r->isImplemented(FiscalEventType::CHAIN_BREAK_DETECTED));
        $this->assertTrue($r->isImplemented(FiscalEventType::CHAIN_RESTART));
        $this->assertTrue($r->isImplemented(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT));
        $this->assertTrue($r->isImplemented(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertTrue($r->isImplemented(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertTrue($r->isImplemented(FiscalEventType::ACCOUNT_STATUS_CHANGED));
        $this->assertTrue($r->isImplemented(FiscalEventType::OPERATOR_APPROVAL_GRANTED));
        $this->assertTrue($r->isImplemented(FiscalEventType::OVERRIDE_CREDIT_LIMIT));
        $this->assertTrue($r->isImplemented(FiscalEventType::OVERRIDE_ACCOUNT_STATUS));
        $this->assertTrue($r->isImplemented(FiscalEventType::OVERRIDE_DISCOUNT_LIMIT));
        $this->assertTrue($r->isImplemented(FiscalEventType::OVERRIDE_TENDER_TOLERANCE));
        $this->assertTrue($r->isImplemented(FiscalEventType::OVERRIDE_VOID_OR_RETURN));

        $this->assertFalse($r->isImplemented(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST));
        $this->assertFalse($r->isImplemented(FiscalEventType::SALE_VOID));
        $this->assertFalse($r->isImplemented(FiscalEventType::Z_REPORT));
        $this->assertFalse($r->isImplemented(FiscalEventType::CASH_OUT));
        $this->assertFalse($r->isImplemented(FiscalEventType::SAFE_DROP));
    }

    public function test_account_charge_is_implemented_at_version_one(): void
    {
        $registry = new FiscalEventPayloadRegistry;

        $this->assertTrue($registry->isImplemented(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertSame(1, $registry->eventVersionFor(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertSame(AccountChargePayload::class, $registry->dtoClassFor(FiscalEventType::ACCOUNT_CHARGE));
    }

    public function test_registry_is_consistent_with_enum_phase1_helper(): void
    {
        $r = new FiscalEventPayloadRegistry;

        // Walks every enum case and asserts the registry agrees with the
        // enum's own implemented classifier. A future drift between the
        // registry and the enum surfaces as a test failure.
        foreach (FiscalEventType::cases() as $case) {
            $this->assertSame(
                $case->isImplemented(),
                $r->isImplemented($case),
                "Mismatch on FiscalEventType::{$case->name}",
            );
        }
    }

    public function test_sale_receipt_payload_from_array_to_array_roundtrip(): void
    {
        // Pass 2A.PHP.2 — 27-key Candidate C-v3 round-trip.
        $data = $this->canonicalSaleReceiptArray();

        $dto = SaleReceiptPayload::fromArray($data);

        $this->assertSame('TND', $dto->currencyCode);
        $this->assertSame(3, $dto->currencyScale);
        // The DTO sorts on output via fixed-order toArray(); compare on the
        // sorted form.
        ksort($data);
        $out = $dto->toArray();
        ksort($out);
        $this->assertSame($data, $out);
    }

    public function test_account_payment_payload_from_array_to_array_roundtrip(): void
    {
        $data = $this->canonicalAccountPaymentArray();

        $dto = AccountPaymentPayload::fromArray($data);

        $this->assertSame('ACCOUNT_PAYMENT', $dto->receiptTypeCode);
        $this->assertSame('FIFO', $dto->treasuryAllocationPolicy);
        $this->assertSame('100.000', $dto->payment['amount']);
        $out = $dto->toArray();
        ksort($data);
        ksort($out);
        $this->assertSame($data, $out);
    }

    public function test_account_charge_payload_from_array_to_array_roundtrip(): void
    {
        $data = $this->canonicalAccountChargeArray();

        $dto = AccountChargePayload::fromArray($data);

        $this->assertSame('ACCOUNT_CHARGE', $dto->receiptTypeCode);
        $this->assertSame('119.000', $dto->totals['amount_charged_to_account']);
        $this->assertNull($dto->buyer);
        $out = $dto->toArray();
        ksort($data);
        ksort($out);
        $this->assertSame($data, $out);
    }

    public function test_chain_break_detected_payload_from_array_to_array_roundtrip(): void
    {
        $data = [
            'reason' => 'previous_hash_mismatch',
            'last_good_sequence' => 42,
            'last_good_hash' => str_repeat('a', 64),
            'offending_record_reference' => ['terminal_id' => 't-1', 'sequence_number' => 43],
        ];

        $dto = ChainBreakDetectedPayload::fromArray($data);

        $this->assertSame('previous_hash_mismatch', $dto->reason);
        $this->assertSame(42, $dto->lastGoodSequence);
        $this->assertSame(str_repeat('a', 64), $dto->lastGoodHash);
        $this->assertSame($data, $dto->toArray());
    }

    public function test_chain_restart_payload_from_array_to_array_roundtrip(): void
    {
        $data = [
            'new_genesis_reference' => str_repeat('b', 64),
            'last_good_anchor' => ['sequence_number' => 100, 'hash' => str_repeat('c', 64)],
            'operator_authorization_evidence' => ['user_id' => 'u-1', 'reason_code' => 'CHAIN_RESTART'],
            'provenance_link' => ['chain_break_event_id' => 'evt-abc'],
        ];

        $dto = ChainRestartPayload::fromArray($data);

        $this->assertSame(str_repeat('b', 64), $dto->newGenesisReference);
        $this->assertSame($data, $dto->toArray());
    }

    public function test_terminal_registry_snapshot_payload_from_array_to_array_roundtrip(): void
    {
        $data = [
            'terminals' => [
                ['terminal_id' => 't-1', 'terminal_code' => 'T01', 'is_active' => true],
                ['terminal_id' => 't-2', 'terminal_code' => 'T02', 'is_active' => false],
            ],
            'snapshot_hash' => str_repeat('d', 64),
            'prior_snapshot_link' => null,
        ];

        $dto = TerminalRegistrySnapshotPayload::fromArray($data);

        $this->assertCount(2, $dto->terminals);
        $this->assertSame(str_repeat('d', 64), $dto->snapshotHash);
        $this->assertNull($dto->priorSnapshotLink);
        $this->assertSame($data, $dto->toArray());
    }

    // Regression for Task 14 round-2 BLOCKER-1 (Codex): the original
    // `(string) $data['key']` cast silently coerced missing keys into ''
    // (raising only a PHP undefined-array-key warning). The hardened
    // fromArray uses FiscalPayloadArrayGuards::require* which throws
    // InvalidArgumentException with a precise "missing required key"
    // message instead.
    public function test_from_array_rejects_missing_required_keys_on_every_implemented_dto(): void
    {
        // Pass 2A.PHP.2 — `currency_code` replaces `currency` in the 27-key
        // contract; first required key (alphabetical) is `business_date`.
        // The DTO's fromArray() validates via FiscalPayloadArrayGuards which
        // throws "missing required key: <key>" for the first one it hits.
        $this->assertThrowsInvalidArg(fn () => SaleReceiptPayload::fromArray([]), 'seller');
        $this->assertThrowsInvalidArg(fn () => ChainBreakDetectedPayload::fromArray([]), 'reason');
        $this->assertThrowsInvalidArg(fn () => ChainRestartPayload::fromArray([]), 'new_genesis_reference');
        $this->assertThrowsInvalidArg(fn () => TerminalRegistrySnapshotPayload::fromArray([]), 'terminals');
    }

    // Regression for Task 14 round-2 BLOCKER-2 (Codex): the original
    // `(string) $data['key']` cast silently coerced floats into
    // locale-sensitive decimal-string representations
    // (e.g. (string) 10.5 -> '10.5', losing the float-to-decimal
    // contract from SoT v3 §4). The hardened fromArray asserts
    // is_string() and rejects floats outright.
    public function test_sale_receipt_payload_rejects_float_monetary_fields(): void
    {
        // Pass 2A.PHP.2 — 27-key contract; money fields renamed per
        // synthesis v5 §3 (tax_total → vat_total, discount_total →
        // transaction_discount_amount).
        $base = $this->canonicalSaleReceiptArray();

        // Float in monetary fields — every cast-site must reject.
        foreach (['subtotal', 'transaction_discount_amount', 'vat_total', 'total'] as $key) {
            $data = $base;
            $data[$key] = 10.5;
            try {
                SaleReceiptPayload::fromArray($data);
                $this->fail("Expected float in '{$key}' to be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
                $this->assertStringContainsString('string', $e->getMessage());
            }
        }
    }

    public function test_sale_receipt_payload_rejects_non_int_currency_scale(): void
    {
        // Pass 2A.PHP.2 — 27-key contract; currency_scale must be int.
        $base = $this->canonicalSaleReceiptArray();
        $base['currency_scale'] = '3'; // string instead of int — must reject

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/currency_scale.*int/');
        SaleReceiptPayload::fromArray($base);
    }

    public function test_sale_receipt_payload_rejects_bool_currency_scale(): void
    {
        // Pass 2A.PHP.2 — bool currency_scale must reject (is_int(true) === false).
        $base = $this->canonicalSaleReceiptArray();
        $base['currency_scale'] = true;

        $this->expectException(\InvalidArgumentException::class);
        SaleReceiptPayload::fromArray($base);
    }

    public function test_sale_receipt_payload_rejects_non_array_lines(): void
    {
        // Pass 2A.PHP.2 — 27-key list container renamed to `line_items`.
        $base = $this->canonicalSaleReceiptArray();
        $base['line_items'] = 'not an array';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/line_items.*array/');
        SaleReceiptPayload::fromArray($base);
    }

    public function test_chain_break_detected_payload_rejects_string_last_good_sequence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/last_good_sequence.*int/');
        ChainBreakDetectedPayload::fromArray([
            'reason' => 'sequence_gap',
            'last_good_sequence' => '42', // string instead of int — rejected
            'last_good_hash' => str_repeat('a', 64),
            'offending_record_reference' => [],
        ]);
    }

    public function test_terminal_registry_snapshot_payload_accepts_null_prior_snapshot_link(): void
    {
        // Optional-string semantics: missing key OR null both allowed.
        $dto1 = TerminalRegistrySnapshotPayload::fromArray([
            'terminals' => [],
            'snapshot_hash' => str_repeat('d', 64),
            'prior_snapshot_link' => null,
        ]);
        $this->assertNull($dto1->priorSnapshotLink);

        $dto2 = TerminalRegistrySnapshotPayload::fromArray([
            'terminals' => [],
            'snapshot_hash' => str_repeat('d', 64),
            // prior_snapshot_link key absent entirely
        ]);
        $this->assertNull($dto2->priorSnapshotLink);
    }

    public function test_terminal_registry_snapshot_payload_rejects_non_string_prior_snapshot_link(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TerminalRegistrySnapshotPayload::fromArray([
            'terminals' => [],
            'snapshot_hash' => str_repeat('d', 64),
            'prior_snapshot_link' => 123, // int — must reject
        ]);
    }

    // Aligned with Opus P2-1 (Task 14 round-2): both the registry AND the
    // reserved DTO surface throw the same FiscalEventTypeNotImplemented
    // class. Future Phase 2 callers can catch one exception type instead
    // of branching on (FiscalEventTypeNotImplemented | \LogicException).
    public function test_company_day_closure_manifest_payload_throws_unified_exception(): void
    {
        $caught = null;
        try {
            CompanyDayClosureManifestPayload::fromArray(['anything' => 'goes']);
        } catch (FiscalEventTypeNotImplemented $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(FiscalEventTypeNotImplemented::class, $caught);
        $this->assertSame(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST, $caught->type);
    }

    private function assertThrowsInvalidArg(callable $fn, string $missingKey): void
    {
        try {
            $fn();
            $this->fail("Expected InvalidArgumentException for missing key '{$missingKey}'");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($missingKey, $e->getMessage());
        }
    }

    /**
     * Pass 2A.PHP.2 — 27-key canonical SALE_RECEIPT array used by DTO
     * round-trip + negative tests. Mirrors GoldenFixtureBuilder F-01 in
     * structure but kept local to avoid coupling unit-test scope to the
     * Helpers/Fiscal/ directory.
     *
     * @return array<string, mixed>
     */
    private function canonicalSaleReceiptArray(): array
    {
        return [
            'business_date' => '2026-05-20',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '10.000',
                'line_vat' => '0.700',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '2.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '5.000',
                'vat_rate' => '7.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.700',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.000',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.700',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.700',
                'net_amount' => '10.000',
                'rate' => '7.00',
                'tax_category_code' => '',
                'vat_amount' => '0.700',
            ]],
            'vat_total' => '0.700',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalAccountPaymentArray(): array
    {
        return [
            'account_payment_uuid' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-05-21',
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'payment_amount' => '100.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '200.000',
                'projected_receivable_balance_after' => '200.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '100.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => null,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalAccountChargeArray(): array
    {
        return [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'charge_terms' => [
                'due_date' => '2026-06-20',
                'payment_terms_days' => 30,
                'terms_label' => 'Net 30',
            ],
            'credit_decision' => [
                'credit_available_after' => '81.000',
                'credit_available_before' => '200.000',
                'credit_limit' => '500.000',
                'decision' => 'approved',
                'limit_exceeded' => false,
                'mirror_stale_at_authoring' => false,
                'policy_version' => 'phase3-default-v1',
                'stale_policy_action' => 'allow',
                'warnings' => [],
            ],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'account_identifier' => 'CUST-0001',
                'customer_category' => 'individual',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'invoice_classification' => 'b2c_charge_receipt',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '100.000',
                'line_uuid' => '77777777-7777-4777-8777-777777777777',
                'line_vat' => '19.000',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '100.000',
                'vat_rate' => '19.00',
            ]],
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'charge_amount' => '119.000',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '419.000',
                'projected_receivable_balance_after' => '419.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'print_profile' => 'ACCOUNT_CHARGE_RECEIPT',
            'receipt_type_code' => 'ACCOUNT_CHARGE',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'totals' => [
                'amount_charged_to_account' => '119.000',
                'grand_total_before_charge' => '119.000',
                'subtotal' => '100.000',
                'total' => '119.000',
                'vat_total' => '19.000',
            ],
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '119.000',
                'net_amount' => '100.000',
                'rate' => '19.00',
                'tax_category_code' => '',
                'vat_amount' => '19.000',
            ]],
        ];
    }
}
