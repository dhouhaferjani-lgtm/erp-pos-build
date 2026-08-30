<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ColumnMappingData
{
    /** @param array<string, string> $mapping */
    public function __construct(public array $mapping) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $mapping = [];
        foreach ($payload as $source => $target) {
            if (is_string($target)) {
                $mapping[$source] = $target;
            }
        }

        return new self($mapping);
    }
}
