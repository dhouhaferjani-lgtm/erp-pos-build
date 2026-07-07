<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Services;

use App\Modules\DocumentIngestion\Application\Committers\SupplierDeliveryNoteCommitter;
use App\Modules\DocumentIngestion\Application\Contracts\IngestionCommitterInterface;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;

final readonly class IngestionCommitterRegistry
{
    /**
     * @var list<IngestionCommitterInterface>
     */
    private array $committers;

    public function __construct(SupplierDeliveryNoteCommitter $deliveryNoteCommitter)
    {
        $this->committers = [$deliveryNoteCommitter];
    }

    public function for(DocumentKind $kind): IngestionCommitterInterface
    {
        foreach ($this->committers as $committer) {
            if ($committer->supports($kind)) {
                return $committer;
            }
        }

        throw new \DomainException("No committer registered for document kind [{$kind->value}].");
    }
}
