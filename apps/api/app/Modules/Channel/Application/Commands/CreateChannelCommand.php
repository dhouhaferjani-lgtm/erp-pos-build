<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Commands;

final readonly class CreateChannelCommand
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $companyId,
        public string $name,
        public string $adapterType,
        public array $metadata = [],
    ) {}
}
