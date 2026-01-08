<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Certification;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductCertificationData extends Data
{
    public function __construct(
        public CertificationData $certification,
        public ?string $certification_code,
        public ?string $issued_date,
        public ?string $expiry_date,
        public ?string $verification_url,
        public ?string $notes,
    ) {}

    public static function fromPivot(Certification $certification, Pivot $pivot): self
    {
        return new self(
            certification: CertificationData::fromModel($certification),
            certification_code: $pivot->certification_code,
            issued_date: $pivot->issued_date?->toDateString(),
            expiry_date: $pivot->expiry_date?->toDateString(),
            verification_url: $pivot->verification_url,
            notes: $pivot->notes,
        );
    }
}
