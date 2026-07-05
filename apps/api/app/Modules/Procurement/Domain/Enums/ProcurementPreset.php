<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Enums;

enum ProcurementPreset: string
{
    case Complet = 'complet';
    case Standard = 'standard';
    case Leger = 'leger';

    /**
     * @return array<string, array{
     *     bill_control_mode: string,
     *     match_mode: string,
     *     match_enforcement: string,
     *     allow_receipt_first: bool,
     *     allow_invoice_first: bool
     * }>
     */
    public static function values(): array
    {
        return [
            self::Complet->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::ThreeWay->value,
                'match_enforcement' => MatchEnforcement::Block->value,
                'allow_receipt_first' => false,
                'allow_invoice_first' => false,
            ],
            self::Standard->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::ThreeWay->value,
                'match_enforcement' => MatchEnforcement::Warn->value,
                'allow_receipt_first' => true,
                'allow_invoice_first' => false,
            ],
            self::Leger->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::TwoWay->value,
                'match_enforcement' => MatchEnforcement::Warn->value,
                'allow_receipt_first' => true,
                'allow_invoice_first' => true,
            ],
        ];
    }

    /**
     * @return array{
     *     bill_control_mode: string,
     *     match_mode: string,
     *     match_enforcement: string,
     *     allow_receipt_first: bool,
     *     allow_invoice_first: bool
     * }
     */
    public function fields(): array
    {
        return self::values()[$this->value];
    }
}
