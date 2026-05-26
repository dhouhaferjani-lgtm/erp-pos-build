<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\DTOs;

final readonly class SyncResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $successful,
        public string $externalId,
        public ?string $message = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function success(string $externalId, ?string $message = null, array $metadata = []): self
    {
        return new self(true, $externalId, $message, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failure(string $message, array $metadata = []): self
    {
        return new self(false, '', $message, $metadata);
    }
}
