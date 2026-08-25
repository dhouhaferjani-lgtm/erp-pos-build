<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Everything the rule must stay SILENT about:
 *  - a non-Paid, non-Posted status write from outside Treasury,
 *  - a `Posted` write from outside Treasury (posting is Document's own job),
 *  - a same-named property on a different class,
 *  - a status COMPARISON.
 */
final class DocumentStatusUnrelatedWritesFixture
{
    public function write(Document $document, DocumentLine $line): bool
    {
        $document->status = DocumentStatus::Confirmed;
        $document->update(['status' => DocumentStatus::Posted]);
        $line->status = 'pending';

        return $document->status === DocumentStatus::Paid;
    }
}
