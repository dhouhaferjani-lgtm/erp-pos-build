<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * The form the WorkOrder precedent MISSES (TRIAGE #27): an `update([...])`
 * array write never touches a PropertyFetch node.
 */
final class DocumentStatusPaidUpdateArrayFixture
{
    public function write(Document $invoice): void
    {
        $invoice->update(['balance_due' => '0.000', 'status' => DocumentStatus::Paid]);
    }
}
