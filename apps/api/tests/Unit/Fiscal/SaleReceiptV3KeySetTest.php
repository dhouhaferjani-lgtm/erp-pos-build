<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

/**
 * SALE_RECEIPT v3 key-drift gate (cash rounding, spec §4.4).
 *
 * Asserts the constants directly rather than regex-scraping the PHP source
 * (the device-side `FiscalPayloadKeyDrift.test.ts` gate still pins the v2
 * `PAYLOAD_KEYS['SALE_RECEIPT']` list against the TS mirror; that list is
 * deliberately NOT mutated here).
 */
final class SaleReceiptV3KeySetTest extends TestCase
{
    public function test_v3_key_set_has_thirty_keys(): void
    {
        $this->assertCount(30, FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3);
    }

    public function test_v3_key_set_is_lexicographically_sorted(): void
    {
        $keys = FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3;
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys, 'SALE_RECEIPT_PAYLOAD_KEYS_V3 must be lexicographically sorted');
    }

    public function test_v3_key_set_is_the_v2_set_plus_exactly_the_two_rounding_keys(): void
    {
        $v2 = FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'];
        $v3 = FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3;

        $this->assertCount(28, $v2);
        $this->assertSame(
            ['cash_rounding_adjustment', 'cash_rounding_denomination'],
            array_values(array_diff($v3, $v2)),
        );
        $this->assertSame([], array_values(array_diff($v2, $v3)));
    }

    public function test_the_two_new_keys_sort_between_buyer_and_cashier_id(): void
    {
        $keys = FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3;
        $buyer = array_search('buyer', $keys, true);
        $adj = array_search('cash_rounding_adjustment', $keys, true);
        $denom = array_search('cash_rounding_denomination', $keys, true);
        $cashierId = array_search('cashier_id', $keys, true);

        $this->assertSame($buyer + 1, $adj);
        $this->assertSame($adj + 1, $denom);
        $this->assertSame($denom + 1, $cashierId);
    }

    public function test_payload_keys_for_returns_v2_shape_for_versions_below_three(): void
    {
        $validator = new FiscalPayloadConstraintValidator;

        $this->assertSame(
            FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'],
            $validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 1),
        );
        $this->assertSame(
            FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'],
            $validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 2),
        );
        $this->assertSame(
            FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3,
            $validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 3),
        );
    }

    public function test_payload_keys_for_is_version_blind_for_other_event_types(): void
    {
        $validator = new FiscalPayloadConstraintValidator;

        $this->assertSame(
            FiscalPayloadConstraintValidator::PAYLOAD_KEYS['Z_REPORT'],
            $validator->payloadKeysFor(FiscalEventType::Z_REPORT, 3),
        );
        $this->assertNull($validator->payloadKeysFor(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST, 1));
    }

    public function test_registry_supports_versions_one_two_and_three_and_authors_three(): void
    {
        $registry = new FiscalEventPayloadRegistry;

        $this->assertSame([1, 2, 3], $registry->supportedVersionsFor(FiscalEventType::SALE_RECEIPT));
        $this->assertSame(3, $registry->eventVersionFor(FiscalEventType::SALE_RECEIPT));
    }
}
