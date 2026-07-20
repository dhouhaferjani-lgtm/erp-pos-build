<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\Actions\ExpenseSettleHandler;
use App\Modules\Treasury\Application\Services\Actions\InboundClearHandler;
use App\Modules\Treasury\Application\Services\Actions\OutboundClearHandler;
use App\Modules\Treasury\Application\Services\OutboundInstrumentService;
use App\Modules\Treasury\Application\Services\StatementActionRegistry;
use App\Modules\Treasury\Application\Services\StatementMatchingService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StatementActionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_registry_exposes_the_three_wave_three_handlers(): void
    {
        $registry = app(StatementActionRegistry::class);

        self::assertInstanceOf(OutboundClearHandler::class, $registry->handler(MatchActionType::OutboundClear));
        self::assertInstanceOf(InboundClearHandler::class, $registry->handler(MatchActionType::InboundClear));
        self::assertInstanceOf(ExpenseSettleHandler::class, $registry->handler(MatchActionType::ExpenseSettle));
    }

    public function test_outbound_handler_clears_received_and_represents_bounced_instrument(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'gl_account_id' => Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'STMT-OUT-'.Str::upper(Str::random(8)),
            'partner_id' => $partner->id,
            'amount' => '125.000',
            'currency' => 'TND',
            'received_date' => '2026-07-18',
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Outbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $repository->id,
            'created_by' => $user->id,
        ]);
        $matcher = app(StatementMatchingService::class);
        $firstLine = $this->line($user, $company, $repository, 'received');

        $matcher->executeAndAllocate(
            $firstLine->id,
            MatchActionType::OutboundClear,
            ['instrument_id' => $instrument->id],
            $user->id,
        );

        self::assertSame(InstrumentStatus::Cleared, $instrument->fresh()?->status);
        self::assertSame(StatementLineMatchStatus::Matched, $firstLine->fresh()?->match_status);
        $firstExecution = $firstLine->executions()->firstOrFail();
        $firstMovement = RepositoryMovement::query()->findOrFail($firstExecution->produced_repository_movement_ids[0]);
        self::assertSame(MovementDirection::Out, $firstMovement->direction);

        app(OutboundInstrumentService::class)->bounce(
            $instrument->id,
            $tenant->id,
            $company->id,
            $user->id,
            'Dishonored before statement re-presentation',
        );
        self::assertSame(InstrumentStatus::Bounced, $instrument->fresh()?->status);
        $secondLine = $this->line($user, $company, $repository, 'represented');

        $matcher->executeAndAllocate(
            $secondLine->id,
            MatchActionType::OutboundClear,
            ['instrument_id' => $instrument->id],
            $user->id,
        );

        $freshInstrument = $instrument->fresh();
        self::assertSame(InstrumentStatus::Cleared, $freshInstrument?->status);
        self::assertSame(2, $freshInstrument?->presentation_cycle);
        self::assertSame(StatementLineMatchStatus::Matched, $secondLine->fresh()?->match_status);
        $secondExecution = $secondLine->executions()->firstOrFail();
        self::assertNotSame(
            $firstExecution->produced_repository_movement_ids[0],
            $secondExecution->produced_repository_movement_ids[0],
        );
    }

    private function line(
        User $user,
        Company $company,
        PaymentRepository $repository,
        string $suffix,
    ): BankStatementLine {
        $statement = BankStatement::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', "outbound-handler-{$suffix}"),
            'source_file_path' => "bank-statements/outbound-handler-{$suffix}.csv",
            'parser_profile_id' => null,
            'imported_by' => $user->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-18',
            'direction' => MovementDirection::Out,
            'amount' => '125.000',
            'label' => "Outbound {$suffix}",
            'match_status' => StatementLineMatchStatus::Unmatched,
            'location_id' => $repository->location_id,
            'fingerprint' => hash('sha256', "outbound-handler-line-{$suffix}"),
            'dedupe_active' => true,
        ]);
    }
}
