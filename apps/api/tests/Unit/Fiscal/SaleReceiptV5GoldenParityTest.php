<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SaleReceiptV5 (D-1, owner ruling 2026-08-25) cross-language golden parity —
 * server side.
 *
 * `tests/Fixtures/Fiscal/sale-receipt-v5-golden.json` carries the canonical
 * payload bytes AUTHORED BY THE DEVICE ENCODER for the ruling's worked example:
 * a four-line TND sale at 7 / 13 / 19 % plus an exempt line, gross 640.000, with
 * a 50.000 remise ventilated pro-rata. The device-side twin
 * (`saleReceiptV5CanonicalParity.test.ts`) pins the encoder to these exact
 * bytes; this test pins the server contract:
 *
 *   1. SHA-256 of the bytes equals the locked hash (device JS hash == server PHP
 *      hash — the D-1 sign-off contract);
 *   2. the constraint validator ACCEPTS the payload at `event_version = 5`;
 *   3. the SAME bytes are REFUSED at `event_version = 3`, where the identity
 *      still adds the remise back — which is what makes the version, not a
 *      guess about shape, the discriminator.
 *
 * The server never re-serializes fiscal events; it verifies the device's bytes
 * verbatim, so acceptance + hash parity IS byte parity.
 */
final class SaleReceiptV5GoldenParityTest extends TestCase
{
    public function test_golden_hash_matches_the_device_authored_bytes(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(
            $fixture['expected_sha256_hex'],
            hash('sha256', $fixture['expected_canonical_string']),
        );
    }

    public function test_the_golden_payload_seals_the_post_remise_base(): void
    {
        $payload = $this->payload();

        $this->assertSame('524.547', $payload['subtotal']);
        $this->assertSame('65.453', $payload['vat_total']);
        $this->assertSame('590.000', $payload['total']);
        $this->assertSame('50.000', $payload['transaction_discount_amount']);

        $sumAllocated = '0.000';
        foreach ($payload['vat_breakdown'] as $row) {
            $this->assertArrayHasKey('discount_allocated', $row);
            $sumAllocated = bcadd($sumAllocated, (string) $row['discount_allocated'], 3);
        }
        $this->assertSame('50.000', $sumAllocated);
    }

    public function test_validator_accepts_the_golden_payload_at_event_version_5(): void
    {
        $validator = new FiscalPayloadConstraintValidator;
        $payload = $this->payload();

        $this->assertNull($validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        ));

        $validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 5);
        $this->addToAssertionCount(1);
    }

    public function test_the_same_bytes_are_refused_at_event_version_3(): void
    {
        $validator = new FiscalPayloadConstraintValidator;
        $payload = $this->payload();

        // The TOP-LEVEL key set is identical across v3 and v5 — that is the
        // whole point of D-1 being a semantic bump — so the key-set gate has
        // nothing to say here.
        $this->assertNull($validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 3,
        ));

        // v3 refuses the bytes on two independent counts: its identity adds the
        // remise back (590.000 against 640.000, which fires first), and its
        // breakdown rows are the frozen five-key shape. Either way the VERSION
        // decides — never a guess about which shape the bytes carry.
        try {
            $validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 3);
            $this->fail('Expected v3 to refuse the v5 golden bytes.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/payload_total_arithmetic_mismatch|payload_vat_breakdown_extra_keys/',
                $e->getMessage(),
            );
        }

        $stripped = $payload;
        $stripped['vat_breakdown'] = array_map(
            static function (array $row): array {
                unset($row['discount_allocated']);

                return $row;
            },
            $stripped['vat_breakdown'],
        );

        $this->expectException(RuntimeException::class);
        $validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $stripped, eventVersion: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->fixture()['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @return array{expected_canonical_string: string, expected_sha256_hex: string}
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../Fixtures/Fiscal/sale-receipt-v5-golden.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->fail('sale-receipt-v5-golden.json is unreadable at '.$path);
        }

        /** @var array{expected_canonical_string: string, expected_sha256_hex: string} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
