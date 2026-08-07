<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use App\Modules\SupportAccess\Application\DTOs\ChainVerificationResultData;

final class SessionChainVerifier
{
    public function __construct(private readonly SessionChainHasher $hasher) {}

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function verify(array $events, ?string $expectedHead): ChainVerificationResultData
    {
        $previousHash = str_repeat('0', 64);
        $expectedSequence = 1;

        foreach ($events as $event) {
            $sequence = $expectedSequence;
            if (($event['sequence'] ?? null) !== $expectedSequence) {
                return new ChainVerificationResultData(false, $sequence, 'sequence_mismatch');
            }
            if (($event['previous_hash'] ?? null) !== $previousHash) {
                return new ChainVerificationResultData(false, $sequence, 'previous_hash_mismatch');
            }

            $calculated = $this->hasher->hash($event);
            $stored = $event['hash'] ?? null;
            if (! is_string($stored) || ! hash_equals($calculated, $stored)) {
                return new ChainVerificationResultData(false, $sequence, 'hash_mismatch');
            }

            $previousHash = $stored;
            $expectedSequence++;
        }

        if ($expectedHead !== null && ! hash_equals($expectedHead, $previousHash)) {
            return new ChainVerificationResultData(false, max(1, $expectedSequence - 1), 'head_mismatch');
        }

        return new ChainVerificationResultData(true, null, null);
    }
}
