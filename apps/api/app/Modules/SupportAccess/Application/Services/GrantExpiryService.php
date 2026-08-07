<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\SessionEndReason;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Shared\Contracts\SupportAccess\TenantSubjectTokenPort;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final class GrantExpiryService
{
    private const SYSTEM_ACTOR_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly GrantAuditService $grantAudit,
        private readonly SessionAuditService $sessionAudit,
        private readonly TenantSubjectTokenPort $tokens,
    ) {}

    public function expireDue(int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $ids = ImpersonationGrant::query()
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->whereIn('status', [
                GrantStatus::PendingTenantApproval,
                GrantStatus::PendingInternalApproval,
                GrantStatus::Active,
            ])
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            if ($this->expireGrantIfDue((string) $id)) {
                $expired++;
            }
        }

        $sessionIds = ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');
        foreach ($sessionIds as $sessionId) {
            if ($this->expireSessionIfDue((string) $sessionId)) {
                $expired++;
            }
        }

        return $expired;
    }

    public function expireSessionIfDue(string $sessionId): bool
    {
        $mirror = null;
        $tokenId = null;
        $due = $this->centralConnection()->transaction(function () use (
            $sessionId,
            &$mirror,
            &$tokenId,
        ): bool {
            $session = ImpersonationSession::query()->lockForUpdate()->findOrFail($sessionId);
            if ($session->ended_at !== null || $session->expires_at->isFuture()) {
                return false;
            }

            $grant = ImpersonationGrant::query()->findOrFail($session->grant_id);
            $now = CarbonImmutable::now();
            $mirror = $this->sessionAudit->appendLifecycleWithinTransaction(
                $session,
                SessionEventType::SessionEnded,
                $now,
                ImpersonationSession::class,
                $session->id,
                $grant->ticket_ref,
            );
            $session->update([
                'ended_at' => $now,
                'end_reason' => SessionEndReason::Expired,
            ]);
            $tokenId = $session->personal_access_token_id;

            return true;
        });

        if (! $due) {
            return false;
        }
        if (is_int($tokenId)) {
            $this->tokens->revoke($tokenId);
        }
        if ($mirror instanceof ImpersonationAuditMirrorData) {
            $this->sessionAudit->deliver($mirror);
        }

        return true;
    }

    public function expireGrantIfDue(string $grantId): bool
    {
        $grantMirror = null;
        $sessionMirrors = [];
        $tokenIds = [];
        $due = $this->centralConnection()->transaction(function () use (
            $grantId,
            &$grantMirror,
            &$sessionMirrors,
            &$tokenIds,
        ): bool {
            $grant = ImpersonationGrant::query()->lockForUpdate()->findOrFail($grantId);
            if ($grant->expires_at->isFuture()
                || in_array($grant->status, [GrantStatus::Rejected, GrantStatus::Revoked], true)) {
                return false;
            }

            $now = CarbonImmutable::now();
            if ($grant->status !== GrantStatus::Expired) {
                $grant->status = GrantStatus::Expired;
                $grant->save();
                $grantMirror = $this->grantAudit->appendWithinTransaction(
                    $grant,
                    SessionEventType::GrantExpired,
                    AuditOutcome::Denied,
                    self::SYSTEM_ACTOR_ID,
                    'system',
                    'Grant window elapsed.',
                    'expiry',
                    $now,
                );
            }

            $sessions = ImpersonationSession::query()
                ->where('grant_id', $grant->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->get();
            foreach ($sessions as $session) {
                $sessionMirrors[] = $this->sessionAudit->appendLifecycleWithinTransaction(
                    $session,
                    SessionEventType::GrantExpired,
                    $now,
                    ImpersonationGrant::class,
                    $grant->id,
                    $grant->ticket_ref,
                );
                $sessionMirrors[] = $this->sessionAudit->appendLifecycleWithinTransaction(
                    $session,
                    SessionEventType::SessionEnded,
                    $now,
                    ImpersonationSession::class,
                    $session->id,
                    $grant->ticket_ref,
                );
                $session->update([
                    'ended_at' => $now,
                    'end_reason' => SessionEndReason::Expired,
                ]);
                if ($session->personal_access_token_id !== null) {
                    $tokenIds[] = (int) $session->personal_access_token_id;
                }
            }

            return true;
        });

        if (! $due) {
            return false;
        }

        foreach ($tokenIds as $tokenId) {
            $this->tokens->revoke($tokenId);
        }
        if ($grantMirror instanceof GrantAuditMirrorData) {
            $this->grantAudit->deliver($grantMirror);
        }
        foreach ($sessionMirrors as $sessionMirror) {
            $this->sessionAudit->deliver($sessionMirror);
        }

        return true;
    }

    private function centralConnection(): Connection
    {
        return $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );
    }
}
