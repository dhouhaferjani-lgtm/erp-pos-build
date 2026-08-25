<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

use Carbon\CarbonImmutable;

/**
 * Intent to seed a treasury repository's DAY-ONE opening float (W4-2).
 *
 * Authored by the ACCOUNTING opening-balance batch — the ONE sanctioned path.
 * The GL leg (Dr the repository's own cash/bank account / Cr Opening Balance
 * Equity) is posted by the batch; this intent carries the treasury half so both
 * land in the same transaction and the repository balance equals the GL debit.
 */
final readonly class OpeningFloatIntent
{
    /**
     * @param  string  $tenantId  UUID of the owning tenant
     * @param  string  $companyId  UUID of the owning company
     * @param  string  $repositoryId  UUID of the repository receiving the float
     * @param  numeric-string  $amount  positive decimal at currency scale (never float)
     * @param  string  $currency  ISO 4217 code, must match the repository's currency
     * @param  string  $batchId  UUID of the opening-balance batch — the justifying DOCUMENT
     *                           (document-per-action): it carries the cutover date, the row
     *                           the float came from, and a Draft→Validated→Locked lifecycle
     * @param  CarbonImmutable  $occurredAt  the batch cutover date
     * @param  ?string  $journalEntryId  UUID of the batch's historical opening entry
     * @param  ?string  $createdBy  UUID of the posting user
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $repositoryId,
        public string $amount,
        public string $currency,
        public string $batchId,
        public CarbonImmutable $occurredAt,
        public ?string $journalEntryId,
        public ?string $createdBy,
    ) {}

    /**
     * Leg discriminator for the treasury movement port. Keyed on the REPOSITORY
     * (not the row) so two rows in the same batch can never seed the same till
     * twice — the second one collides on the idempotency key instead of
     * doubling the float.
     */
    public function idempotencyLeg(): string
    {
        return "repository:{$this->repositoryId}";
    }
}
