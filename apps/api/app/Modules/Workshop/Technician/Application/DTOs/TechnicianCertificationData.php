<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TechnicianCertificationData extends Data
{
    public function __construct(
        public string $id,
        public string $technician_profile_id,
        public string $certification_name,
        public ?string $issuing_body,
        public ?string $certificate_number,
        public ?string $issued_at,
        public ?string $expires_at,
        public ?string $notes,
        public string $created_at,
    ) {}

    public static function fromModel(TechnicianCertification $c): self
    {
        return new self(
            id: $c->id,
            technician_profile_id: $c->technician_profile_id,
            certification_name: $c->certification_name,
            issuing_body: $c->issuing_body,
            certificate_number: $c->certificate_number,
            issued_at: $c->issued_at?->toDateString(),
            expires_at: $c->expires_at?->toDateString(),
            notes: $c->notes,
            created_at: $c->created_at->toIso8601String(),
        );
    }
}
