<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

abstract readonly class Phase4SimplePayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    final public function __construct(private array $data) {}

    /**
     * @param  array<string, mixed>  $data
     */
    final public static function fromArray(array $data): static
    {
        foreach (static::payloadKeys() as $key) {
            FiscalPayloadArrayGuards::assertPresent($data, $key);
        }

        return new static($data);
    }

    /**
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return list<string>
     */
    abstract public static function payloadKeys(): array;
}
