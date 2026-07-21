<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentLifecycleReceiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_persists_instrument_and_created_event_atomically(): void
    {
        $context = $this->context();

        $location = Location::factory()->create(['company_id' => $context['company']->id]);
        $instrument = $this->service()->receive($this->receiveData($context, locationId: $location->id));

        $this->assertSame(InstrumentStatus::Received, $instrument->status);
        $this->assertSame($location->id, $instrument->location_id);
        $this->assertSame('125.000', $instrument->amount);
        $event = InstrumentEvent::query()->where('instrument_id', $instrument->id)->firstOrFail();
        $this->assertSame(InstrumentEventType::Created, $event->event_type);
        $this->assertNull($event->from_status);
        $this->assertSame(InstrumentStatus::Received->value, $event->to_status);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('repository_movements', 0);
    }

    public function test_receive_rejects_currency_outside_the_company_currency(): void
    {
        $context = $this->context();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Instrument currency must match company currency.');

        try {
            $this->service()->receive($this->receiveData($context, currency: 'EUR'));
        } finally {
            $this->assertDatabaseCount('payment_instruments', 0);
            $this->assertDatabaseCount('instrument_events', 0);
        }
    }

    public function test_update_details_is_received_only_and_records_diff(): void
    {
        $context = $this->context();
        $instrument = $this->service()->receive($this->receiveData($context, needsDetails: true));

        $updated = $this->service()->updateDetails($instrument->id, [
            'reference' => 'CHK-COMPLETE',
            'maturity_date' => '2026-08-01',
        ], $context['user']->id);

        $this->assertSame('CHK-COMPLETE', $updated->reference);
        $this->assertFalse($updated->needs_details);
        $event = InstrumentEvent::query()
            ->where('instrument_id', $instrument->id)
            ->where('event_type', InstrumentEventType::DetailsUpdated)
            ->firstOrFail();
        $this->assertSame('CHK-COMPLETE', $event->payload['details_diff']['reference']['new']);

        $updated->update(['status' => InstrumentStatus::Deposited]);
        $this->expectException(DomainException::class);
        $this->service()->updateDetails($instrument->id, ['reference' => 'NOPE'], $context['user']->id);
    }

    public function test_at_sight_cheque_clears_needs_details_without_maturity_date(): void
    {
        $context = $this->context();
        $instrument = $this->service()->receive($this->receiveData($context, needsDetails: true));

        $updated = $this->service()->updateDetails($instrument->id, [
            'reference' => 'CHK-AT-SIGHT-COMPLETE',
        ], $context['user']->id);

        $this->assertFalse($updated->needs_details);
    }

    public function test_custody_transfer_rejects_deposited_instrument(): void
    {
        $context = $this->context();
        $instrument = $this->service()->receive($this->receiveData($context));
        $instrument->update(['status' => InstrumentStatus::Deposited]);

        $this->expectException(DomainException::class);
        $this->service()->custodyTransfer($instrument->id, $context['otherRepository']->id, $context['user']->id);
    }

    public function test_unlinked_received_instrument_cancels_without_gl_or_movement(): void
    {
        $context = $this->context();
        $instrument = $this->service()->receive($this->receiveData($context));

        $this->service()->cancel($instrument->id, $context['user']->id, 'Registration mistake');

        $this->assertSame(InstrumentStatus::Cancelled, $instrument->fresh()?->status);
        $this->assertDatabaseHas('instrument_events', [
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Cancelled->value,
        ]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('repository_movements', 0);

        $this->expectException(DomainException::class);
        $this->service()->cancel($instrument->id, $context['user']->id, 'Duplicate cancel');
    }

    public function test_linked_active_payment_cannot_be_cancelled(): void
    {
        $context = $this->context();
        $instrument = $this->service()->receive($this->receiveData($context));
        $this->linkPayment($context, $instrument, PaymentStatus::Completed, null);

        $this->expectException(DomainException::class);
        $this->service()->cancel($instrument->id, $context['user']->id, 'Not allowed');
    }

    public function test_reversed_payment_cancellation_posts_b2b_counter_entry(): void
    {
        $context = $this->context();
        app(ChartOfAccountsService::class)->seedForCompany($context['company']);
        $instrument = $this->service()->receive($this->receiveData($context));
        $receiptEntry = JournalEntry::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'entry_number' => 'ORIGINAL-RECEIPT',
            'entry_date' => now(),
            'description' => 'Original receipt provenance',
            'status' => JournalEntryStatus::Draft,
        ]);
        $this->linkPayment($context, $instrument, PaymentStatus::Reversed, $receiptEntry->id);

        $this->service()->cancel(
            $instrument->id,
            $context['user']->id,
            'Payment reversed',
            CancellationShape::B2b,
        );

        $entry = JournalEntry::query()
            ->where('source_type', 'instrument')
            ->where('source_id', $instrument->id)
            ->with('lines.account')
            ->firstOrFail();
        $this->assertSame('EF', $entry->journal_code?->value);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $debit = $entry->lines->firstWhere('debit', '125.000');
        $credit = $entry->lines->firstWhere('credit', '125.000');
        $this->assertSame('411', $debit?->account->code);
        $this->assertSame('5312', $credit?->account->code);
        $this->assertDatabaseCount('repository_movements', 0);
    }

    private function service(): InstrumentLifecycleService
    {
        return app(InstrumentLifecycleService::class);
    }

    /**
     * @param  array{tenant: Tenant, company: Company, user: User, partner: Partner, method: PaymentMethod, repository: PaymentRepository, otherRepository: PaymentRepository}  $context
     */
    private function receiveData(array $context, bool $needsDetails = false, string $currency = 'TND', ?string $locationId = null): ReceiveInstrumentData
    {
        return new ReceiveInstrumentData(
            tenantId: $context['tenant']->id,
            companyId: $context['company']->id,
            paymentMethodId: $context['method']->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Web,
            reference: 'CHK-'.Str::upper(Str::random(8)),
            amount: '125.000',
            currency: $currency,
            repositoryId: $context['repository']->id,
            partnerId: $context['partner']->id,
            receivedDate: '2026-07-11',
            needsDetails: $needsDetails,
            createdBy: $context['user']->id,
            locationId: $locationId,
        );
    }

    /**
     * @param  array{tenant: Tenant, company: Company, user: User, partner: Partner, method: PaymentMethod, repository: PaymentRepository, otherRepository: PaymentRepository}  $context
     */
    private function linkPayment(
        array $context,
        PaymentInstrument $instrument,
        PaymentStatus $status,
        ?string $journalEntryId,
    ): Payment {
        $payment = Payment::factory()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'partner_id' => $context['partner']->id,
            'payment_method_id' => $context['method']->id,
            'instrument_id' => $instrument->id,
            'repository_id' => $context['repository']->id,
            'amount' => '125.000',
            'currency' => 'TND',
            'status' => $status,
            'journal_entry_id' => $journalEntryId,
        ]);
        $instrument->update(['payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * @return array{tenant: Tenant, company: Company, user: User, partner: Partner, method: PaymentMethod, repository: PaymentRepository, otherRepository: PaymentRepository}
     */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $repository = PaymentRepository::factory()->for($company)->create(['tenant_id' => $tenant->id]);
        $otherRepository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'OTHER-'.Str::upper(Str::random(6)),
        ]);

        return compact('tenant', 'company', 'user', 'partner', 'method', 'repository', 'otherRepository');
    }
}
