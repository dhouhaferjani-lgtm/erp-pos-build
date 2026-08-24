<?php

declare(strict_types=1);

namespace App\Modules\Treasury\FixtureOnly;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Declared under `App\Modules\Treasury\…` on purpose: the second half of the
 * rule is namespace-scoped — Treasury may not decide that a document was posted.
 * The fixture lives in tests/ and is never autoloaded by the app.
 */
final class DocumentStatusTreasuryPostedFixture
{
    public function reopen(Document $document): void
    {
        $document->forceFill(['status' => DocumentStatus::Posted])->save();
    }
}
