<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationElevation;
use App\Modules\SupportAccess\Domain\Enums\ElevationStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ElevationData extends Data
{
    public function __construct(
        public string $id,
        public string $session_id,
        public ElevationStatus $status,
        public string $reason,
        public CarbonInterface $requested_at,
    ) {}

    public static function fromModel(ImpersonationElevation $elevation): self
    {
        return new self(
            id: $elevation->id,
            session_id: $elevation->session_id,
            status: $elevation->status,
            reason: $elevation->reason,
            requested_at: $elevation->requested_at,
        );
    }
}
