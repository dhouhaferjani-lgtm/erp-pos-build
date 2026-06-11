<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\DTOs\Canonical\LineItemDTO;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

/**
 * SaleReceiptV2 (M4) cross-language golden parity — server side.
 *
 * The fixture `tests/Fixtures/Fiscal/sale-receipt-v2-golden.json` carries
 * the canonical payload bytes AUTHORED BY THE DEVICE ENCODER
 * (`apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts`) for a golden
 * two-line V2 sale (one variant line, one plain line). The device-side
 * twin (`saleReceiptV2CanonicalParity.test.ts`) pins the encoder output to
 * these exact bytes; this test pins the server contract:
 *
 *   1. SHA-256 of the canonical bytes equals the locked hash
 *      (device JS hash == server PHP hash — the M4 sign-off contract);
 *   2. the StrictCanonicalParser ACCEPTS the payload as event_version=2;
 *   3. the SaleReceiptPayload DTO hydrates the variant identity.
 *
 * The server never re-serializes fiscal events — it verifies the device's
 * bytes verbatim, so acceptance + hash parity IS the byte parity.
 */
final class SaleReceiptV2GoldenParityTest extends TestCase
{
    public function test_golden_hash_matches_the_device_authored_bytes(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(
            $fixture['expected_sha256_hex'],
            hash('sha256', $fixture['expected_canonical_string']),
        );
    }

    public function test_parser_accepts_the_golden_payload_as_event_version_2(): void
    {
        $fixture = $this->fixture();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        $envelope = [
            'business_date' => '2026-06-11',
            'chain_context' => 'operational',
            'company_id' => 'co-1',
            'event_time_device' => '2026-06-11T10:00:00Z',
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 2,
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

    public function test_dto_hydrates_the_variant_identity_from_the_golden_payload(): void
    {
        $fixture = $this->fixture();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        $dto = SaleReceiptPayload::fromArray($payload);

        $this->assertCount(2, $dto->lineItems);
        // SaleReceiptPayload keeps raw line rows; LineItemDTO is what the
        // CanonicalPayloadReader / projections consume per row.
        /** @var array<string, mixed> $variantRow */
        $variantRow = $dto->lineItems[0];
        $variantLine = LineItemDTO::fromArray($variantRow);
        $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $variantLine->variantId);
        $this->assertSame('TSHIRT-RED-L', $variantLine->variantSku);
        $this->assertSame('T-Shirt — Red / L', $variantLine->variantName);
        // Parent identity preserved — V2 never repurposes the V1 fields.
        $this->assertSame('T-Shirt', $variantLine->name);
        $this->assertSame('TSHIRT', $variantLine->sku);

        /** @var array<string, mixed> $plainRow */
        $plainRow = $dto->lineItems[1];
        $plainLine = LineItemDTO::fromArray($plainRow);
        $this->assertNull($plainLine->variantId);
        $this->assertNull($plainLine->variantSku);
        $this->assertNull($plainLine->variantName);
    }

    /**
     * @return array{expected_canonical_string: string, expected_sha256_hex: string}
     */
    private function fixture(): array
    {
        /** @var array{expected_canonical_string: string, expected_sha256_hex: string} */
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/Fiscal/sale-receipt-v2-golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
