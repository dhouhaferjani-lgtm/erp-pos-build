<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Middleware;

use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Application\Services\RequestImpersonationContext;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Services\EffectivePermissionService;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ImpersonationContext
{
    public function __construct(
        private readonly RequestImpersonationContext $context,
        private readonly EffectivePermissionService $permissions,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $token = $this->resolvePersonalAccessToken($user->currentAccessToken());
        if ($token === null) {
            return $next($request);
        }

        $claims = $this->claims($this->extractAbilities($token));
        if (! $claims['shaped']) {
            return $next($request);
        }

        if (! $claims['valid']) {
            return $this->ended();
        }

        try {
            $context = $this->loadContext(
                $user,
                $token,
                $claims['tenant_id'],
                $claims['operator_id'],
                $claims['session_id'],
            );
        } catch (Throwable) {
            return $this->ended();
        }

        if ($context === null) {
            return $this->ended();
        }

        $this->context->set($context);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    /**
     * @param  list<mixed>  $abilities
     * @return array{shaped: bool, valid: bool, tenant_id: string, operator_id: string, session_id: string}
     */
    private function claims(array $abilities): array
    {
        $tenant = $this->matching($abilities, 'tenant:');
        $operators = $this->matching($abilities, 'impersonation:');
        $sessions = $this->matching($abilities, 'impersonation-session:');
        $support = array_values(array_filter(
            $abilities,
            static fn (mixed $ability): bool => $ability === 'support:read' || $ability === 'support:write',
        ));
        $tenantId = $tenant[0] ?? '';
        $operatorId = $operators[0] ?? '';
        $sessionId = $sessions[0] ?? '';
        $shaped = $operators !== [] || $sessions !== [] || $support !== [];
        $valid = $shaped
            && count($tenant) === 1
            && count($operators) === 1
            && count($sessions) === 1
            && count($support) === 1
            && Str::isUuid($tenantId)
            && Str::isUuid($operatorId)
            && Str::isUuid($sessionId);

        return [
            'shaped' => $shaped,
            'valid' => $valid,
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'session_id' => $sessionId,
        ];
    }

    private function loadContext(
        User $user,
        PersonalAccessToken $token,
        string $tenantId,
        string $operatorId,
        string $sessionId,
    ): ?ImpersonationContextData {
        $session = ImpersonationSession::query()->find($sessionId);
        if ($session === null) {
            return null;
        }

        $grant = ImpersonationGrant::query()->find($session->grant_id);
        if ($grant === null || ! $this->isLive($session, $grant, $user, $token, $tenantId, $operatorId)) {
            return null;
        }

        $this->permissionRegistrar->setPermissionsTeamId($user->tenant_id);
        $level = $this->liveAccessLevel($session);
        $effective = $this->permissions->forUser($user, $level);
        $token->abilities = array_values(array_unique(array_merge([
            'tenant:'.$tenantId,
            'impersonation:'.$operatorId,
            'impersonation-session:'.$sessionId,
            $level === SessionAccessLevel::WriteElevated ? 'support:write' : 'support:read',
        ], array_map(static fn (string $permission): string => 'permission:'.$permission, $effective))));

        $session->update(['last_seen_at' => CarbonImmutable::now()]);

        return new ImpersonationContextData(
            operator_id: $operatorId,
            session_id: $sessionId,
            subject_user_id: $user->id,
            subject_name: $user->name,
            tenant_id: $tenantId,
            access_level: $level,
            reason: $grant->reason,
            ticket_ref: $grant->ticket_ref,
            expires_at: CarbonImmutable::instance($session->expires_at),
        );
    }

    private function isLive(
        ImpersonationSession $session,
        ImpersonationGrant $grant,
        User $user,
        PersonalAccessToken $token,
        string $tenantId,
        string $operatorId,
    ): bool {
        $now = CarbonImmutable::now();

        return $session->operator_id === $operatorId
            && $session->subject_user_id === $user->id
            && $session->tenant_id === $tenantId
            && $user->tenant_id === $tenantId
            && (int) $session->personal_access_token_id === (int) $token->getKey()
            && $session->ended_at === null
            && $session->expires_at->isAfter($now)
            && ($token->expires_at === null || $token->expires_at->isAfter($now))
            && $grant->status === GrantStatus::Active
            && $grant->revoked_at === null
            && ! $grant->starts_at->isAfter($now)
            && $grant->expires_at->isAfter($now)
            && $grant->tenant_id === $tenantId
            && ($grant->operator_id === null || $grant->operator_id === $operatorId)
            && ($grant->subject_user_id === null || $grant->subject_user_id === $user->id);
    }

    private function liveAccessLevel(ImpersonationSession $session): SessionAccessLevel
    {
        if ($session->access_level === SessionAccessLevel::WriteElevated
            && $session->write_expires_at !== null
            && $session->write_expires_at->isFuture()) {
            return SessionAccessLevel::WriteElevated;
        }

        return SessionAccessLevel::ReadOnly;
    }

    /**
     * @param  list<mixed>  $abilities
     * @return list<string>
     */
    private function matching(array $abilities, string $prefix): array
    {
        return array_values(array_map(
            static fn (string $ability): string => substr($ability, strlen($prefix)),
            array_filter(
                $abilities,
                static fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, $prefix),
            ),
        ));
    }

    private function resolvePersonalAccessToken(?HasAbilities $token): ?PersonalAccessToken
    {
        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** @return list<string> */
    private function extractAbilities(PersonalAccessToken $token): array
    {
        if (! is_array($token->abilities)) {
            return [];
        }

        return array_values(array_filter($token->abilities, is_string(...)));
    }

    private function ended(): JsonResponse
    {
        $this->context->clear();

        return response()->json([
            'error' => [
                'code' => 'IMPERSONATION_ENDED',
                'message' => 'This support-access session is no longer valid.',
            ],
        ], 401);
    }
}
