<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Enums;

/**
 * Automotive workshop specialty codes.
 *
 * Used for skill matching in availability queries (Spec C §7.1 step 5).
 * Extend with care — every new case requires i18n translations and front-end
 * specialty chip rendering.
 */
enum SpecialtyCode: string
{
    case EngineMechanical = 'engine_mechanical';
    case EngineDiagnostic = 'engine_diagnostic';
    case Transmission = 'transmission';
    case Electrical = 'electrical';
    case Electronic = 'electronic';
    case Suspension = 'suspension';
    case Brakes = 'brakes';
    case AcClimate = 'ac_climate';
    case Tires = 'tires';
    case Alignment = 'alignment';
    case Bodywork = 'bodywork';
    case Paint = 'paint';
    case HybridEv = 'hybrid_ev';
    case Diesel = 'diesel';
    case PreControl = 'pre_control';
    case GeneralService = 'general_service';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
