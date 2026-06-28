<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Contracts\Loyalty;

use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SaleEarnContextTest extends TestCase
{
    public function test_it_holds_sale_earn_fields_as_given(): void
    {
        $postedAt = CarbonImmutable::parse('2026-06-27 10:00:00');
        $ctx = new SaleEarnContext(
            tenantId: 't-1',
            contactId: 'c-1',
            partnerId: null,
            currency: 'TND',
            sourceType: 'pos_receipt',
            sourceId: 'r-1',
            receiptNumber: 'POS-1',
            postedAt: $postedAt,
            earnBase: '12.000',
        );

        self::assertSame('t-1', $ctx->tenantId);
        self::assertSame('c-1', $ctx->contactId);
        self::assertNull($ctx->partnerId);
        self::assertSame('pos_receipt', $ctx->sourceType);
        self::assertSame('12.000', $ctx->earnBase);
        self::assertSame($postedAt, $ctx->postedAt);
    }
}
