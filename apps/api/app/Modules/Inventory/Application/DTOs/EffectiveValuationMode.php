<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;

/**
 * The resolved inventory valuation mode AND where it came from (DPA Wave 3, T9).
 *
 * Carrying the source is not decoration. The chain is
 * company override -> country row -> system default, and an operator staring at
 * "perpetual" cannot otherwise tell whether that is their own setting, their
 * jurisdiction's, or a fallback that fired because the country row is missing.
 * The read-only settings surface (T10) renders it for exactly that reason.
 *
 * Shape mirrors `PaymentToleranceService::getToleranceSettings()`'s `source`
 * key, deliberately: one resolver idiom, not two.
 */
final readonly class EffectiveValuationMode
{
    public const SOURCE_COMPANY = 'company';

    public const SOURCE_COUNTRY = 'country';

    public const SOURCE_SYSTEM = 'system';

    /**
     * @param  self::SOURCE_*  $source
     */
    public function __construct(
        public InventoryValuationMode $mode,
        public string $source,
    ) {}

    public function isSupported(): bool
    {
        return $this->mode->isSupported();
    }
}
