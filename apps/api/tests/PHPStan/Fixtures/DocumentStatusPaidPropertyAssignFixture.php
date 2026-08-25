<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;

final class DocumentStatusPaidPropertyAssignFixture
{
    public function write(Document $invoice): void
    {
        $invoice->status = DocumentStatus::Paid;
    }
}
