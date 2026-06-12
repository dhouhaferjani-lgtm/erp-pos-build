<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the no-version-bump rationale for location-sourced seller identity
 * (spec 2026-06-11 §4.6): validation is structural and never compares
 * seller values to company records. If this test breaks, STOP — the
 * client-side atomic seller resolver's fiscal assumption no longer holds.
 *
 * The canonical SALE_RECEIPT seller validation (`validateSeller`) checks:
 *   1. exact key set {address, name, tax_jurisdiction_country_code, tax_number};
 *   2. name is a non-empty string;
 *   3. tax_jurisdiction_country_code is ISO 3166-1 alpha-2;
 *   4. tax_number matches the per-country format for the jurisdiction
 *      (TN: 7-8 digits + 2 uppercase letters + 3 digits, `/` separators
 *      stripped before matching — Phase 1.5.2 rules, mirrored in
 *      `App\Shared\Domain\Validation\CountryTaxNumberRules`);
 *   5. address is a complete {city, country_code, postal_code, street} object.
 *
 * It never resolves the company / location the values came from, so a
 * terminal whose LOCATION is fiscally complete may sign with the branch
 * tax_id + branch address under the existing event_version=2 contract.
 *
 * Payload base = the device-authored SaleReceiptV2 golden fixture
 * (`tests/Fixtures/Fiscal/sale-receipt-v2-golden.json`) — the same source
 * `SaleReceiptV2GoldenParityTest` pins — with only the seller block swapped
 * for a branch identity that matches no company record.
 */
final class LocationSellerCoherenceTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FiscalPayloadConstraintValidator;
    }

    public function test_location_identity_seller_block_passes_canonical_validation(): void
    {
        $payload = $this->saleReceiptV2PayloadWithBranchSeller();

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 2,
        );

        // No throw == accepted.
        $this->addToAssertionCount(1);
    }

    public function test_tunisian_branch_tax_number_format_validates_against_tn(): void
    {
        // Realistic TN matricule fiscal (slashed entry format) — accepted.
        $payload = $this->saleReceiptV2PayloadWithBranchSeller();
        $payload['seller']['tax_number'] = '1234567/B/A/002';

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 2,
        );

        // Invalid-format value for TN — rejected, proving the per-country
        // format check is live (this test is not vacuous).
        $payload['seller']['tax_number'] = 'NOT-A-TN-TAX-NUMBER';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'payload_tax_number_format_mismatch:field=seller.tax_number:country=TN:value=NOT-A-TN-TAX-NUMBER'
        );
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 2,
        );
    }

    /**
     * Golden SaleReceiptV2 payload with the seller block replaced by a
     * BRANCH identity (branch-format TN tax number + branch address) that
     * deliberately matches no company record in any fixture or seeder.
     *
     * @return array<string, mixed>
     */
    private function saleReceiptV2PayloadWithBranchSeller(): array
    {
        /** @var array{expected_canonical_string: string} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/Fiscal/sale-receipt-v2-golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        $payload['seller'] = [
            'address' => [
                'city' => 'Tunis',
                'country_code' => 'TN',
                'postal_code' => '1053',
                'street' => '12 rue du Lac Victoria, Les Berges du Lac',
            ],
            'name' => 'Otospex — Succursale Lac 2',
            'tax_jurisdiction_country_code' => 'TN',
            'tax_number' => '7654321/Z/P/004',
        ];

        return $payload;
    }
}
