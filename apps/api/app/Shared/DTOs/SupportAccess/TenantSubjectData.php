<?php

declare(strict_types=1);

namespace App\Shared\DTOs\SupportAccess;

use Spatie\LaravelData\Data;

final class TenantSubjectData extends Data
{
    public function __construct(
        public string $tenant_id,
        public string $subject_user_id,
    ) {}
}
