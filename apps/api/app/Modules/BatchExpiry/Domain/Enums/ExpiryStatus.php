<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Enums;

enum ExpiryStatus: string
{
    case OK = 'ok';
    case APPROACHING = 'approaching';   // 90 days before expiry
    case WARNING = 'warning';           // 30 days before expiry
    case CRITICAL = 'critical';         // 7 days before expiry
    case EXPIRED = 'expired';

    public function color(): string
    {
        return match ($this) {
            self::OK => 'green',
            self::APPROACHING => 'yellow',
            self::WARNING => 'orange',
            self::CRITICAL => 'red',
            self::EXPIRED => 'gray',
        };
    }

    public function canSell(): bool
    {
        return $this !== self::EXPIRED;
    }

    public function label(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::APPROACHING => 'Approaching Expiry',
            self::WARNING => 'Warning',
            self::CRITICAL => 'Critical',
            self::EXPIRED => 'Expired',
        };
    }

    public function daysThreshold(): ?int
    {
        return match ($this) {
            self::CRITICAL => 7,
            self::WARNING => 30,
            self::APPROACHING => 90,
            self::OK, self::EXPIRED => null,
        };
    }
}
