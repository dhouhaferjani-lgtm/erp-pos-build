<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Inventory;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Identity\Domain\User;

interface LinkedCostApplicatorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function apply(Document $expense, DocumentAdditionalCost $cost, User $user): array;

    /**
     * @return array<string, mixed>
     */
    public function reverse(Document $expense, DocumentAdditionalCost $originalCost, DocumentAdditionalCost $reversalCost, User $user): array;
}
