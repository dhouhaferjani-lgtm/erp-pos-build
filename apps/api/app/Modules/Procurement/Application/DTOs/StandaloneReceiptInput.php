<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application\DTOs;

final readonly class StandaloneReceiptInput
{
    /**
     * @param  list<StandaloneReceiptLineInput>  $lines
     */
    public function __construct(
        public string $companyId,
        public string $supplierId,
        public string $locationId,
        public string $actorId,
        public string $idempotencyKey,
        public string $source,
        public ?string $externalReference,
        public ?string $externalDate,
        public bool $postImmediately,
        public array $lines,
    ) {}
}
