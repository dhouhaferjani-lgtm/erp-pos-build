<?php

declare(strict_types=1);

namespace App\Shared\DTOs\SupportAccess;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class MintedImpersonationTokenData extends Data
{
    public function __construct(
        public string $plain_text_token,
        public int $personal_access_token_id,
        public CarbonImmutable $expires_at,
    ) {}
}
