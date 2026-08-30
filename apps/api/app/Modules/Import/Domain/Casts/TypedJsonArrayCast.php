<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<array<array-key, mixed>|null, mixed>
 */
abstract class TypedJsonArrayCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $this->normalize($decoded) : $this->normalize([]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $normalized = is_array($decoded) ? $this->normalize($decoded) : $this->normalize([]);

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    abstract protected function normalize(array $payload): array;
}
