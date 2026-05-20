<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Domain\DTOs\ChainBreakDetectedPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainRestartPayload;
use App\Modules\Fiscal\Domain\DTOs\CompanyDayClosureManifestPayload;
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
    }

    public function test_returns_event_version_one_for_implemented_types(): void
    {
        $r = new FiscalEventPayloadRegistry;

        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::SALE_RECEIPT));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::CHAIN_BREAK_DETECTED));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::CHAIN_RESTART));
        $this->assertSame(1, $r->eventVersionFor(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT));
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

        $this->assertFalse($r->isImplemented(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST));
        $this->assertFalse($r->isImplemented(FiscalEventType::SALE_VOID));
        $this->assertFalse($r->isImplemented(FiscalEventType::Z_REPORT));
    }

    public function test_registry_is_consistent_with_enum_phase1_helper(): void
    {
        $r = new FiscalEventPayloadRegistry;

        // Walks every enum case and asserts the registry agrees with the
        // enum's own isImplementedInPhase1() classifier. A future drift
        // between the registry and the enum surfaces as a test failure.
        foreach (FiscalEventType::cases() as $case) {
            $this->assertSame(
                $case->isImplementedInPhase1(),
                $r->isImplemented($case),
                "Mismatch on FiscalEventType::{$case->name}",
            );
        }
    }

    public function test_sale_receipt_payload_from_array_to_array_roundtrip(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the SALE_RECEIPT payload helper(s) (minimalSaleReceiptPayload / correctedPayload / payload builders) '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $data = [
            'currency' => 'TND',
            'currency_scale' => 3,
            'lines' => [
                ['product_id' => 'p-1', 'quantity' => '2', 'unit_price' => '5.000', 'line_total' => '10.000', 'vat_rate' => '7'],
            ],
            'subtotal' => '10.000',
            'discount_total' => '0.000',
            'tax_total' => '0.700',
            'total' => '10.700',
            'vat_breakdown' => [
                ['rate' => '7', 'base' => '10.000', 'amount' => '0.700'],
            ],
            'payment_lines' => [
                ['payment_method_id' => 'pm-cash', 'amount' => '10.700', 'tendered' => '11.000', 'change' => '0.300'],
            ],
            'voucher_redemptions' => [],
        ];

        $dto = SaleReceiptPayload::fromArray($data);

        $this->assertSame('TND', $dto->currency);
        $this->assertSame(3, $dto->currencyScale);
        $this->assertSame($data, $dto->toArray());
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the SALE_RECEIPT payload helper(s) (minimalSaleReceiptPayload / correctedPayload / payload builders) '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $this->assertThrowsInvalidArg(fn () => SaleReceiptPayload::fromArray([]), 'currency');
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the SALE_RECEIPT payload helper(s) (minimalSaleReceiptPayload / correctedPayload / payload builders) '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $base = [
            'currency' => 'TND',
            'currency_scale' => 3,
            'lines' => [],
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'total' => '0.000',
            'vat_breakdown' => [],
            'payment_lines' => [],
            'voucher_redemptions' => [],
        ];

        // Float in monetary fields — every cast-site must reject.
        foreach (['subtotal', 'discount_total', 'tax_total', 'total'] as $key) {
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the SALE_RECEIPT payload helper(s) (minimalSaleReceiptPayload / correctedPayload / payload builders) '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $base = [
            'currency' => 'TND',
            'currency_scale' => '3', // string instead of int — must reject (no silent coercion)
            'lines' => [],
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'total' => '0.000',
            'vat_breakdown' => [],
            'payment_lines' => [],
            'voucher_redemptions' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/currency_scale.*int/');
        SaleReceiptPayload::fromArray($base);
    }

    public function test_sale_receipt_payload_rejects_bool_currency_scale(): void
    {
        $base = [
            'currency' => 'TND',
            'currency_scale' => true, // bool — must reject (is_int(true) === false)
            'lines' => [],
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'total' => '0.000',
            'vat_breakdown' => [],
            'payment_lines' => [],
            'voucher_redemptions' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        SaleReceiptPayload::fromArray($base);
    }

    public function test_sale_receipt_payload_rejects_non_array_lines(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the SALE_RECEIPT payload helper(s) (minimalSaleReceiptPayload / correctedPayload / payload builders) '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $base = [
            'currency' => 'TND',
            'currency_scale' => 3,
            'lines' => 'not an array',
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'total' => '0.000',
            'vat_breakdown' => [],
            'payment_lines' => [],
            'voucher_redemptions' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lines.*array/');
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
}
