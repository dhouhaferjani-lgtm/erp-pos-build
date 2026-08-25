<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;
use JsonSerializable;

/**
 * The SET of document types the fiscal authority applies to, for one country
 * (SPEC §2.3 applicability matrix, F-108 / OQ-53).
 *
 * Backs the JSON column `country_document_settings.fiscal_authority_types`. It is
 * a DTO and not a bare `array` because rule 3 forbids a JSON column without one:
 * an untyped array lets `['Invoice']`, `['facture']` and `[null]` all reach
 * storage, and the first reader to `DocumentType::from()` them blows up in a
 * queued worker rather than at the write.
 *
 * ── WHY A SET, CANONICALLY ORDERED ──
 * `{invoice, credit_note}` and `{credit_note, invoice}` are the same policy. This
 * value object canonicalises to `DocumentType::cases()` order and deduplicates, so
 * two rows that mean the same thing are equal byte-for-byte in the database —
 * which is what lets the country-parity test of C-QR0b be a comparison rather than
 * a set intersection.
 *
 * TN seeds `{invoice, credit_note}`: DeliveryNote and ReturnNote seal on
 * `draft→confirmed`, outside the authority's applicability, and initialise to
 * `not_required` regardless of the country mode.
 *
 * ── UNACTIVATED IN THIS LANE (C-QR0a) ──
 * Nothing constructs this outside the Eloquent cast and its tests. The seeder, the
 * resolver and the posting guard are C-QR0b.
 */
final class FiscalAuthorityTypes implements Castable, JsonSerializable
{
    /**
     * @param  list<DocumentType>  $types  canonically ordered, deduplicated
     */
    private function __construct(public readonly array $types) {}

    /**
     * The empty set — "the authority applies to no type here". A POLICY, not an
     * absence: a country with no row at all is NULL, and C-QR0b refuses it.
     */
    public static function none(): self
    {
        return new self([]);
    }

    public static function of(DocumentType ...$types): self
    {
        // `array_values` is not decorative: PHP 8 named arguments can give a variadic
        // string keys, so the spread is not guaranteed to be a list.
        return new self(self::canonicalise(array_values($types)));
    }

    /**
     * @param  iterable<mixed>  $values  raw JSON list of `DocumentType` values
     *
     * @throws InvalidArgumentException on anything that is not a DocumentType value
     */
    public static function fromArray(iterable $values): self
    {
        $types = [];

        foreach ($values as $value) {
            if ($value instanceof DocumentType) {
                $types[] = $value;

                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'fiscal_authority_types entries must be DocumentType values; got %s.',
                    get_debug_type($value),
                ));
            }

            $type = DocumentType::tryFrom($value);

            if ($type === null) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is not a DocumentType value; fiscal_authority_types admits only document types.',
                    $value,
                ));
            }

            $types[] = $type;
        }

        return new self(self::canonicalise($types));
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_map(static fn (DocumentType $type): string => $type->value, $this->types);
    }

    public function contains(DocumentType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function isEmpty(): bool
    {
        return $this->types === [];
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The cast lives in Domain next to this value object: the model that carries it
     * is a Domain class and deptrac forbids Domain reaching into Infrastructure, so
     * a cast under `Infrastructure/Casts` would be a boundary violation.
     *
     * @param  array<int, string>  $arguments
     * @return CastsAttributes<FiscalAuthorityTypes, FiscalAuthorityTypes|iterable<mixed>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new FiscalAuthorityTypesCast;
    }

    /**
     * @param  list<DocumentType>  $types
     * @return list<DocumentType>
     */
    private static function canonicalise(array $types): array
    {
        return array_values(array_filter(
            DocumentType::cases(),
            static fn (DocumentType $case): bool => in_array($case, $types, true),
        ));
    }
}
