<?php

declare(strict_types=1);

namespace App\Enums;

enum Product: string
{
    case IziPOS = 'izipos';
    case Otospex = 'otospex';

    /**
     * Get the human-readable label for the product
     */
    public function label(): string
    {
        return match ($this) {
            self::IziPOS => 'IziPOS',
            self::Otospex => 'Otospex',
        };
    }

    /**
     * Get the description of the product
     */
    public function description(): string
    {
        return match ($this) {
            self::IziPOS => 'All-in-one POS and ERP solution for retail and service businesses',
            self::Otospex => 'Specialized automotive service management platform',
        };
    }

    /**
     * Get the domains associated with this product
     *
     * @return array<int, string>
     */
    public function domains(): array
    {
        return match ($this) {
            self::IziPOS => ['izipos.com', 'app.izipos.com'],
            self::Otospex => ['otospex.com', 'app.otospex.com'],
        };
    }

    /**
     * Get all product labels as an associative array
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::IziPOS->value => self::IziPOS->label(),
            self::Otospex->value => self::Otospex->label(),
        ];
    }
}
