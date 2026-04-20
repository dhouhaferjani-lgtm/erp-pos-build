<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Origin of an Appointment. `Online` implies the storefront CAPTCHA-gated
 * public endpoint; the other three are operator-authored.
 */
enum AppointmentSource: string
{
    case Manual = 'manual';
    case Phone = 'phone';
    case Online = 'online';
    case Walkin = 'walkin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
