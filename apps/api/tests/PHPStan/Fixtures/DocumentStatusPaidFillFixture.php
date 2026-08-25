<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * `fill()` — the fourth advertised write form, previously advertised but not
 * pinned (fiscal gate r1 F-11).
 */
final class DocumentStatusPaidFillFixture
{
    public function write(Document $invoice): void
    {
        $invoice->fill(['status' => DocumentStatus::Paid])->save();
    }
}
