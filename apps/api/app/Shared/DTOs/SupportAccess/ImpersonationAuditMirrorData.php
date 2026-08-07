<?php

declare(strict_types=1);

namespace App\Shared\DTOs\SupportAccess;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ImpersonationAuditMirrorData extends Data
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $event_id,
        public string $session_id,
        public int $sequence,
        public string $previous_hash,
        public string $hash,
        public string $event_type,
        public string $outcome,
        public string $operator_id,
        public string $subject_user_id,
        public string $tenant_id,
        public ?string $request_id,
        public ?string $http_method,
        public ?string $path,
        public array $details,
        public CarbonImmutable $occurred_at,
    ) {}

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'version' => 1,
            'event_id' => $this->event_id,
            'session_id' => $this->session_id,
            'sequence' => $this->sequence,
            'previous_hash' => $this->previous_hash,
            'event_type' => $this->event_type,
            'outcome' => $this->outcome,
            'operator_id' => $this->operator_id,
            'subject_user_id' => $this->subject_user_id,
            'tenant_id' => $this->tenant_id,
            'request_id' => $this->request_id,
            'http_method' => $this->http_method,
            'path' => $this->path,
            'details' => $this->details,
            'occurred_at' => $this->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
