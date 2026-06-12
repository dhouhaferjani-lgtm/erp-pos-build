<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical list of module names used by vertical configuration.
 *
 * Every name appearing in config/verticals.php (default_modules and
 * compatible_extras) must have a case here. The drift guard lives in
 * tests/Unit/Enums/ModuleNameTest.php.
 */
enum ModuleName: string
{
    case Identity = 'Identity';
    case Tenant = 'Tenant';
    case Catalog = 'Catalog';
    case Vehicle = 'Vehicle';
    case Partner = 'Partner';
    case Workshop = 'Workshop';
    case Sales = 'Sales';
    case Inventory = 'Inventory';
    case Treasury = 'Treasury';
    case Accounting = 'Accounting';
    case PlatformIntegration = 'PlatformIntegration';
    case BatchExpiry = 'BatchExpiry';
    case Menu = 'Menu';
    case Tables = 'Tables';
    case CompositeItems = 'CompositeItems';
    case Parapharmacy = 'Parapharmacy';
    case Appointments = 'Appointments';
    case Fleet = 'Fleet';
    case Prescription = 'Prescription';
    case Reservation = 'Reservation';
    case Loyalty = 'Loyalty';
    case Ecommerce = 'Ecommerce';

    /**
     * Get all module names as backing strings.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }
}
