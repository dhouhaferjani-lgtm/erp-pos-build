<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;

final class DeliveryNoteBillingArrayWriteFixture
{
    public function write(Document $deliveryNote): void
    {
        $deliveryNote->update(['invoiced_at' => '2026-08-18T10:00:00+00:00']);
    }
}
