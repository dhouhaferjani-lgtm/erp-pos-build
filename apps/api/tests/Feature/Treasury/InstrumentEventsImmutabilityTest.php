<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentEventsImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_and_typed_payload_round_trip(): void
    {
        $instrument = $this->instrument();
        $payload = new InstrumentEventPayload(
            detailsDiff: ['reference' => ['old' => null, 'new' => 'CHK-001']],
            dishonorRouting: DishonorRouting::Receivable,
            feeAmount: '1.000',
            feeVatAmount: '0.190',
            reason: 'Insufficient funds',
            alertKey: 'alert-1',
        );

        $event = InstrumentEvent::query()->create([
            'tenant_id' => $instrument->tenant_id,
            'company_id' => $instrument->company_id,
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Created,
            'from_status' => null,
            'to_status' => InstrumentStatus::Received->value,
            'payload' => $payload->toArray(),
            'occurred_at' => now(),
        ])->fresh();

        $this->assertNotNull($event);
        $this->assertSame(InstrumentEventType::Created, $event->event_type);
        $this->assertSame($instrument->id, $event->instrument->id);
        $this->assertSame($payload->toArray(), InstrumentEventPayload::fromArray($event->payload)->toArray());
    }

    public function test_event_row_cannot_be_updated(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('append-only event trigger is PostgreSQL-specific');
        }

        $event = $this->event();

        try {
            DB::table('instrument_events')->where('id', $event->id)->update(['payload' => '{}']);
            $this->fail('UPDATE should have been rejected');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_event_row_cannot_be_deleted(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('append-only event trigger is PostgreSQL-specific');
        }

        $event = $this->event();

        try {
            DB::table('instrument_events')->where('id', $event->id)->delete();
            $this->fail('DELETE should have been rejected');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_event_migrations_are_re_runnable(): void
    {
        $tableMigration = require database_path('migrations/tenant/2026_07_12_100200_create_instrument_events.php');
        $triggerMigration = require database_path('migrations/tenant/2026_07_12_100300_create_instrument_events_immutability.php');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $tableMigration->up();
            $triggerMigration->up();
        }

        $this->assertTrue(Schema::hasTable('instrument_events'));
    }

    private function event(): InstrumentEvent
    {
        $instrument = $this->instrument();

        return InstrumentEvent::query()->create([
            'tenant_id' => $instrument->tenant_id,
            'company_id' => $instrument->company_id,
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Created,
            'to_status' => InstrumentStatus::Received->value,
            'payload' => [],
            'occurred_at' => now(),
        ]);
    }

    private function instrument(): PaymentInstrument
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $method = PaymentMethod::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CHECK-'.Str::lower(Str::random(8)),
            'name' => 'Check',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
            'is_active' => true,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'CHK-'.Str::upper(Str::random(8)),
            'amount' => '100.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
        ]);
    }
}
