<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class UnitCatalogEntryData
{
    /**
     * @param  'system'|'tenant'|'company'  $tier
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $symbol,
        public int $decimalPlaces,
        public string $tier,
        public string $category,
    ) {}

    /**
     * @param  list<self>  $visible
     * @return list<self>
     */
    public static function exactCodeMatchesAtWinningTier(array $visible, string $code): array
    {
        $matches = array_values(array_filter(
            $visible,
            static fn (self $unit): bool => $unit->code === $code,
        ));
        if ($matches === []) {
            return [];
        }

        $winningRank = min(array_map(static fn (self $unit): int => self::tierRank($unit->tier), $matches));

        return array_values(array_filter(
            $matches,
            static fn (self $unit): bool => self::tierRank($unit->tier) === $winningRank,
        ));
    }

    /** @param 'system'|'tenant'|'company' $tier */
    public static function tierRank(string $tier): int
    {
        return match ($tier) {
            'company' => 0,
            'tenant' => 1,
            'system' => 2,
        };
    }
}
