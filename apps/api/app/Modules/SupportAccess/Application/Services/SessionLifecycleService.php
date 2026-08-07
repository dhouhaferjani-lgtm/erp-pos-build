<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\SupportAccess\Application\DTOs\StartedSessionData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionPermission;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Enums\SessionEndReason;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Repositories\ImpersonationGrantRepository;
use App\Modules\SupportAccess\Domain\Services\EffectivePermissionService;
use App\Shared\Contracts\SupportAccess\TenantSubjectTokenPort;
use App\Shared\DTOs\SupportAccess\TenantSubjectData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

final class SessionLifecycleService
{
    public function __construct(
        private readonly ImpersonationGrantRepository $grants,
        private readonly TenantSubjectTokenPort $tokens,
        private readonly EffectivePermissionService $permissions,
        private readonly Repository $config,
        private readonly DatabaseManager $database,
        private readonly SessionAuditService $audit,
    ) {}

    public function start(SuperAdmin $operator, string $grantId, string $subjectUserId): StartedSessionData
    {
        if (! $operator->is_active) {
            throw new AuthorizationException('Inactive operators cannot start support sessions.');
        }

        $started = null;

        try {
            $this->grants->mutateLocked($grantId, function (ImpersonationGrant $grant) use ($operator, $subjectUserId, &$started): void {
                $this->authorizeStart($grant, $operator, $subjectUserId);

                $subject = new TenantSubjectData($grant->tenant_id, $subjectUserId);
                $effective = $this->permissions->intersect(
                    $this->tokens->permissionNames($subject),
                    SessionAccessLevel::ReadOnly,
                );
                $subjectName = $this->tokens->displayName($subject);
                $expiresAt = $this->sessionExpiry($grant);

                $session = ImpersonationSession::query()->create([
                    'grant_id' => $grant->id,
                    'operator_id' => $operator->id,
                    'subject_user_id' => $subjectUserId,
                    'tenant_id' => $grant->tenant_id,
                    'access_level' => SessionAccessLevel::ReadOnly,
                    'started_at' => CarbonImmutable::now(),
                    'expires_at' => $expiresAt,
                    'chain_sequence' => 0,
                ]);

                $abilities = array_values(array_unique(array_merge([
                    'tenant:'.$grant->tenant_id,
                    'impersonation:'.$operator->id,
                    'impersonation-session:'.$session->id,
                    'support:read',
                ], array_map(static fn (string $permission): string => 'permission:'.$permission, $effective))));

                $minted = $this->tokens->mint(
                    $subject,
                    'support-impersonation:'.$session->id,
                    $abilities,
                    $expiresAt,
                );

                $session->update(['personal_access_token_id' => $minted->personal_access_token_id]);
                foreach ($effective as $permission) {
                    ImpersonationSessionPermission::query()->create([
                        'session_id' => $session->id,
                        'permission' => $permission,
                    ]);
                }

                $this->audit->recordLifecycleEvent(
                    $session,
                    SessionEventType::GrantRequested,
                    CarbonImmutable::instance($grant->requested_at),
                    'ImpersonationGrant',
                    $grant->id,
                    $grant->ticket_ref,
                );
                $approvedAt = $grant->second_approved_at ?? $grant->tenant_approved_at;
                if ($approvedAt !== null) {
                    $this->audit->recordLifecycleEvent(
                        $session,
                        SessionEventType::GrantApproved,
                        CarbonImmutable::instance($approvedAt),
                        'ImpersonationGrant',
                        $grant->id,
                        $grant->ticket_ref,
                    );
                }
                $this->audit->recordLifecycleEvent(
                    $session,
                    SessionEventType::SessionStarted,
                    CarbonImmutable::instance($session->started_at),
                    'ImpersonationSession',
                    $session->id,
                    $grant->ticket_ref,
                );

                $started = new StartedSessionData(
                    session_id: $session->id,
                    subject_name: $subjectName,
                    plain_text_token: $minted->plain_text_token,
                    personal_access_token_id: $minted->personal_access_token_id,
                    expires_at: $expiresAt,
                    permissions: $effective,
                );
            });
        } catch (ModelNotFoundException) {
            throw new AuthorizationException('No usable support grant was found.');
        }

        if (! $started instanceof StartedSessionData) {
            throw new LogicException('Session start did not produce a token.');
        }

        return $started;
    }

    /** @return list<string> */
    public function effectivePermissionsFor(User $subject): array
    {
        return $this->permissions->forUser($subject, SessionAccessLevel::ReadOnly);
    }

    public function exit(User $subject, string $sessionId): void
    {
        $token = $subject->currentAccessToken();
        if (! $token instanceof CentralPersonalAccessToken) {
            throw new AuthorizationException('An impersonation token is required to exit.');
        }

        $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        )->transaction(function () use ($subject, $sessionId, $token): void {
            $session = ImpersonationSession::query()->lockForUpdate()->findOrFail($sessionId);
            if ($session->subject_user_id !== $subject->id
                || $session->tenant_id !== $subject->tenant_id
                || (int) $session->personal_access_token_id !== (int) $token->getKey()) {
                throw new AuthorizationException('Session does not belong to this subject token.');
            }

            $now = CarbonImmutable::now();
            $this->audit->recordLifecycleEvent(
                $session,
                SessionEventType::SessionEnded,
                $now,
                'ImpersonationSession',
                $session->id,
            );
            $this->audit->recordLifecycleEvent(
                $session,
                SessionEventType::GrantRevoked,
                $now,
                'ImpersonationGrant',
                $session->grant_id,
            );
            $session->update([
                'ended_at' => $session->ended_at ?? $now,
                'end_reason' => $session->end_reason ?? SessionEndReason::Exited,
            ]);

            $grant = ImpersonationGrant::query()->lockForUpdate()->findOrFail($session->grant_id);
            if ($grant->status !== GrantStatus::Revoked) {
                $grant->update([
                    'status' => GrantStatus::Revoked,
                    'revoked_by' => $subject->id,
                    'revoked_at' => $now,
                    'revocation_reason' => 'Subject exited support access.',
                ]);
            }

            CentralPersonalAccessToken::query()->whereKey($token->getKey())->delete();
        });
    }

    private function authorizeStart(ImpersonationGrant $grant, SuperAdmin $operator, string $subjectUserId): void
    {
        $now = CarbonImmutable::now();
        if ($grant->status !== GrantStatus::Active
            || $grant->starts_at->isAfter($now)
            || ! $grant->expires_at->isAfter($now)
            || $grant->revoked_at !== null
            || ($grant->operator_id !== null && $grant->operator_id !== $operator->id)
            || ($grant->second_approved_by !== null && $grant->second_approved_by === $operator->id)
            || ($grant->subject_user_id !== null && $grant->subject_user_id !== $subjectUserId)) {
            throw new AuthorizationException('No usable support grant was found.');
        }
    }

    private function sessionExpiry(ImpersonationGrant $grant): CarbonImmutable
    {
        $ttl = $this->config->get('support_access.session_ttl_minutes', 60);
        if (! is_int($ttl) || $ttl < 1 || $ttl > 60) {
            $ttl = 60;
        }

        $cap = CarbonImmutable::now()->addMinutes($ttl);
        $grantExpiry = CarbonImmutable::instance($grant->expires_at);

        return $grantExpiry->lessThan($cap) ? $grantExpiry : $cap;
    }
}
