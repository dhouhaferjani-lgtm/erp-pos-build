<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class InstrumentRemittanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentRepository $bank;

    private PaymentRepository $safe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('admin');
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'code' => 'BANK-'.Str::upper(Str::random(6)),
            'currency' => 'TND',
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
        ]);
        $this->safe = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'safe',
            'code' => 'SAFE-'.Str::upper(Str::random(6)),
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
    }

    public function test_happy_path_two_lines_remit_then_clear_closes_slip(): void
    {
        $first = $this->instrument('30.000');
        $second = $this->instrument('20.000');
        $slipId = $this->createSlip();
        $firstLine = $this->addLine($slipId, $first->id);
        $secondLine = $this->addLine($slipId, $second->id);

        $this->actingAs($this->user)->postJson("/api/v1/instrument-remittances/{$slipId}/remit")
            ->assertOk()->assertJsonPath('data.status', 'remitted');
        $this->actingAs($this->user)->postJson("/api/v1/instrument-remittances/{$slipId}/lines/{$firstLine}/clear")
            ->assertOk()->assertJsonPath('data.line_status', 'cleared');
        $this->actingAs($this->user)->postJson("/api/v1/instrument-remittances/{$slipId}/lines/{$secondLine}/clear")
            ->assertOk()->assertJsonPath('data.line_status', 'cleared');

        $this->actingAs($this->user)->getJson("/api/v1/instrument-remittances/{$slipId}")
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonCount(2, 'data.lines');
    }

    public function test_draft_line_is_deletable_but_remitted_line_is_not(): void
    {
        $first = $this->instrument('10.000');
        $slipId = $this->createSlip();
        $lineId = $this->addLine($slipId, $first->id);
        $this->actingAs($this->user)->deleteJson("/api/v1/instrument-remittances/{$slipId}/lines/{$lineId}")
            ->assertNoContent();

        $second = $this->instrument('11.000');
        $lineId = $this->addLine($slipId, $second->id);
        $this->actingAs($this->user)->postJson("/api/v1/instrument-remittances/{$slipId}/remit")->assertOk();
        $this->actingAs($this->user)->deleteJson("/api/v1/instrument-remittances/{$slipId}/lines/{$lineId}")
            ->assertUnprocessable();
    }

    public function test_cross_company_slip_is_hidden(): void
    {
        $other = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $otherBank = PaymentRepository::factory()->for($other)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
        ]);
        $slip = InstrumentRemittance::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $other->id,
            'number' => 'REM-2026-9999',
            'remittance_type' => 'collection',
            'instrument_kind' => 'cheque',
            'bank_repository_id' => $otherBank->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)->getJson("/api/v1/instrument-remittances/{$slip->id}")->assertNotFound();
    }

    public function test_permission_matrix_separates_remit_clear_and_bounce(): void
    {
        $operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $operator->givePermissionTo('instruments.remit');
        UserCompanyMembership::query()->create([
            'user_id' => $operator->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
        $instrument = $this->instrument('12.000');
        $slipId = $this->createSlip();
        $lineId = $this->addLine($slipId, $instrument->id);

        $this->actingAs($operator)->postJson("/api/v1/instrument-remittances/{$slipId}/lines/{$lineId}/clear")
            ->assertForbidden();
        $this->actingAs($operator)->postJson("/api/v1/instrument-remittances/{$slipId}/lines/{$lineId}/bounce", [
            'routing' => 'receivable',
        ])->assertForbidden();

        $operator->givePermissionTo(['instruments.clear', 'instruments.bounce']);
        $this->actingAs($operator)->postJson("/api/v1/instrument-remittances/{$slipId}/lines/{$lineId}/clear")
            ->assertUnprocessable();
        $this->actingAs($operator)->postJson("/api/v1/instrument-remittances/{$slipId}/lines/{$lineId}/bounce", [
            'routing' => 'receivable',
        ])->assertUnprocessable();
    }

    private function createSlip(): string
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/instrument-remittances', [
            'bank_repository_id' => $this->bank->id,
            'remittance_type' => 'collection',
            'instrument_kind' => 'cheque',
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    private function addLine(string $slipId, string $instrumentId): string
    {
        $response = $this->actingAs($this->user)->postJson("/api/v1/instrument-remittances/{$slipId}/lines", [
            'instrument_id' => $instrumentId,
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    private function instrument(string $amount): PaymentInstrument
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'reference' => 'REF-'.Str::upper(Str::random(8)),
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Inbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $this->safe->id,
        ]);
    }
}
