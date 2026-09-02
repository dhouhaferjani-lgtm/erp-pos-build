<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum UnitTextMappingErrorCode: string
{
    case BlankRequiresPc = 'UNIT_TEXT_MAPPING_BLANK_REQUIRES_PC';
    case Conflict = 'UNIT_TEXT_MAPPING_CONFLICT';
    case TargetNotVisible = 'UNIT_TEXT_MAPPING_TARGET_NOT_VISIBLE';
}
