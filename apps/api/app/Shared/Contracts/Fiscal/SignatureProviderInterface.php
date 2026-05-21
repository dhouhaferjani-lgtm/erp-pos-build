<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Fiscal;

interface SignatureProviderInterface
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{status: 'pending'|'signed'|'failed', signature?: string, transaction_id?: string, reason?: string}
     */
    public function sign(string $canonicalBytes, array $context = []): array;

    public function requiresConnectivity(): bool;

    public function signsSynchronously(): bool;

    public function assignsTransactionId(): bool;
}
