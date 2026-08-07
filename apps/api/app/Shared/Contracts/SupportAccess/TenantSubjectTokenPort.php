<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

use App\Shared\DTOs\SupportAccess\MintedImpersonationTokenData;
use App\Shared\DTOs\SupportAccess\TenantSubjectData;
use Carbon\CarbonImmutable;

interface TenantSubjectTokenPort
{
    /** @return list<string> */
    public function permissionNames(TenantSubjectData $subject): array;

    public function displayName(TenantSubjectData $subject): string;

    /** @param list<string> $abilities */
    public function mint(
        TenantSubjectData $subject,
        string $name,
        array $abilities,
        CarbonImmutable $expiresAt,
    ): MintedImpersonationTokenData;

    public function elevateForWrite(int $personalAccessTokenId): void;

    public function revoke(int $personalAccessTokenId): void;
}
