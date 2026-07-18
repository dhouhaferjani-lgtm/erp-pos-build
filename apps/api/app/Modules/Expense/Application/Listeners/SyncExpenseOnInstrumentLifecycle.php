<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Listeners;

use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Events\InstrumentCancelled;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\PaymentInstrument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class SyncExpenseOnInstrumentLifecycle
{
    public function handle(InstrumentCleared|InstrumentCancelled $event): void
    {
        DB::transaction(function () use ($event): void {
            $metadata = ExpenseMetadata::query()
                ->where('payment_instrument_id', $event->instrumentId)
                ->whereHas('document', function (Builder $query) use ($event): void {
                    $query->whereRaw('tenant_id = ?', [$event->tenantId])
                        ->whereRaw('company_id = ?', [$event->companyId]);
                })
                ->lockForUpdate()
                ->first();
            if (! $metadata instanceof ExpenseMetadata) {
                return;
            }

            if ($event instanceof InstrumentCancelled) {
                $metadata->update([
                    'payment_instrument_id' => null,
                    'is_paid' => false,
                    'paid_at' => null,
                    'payment_repository_id' => null,
                    'payment_method_id' => null,
                    'payment_date' => null,
                ]);

                return;
            }

            $instrument = PaymentInstrument::query()
                ->where('tenant_id', $event->tenantId)
                ->where('company_id', $event->companyId)
                ->whereKey($event->instrumentId)
                ->first();
            if (! $instrument instanceof PaymentInstrument
                || $instrument->direction !== InstrumentDirection::Outbound) {
                throw new \DomainException('The cleared expense instrument could not be resolved as outbound.');
            }

            $clearedAt = Carbon::parse($event->clearedAt);
            $metadata->update([
                'is_paid' => true,
                'paid_at' => $clearedAt,
                'payment_repository_id' => $instrument->repository_id,
                'payment_method_id' => $instrument->payment_method_id,
                'payment_date' => $clearedAt->toDateString(),
            ]);
        });
    }
}
