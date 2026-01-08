<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Vertical;

/**
 * Service for managing vertical configurations
 *
 * This service provides access to vertical-specific configurations
 * including labels, descriptions, compatible extras, and default modules.
 */
class VerticalConfigService
{
    /**
     * Get complete configuration for a vertical
     *
     * @return array<string, mixed>
     */
    public function getVerticalConfig(Vertical $vertical): array
    {
        $config = config("verticals.{$vertical->value}");

        if (! is_array($config)) {
            throw new \RuntimeException("Configuration not found for vertical: {$vertical->value}");
        }

        return $config;
    }

    /**
     * Get the human-readable label for a vertical
     */
    public function getLabel(Vertical $vertical): string
    {
        $config = $this->getVerticalConfig($vertical);

        return (string) ($config['label'] ?? $vertical->value);
    }

    /**
     * Get the description for a vertical
     */
    public function getDescription(Vertical $vertical): string
    {
        $config = $this->getVerticalConfig($vertical);

        return (string) ($config['description'] ?? '');
    }

    /**
     * Get the product (izipos or otospex) for a vertical
     */
    public function getProduct(Vertical $vertical): string
    {
        $config = $this->getVerticalConfig($vertical);

        return (string) ($config['product'] ?? 'izipos');
    }

    /**
     * Get compatible extras (optional modules) for a vertical
     *
     * @return array<int, string>
     */
    public function getCompatibleExtras(Vertical $vertical): array
    {
        $config = $this->getVerticalConfig($vertical);

        return (array) ($config['compatible_extras'] ?? []);
    }

    /**
     * Get default modules for a vertical
     *
     * @return array<int, string>
     */
    public function getDefaultModules(Vertical $vertical): array
    {
        $config = $this->getVerticalConfig($vertical);

        return (array) ($config['default_modules'] ?? []);
    }

    /**
     * Get all verticals for a specific product
     *
     * @return array<int, Vertical>
     */
    public function getVerticalsForProduct(string $product): array
    {
        return array_filter(
            Vertical::cases(),
            fn (Vertical $vertical): bool => $this->getProduct($vertical) === $product
        );
    }
}
