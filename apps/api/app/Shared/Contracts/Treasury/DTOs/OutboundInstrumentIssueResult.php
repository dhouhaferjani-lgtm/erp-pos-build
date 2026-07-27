<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

final readonly class OutboundInstrumentIssueResult
{
    public function __construct(
        public string $instrumentId,
        public string $journalEntryId,
        public string $repositoryId,
        public string $paymentMethodId,
        public bool $replayed,
    ) {}
}
