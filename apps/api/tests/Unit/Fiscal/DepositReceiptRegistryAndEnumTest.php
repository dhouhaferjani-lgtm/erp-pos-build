<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

final class DepositReceiptRegistryAndEnumTest extends TestCase
{
    public function test_registry_resolves_deposit_receipt_payload(): void
    {
        $registry = new FiscalEventPayloadRegistry;

        self::assertTrue($registry->isImplemented(FiscalEventType::DEPOSIT_RECEIPT));
        self::assertSame(DepositReceiptPayload::class, $registry->dtoClassFor(FiscalEventType::DEPOSIT_RECEIPT));
        self::assertSame(1, $registry->eventVersionFor(FiscalEventType::DEPOSIT_RECEIPT));
    }

    public function test_enum_marks_deposit_receipt_implemented_and_server_only(): void
    {
        self::assertTrue(FiscalEventType::DEPOSIT_RECEIPT->isImplemented());
        self::assertTrue(FiscalEventType::DEPOSIT_RECEIPT->isServerOnly());
    }
}
