<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Ordinary Laravel, and the hole treasury gate r1 I-7 found: an Eloquent
 * BUILDER mass-update writes `documents.status` just as directly as the model
 * does, and the DB CHECK cannot stop it (`'paid'` is a legal VALUE).
 */
final class DocumentStatusPaidBuilderUpdateFixture
{
    public function write(string $id): void
    {
        Document::query()->whereKey($id)->update(['status' => DocumentStatus::Paid]);
    }
}
