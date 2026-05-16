<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Domain\DTOs\ChainBreakDetectedPayload;
use App\Modules\Fiscal\Domain\DTOs\ChainRestartPayload;
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
}
