<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use App\Modules\SupportAccess\Domain\Enums\GrantType;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class GrantRequestData extends Data
{
    public function __construct(
        public ?string $tenant_id,
        public ?string $subject_user_id,
        public GrantType $type,
        public string $reason,
        public string $ticket_ref,
        public CarbonImmutable $starts_at,
        public CarbonImmutable $expires_at,
    ) {}
}
