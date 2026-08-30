<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillLocationAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_fills_repository_and_pos_locations_without_overwriting_existing_values(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND']);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminalLocation = Location::factory()->create(['company_id' => $company->id]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
        ]);
        $method = PaymentMethod::factory()->for($company)->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->for($company)->create(['tenant_id' => $tenant->id]);
        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $repository->id,
            'location_id' => null,
            'origin' => PaymentOrigin::WebAdmin,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
        ]);
        $instrument = PaymentInstrument::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'BACKFILL-1',
            'partner_id' => $partner->id,
            'amount' => '10.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'direction' => InstrumentDirection::Inbound,
            'kind' => InstrumentKind::Cheque,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $repository->id,
            'location_id' => null,
            'created_by' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $terminalLocation->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $eventId = (string) Str::uuid();
        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $payment->created_by ?? $instrument->created_by,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'test',
            'sequence_number' => 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'canonical_bytes' => 'backfill-test',
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'payload_parse_status' => 'parsed',
        ]);
        $posPayment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => null,
            'fiscal_event_id' => $eventId,
            'location_id' => null,
            'origin' => PaymentOrigin::Pos,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
        ]);

        $this->artisan('treasury:backfill-location-attribution', ['--company' => $company->id])->assertSuccessful();

        self::assertSame($location->id, $payment->fresh()->location_id);
        self::assertSame($location->id, $instrument->fresh()->location_id);
        self::assertSame($terminalLocation->id, $posPayment->fresh()->location_id);

        $payment->update(['location_id' => $terminalLocation->id]);
        $this->artisan('treasury:backfill-location-attribution', ['--company' => $company->id])->assertSuccessful();
        self::assertSame($terminalLocation->id, $payment->fresh()->location_id);
    }

    public function test_backfill_requires_an_existing_company(): void
    {
        $this->artisan('treasury:backfill-location-attribution')->assertFailed();
        $this->artisan('treasury:backfill-location-attribution', ['--company' => (string) Str::uuid()])->assertFailed();
    }

    public function test_legacy_unattributed_drawers_leave_repositories_and_documents_unattributed_on_re_run(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND']);
        Location::factory()->count(2)->create(['company_id' => $company->id]);
        $cashAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $repositories = collect(['CASH-01', 'CASH-02'])->map(
            fn (string $code): PaymentRepository => PaymentRepository::factory()->for($company)->create([
                'tenant_id' => $tenant->id,
                'code' => $code,
                'location_id' => null,
                'gl_account_id' => $cashAccount->id,
            ]),
        );
        $method = PaymentMethod::factory()->for($company)->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->for($company)->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $repositories->firstOrFail()->id,
            'location_id' => null,
            'origin' => PaymentOrigin::WebAdmin,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
        ]);
        $instrument = PaymentInstrument::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'LEGACY-NULL-DRAWER',
            'partner_id' => $partner->id,
            'amount' => '10.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'direction' => InstrumentDirection::Inbound,
            'kind' => InstrumentKind::Cheque,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $repositories->last()->id,
            'location_id' => null,
            'created_by' => $user->id,
        ]);

        foreach (range(1, 2) as $_run) {
            $this->artisan('treasury:backfill-location-attribution', ['--company' => $company->id])
                ->expectsOutputToContain('Backfilled 0 payment(s) and 0 instrument(s)')
                ->assertSuccessful();

            self::assertSame(2, PaymentRepository::query()
                ->where('company_id', $company->id)
                ->whereNull('location_id')
                ->count());
            self::assertNull($payment->fresh()->location_id);
            self::assertNull($instrument->fresh()->location_id);
        }
    }

    public function test_all_companies_processes_each_company_in_the_selected_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $payments = collect(['A', 'B'])->map(function (string $suffix) use ($tenant): Payment {
            $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND']);
            $location = Location::factory()->create(['company_id' => $company->id]);
            $repository = PaymentRepository::factory()->for($company)->create([
                'tenant_id' => $tenant->id,
                'code' => 'CASH-'.$suffix,
                'location_id' => $location->id,
            ]);
            $method = PaymentMethod::factory()->for($company)->create(['tenant_id' => $tenant->id]);
            $partner = Partner::factory()->for($company)->create(['tenant_id' => $tenant->id]);

            return Payment::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'payment_method_id' => $method->id,
                'repository_id' => $repository->id,
                'location_id' => null,
                'origin' => PaymentOrigin::WebAdmin,
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::Advance,
            ]);
        });

        $this->artisan('treasury:backfill-location-attribution', [
            '--tenant' => $tenant->id,
            '--all-companies' => true,
        ])->assertSuccessful();

        foreach ($payments as $payment) {
            self::assertNotNull($payment->fresh()->location_id);
        }

        $this->artisan('treasury:backfill-location-attribution', [
            '--company' => $payments->firstOrFail()->company_id,
            '--all-companies' => true,
        ])->assertFailed();
    }
}
