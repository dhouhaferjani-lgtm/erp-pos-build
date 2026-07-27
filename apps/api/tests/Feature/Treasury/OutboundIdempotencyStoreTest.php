<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Exceptions\InstrumentActionConflictException;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OutboundIdempotencyStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_key_is_unique_and_replay_digest_must_match(): void
    {
        $instrument = $this->instrument();
        $event = $this->event($instrument, 'instrument:'.$instrument->id.':clear:1', hash('sha256', 'clear-v1'));

        $event->assertSemanticDigest(hash('sha256', 'clear-v1'));

        $this->expectException(InstrumentActionConflictException::class);
        $event->assertSemanticDigest(hash('sha256', 'clear-v2'));
    }

    public function test_duplicate_action_key_is_rejected_by_the_database(): void
    {
        $instrument = $this->instrument();
        $actionKey = 'instrument:'.$instrument->id.':cancel';
        $this->event($instrument, $actionKey, hash('sha256', 'cancel'));

        $this->expectException(QueryException::class);
        $this->event($instrument, $actionKey, hash('sha256', 'cancel'));
    }

    public function test_action_key_requires_a_semantic_digest(): void
    {
        $instrument = $this->instrument();

        $this->expectException(QueryException::class);
        InstrumentEvent::query()->create([
            'tenant_id' => $instrument->tenant_id,
            'company_id' => $instrument->company_id,
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Cleared,
            'action_key' => 'instrument:'.$instrument->id.':clear:1',
            'payload' => [],
            'occurred_at' => now(),
        ]);
    }

    public function test_presentation_cycle_defaults_to_one_and_is_integer_cast(): void
    {
        $instrument = $this->instrument();

        self::assertSame(1, $instrument->refresh()->presentation_cycle);
    }

    public function test_expense_metadata_instrument_link_is_unique_and_relational(): void
    {
        $instrument = $this->instrument();
        $first = $this->expenseMetadata($instrument);

        self::assertSame($instrument->id, $first->paymentInstrument?->id);

        $this->expectException(QueryException::class);
        $this->expenseMetadata($instrument);
    }

    public function test_expense_metadata_instrument_link_enforces_the_foreign_key(): void
    {
        $this->expectException(QueryException::class);
        $this->expenseMetadata(null, Str::uuid()->toString());
    }

    public function test_linkage_migrations_are_rerunnable(): void
    {
        $migrations = [
            require database_path('migrations/tenant/2026_07_18_100000_add_presentation_cycle_to_payment_instruments.php'),
            require database_path('migrations/tenant/2026_07_18_100100_add_action_key_to_instrument_events.php'),
            require database_path('migrations/tenant/2026_07_18_100200_add_payment_instrument_to_expense_metadata.php'),
        ];

        foreach ($migrations as $migration) {
            $migration->up();
            $migration->up();
        }

        self::assertTrue(Schema::hasColumn('payment_instruments', 'presentation_cycle'));
        self::assertTrue(Schema::hasColumn('instrument_events', 'action_key'));
        self::assertTrue(Schema::hasColumn('instrument_events', 'semantic_digest'));
        self::assertTrue(Schema::hasColumn('expense_metadata', 'payment_instrument_id'));
    }

    private function event(PaymentInstrument $instrument, string $actionKey, string $digest): InstrumentEvent
    {
        return InstrumentEvent::query()->create([
            'tenant_id' => $instrument->tenant_id,
            'company_id' => $instrument->company_id,
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Cleared,
            'from_status' => InstrumentStatus::Received->value,
            'to_status' => InstrumentStatus::Cleared->value,
            'action_key' => $actionKey,
            'semantic_digest' => $digest,
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

    private function expenseMetadata(?PaymentInstrument $instrument, ?string $instrumentId = null): ExpenseMetadata
    {
        if ($instrument === null) {
            $tenant = Tenant::factory()->create();
            $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
            $linkedInstrumentId = $instrumentId;
        } else {
            $tenant = $instrument->tenant;
            $company = $instrument->company;
            $linkedInstrumentId = $instrument->id;
        }

        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $document = Document::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'currency' => 'TND',
        ]);

        return ExpenseMetadata::query()->create([
            'document_id' => $document->id,
            'payment_instrument_id' => $linkedInstrumentId,
        ]);
    }
}
