<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\DTOs\Canonical\OriginalLineReferenceDTO;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

/**
 * v3-refund-chain-integration spec §17 — SALE_RECEIPT V4 REFUND
 * cross-language golden parity, server side.
 *
 * Mirrors the established M4 mechanism ({@see SaleReceiptV2GoldenParityTest}
 * + `sale-receipt-v2-golden.json`) for the v4 refund contract. The fixture
 * `tests/Fixtures/Fiscal/sale-receipt-v4-refund-golden.json` carries the
 * F-16 golden corpus payload (`F-16-refund-v4-cash-eur`,
 * `GoldenFixtureBuilder.php` / `tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/payload.json`)
 * as its locked `expected_canonical_string` + `expected_sha256_hex` — the
 * device-side twin (`RefundReceiptV4Payload.parity.test.ts`, apps/pos,
 * wave 2) pins its own encoder output to these same exact bytes. This
 * test pins the server contract:
 *
 *   1. SHA-256 of the canonical bytes equals the locked hash (device JS
 *      hash == server PHP hash — the same M4-style sign-off contract,
 *      applied to v4);
 *   2. the StrictCanonicalParser ACCEPTS the payload as event_version=4;
 *   3. the SaleReceiptPayload DTO hydrates the v4-only fields
 *      (`original_line_references[]` via OriginalLineReferenceDTO,
 *      `refund_destination`) correctly.
 *
 * The server never re-serializes fiscal events — it verifies the device's
 * bytes verbatim, so acceptance + hash parity IS the byte parity.
 */
final class CanonicalByteHashV4ParityTest extends TestCase
{
    public function test_golden_hash_matches_the_device_authored_bytes(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(
            $fixture['expected_sha256_hex'],
            hash('sha256', $fixture['expected_canonical_string']),
        );
    }

    public function test_parser_accepts_the_golden_payload_as_event_version_4(): void
    {
        $fixture = $this->fixture();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        $envelope = [
            'business_date' => '2026-05-20',
            'chain_context' => 'operational',
            'company_id' => 'co-1',
            'event_time_device' => '2026-05-20T14:30:00Z',
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 4,
            'operator_id' => 'op-1',
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => 'tn-1',
            'terminal_id' => 'tm-1',
        ];
        ksort($envelope);
        $bytes = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $parser = new StrictCanonicalParser(
            new FiscalEventPayloadRegistry,
            new FiscalPayloadConstraintValidator,
        );
        $result = $parser->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_dto_hydrates_the_v4_only_fields_from_the_golden_payload(): void
    {
        $fixture = $this->fixture();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        $dto = SaleReceiptPayload::fromArray($payload);

        $this->assertSame('REFUND', $dto->invoiceTypeCode);
        $this->assertSame('cash', $dto->refundDestination);
        $this->assertNotNull($dto->originalLineReferences);
        $this->assertCount(1, $dto->originalLineReferences);

        /** @var array<string, mixed> $referenceRow */
        $referenceRow = $dto->originalLineReferences[0];
        $reference = OriginalLineReferenceDTO::fromArray($referenceRow);

        $this->assertSame('restock', $reference->disposition);
        $this->assertSame(0, $reference->originalLineIndex);
        $this->assertSame('prod-default', $reference->productId);
        $this->assertSame('1.000', $reference->quantity);

        $this->assertNotNull($dto->originalReceiptReference);
        $this->assertSame(
            '99999999-9999-4999-8999-999999999999',
            $dto->originalReceiptReference['fiscal_event_id'] ?? null,
        );
    }

    /**
     * @return array{expected_canonical_string: string, expected_sha256_hex: string}
     */
    private function fixture(): array
    {
        /** @var array{expected_canonical_string: string, expected_sha256_hex: string} */
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/Fiscal/sale-receipt-v4-refund-golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
