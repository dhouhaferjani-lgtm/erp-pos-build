<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Product;

/**
 * Service for managing the current product (IziPOS or Otospex)
 *
 * This service provides a centralized way to detect and work with
 * the current product based on the APP_PRODUCT environment variable.
 */
class ProductService
{
    /**
     * Get the current product
     */
    public function current(): Product
    {
        $productValue = config('app.product', 'izipos');

        // Handle null/empty config values
        if (empty($productValue)) {
            $productValue = 'izipos';
        }

        return Product::from($productValue);
    }

    /**
     * Check if the current product is IziPOS
     */
    public function isIziPOS(): bool
    {
        return $this->current() === Product::IziPOS;
    }

    /**
     * Check if the current product is Otospex
     */
    public function isOtospex(): bool
    {
        return $this->current() === Product::Otospex;
    }

    /**
     * Get the current product name (string value)
     */
    public function name(): string
    {
        return $this->current()->value;
    }

    /**
     * Get the current product label
     */
    public function label(): string
    {
        return $this->current()->label();
    }

    /**
     * Get the current product description
     */
    public function description(): string
    {
        return $this->current()->description();
    }

    /**
     * Get the current product domains
     *
     * @return array<int, string>
     */
    public function domains(): array
    {
        return $this->current()->domains();
    }
}
