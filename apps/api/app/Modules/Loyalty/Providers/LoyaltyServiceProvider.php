<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Providers;

use App\Modules\Contact\Domain\Contact;
use App\Modules\Loyalty\Application\Services\LoyaltyPartnerReferenceSource;
use App\Modules\Loyalty\Application\Services\SaleEarningService;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyMemberRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\StampCardRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TierRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentEarningRuleRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentEnrollmentRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentLoyaltyMemberRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentLoyaltyProgramRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentRewardRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentStampCardRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentTierRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentTransactionRepository;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([LoyaltyPartnerReferenceSource::class], PartnerReferenceSource::class);

        // Bind cross-module earning contract
        $this->app->bind(LoyaltyEarningContract::class, SaleEarningService::class);

        // Register repository bindings
        $this->app->bind(LoyaltyProgramRepositoryInterface::class, EloquentLoyaltyProgramRepository::class);
        $this->app->bind(LoyaltyMemberRepositoryInterface::class, EloquentLoyaltyMemberRepository::class);
        $this->app->bind(EnrollmentRepositoryInterface::class, EloquentEnrollmentRepository::class);
        $this->app->bind(EarningRuleRepositoryInterface::class, EloquentEarningRuleRepository::class);
        $this->app->bind(RewardRepositoryInterface::class, EloquentRewardRepository::class);
        $this->app->bind(TierRepositoryInterface::class, EloquentTierRepository::class);
        $this->app->bind(TransactionRepositoryInterface::class, EloquentTransactionRepository::class);
        $this->app->bind(StampCardRepositoryInterface::class, EloquentStampCardRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        Relation::morphMap([
            'contact' => Contact::class,
            'partner' => Partner::class,
        ]);
    }
}
