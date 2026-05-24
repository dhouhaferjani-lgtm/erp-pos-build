<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\DTOs;

final readonly class ConnectionTestResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $successful,
        public string $message,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function success(string $message, array $metadata = []): self
    {
        return new self(true, $message, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failure(string $message, array $metadata = []): self
    {
        return new self(false, $message, $metadata);
    }
}
