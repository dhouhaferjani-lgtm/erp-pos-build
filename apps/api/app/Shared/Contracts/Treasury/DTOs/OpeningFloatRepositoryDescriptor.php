<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

/**
 * Read-only view of a payment repository, published across the module boundary
 * so the Accounting opening-balance wizard can VALIDATE a `repository_code`
 * column without importing Treasury domain models (CLAUDE.md rule 6).
 */
final readonly class OpeningFloatRepositoryDescriptor
{
    /**
     * @param  string  $id  UUID of the repository
     * @param  string  $code  operator-facing repository code (the CSV column's value)
     * @param  string  $name  display name
     * @param  string  $currency  ISO 4217 code
     * @param  ?string  $glAccountId  UUID of the repository's own cash/bank GL account, if linked
     * @param  bool  $hasMovements  true when treasury already recorded money on this repository —
     *                              an opening float is then no longer honest and must be refused
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $currency,
        public ?string $glAccountId,
        public bool $hasMovements,
    ) {}
}
