<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of the company header used in the NF525 JET XML <Entete>/<Societe>
 * block. Compliance no longer touches the Company Eloquent model directly.
 */
final readonly class Nf525CompanyHeaderData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $siret,
        public ?string $address,
    ) {}
}
