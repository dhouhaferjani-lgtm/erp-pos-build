<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\Vertical;

/**
 * Data Transfer Object for company configuration
 *
 * Represents the effective configuration for a company including
 * its vertical, default modules, enabled extras, and all enabled modules.
 */
class CompanyConfig implements \JsonSerializable
{
    /**
     * @param  array<int, string>  $defaultModules  Default modules for the vertical
     * @param  array<int, string>  $enabledExtras  Enabled optional modules (extras)
     * @param  array<int, string>  $allEnabledModules  All enabled modules (defaults + extras)
     */
    public function __construct(
        public readonly Vertical $vertical,
        public readonly array $defaultModules,
        public readonly array $enabledExtras,
        public readonly array $allEnabledModules,
    ) {}

    /**
     * Create instance from array
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            vertical: $data['vertical'],
            defaultModules: $data['default_modules'] ?? [],
            enabledExtras: $data['enabled_extras'] ?? [],
            allEnabledModules: $data['all_enabled_modules'] ?? [],
        );
    }

    /**
     * Check if a specific module is enabled
     */
    public function hasModule(string $module): bool
    {
        return in_array($module, $this->allEnabledModules, true);
    }

    /**
     * Convert to array representation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vertical' => $this->vertical->value,
            'default_modules' => $this->defaultModules,
            'enabled_extras' => $this->enabledExtras,
            'all_enabled_modules' => $this->allEnabledModules,
        ];
    }

    /**
     * Specify data which should be serialized to JSON
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
