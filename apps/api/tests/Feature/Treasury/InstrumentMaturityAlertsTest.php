<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class InstrumentMaturityAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_alert_payload_uses_country_window_and_includes_only_actionable_instruments(): void
    {
        Carbon::setTestNow('2026-07-11 05:00:00');

        $tenant = Tenant::factory()->create();
        Country::query()->firstOrCreate(
            ['code' => 'TN'],
            ['name' => 'Tunisia', 'currency_code' => 'TND', 'currency_symbol' => 'DT'],
        );
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $method = $this->method($tenant, $company);

        CountryPaymentSettings::query()->updateOrCreate(['country_code' => 'TN'], [
            'instrument_alert_days' => 10,
        ]);

        $receivedDue = $this->instrument($tenant, $company, $method, [
            'reference' => 'RECEIVED-DUE',
            'status' => 'received',
            'maturity_date' => '2026-07-21',
        ]);
        $this->instrument($tenant, $company, $method, [
            'reference' => 'RECEIVED-OUTSIDE',
            'status' => 'received',
            'maturity_date' => '2026-07-22',
        ]);
        $depositedOverdue = $this->instrument($tenant, $company, $method, [
            'reference' => 'DEPOSITED-OVERDUE',
            'status' => 'deposited',
            'maturity_date' => '2026-06-30',
            'deposited_at' => '2026-06-25 10:00:00',
        ]);
        $this->instrument($tenant, $company, $method, [
            'reference' => 'DEPOSITED-TOO-RECENT',
            'status' => 'deposited',
            'maturity_date' => '2026-07-01',
            'deposited_at' => '2026-06-25 10:00:00',
        ]);
        $this->instrument($tenant, $company, $method, [
            'reference' => 'OUTBOUND-IGNORED',
            'status' => 'received',
            'direction' => 'outbound',
            'maturity_date' => '2026-07-12',
        ]);

        $this->assertSame(0, Artisan::call('treasury:instrument-maturity-alerts'));

        $event = AuditEvent::query()
            ->where('company_id', $company->id)
            ->where('event_type', 'treasury.instrument.maturity_alert')
            ->sole();

        $this->assertSame(10, $event->payload['window_days']);
        $this->assertSame('2026-07-11', $event->payload['as_of_date']);
        $this->assertSame(1, $event->payload['received_due_count']);
        $this->assertSame([$receivedDue->id], $event->payload['received_due_ids']);
        $this->assertSame(1, $event->payload['deposited_overdue_count']);
        $this->assertSame([$depositedOverdue->id], $event->payload['deposited_overdue_ids']);

        $this->assertSame(0, Artisan::call('treasury:instrument-maturity-alerts'));
        $this->assertSame(2, AuditEvent::query()
            ->where('company_id', $company->id)
            ->where('event_type', 'treasury.instrument.maturity_alert')
            ->count());
    }

    public function test_maturity_alert_sends_one_notification_per_user_per_company(): void
    {
        Carbon::setTestNow('2026-07-11 05:00:00');

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $manager = $this->createTreasuryManager($tenant, $company);
        $secondCompany = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $manager->id,
            'company_id' => $secondCompany->id,
            'role' => 'manager',
            'status' => 'active',
        ]);
        $method = $this->method($tenant, $company);
        $secondMethod = $this->method($tenant, $secondCompany);
        $this->instrument($tenant, $company, $method, ['reference' => 'DUE-ONE']);
        $this->instrument($tenant, $company, $method, ['reference' => 'DUE-TWO']);
        $this->instrument($tenant, $secondCompany, $secondMethod, ['reference' => 'DUE-OTHER-COMPANY']);

        $this->assertSame(0, Artisan::call('treasury:instrument-maturity-alerts'));

        $notifications = DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('type', 'treasury.instrument.maturity_alert')
            ->get();
        $this->assertCount(2, $notifications);
        $notification = $notifications->first(
            static fn (object $row): bool => json_decode((string) $row->data, true, flags: JSON_THROW_ON_ERROR)['company_id'] === $company->id,
        );
        $this->assertNotNull($notification);
        $data = json_decode((string) $notification->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($company->id, $data['company_id']);
        $this->assertSame(2, $data['received_due_count']);
        $this->assertSame('/treasury/instruments?maturing=1', $data['deep_link']);
    }

    public function test_maturity_alert_sends_no_notification_when_counts_are_zero(): void
    {
        Carbon::setTestNow('2026-07-11 05:00:00');

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $manager = $this->createTreasuryManager($tenant, $company);

        $this->assertSame(0, Artisan::call('treasury:instrument-maturity-alerts'));
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $company->id,
            'event_type' => 'treasury.instrument.maturity_alert',
        ]);
        $this->assertSame(0, DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('type', 'treasury.instrument.maturity_alert')
            ->count());
    }

    public function test_company_failure_does_not_abort_later_companies_and_returns_failure(): void
    {
        Carbon::setTestNow('2026-07-11 05:00:00');

        $tenant = Tenant::factory()->create();
        $failingCompany = Company::factory()->create([
            'id' => '00000000-0000-4000-8000-000000000001',
            'tenant_id' => $tenant->id,
        ]);
        $laterCompany = Company::factory()->create([
            'id' => '00000000-0000-4000-8000-000000000002',
            'tenant_id' => $tenant->id,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['company_id'] === $failingCompany->id)
            ->andThrow(new RuntimeException('simulated company alert-channel failure'));
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['company_id'] === $laterCompany->id);

        $this->assertSame(1, Artisan::call('treasury:instrument-maturity-alerts'));

        $this->assertDatabaseHas('audit_events', [
            'company_id' => $laterCompany->id,
            'event_type' => 'treasury.instrument.maturity_alert',
        ]);
    }

    public function test_command_is_scheduled_daily_at_0630_without_background_execution(): void
    {
        $output = Artisan::call('schedule:list');

        $this->assertSame(0, $output);
        $schedule = Artisan::output();
        $this->assertStringContainsString('treasury:instrument-maturity-alerts', $schedule);
        $this->assertMatchesRegularExpression('/30\s+6\s+\*\s+\*\s+\*/', $schedule);
    }

    public function test_alert_boundary_uses_each_company_timezone(): void
    {
        Carbon::setTestNow('2026-07-11 23:30:00 UTC');

        $tenant = Tenant::factory()->create();
        Country::query()->firstOrCreate(
            ['code' => 'TN'],
            ['name' => 'Tunisia', 'currency_code' => 'TND', 'currency_symbol' => 'DT'],
        );
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'Pacific/Kiritimati',
        ]);
        $method = $this->method($tenant, $company);
        CountryPaymentSettings::query()->updateOrCreate(['country_code' => 'TN'], [
            'instrument_alert_days' => 0,
        ]);
        $instrument = $this->instrument($tenant, $company, $method, [
            'maturity_date' => '2026-07-12',
        ]);

        $this->assertSame(0, Artisan::call('treasury:instrument-maturity-alerts'));

        $event = AuditEvent::query()
            ->where('company_id', $company->id)
            ->where('event_type', 'treasury.instrument.maturity_alert')
            ->sole();
        $this->assertSame('2026-07-12', $event->payload['as_of_date']);
        $this->assertSame([$instrument->id], $event->payload['received_due_ids']);
    }

    private function method(Tenant $tenant, Company $company): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'has_maturity' => true,
        ]);
    }

    private function createTreasuryManager(Tenant $tenant, Company $company): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate('treasury.manage', 'sanctum');

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('treasury.manage');
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function instrument(Tenant $tenant, Company $company, PaymentMethod $method, array $overrides): PaymentInstrument
    {
        return PaymentInstrument::create(array_merge([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'INST-'.Str::upper(Str::random(8)),
            'amount' => '100.000',
            'currency' => $company->currency,
            'received_date' => '2026-06-01',
            'maturity_date' => '2026-07-11',
            'status' => 'received',
            'direction' => 'inbound',
            'origin' => 'web',
        ], $overrides));
    }
}
