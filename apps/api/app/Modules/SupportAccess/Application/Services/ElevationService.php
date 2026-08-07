<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationElevation;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Enums\ElevationStatus;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Services\ConfiguredApproverSet;
use App\Shared\Contracts\SupportAccess\TenantSubjectTokenPort;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;

final class ElevationService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly ConfiguredApproverSet $approvers,
        private readonly SessionAuditService $audit,
        private readonly TenantSubjectTokenPort $tokens,
    ) {}

    public function request(SuperAdmin $operator, string $sessionId, string $reason): ImpersonationElevation
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A write-elevation reason is required.');
        }

        $mirror = null;
        $elevation = $this->centralTransaction(function () use ($operator, $sessionId, $reason, &$mirror): ImpersonationElevation {
            $session = ImpersonationSession::query()->lockForUpdate()->findOrFail($sessionId);
            if (! $operator->is_active
                || $session->operator_id !== $operator->id
                || $session->ended_at !== null
                || ! $session->expires_at->isFuture()) {
                throw new AuthorizationException('Operator cannot elevate this session.');
            }

            if ($session->access_level === SessionAccessLevel::WriteElevated
                && $session->write_expires_at?->isFuture()) {
                throw new DomainException('Session already has write elevation.');
            }

            $elevation = ImpersonationElevation::query()->create([
                'session_id' => $session->id,
                'requested_by' => $operator->id,
                'status' => ElevationStatus::Pending,
                'reason' => trim($reason),
                'requested_at' => CarbonImmutable::now(),
            ]);
            $mirror = $this->audit->appendLifecycleWithinTransaction(
                $session,
                SessionEventType::WriteElevationRequested,
                CarbonImmutable::instance($elevation->requested_at),
                'ImpersonationElevation',
                $elevation->id,
            );

            return $elevation;
        });
        $this->deliverMirror($mirror);

        return $elevation;
    }

    public function approve(SuperAdmin $approver, string $elevationId): ImpersonationElevation
    {
        $mirror = null;
        $elevation = $this->centralTransaction(function () use ($approver, $elevationId, &$mirror): ImpersonationElevation {
            $elevation = ImpersonationElevation::query()->lockForUpdate()->findOrFail($elevationId);
            if ($elevation->requested_by === $approver->id || ! $this->approvers->allows($approver)) {
                throw new AuthorizationException('A distinct configured approver is required.');
            }
            if ($elevation->status !== ElevationStatus::Pending) {
                throw new DomainException('Elevation is not pending.');
            }

            $session = ImpersonationSession::query()->lockForUpdate()->findOrFail($elevation->session_id);
            if ($session->ended_at !== null || ! $session->expires_at->isFuture()) {
                throw new AuthorizationException('Session is no longer active.');
            }

            $ttl = $this->config->get('support_access.write_elevation_ttl_minutes', 15);
            $ttl = is_int($ttl) && $ttl > 0 && $ttl <= 60 ? $ttl : 15;
            $cap = CarbonImmutable::now()->addMinutes($ttl);
            $sessionExpiry = CarbonImmutable::instance($session->expires_at);
            $expiresAt = $sessionExpiry->lessThan($cap) ? $sessionExpiry : $cap;
            $now = CarbonImmutable::now();

            $elevation->update([
                'approved_by' => $approver->id,
                'status' => ElevationStatus::Approved,
                'approved_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            $session->update([
                'access_level' => SessionAccessLevel::WriteElevated,
                'write_elevated_at' => $now,
                'write_expires_at' => $expiresAt,
                'write_approved_by' => $approver->id,
            ]);

            $this->tokens->elevateForWrite((int) $session->personal_access_token_id);

            $mirror = $this->audit->appendLifecycleWithinTransaction(
                $session,
                SessionEventType::WriteElevationApproved,
                $now,
                'ImpersonationElevation',
                $elevation->id,
            );

            return $elevation->refresh();
        });
        $this->deliverMirror($mirror);

        return $elevation;
    }

    public function reject(SuperAdmin $approver, string $elevationId, string $reason): ImpersonationElevation
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A write-elevation rejection reason is required.');
        }

        $mirror = null;
        $elevation = $this->centralTransaction(function () use ($approver, $elevationId, $reason, &$mirror): ImpersonationElevation {
            $elevation = ImpersonationElevation::query()->lockForUpdate()->findOrFail($elevationId);
            if ($elevation->requested_by === $approver->id || ! $this->approvers->allows($approver)) {
                throw new AuthorizationException('A distinct configured approver is required.');
            }
            if ($elevation->status !== ElevationStatus::Pending) {
                throw new DomainException('Elevation is not pending.');
            }

            $session = ImpersonationSession::query()->lockForUpdate()->findOrFail($elevation->session_id);
            if ($session->ended_at !== null || ! $session->expires_at->isFuture()) {
                throw new AuthorizationException('Session is no longer active.');
            }

            $now = CarbonImmutable::now();
            $elevation->update([
                'status' => ElevationStatus::Rejected,
                'rejected_at' => $now,
                'rejection_reason' => trim($reason),
            ]);
            $mirror = $this->audit->appendLifecycleWithinTransaction(
                $session,
                SessionEventType::WriteElevationRejected,
                $now,
                'ImpersonationElevation',
                $elevation->id,
            );

            return $elevation->refresh();
        });
        $this->deliverMirror($mirror);

        return $elevation;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function centralTransaction(Closure $callback): mixed
    {
        $connection = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );

        return $connection->transaction(
            static fn (Connection $unused): mixed => $callback(),
        );
    }

    private function deliverMirror(?ImpersonationAuditMirrorData $mirror): void
    {
        if ($mirror === null) {
            throw new LogicException('Elevation transition did not produce an audit event.');
        }

        $this->audit->deliver($mirror);
    }
}
