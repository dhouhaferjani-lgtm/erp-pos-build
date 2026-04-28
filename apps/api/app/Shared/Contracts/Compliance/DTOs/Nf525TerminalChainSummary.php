<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Per-terminal identity used by /verify-chains so Compliance can label rows
 * without ever importing the POS Terminal Eloquent model.
 */
final readonly class Nf525TerminalChainSummary
{
    public function __construct(
        public string $terminalId,
        public string $terminalCode,
        public string $terminalName,
    ) {}
}
