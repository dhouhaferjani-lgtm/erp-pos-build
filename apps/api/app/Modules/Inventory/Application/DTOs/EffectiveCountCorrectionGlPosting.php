<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

/**
 * Whether count-correction GL posting is ON for a company AND where that answer
 * came from (lane P-1, owner ruling 2026-08-25).
 *
 * Same shape and the same reasoning as {@see EffectiveValuationMode}: the chain
 * is company override -> country row -> system default, and an operator looking
 * at "enabled" cannot otherwise tell whether that is their own setting, their
 * jurisdiction's, or a fallback that fired because the country row is missing.
 * The settings surface renders both halves for exactly that reason.
 */
final readonly class EffectiveCountCorrectionGlPosting
{
    public const SOURCE_COMPANY = 'company';

    public const SOURCE_COUNTRY = 'country';

    public const SOURCE_SYSTEM = 'system';

    /**
     * @param  self::SOURCE_*  $source
     */
    public function __construct(
        public bool $enabled,
        public string $source,
    ) {}
}
