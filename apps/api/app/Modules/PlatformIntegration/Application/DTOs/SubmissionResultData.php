<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SubmissionResultData extends Data
{
    public function __construct(
        public string $trackingId,
        public string $status,
        public string $statusUrl,
    ) {}

    /**
     * @param array<string, mixed> $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            trackingId: (string) ($response['tracking_id'] ?? ''),
            status: (string) ($response['status'] ?? ''),
            statusUrl: (string) ($response['status_url'] ?? ''),
        );
    }
}
