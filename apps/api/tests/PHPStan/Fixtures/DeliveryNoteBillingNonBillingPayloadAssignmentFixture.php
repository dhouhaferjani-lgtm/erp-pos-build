<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;

final class DeliveryNoteBillingNonBillingPayloadAssignmentFixture
{
    public function writeLiteral(Document $document): void
    {
        $document->payload = ['rfq_number' => 'RFQ-2026-0001'];
    }

    /** @param array<string, mixed> $payload */
    public function writeDynamic(Document $document, array $payload): void
    {
        $document->payload = $payload;
    }
}
