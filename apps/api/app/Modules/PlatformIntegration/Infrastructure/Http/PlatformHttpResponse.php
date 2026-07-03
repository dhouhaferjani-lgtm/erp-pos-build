<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure\Http;

/**
 * Status-aware platform response for callers that must branch on HTTP status.
 * postRaw() flattens every non-success to null, which cannot distinguish
 * terminal 4xx reconciliation outcomes from retryable transport/platform errors.
 */
final readonly class PlatformHttpResponse
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        public int $status,
        public ?array $body,
    ) {}
}
