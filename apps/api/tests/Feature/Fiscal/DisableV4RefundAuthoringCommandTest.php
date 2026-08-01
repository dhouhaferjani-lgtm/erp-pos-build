<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Application\Services\V4RefundAuthoringAcknowledgementService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Exceptions\V4RefundAuthoringNotOfferedException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FINAL-REVIEW CONDITION 2 (I-2) — `pos:disable-v4-refund-authoring`, the
 * launch contract's rollback lever.
 *
 * The state this command exists to prevent is the review's S6:
 * `v4_refund_authoring_enabled` cleared ALONE leaves the terminal in
 * NEITHER refund path — the device pulls `false` and routes to the legacy
 * flow, while the server's {@see LegacyCorrectionGuard} is conditioned on
 * `v4_refund_authoring_acknowledged_at`, which nothing in the codebase ever
 * cleared, and so keeps returning 409 `LEGACY_CORRECTION_RETIRED`. Neither
 * column is `$fillable`, so `PATCH /pos/terminals/{id}` cannot reach them
 * either: before this command, recovery was hand-written SQL.
 *
 * The lifecycle test below is therefore the whole point of the file:
 * enable -> acknowledge -> (legacy retired) -> disable -> a legacy return
 * is AUTHORED AND FISCALIZED again, and the device's capability channel
 * (`GET /pos/terminals/{id}`) reports the capability withdrawn.
 */
final class DisableV4RefundAuthoringCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            // Generic chart — both §5.3 account purposes are seeded correctly
            // from day one, so the ENABLE preflight passes (mirrors
            // EnableV4RefundAuthoringCommandTest).
            'country_code' => 'US',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->cashier->givePermissionTo('pos.process_returns');
        $this->cashier->givePermissionTo('pos.manage_terminals');

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($this->company);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);
    }

    // =================================================================
    // The lifecycle: enable -> acknowledge -> disable -> legacy open.
    // =================================================================

    public function test_disable_reopens_the_legacy_correction_path_end_to_end(): void
    {
        $terminal = $this->createDeviceTerminal();

        // --- Phase 1: server offers. ---
        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertSuccessful();

        // --- Phase 2: the device acknowledges; legacy is retired. ---
        $this->app->make(V4RefundAuthoringAcknowledgementService::class)
            ->acknowledge($terminal->refresh());

        $terminal->refresh();
        self::assertTrue((bool) $terminal->v4_refund_authoring_enabled);
        self::assertNotNull($terminal->v4_refund_authoring_acknowledged_at);

        $guard = $this->app->make(LegacyCorrectionGuard::class);
        try {
            $guard->assertLegacyCorrectionAllowed($terminal);
            self::fail('the legacy correction path should be retired once acknowledged');
        } catch (LegacyCorrectionRetiredException) {
            $this->addToAssertionCount(1);
        }

        // --- Rollback. ---
        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--force' => true,
        ])->assertSuccessful();

        $terminal->refresh();
        self::assertFalse((bool) $terminal->v4_refund_authoring_enabled, 'the capability flag must be cleared');
        self::assertNull($terminal->v4_refund_authoring_acknowledged_at, 'the acknowledgement stamp must be cleared TOO — clearing only the flag is exactly the S6 brick');

        // The guard is inert again...
        $guard->assertLegacyCorrectionAllowed($terminal);
        $this->addToAssertionCount(1);

        // ...and a legacy return is genuinely AUTHORED again, not merely
        // waved past the guard: fiscalized, hashed, chained.
        [$saleReceipt, $line] = $this->fiscalizedSaleWithOneLine($terminal);
        $this->openShift($terminal);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
        $returnReceipt = $this->app->make(ReceiptReturnService::class)->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [['line_id' => $line->id, 'quantity' => '1']],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $terminal->id,
            notes: null,
        );

        self::assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $returnReceipt->fiscal_hash);
        self::assertGreaterThan(0, $returnReceipt->chain_sequence);
    }

    public function test_device_capability_sync_reports_the_capability_withdrawn(): void
    {
        $terminal = $this->createDeviceTerminal();

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertSuccessful();
        $this->app->make(V4RefundAuthoringAcknowledgementService::class)
            ->acknowledge($terminal->refresh());

        Sanctum::actingAs($this->cashier);

        // The device's capability channel BEFORE the rollback.
        $this->getJson("/api/v1/pos/terminals/{$terminal->id}")
            ->assertOk()
            ->assertJsonPath('data.v4_refund_authoring_enabled', true);

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--force' => true,
        ])->assertSuccessful();

        // ...and after. `syncService.pullTerminalState` reads exactly this
        // field into `terminal_state.v4_refund_authoring_enabled`, which is
        // the single boolean `HomePage` routes refunds on.
        $this->getJson("/api/v1/pos/terminals/{$terminal->id}")
            ->assertOk()
            ->assertJsonPath('data.v4_refund_authoring_enabled', false);
    }

    public function test_acknowledgement_is_refused_after_a_disable(): void
    {
        $terminal = $this->createDeviceTerminal();

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertSuccessful();
        $this->app->make(V4RefundAuthoringAcknowledgementService::class)
            ->acknowledge($terminal->refresh());

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--force' => true,
        ])->assertSuccessful();

        // A late in-flight ACK from the device must NOT silently re-retire
        // the legacy path behind the operator's back.
        $this->expectException(V4RefundAuthoringNotOfferedException::class);
        $this->app->make(V4RefundAuthoringAcknowledgementService::class)
            ->acknowledge($terminal->refresh());
    }

    // =================================================================
    // Guards / conventions mirrored from the Enable command.
    // =================================================================

    public function test_dry_run_reports_without_writing(): void
    {
        $terminal = $this->enabledAndAcknowledgedTerminal();

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $terminal->refresh();
        self::assertTrue((bool) $terminal->v4_refund_authoring_enabled);
        self::assertNotNull($terminal->v4_refund_authoring_acknowledged_at);
    }

    public function test_declining_the_confirmation_writes_nothing(): void
    {
        $terminal = $this->enabledAndAcknowledgedTerminal();

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])
            ->expectsConfirmation(
                'Clear BOTH v4 refund-authoring flags for the terminal(s) listed above?',
                'no',
            )
            ->assertFailed();

        $terminal->refresh();
        self::assertTrue((bool) $terminal->v4_refund_authoring_enabled);
        self::assertNotNull($terminal->v4_refund_authoring_acknowledged_at);
    }

    public function test_confirming_the_prompt_clears_both_flags(): void
    {
        $terminal = $this->enabledAndAcknowledgedTerminal();

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])
            ->expectsConfirmation(
                'Clear BOTH v4 refund-authoring flags for the terminal(s) listed above?',
                'yes',
            )
            ->assertSuccessful();

        $terminal->refresh();
        self::assertFalse((bool) $terminal->v4_refund_authoring_enabled);
        self::assertNull($terminal->v4_refund_authoring_acknowledged_at);
    }

    public function test_is_a_no_op_when_no_terminal_carries_either_flag(): void
    {
        $this->createDeviceTerminal();

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--force' => true,
        ])->assertSuccessful();
    }

    public function test_clears_a_terminal_left_in_the_s6_half_state(): void
    {
        // The exact shape the review's S6 describes: an operator already ran
        // `UPDATE pos_terminals SET v4_refund_authoring_enabled = false`, so
        // only the acknowledgement stamp is left — and it is the stamp that
        // bricks the legacy path. Selecting on `enabled` alone would miss it.
        $terminal = $this->createDeviceTerminal();
        $terminal->forceFill([
            'v4_refund_authoring_enabled' => false,
            'v4_refund_authoring_acknowledged_at' => now(),
        ])->save();

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--force' => true,
        ])->assertSuccessful();

        $terminal->refresh();
        self::assertNull($terminal->v4_refund_authoring_acknowledged_at);
    }

    public function test_terminal_option_narrows_to_that_terminal_only(): void
    {
        $target = $this->enabledAndAcknowledgedTerminal();
        $bystander = $this->enabledAndAcknowledgedTerminal();

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--terminal' => $target->id,
            '--force' => true,
        ])->assertSuccessful();

        $target->refresh();
        $bystander->refresh();
        self::assertNull($target->v4_refund_authoring_acknowledged_at);
        self::assertNotNull($bystander->v4_refund_authoring_acknowledged_at);
    }

    public function test_refuses_a_terminal_belonging_to_another_company(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $foreign = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'v4_refund_authoring_enabled' => true,
            'v4_refund_authoring_acknowledged_at' => now(),
        ]);

        $this->artisanCommand('pos:disable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--terminal' => $foreign->id,
            '--force' => true,
        ])->assertFailed();

        $foreign->refresh();
        self::assertNotNull($foreign->v4_refund_authoring_acknowledged_at);
    }

    // =================================================================
    // Fixtures.
    // =================================================================

    private function createDeviceTerminal(): Terminal
    {
        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
        ]);
    }

    private function enabledAndAcknowledgedTerminal(): Terminal
    {
        $terminal = $this->createDeviceTerminal();
        $terminal->forceFill([
            'v4_refund_authoring_enabled' => true,
            'v4_refund_authoring_acknowledged_at' => now(),
        ])->save();

        return $terminal;
    }

    private function openShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    /**
     * @return array{0: Receipt, 1: ReceiptLine}
     */
    private function fiscalizedSaleWithOneLine(Terminal $terminal): array
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => 'sale',
            'subtotal' => '30.000',
            'tax_amount' => '0.000',
            'total' => '30.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);

        $line = ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-ROLLBACK',
            'product_name' => 'Widget Rollback',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);

        return [$receipt, $line];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
