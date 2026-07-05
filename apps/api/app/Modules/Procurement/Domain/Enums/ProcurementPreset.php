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
     *     match_enforcement: string
     * }>
     */
    public static function values(): array
    {
        return [
            self::Complet->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::ThreeWay->value,
                'match_enforcement' => MatchEnforcement::Block->value,
            ],
            self::Standard->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::ThreeWay->value,
                'match_enforcement' => MatchEnforcement::Warn->value,
            ],
            self::Leger->value => [
                'bill_control_mode' => BillControlMode::Received->value,
                'match_mode' => MatchMode::TwoWay->value,
                'match_enforcement' => MatchEnforcement::Warn->value,
            ],
        ];
    }

    /**
     * @return array{
     *     bill_control_mode: string,
     *     match_mode: string,
     *     match_enforcement: string
     * }
     */
    public function fields(): array
    {
        return self::values()[$this->value];
    }
}
