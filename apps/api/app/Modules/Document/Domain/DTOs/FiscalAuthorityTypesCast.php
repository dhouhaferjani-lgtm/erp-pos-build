<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

use App\Modules\Document\Domain\CountryDocumentSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for {@see FiscalAuthorityTypes} — the JSON column
 * `country_document_settings.fiscal_authority_types`.
 *
 * Lives in Domain, next to the value object it serves, because the model that
 * declares it ({@see CountryDocumentSettings}) is a
 * Domain class and deptrac forbids Domain → Infrastructure.
 *
 * NULL is preserved in both directions: in this lane (C-QR0a) the column is NULL on
 * every row and stays that way until C-QR0b seeds it, so "no value" must never
 * decay into "the empty set" — an empty set says the authority applies to NO type,
 * which is a policy, and F-112 forbids inventing one.
 *
 * @implements CastsAttributes<FiscalAuthorityTypes, FiscalAuthorityTypes|iterable<mixed>>
 */
final class FiscalAuthorityTypesCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?FiscalAuthorityTypes
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof FiscalAuthorityTypes) {
            return $value;
        }

        $decoded = is_string($value)
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(sprintf(
                '%s.%s must hold a JSON list of DocumentType values; got %s.',
                $model->getTable(),
                $key,
                get_debug_type($decoded),
            ));
        }

        return FiscalAuthorityTypes::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $types = $value instanceof FiscalAuthorityTypes
            ? $value
            : FiscalAuthorityTypes::fromArray($value);

        return json_encode($types->toArray(), JSON_THROW_ON_ERROR);
    }
}
