<?php

declare(strict_types=1);

namespace App\Enums;

enum Vertical: string
{
    case Mechanic = 'mechanic';
    case Pharmacy = 'pharmacy';
    case Restaurant = 'restaurant';
    case CoffeeShop = 'coffee_shop';
    case Retail = 'retail';
    case Fashion = 'fashion';
    case BodyShop = 'body_shop';
    case PartsRetailer = 'parts_retailer';
    case CarGlass = 'car_glass';
    case TireShop = 'tire_shop';
    case ServiceStation = 'service_station';
    case Parapharmacy = 'parapharmacy';

    /**
     * Get the human-readable label for the vertical
     */
    public function label(): string
    {
        return match ($this) {
            self::Mechanic => 'Mechanic',
            self::Pharmacy => 'Pharmacy',
            self::Restaurant => 'Restaurant',
            self::CoffeeShop => 'Coffee Shop',
            self::Retail => 'Retail',
            self::Fashion => 'Fashion',
            self::BodyShop => 'Body Shop',
            self::PartsRetailer => 'Parts Retailer',
            self::CarGlass => 'Car Glass',
            self::TireShop => 'Tire Shop',
            self::ServiceStation => 'Service Station',
            self::Parapharmacy => 'Parapharmacy',
        };
    }

    /**
     * Get the description of the vertical
     */
    public function description(): string
    {
        return match ($this) {
            self::Mechanic => 'Automotive repair and maintenance services',
            self::Pharmacy => 'Pharmaceutical retail with prescription management',
            self::Restaurant => 'Full-service dining with table management',
            self::CoffeeShop => 'Coffee shop and quick-service cafe',
            self::Retail => 'General retail and merchandise',
            self::Fashion => 'Fashion retail and boutiques',
            self::BodyShop => 'Automotive body repair and painting',
            self::PartsRetailer => 'Automotive parts retail and wholesale',
            self::CarGlass => 'Automotive glass replacement and repair',
            self::TireShop => 'Tire sales and services',
            self::ServiceStation => 'Fuel station and quick services',
            self::Parapharmacy => 'Health and wellness retail',
        };
    }

    /**
     * Get the product this vertical belongs to
     */
    public function product(): string
    {
        return match ($this) {
            self::Mechanic,
            self::BodyShop,
            self::PartsRetailer,
            self::CarGlass,
            self::TireShop,
            self::ServiceStation => 'otospex',

            self::Pharmacy,
            self::Restaurant,
            self::CoffeeShop,
            self::Retail,
            self::Fashion,
            self::Parapharmacy => 'izipos',
        };
    }

    /**
     * Check if this vertical is an automotive (Otospex) vertical.
     */
    public function isAutomotive(): bool
    {
        return $this->product() === 'otospex';
    }

    /**
     * Get compatible optional modules (extras) for this vertical
     *
     * @return array<int, string>
     */
    public function compatibleExtras(): array
    {
        return match ($this) {
            self::Mechanic => ['Appointments', 'Fleet'],
            self::Pharmacy => ['BatchExpiry', 'Prescription', 'Ecommerce'],
            self::Restaurant => ['Tables', 'Reservation'],
            self::CoffeeShop => ['Tables', 'Loyalty'],
            self::Retail => ['Loyalty', 'Ecommerce'],
            self::Fashion => ['Loyalty', 'Ecommerce'],
            self::BodyShop => ['Appointments', 'Fleet'],
            self::PartsRetailer => ['Ecommerce'],
            self::CarGlass => ['Appointments', 'Fleet'],
            self::TireShop => ['Appointments'],
            self::ServiceStation => [],
            self::Parapharmacy => ['BatchExpiry', 'Loyalty', 'Ecommerce'],
        };
    }

    /**
     * Get default modules enabled for this vertical
     *
     * @return array<int, string>
     */
    public function defaultModules(): array
    {
        return match ($this) {
            self::Mechanic => ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'PlatformIntegration'],
            self::Pharmacy => ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'BatchExpiry'],
            self::Restaurant => ['Identity', 'Tenant', 'Catalog', 'Menu', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'Tables'],
            self::CoffeeShop => ['Identity', 'Tenant', 'Catalog', 'Menu', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
            self::Retail => ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
            self::Fashion => ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
            self::BodyShop => ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'PlatformIntegration'],
            self::PartsRetailer => ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'PlatformIntegration'],
            self::CarGlass => ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'PlatformIntegration'],
            self::TireShop => ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'PlatformIntegration'],
            self::ServiceStation => ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'PlatformIntegration'],
            self::Parapharmacy => ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'Parapharmacy'],
        };
    }

    /**
     * Get all vertical string values
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

    /**
     * Get all vertical labels as an associative array
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * Get the catalog scope filter for this vertical.
     * Used to filter platform catalog results to relevant product groups.
     *
     * @return string|null Scope identifier passed to platform API, null means full catalog
     */
    public function catalogScope(): ?string
    {
        return match ($this) {
            self::TireShop => 'tire_shop',
            self::CarGlass => 'car_glass',
            default => null,
        };
    }

    /**
     * Get the platform vertical alias for this vertical.
     * Maps ERP verticals to Syneriva platform VerticalAlias values.
     *
     * @return string|null Null means this vertical has no platform equivalent
     */
    public function platformVertical(): ?string
    {
        return match ($this) {
            self::Mechanic,
            self::BodyShop,
            self::PartsRetailer,
            self::CarGlass,
            self::TireShop,
            self::ServiceStation => 'automotive',
            self::Parapharmacy => 'parapharmacy',
            self::Pharmacy => 'pharmacy',
            default => null,
        };
    }

    /**
     * Get all verticals for a specific product
     *
     * @return array<int, self>
     */
    public static function forProduct(string $product): array
    {
        return array_filter(
            self::cases(),
            fn (self $vertical) => $vertical->product() === $product
        );
    }
}
