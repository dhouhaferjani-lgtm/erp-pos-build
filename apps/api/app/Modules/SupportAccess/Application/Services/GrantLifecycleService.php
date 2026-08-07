<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Application\DTOs\GrantData;
use App\Modules\SupportAccess\Application\DTOs\GrantRequestData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionEndReason;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Repositories\ImpersonationGrantRepository;
use App\Modules\SupportAccess\Domain\Services\ConfiguredApproverSet;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use App\Shared\Contracts\SupportAccess\SupportAccessNotifier;
use App\Shared\Contracts\SupportAccess\TenantSubjectDirectory;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GrantLifecycleService
{
    public function __construct(
        private readonly ImpersonationGrantRepository $grants,
        private readonly ConfiguredApproverSet $approvers,
        private readonly Repository $config,
        private readonly TenantSubjectDirectory $subjects,
        private readonly SupportAccessNotifier $notifier,
        private readonly ImpersonationContextProvider $impersonationContext,
        private readonly SessionAuditService $sessionAudit,
        private readonly GrantAuditService $grantAudit,
        private readonly DatabaseManager $database,
    ) {}

    public function requestIncident(SuperAdmin $operator, GrantRequestData $data): GrantData
    {
        if (! $operator->is_active) {
            throw new AuthorizationException('Inactive operators cannot request support access.');
        }

        if ($data->type !== GrantType::PerIncident || $data->tenant_id === null || $data->subject_user_id === null) {
            throw new InvalidArgumentException('Per-incident access requires a subject user.');
        }

        $tenantId = $data->tenant_id;
        $subjectUserId = $data->subject_user_id;
        $this->validateRequest($data);
        Tenant::query()->findOrFail($tenantId);
        if (! $this->subjects->belongsToTenant($tenantId, $subjectUserId)) {
            throw new AuthorizationException('Subject user does not belong to the requested tenant.');
        }

        $mirror = null;
        $grant = $this->centralTransaction(function () use ($data, $operator, $subjectUserId, $tenantId, &$mirror): ImpersonationGrant {
            $grant = $this->grants->create([
                'tenant_id' => $tenantId,
                'subject_user_id' => $subjectUserId,
                'operator_id' => $operator->id,
                'type' => $data->type,
                'status' => GrantStatus::PendingTenantApproval,
                'reason' => trim($data->reason),
                'ticket_ref' => trim($data->ticket_ref),
                'requested_at' => CarbonImmutable::now(),
                'starts_at' => $data->starts_at,
                'expires_at' => $data->expires_at,
            ]);
            $mirror = $this->grantAudit->appendWithinTransaction(
                $grant,
                SessionEventType::GrantRequested,
                AuditOutcome::Observed,
                $operator->id,
                'super_admin',
                $grant->reason,
                'tenant_consent',
                CarbonImmutable::instance($grant->requested_at),
            );

            return $grant->refresh();
        });
        $this->deliverGrantMirror($mirror);
        $this->notifier->grantRequested($grant->id, $grant->tenant_id, $grant->ticket_ref);

        return GrantData::fromModel($grant);
    }

    public function createPreGrantedWindow(User $tenantAdmin, GrantRequestData $data): GrantData
    {
        $this->refuseImpersonatedTenantMutation();

        if (! $tenantAdmin->hasPermissionTo('support-access.manage')) {
            throw new AuthorizationException('Support-access management permission is required.');
        }

        if ($data->type !== GrantType::PreGrantedWindow || $data->tenant_id !== $tenantAdmin->tenant_id) {
            throw new AuthorizationException('Pre-granted windows are restricted to the current tenant.');
        }

        $this->validateRequest($data);
        $tenant = Tenant::query()->findOrFail($tenantAdmin->tenant_id);
        $requiresSecondApproval = $this->requiresSensitiveTenantApproval($tenant);
        $now = CarbonImmutable::now();

        $mirrors = [];
        $grant = $this->centralTransaction(function () use ($data, $requiresSecondApproval, $tenantAdmin, $now, &$mirrors): ImpersonationGrant {
            $grant = $this->grants->create([
                'tenant_id' => $tenantAdmin->tenant_id,
                'subject_user_id' => $data->subject_user_id,
                'operator_id' => null,
                'type' => GrantType::PreGrantedWindow,
                'status' => $requiresSecondApproval
                    ? GrantStatus::PendingInternalApproval
                    : GrantStatus::Active,
                'reason' => trim($data->reason),
                'ticket_ref' => trim($data->ticket_ref),
                'requested_at' => $now,
                'starts_at' => $data->starts_at,
                'expires_at' => $data->expires_at,
                'tenant_approved_by' => $tenantAdmin->id,
                'tenant_approved_at' => $now,
            ]);
            $mirrors[] = $this->grantAudit->appendWithinTransaction(
                $grant, SessionEventType::GrantRequested, AuditOutcome::Observed,
                $tenantAdmin->id, 'tenant_user', $grant->reason, 'pre_granted_window', $now,
            );
            $mirrors[] = $this->grantAudit->appendWithinTransaction(
                $grant, SessionEventType::GrantApproved, AuditOutcome::Allowed,
                $tenantAdmin->id, 'tenant_user', null, 'tenant_consent', $now,
            );

            return $grant->refresh();
        });
        $this->deliverGrantMirrors($mirrors);

        return GrantData::fromModel($grant->refresh());
    }

    public function approveByTenant(User $tenantAdmin, string $grantId): GrantData
    {
        $expired = false;
        $approvedAt = null;
        $mirror = null;
        $grant = $this->centralTransaction(function () use ($grantId, $tenantAdmin, &$expired, &$approvedAt, &$mirror): ImpersonationGrant {
            $grant = $this->grants->mutateLocked($grantId, function (ImpersonationGrant $grant) use ($tenantAdmin, &$expired, &$approvedAt): void {
                $this->authorizeTenantManager($tenantAdmin, $grant);

                if ($this->expireIfElapsed($grant)) {
                    $expired = true;

                    return;
                }

                if ($grant->status !== GrantStatus::PendingTenantApproval) {
                    throw new DomainException('Grant is not awaiting tenant approval.');
                }

                $tenant = Tenant::query()->findOrFail($grant->tenant_id);
                $grant->tenant_approved_by = $tenantAdmin->id;
                $grant->tenant_approved_at = CarbonImmutable::now();
                $approvedAt = $grant->tenant_approved_at;
                $grant->status = $this->requiresSensitiveTenantApproval($tenant)
                    ? GrantStatus::PendingInternalApproval
                    : GrantStatus::Active;
            });

            $mirror = $expired
                ? $this->appendExpiry($grant, $tenantAdmin->id, 'tenant_user')
                : $this->appendApproval($grant, $tenantAdmin->id, 'tenant_user', 'tenant_consent', $approvedAt);

            return $grant->refresh();
        });
        $this->deliverGrantMirror($mirror);

        $this->throwIfExpired($grant);

        return GrantData::fromModel($grant);
    }

    public function rejectByTenant(User $tenantAdmin, string $grantId, string $reason): GrantData
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $expired = false;
        $rejectedAt = null;
        $mirror = null;
        $grant = $this->centralTransaction(function () use ($grantId, $tenantAdmin, $reason, &$expired, &$rejectedAt, &$mirror): ImpersonationGrant {
            $grant = $this->grants->mutateLocked($grantId, function (ImpersonationGrant $grant) use ($tenantAdmin, $reason, &$expired, &$rejectedAt): void {
                $this->authorizeTenantManager($tenantAdmin, $grant);
                if ($this->expireIfElapsed($grant)) {
                    $expired = true;

                    return;
                }
                if ($grant->status !== GrantStatus::PendingTenantApproval) {
                    throw new DomainException('Grant is not awaiting tenant approval.');
                }

                $grant->status = GrantStatus::Rejected;
                $grant->rejected_by = $tenantAdmin->id;
                $grant->rejected_at = CarbonImmutable::now();
                $rejectedAt = $grant->rejected_at;
                $grant->rejection_reason = trim($reason);
            });
            $mirror = $expired
                ? $this->appendExpiry($grant, $tenantAdmin->id, 'tenant_user')
                : ($rejectedAt instanceof CarbonImmutable
                    ? $this->grantAudit->appendWithinTransaction(
                        $grant, SessionEventType::GrantRejected, AuditOutcome::Denied,
                        $tenantAdmin->id, 'tenant_user', trim($reason), 'tenant_consent', $rejectedAt,
                    )
                    : null);

            return $grant->refresh();
        });
        $this->deliverGrantMirror($mirror);

        $this->throwIfExpired($grant);

        return GrantData::fromModel($grant);
    }

    public function approveSecond(SuperAdmin $approver, string $grantId): GrantData
    {
        $expired = false;
        $approvedAt = null;
        $mirror = null;
        $grant = $this->centralTransaction(function () use ($approver, $grantId, &$expired, &$approvedAt, &$mirror): ImpersonationGrant {
            $grant = $this->grants->mutateLocked($grantId, function (ImpersonationGrant $grant) use ($approver, &$expired, &$approvedAt): void {
                if ($grant->operator_id === $approver->id || ! $this->approvers->allows($approver)) {
                    throw new AuthorizationException('A distinct configured approver is required.');
                }
                if ($this->expireIfElapsed($grant)) {
                    $expired = true;

                    return;
                }
                if ($grant->status !== GrantStatus::PendingInternalApproval) {
                    throw new DomainException('Grant is not awaiting internal approval.');
                }

                $grant->status = GrantStatus::Active;
                $grant->second_approved_by = $approver->id;
                $grant->second_approved_at = CarbonImmutable::now();
                $approvedAt = $grant->second_approved_at;
            });
            $mirror = $expired
                ? $this->appendExpiry($grant, $approver->id, 'support_approver')
                : $this->appendApproval($grant, $approver->id, 'support_approver', 'internal_four_eyes', $approvedAt);

            return $grant->refresh();
        });
        $this->deliverGrantMirror($mirror);

        $this->throwIfExpired($grant);

        return GrantData::fromModel($grant);
    }

    public function revoke(User|SuperAdmin $actor, string $grantId, string $reason): GrantData
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A revocation reason is required.');
        }

        $transitioned = false;
        $mirror = null;
        $grant = $this->centralTransaction(function () use ($actor, $grantId, $reason, &$transitioned, &$mirror): ImpersonationGrant {
            $grant = $this->grants->mutateLocked($grantId, function (ImpersonationGrant $grant) use ($actor, $reason, &$transitioned): void {
                $this->authorizeRevoker($actor, $grant);

                if ($grant->status === GrantStatus::Revoked) {
                    return;
                }

                $grant->status = GrantStatus::Revoked;
                $grant->revoked_by = $actor->id;
                $grant->revoked_at = CarbonImmutable::now();
                $grant->revocation_reason = trim($reason);
                $transitioned = true;
            });

            if ($transitioned) {
                $revokedAt = $grant->revoked_at;
                if ($revokedAt === null) {
                    throw new DomainException('Revoked grant is missing its transition timestamp.');
                }
                $mirror = $this->grantAudit->appendWithinTransaction(
                    $grant, SessionEventType::GrantRevoked, AuditOutcome::Denied,
                    $actor->id, $actor instanceof User ? 'tenant_user' : 'super_admin',
                    trim($reason), 'revocation', CarbonImmutable::instance($revokedAt),
                );
            }

            return $grant->refresh();
        });
        $this->deliverGrantMirror($mirror);

        if ($transitioned) {
            $revokedAt = $grant->revoked_at;
            if ($revokedAt === null) {
                throw new DomainException('Revoked grant is missing its transition timestamp.');
            }

            ImpersonationSession::query()
                ->where('grant_id', $grant->id)
                ->whereNull('ended_at')
                ->eachById(function (ImpersonationSession $session) use ($grant, $revokedAt): void {
                    $this->endRevokedSession($session, $grant, CarbonImmutable::instance($revokedAt));
                });
        }

        return GrantData::fromModel($grant);
    }

    private function appendExpiry(ImpersonationGrant $grant, string $actorId, string $actorType): GrantAuditMirrorData
    {
        return $this->grantAudit->appendWithinTransaction(
            $grant,
            SessionEventType::GrantExpired,
            AuditOutcome::Denied,
            $actorId,
            $actorType,
            'Grant window elapsed.',
            'expiry',
        );
    }

    private function appendApproval(
        ImpersonationGrant $grant,
        string $actorId,
        string $actorType,
        string $phase,
        ?CarbonImmutable $occurredAt,
    ): ?GrantAuditMirrorData {
        return $occurredAt instanceof CarbonImmutable
            ? $this->grantAudit->appendWithinTransaction(
                $grant, SessionEventType::GrantApproved, AuditOutcome::Allowed,
                $actorId, $actorType, null, $phase, $occurredAt,
            )
            : null;
    }

    private function deliverGrantMirror(?GrantAuditMirrorData $mirror): void
    {
        if ($mirror instanceof GrantAuditMirrorData) {
            $this->grantAudit->deliver($mirror);
        }
    }

    private function endRevokedSession(
        ImpersonationSession $session,
        ImpersonationGrant $grant,
        CarbonImmutable $revokedAt,
    ): void {
        $mirrors = $this->centralTransaction(function () use ($session, $grant, $revokedAt): array {
            $locked = ImpersonationSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->ended_at !== null) {
                return [];
            }
            $mirrors = [
                $this->sessionAudit->appendLifecycleWithinTransaction(
                    $locked, SessionEventType::GrantRevoked, $revokedAt,
                    ImpersonationGrant::class, $grant->id, $grant->ticket_ref,
                ),
                $this->sessionAudit->appendLifecycleWithinTransaction(
                    $locked, SessionEventType::SessionEnded, $revokedAt,
                    ImpersonationSession::class, $locked->id, $grant->ticket_ref,
                ),
            ];
            $locked->update([
                'ended_at' => $revokedAt,
                'end_reason' => SessionEndReason::GrantRevoked,
            ]);

            return $mirrors;
        });
        foreach ($mirrors as $mirror) {
            $this->sessionAudit->deliver($mirror);
        }
    }

    /** @param list<GrantAuditMirrorData> $mirrors */
    private function deliverGrantMirrors(array $mirrors): void
    {
        foreach ($mirrors as $mirror) {
            $this->grantAudit->deliver($mirror);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function centralTransaction(Closure $callback): mixed
    {
        return $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        )->transaction(static fn (Connection $unused): mixed => $callback());
    }

    private function validateRequest(GrantRequestData $data): void
    {
        if ($data->tenant_id === null || ! Str::isUuid($data->tenant_id)
            || ($data->subject_user_id !== null && ! Str::isUuid($data->subject_user_id))
            || trim($data->reason) === '' || trim($data->ticket_ref) === '') {
            throw new InvalidArgumentException('Tenant, purpose, ticket, and valid subject scope are required.');
        }

        if ($data->starts_at >= $data->expires_at || $data->expires_at <= CarbonImmutable::now()) {
            throw new InvalidArgumentException('Support access must use a future, non-empty time window.');
        }

        $maxWindowHours = $this->config->get('support_access.max_grant_window_hours', 168);
        if (! is_int($maxWindowHours) || $maxWindowHours < 1
            || $data->expires_at->isAfter(CarbonImmutable::now()->addHours($maxWindowHours))) {
            throw new InvalidArgumentException('Support access exceeds the maximum grant window.');
        }
    }

    private function authorizeTenantManager(User $actor, ImpersonationGrant $grant): void
    {
        $this->refuseImpersonatedTenantMutation();

        if ($actor->tenant_id !== $grant->tenant_id) {
            throw new AuthorizationException('Grant belongs to another tenant.');
        }

        if (! $actor->hasPermissionTo('support-access.manage')) {
            throw new AuthorizationException('Support-access management permission is required.');
        }
    }

    private function refuseImpersonatedTenantMutation(): void
    {
        if ($this->impersonationContext->current() !== null) {
            throw new AuthorizationException('Support grants cannot be changed from an impersonated session.');
        }
    }

    private function authorizeRevoker(User|SuperAdmin $actor, ImpersonationGrant $grant): void
    {
        if ($actor instanceof User) {
            $this->authorizeTenantManager($actor, $grant);

            return;
        }

        if (! $actor->is_active
            || ($grant->operator_id !== $actor->id && ! $this->approvers->allows($actor))) {
            throw new AuthorizationException('Operator is not allowed to revoke this grant.');
        }
    }

    private function requiresSensitiveTenantApproval(Tenant $tenant): bool
    {
        return (bool) $this->config->get('support_access.four_eyes.enabled', true)
            && (bool) $this->config->get('support_access.four_eyes.sensitive_tenants', true)
            && (bool) $tenant->getAttribute('is_sensitive');
    }

    private function expireIfElapsed(ImpersonationGrant $grant): bool
    {
        if ($grant->expires_at->isFuture()) {
            return false;
        }

        $grant->status = GrantStatus::Expired;

        return true;
    }

    private function throwIfExpired(ImpersonationGrant $grant): void
    {
        if ($grant->status === GrantStatus::Expired) {
            throw new DomainException('Grant has expired.');
        }
    }
}
