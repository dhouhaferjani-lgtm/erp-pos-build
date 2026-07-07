<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReconciliationData extends Data
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public bool $consistent,
        public array $flags,
    ) {}

    /**
     * @return array{consistent: bool, flags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'consistent' => $this->consistent,
            'flags' => $this->flags,
        ];
    }
}
