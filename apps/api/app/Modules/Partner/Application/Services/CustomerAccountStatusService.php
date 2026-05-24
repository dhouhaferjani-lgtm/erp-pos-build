<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerAccountStatusService
{
    public function __construct(
        private readonly VirtualAdminFiscalEventService $fiscalEvents,
    ) {}

    public function transition(
        string $tenantId,
        string $companyId,
        string $partnerId,
        CustomerAccountStatus $newStatus,
        string $actorUserId,
        string $reason,
    ): Partner {
        $actorUserId = trim($actorUserId);
        $reason = trim($reason);

        if ($actorUserId === '') {
            throw new InvalidArgumentException('Account status transition requires an actor user id.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('Account status transition requires a reason.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $partnerId,
            $newStatus,
            $actorUserId,
            $reason,
        ): Partner {
            /** @var Partner|null $partner */
            $partner = Partner::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($partnerId)
                ->lockForUpdate()
                ->first();

            if (! $partner instanceof Partner) {
                throw (new ModelNotFoundException)->setModel(Partner::class, [$partnerId]);
            }

            if (! $partner->isCustomer()) {
                throw new InvalidArgumentException('Account status transitions are only valid for customers.');
            }

            $oldStatus = $partner->account_status;
            if (! $oldStatus->canTransitionTo($newStatus)) {
                throw new InvalidArgumentException(sprintf(
                    'Customer account status cannot transition from %s to %s.',
                    $oldStatus->value,
                    $newStatus->value,
                ));
            }

            $partner->account_status = $newStatus;
            $partner->account_status_version = ((int) $partner->account_status_version) + 1;
            $partner->account_status_changed_at = now();
            $partner->account_status_changed_by = $actorUserId;
            $partner->account_status_reason = $reason;
            $partner->save();

            $this->fiscalEvents->appendAccountStatusChanged(
                partner: $partner,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                actorUserId: $actorUserId,
                reason: $reason,
            );

            return $partner->refresh();
        });
    }
}
