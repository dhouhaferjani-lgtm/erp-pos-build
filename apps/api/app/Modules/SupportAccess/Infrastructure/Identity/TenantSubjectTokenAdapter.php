<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Infrastructure\Identity;

use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\SupportAccess\TenantSubjectTokenPort;
use App\Shared\DTOs\SupportAccess\MintedImpersonationTokenData;
use App\Shared\DTOs\SupportAccess\TenantSubjectData;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Spatie\Permission\PermissionRegistrar;

final class TenantSubjectTokenAdapter implements TenantSubjectTokenPort
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function permissionNames(TenantSubjectData $subject): array
    {
        return $this->withSubject($subject, static function (User $user): array {
            /** @var list<string> $names */
            $names = $user->getUnfilteredPermissionsForSupportAccess()
                ->pluck('name')
                ->filter(static fn (mixed $name): bool => is_string($name))
                ->values()
                ->all();

            return $names;
        });
    }

    public function displayName(TenantSubjectData $subject): string
    {
        return $this->withSubject($subject, static fn (User $user): string => $user->name);
    }

    public function mint(
        TenantSubjectData $subject,
        string $name,
        array $abilities,
        CarbonImmutable $expiresAt,
    ): MintedImpersonationTokenData {
        return $this->withSubject($subject, static function (User $user) use ($name, $abilities, $expiresAt): MintedImpersonationTokenData {
            $token = $user->createToken($name, $abilities, $expiresAt);

            return new MintedImpersonationTokenData(
                plain_text_token: $token->plainTextToken,
                personal_access_token_id: (int) $token->accessToken->getKey(),
                expires_at: $expiresAt,
            );
        });
    }

    public function elevateForWrite(int $personalAccessTokenId): void
    {
        $token = CentralPersonalAccessToken::query()->findOrFail($personalAccessTokenId);
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        $abilities = array_values(array_filter(
            $abilities,
            static fn (mixed $ability): bool => $ability !== 'support:read' && $ability !== 'support:write',
        ));
        $abilities[] = 'support:write';
        $token->update(['abilities' => array_values(array_unique($abilities))]);
    }

    public function revoke(int $personalAccessTokenId): void
    {
        CentralPersonalAccessToken::query()->whereKey($personalAccessTokenId)->delete();
    }

    /**
     * @template TResult
     *
     * @param  Closure(User): TResult  $callback
     * @return TResult
     */
    private function withSubject(TenantSubjectData $subject, Closure $callback): mixed
    {
        $tenant = Tenant::query()->find($subject->tenant_id);
        if ($tenant === null) {
            throw new AuthorizationException('Support subject tenant is unavailable.');
        }

        $resolve = function () use ($subject, $callback): mixed {
            $previousTeamId = $this->permissions->getPermissionsTeamId();
            $this->permissions->setPermissionsTeamId($subject->tenant_id);

            try {
                $user = User::query()
                    ->whereKey($subject->subject_user_id)
                    ->where('tenant_id', $subject->tenant_id)
                    ->first();

                if ($user === null || ! $user->isActive()) {
                    throw new AuthorizationException('Support subject is unavailable or inactive.');
                }

                return $callback($user);
            } finally {
                $this->permissions->setPermissionsTeamId($previousTeamId);
            }
        };

        $central = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );

        if (! (bool) $this->config->get('tenancy_resolver.db_per_tenant', false)
            && $central->getSchemaBuilder()->hasTable('users')) {
            return $resolve();
        }

        return $tenant->run($resolve);
    }
}
