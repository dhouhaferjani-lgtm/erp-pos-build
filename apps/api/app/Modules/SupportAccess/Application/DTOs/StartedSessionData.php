<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class StartedSessionData extends Data
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $session_id,
        public string $subject_name,
        public string $plain_text_token,
        public int $personal_access_token_id,
        public CarbonImmutable $expires_at,
        public array $permissions,
    ) {}
}
