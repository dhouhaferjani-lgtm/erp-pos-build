<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Indirection through a local: the rule matches on the RESOLVED TYPE, not on the
 * syntax of the expression, so a variable hop does not launder the write.
 */
final class DocumentStatusPaidViaVariableFixture
{
    public function write(Document $invoice): void
    {
        $target = DocumentStatus::Paid;
        $invoice->status = $target;
    }
}
