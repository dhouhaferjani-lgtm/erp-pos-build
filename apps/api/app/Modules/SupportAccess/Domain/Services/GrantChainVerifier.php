<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use App\Modules\SupportAccess\Domain\DTOs\ChainVerificationResultData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrantEvent;
use Illuminate\Support\Collection;

final class GrantChainVerifier
{
    public function __construct(private readonly SessionChainVerifier $verifier) {}

    /** @param Collection<int, ImpersonationGrantEvent> $events */
    public function verify(Collection $events, ?string $expectedHead): ChainVerificationResultData
    {
        $payloads = [];
        foreach ($events as $event) {
            $payloads[] = [
                'version' => 1,
                'event_id' => $event->id,
                'grant_id' => $event->grant_id,
                'sequence' => $event->sequence,
                'previous_hash' => $event->previous_hash,
                'event_type' => $event->event_type->value,
                'outcome' => $event->outcome->value,
                'operator_id' => $event->operator_id,
                'subject_user_id' => $event->subject_user_id,
                'tenant_id' => $event->tenant_id,
                'actor_id' => $event->actor_id,
                'actor_type' => $event->actor_type,
                'details' => $event->details->toArray(),
                'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'hash' => $event->hash,
            ];
        }

        return $this->verifier->verify($payloads, $expectedHead);
    }
}
