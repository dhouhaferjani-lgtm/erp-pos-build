<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * Types of transactions subject to withholding tax.
 *
 * Used to match against withholding tax rules for rate determination.
 */
enum TransactionType: string
{
    case SERVICES = 'services';
    case GOODS = 'goods';
    case RENTAL = 'rental';
    case RENTAL_HOTEL = 'rental_hotel';
    case COMMISSION = 'commission';
    case EXPORT_SERVICES = 'export_services';

    public function label(): string
    {
        return match ($this) {
            self::SERVICES => 'Professional Services',
            self::GOODS => 'Goods',
            self::RENTAL => 'Rental',
            self::RENTAL_HOTEL => 'Hotel Rental',
            self::COMMISSION => 'Commission / Brokerage',
            self::EXPORT_SERVICES => 'Export-Related Services',
        };
    }

    public function labelFr(): string
    {
        return match ($this) {
            self::SERVICES => 'Services Professionnels',
            self::GOODS => 'Biens',
            self::RENTAL => 'Location',
            self::RENTAL_HOTEL => 'Location Hôtelière',
            self::COMMISSION => 'Commission / Courtage',
            self::EXPORT_SERVICES => 'Services liés à l\'Export',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SERVICES => 'Payments for professional services (consulting, accounting, legal, etc.)',
            self::GOODS => 'Payments for purchase of goods or merchandise',
            self::RENTAL => 'Rental payments for offices, equipment, etc.',
            self::RENTAL_HOTEL => 'Hotel or accommodation rental payments',
            self::COMMISSION => 'Commission, brokerage fees, or intermediary payments',
            self::EXPORT_SERVICES => 'Services or commissions related to export activities',
        };
    }
}
