<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\RepositoryOutflowInterface;
use Illuminate\Support\Facades\DB;

final class RepositoryOutflowService implements RepositoryOutflowInterface
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function applyOutflow(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $amount,
        string $currency,
    ): void {
        $scale = $this->scaleResolver->getScale($currency);

        DB::transaction(function () use ($repositoryId, $tenantId, $companyId, $amount, $currency, $scale): void {
            /** @var PaymentRepository $repo */
            $repo = PaymentRepository::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($repositoryId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var numeric-string $previous */
            $previous = $repo->balance ?? '0';
            $repo->balance = bcsub($previous, $amount, $scale);
            $repo->save();

            $new = $repo->balance;

            DB::afterCommit(function () use ($repo, $tenantId, $companyId, $previous, $new, $amount, $currency): void {
                event(new RepositoryBalanceChanged(
                    repositoryId: $repo->id,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    previousBalance: $previous,
                    newBalance: $new,
                    changeAmount: $amount,
                    currency: $currency,
                    changedAt: now()->toIso8601String(),
                ));
            });
        });
    }
}
