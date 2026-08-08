<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Providers;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Treasury\Application\Listeners\PostShiftCashVarianceAdjustment;
use App\Modules\Treasury\Application\Projections\TreasuryAccountChargeBridge;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Application\Services\Actions\AcquirerFeeHandler;
use App\Modules\Treasury\Application\Services\Actions\CreateExpenseHandler;
use App\Modules\Treasury\Application\Services\Actions\CreateIncomeHandler;
use App\Modules\Treasury\Application\Services\Actions\ExpenseSettleHandler;
use App\Modules\Treasury\Application\Services\Actions\InboundClearHandler;
use App\Modules\Treasury\Application\Services\Actions\OutboundClearHandler;
use App\Modules\Treasury\Application\Services\CsvStatementParser;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Application\Services\OutboundInstrumentIssuer;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Application\Services\RepositoryAdjustmentService;
use App\Modules\Treasury\Application\Services\StatementActionRegistry;
use App\Modules\Treasury\Application\Services\StatementParserRegistry;
use App\Modules\Treasury\Application\Services\TreasuryMovementService;
use App\Modules\Treasury\Application\Services\TreasuryPartnerReferenceSource;
use App\Modules\Treasury\Application\Services\XlsxStatementParser;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use App\Modules\Treasury\Infrastructure\EloquentOutboundInstrumentPaymentLinkResolver;
use App\Modules\Treasury\Infrastructure\EloquentPaymentMethodResolver;
use App\Modules\Treasury\Presentation\Console\AuditDiscountsCommand;
use App\Modules\Treasury\Presentation\Console\BackfillLocationAttributionCommand;
use App\Modules\Treasury\Presentation\Console\InstrumentMaturityAlertsCommand;
use App\Modules\Treasury\Presentation\Console\ReconcileTreasuryCommand;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use App\Shared\Contracts\Treasury\InstrumentReversalCancellerInterface;
use App\Shared\Contracts\Treasury\OutboundInstrumentIssuerInterface;
use App\Shared\Contracts\Treasury\OutboundInstrumentPaymentLinkResolver;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TreasuryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([TreasuryPartnerReferenceSource::class], PartnerReferenceSource::class);

        // Module-boundary contract: POS A1 and B2B A2 close-with-tolerance
        // depend on this interface from Shared/Contracts/Treasury, not on the
        // concrete service. Singleton because the service is stateless and
        // we want callers to share a resolved instance during a request.
        $this->app->singleton(
            PaymentToleranceCheckerContract::class,
            PaymentToleranceService::class,
        );

        // Pass 2A.PHP.2 — bind the PaymentMethodResolver seam (synthesis v5
        // §8.B + dispatch §0 Gap A). The 27-key canonical SALE_RECEIPT
        // payload no longer carries `payment_method_id` per-payment; the POS
        // projector resolves the company-scoped FK from `(tenant_id, company_id, method_code)`
        // via this interface so POS module never imports Treasury directly
        // (SoT §13.6/D16 bounded-modules asymmetric seam).
        $this->app->singleton(
            PaymentMethodResolver::class,
            EloquentPaymentMethodResolver::class,
        );

        // Task 11 — the single money-movement write port. Every treasury
        // movement (payments, expenses, POS receipt legs, transfers, reversals)
        // converges onto record(). Bound to the Shared contract so cross-module
        // consumers never import the concrete service or Treasury domain models.
        $this->app->bind(
            TreasuryMovementServiceInterface::class,
            TreasuryMovementService::class,
        );

        // DPA lane V3/G3 — the single repository-adjustment orchestration port
        // (document + 658/758 journal entry + movement, one transaction). Bound
        // to the Shared contract so the POS shift-close cash-variance listener
        // consumes it without importing the Treasury `RepositoryAdjustment`
        // domain model or re-implementing the ordering guarantees.
        $this->app->bind(
            RepositoryAdjustmentServiceInterface::class,
            RepositoryAdjustmentService::class,
        );

        // Task 3 (treasury burn-down) — read port so the Expense listener
        // `SyncExpenseOnInstrumentLifecycle` resolves an outbound instrument's
        // settlement linkage via Shared/Contracts instead of importing the
        // Treasury `PaymentInstrument` model across the module boundary.
        $this->app->bind(
            OutboundInstrumentPaymentLinkResolver::class,
            EloquentOutboundInstrumentPaymentLinkResolver::class,
        );

        $this->app->bind(InstrumentLifecycleService::class);

        // MTP-TRE-23 fix (review finding I1) — PaymentRefundService (Treasury
        // Domain) depends on this Shared/Contracts port, never on the concrete
        // Application-tier InstrumentLifecycleService, to keep the deptrac
        // hexagonal boundary (Domain -> SharedDomain/SharedContracts only)
        // clean.
        $this->app->bind(
            InstrumentReversalCancellerInterface::class,
            InstrumentLifecycleService::class,
        );
        $this->app->bind(InstrumentRemittanceService::class);
        $this->app->bind(OutboundInstrumentIssuerInterface::class, OutboundInstrumentIssuer::class);
        $this->app->singleton(
            StatementParserRegistry::class,
            static fn (Application $app): StatementParserRegistry => new StatementParserRegistry([
                StatementParserKey::Csv->value => $app->make(CsvStatementParser::class),
                StatementParserKey::Xlsx->value => $app->make(XlsxStatementParser::class),
            ]),
        );
        $this->app->singleton(
            StatementActionRegistry::class,
            static fn (Application $app): StatementActionRegistry => new StatementActionRegistry([
                $app->make(AcquirerFeeHandler::class),
                $app->make(OutboundClearHandler::class),
                $app->make(InboundClearHandler::class),
                $app->make(ExpenseSettleHandler::class),
                $app->make(CreateExpenseHandler::class),
                $app->make(CreateIncomeHandler::class),
            ]),
        );

        // Phase 1 §7.4 / §13 / SoT §13.6/D16 — Treasury-operational projector
        // for `SALE_RECEIPT` fiscal events. Owns the Treasury `Payment` +
        // POS-payment GL post relocated from `ReceiptPaymentService`. The
        // Fiscal module's registry (Task 18 — FiscalEventProjectionRegistry)
        // consumes the tagged set via `app->tagged(FiscalEventProjector::class)`
        // and gates dispatch on `ModuleActivationResolver::isActive('Treasury',
        // tenant, company)` (the bridge's `requiresModule()` returns the
        // canonical 'Treasury' token). Tag at register() (not boot()) — the
        // registry is a singleton constructed off the tagged set and a
        // boot-time tag would leave the registry's $projectors array empty.
        $this->app->tag(
            [
                TreasuryReceiptBridge::class,
                TreasuryAccountPaymentBridge::class,
                TreasuryAccountChargeBridge::class,
                TreasuryDepositBridge::class,
            ],
            FiscalEventProjector::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        // DPA lane G3 — book the shift-close cash-count variance to the GL.
        // TREASURY-side deliberately: the POS module owns no treasury write-port
        // usage (policed by tests/Architecture/TreasuryBalanceWritePortTest.php),
        // so the consumer of this POS domain event lives here. Registered the
        // same way Compliance registers its own CashCountRecorded consumer
        // (OpenFraudAlertForShiftVariance) — synchronous, and internally
        // log-never-block, because both trigger paths raise the event from a
        // DB::afterCommit callback where a throw would surface as a 500 on a
        // shift close that already succeeded.
        Event::listen(
            CashCountRecorded::class,
            [PostShiftCashVarianceAdjustment::class, 'handle'],
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditDiscountsCommand::class,
                BackfillLocationAttributionCommand::class,
                InstrumentMaturityAlertsCommand::class,
                ReconcileTreasuryCommand::class,
            ]);
        }
    }
}
