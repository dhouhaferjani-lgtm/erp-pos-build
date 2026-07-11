<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\Reports\UpcomingPaymentsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UpcomingPaymentsInstrumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Partner $supplier;

    private PaymentMethod $method;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-11 08:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);
        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'name' => 'Instrument Customer',
        ]);
        $this->supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
            'name' => 'Instrument Supplier',
        ]);
        $this->method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TRAITE',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Effet,
        ]);
        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'TND',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_deferred_settled_invoice_appears_once_as_instrument_then_once_as_invoice_after_bounce(): void
    {
        $invoice = Document::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-TRAITE-001',
            'document_date' => '2026-07-01',
            'due_date' => '2026-07-20',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
        ]);
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->method->id,
            'repository_id' => $this->repository->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => '2026-07-11',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);
        $instrument = $this->instrument(
            partner: $this->customer,
            direction: InstrumentDirection::Inbound,
            amount: '100.000',
            maturityDate: '2026-07-20',
        );
        $payment->update(['instrument_id' => $instrument->id]);
        $instrument->update(['payment_id' => $payment->id]);
        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '100.000',
        ]);
        $invoice->refresh()->update(['status' => DocumentStatus::Paid, 'balance_due' => '0.000']);
        $this->assertSame('0.000', $invoice->fresh()?->balance_due);

        $report = $this->app->make(UpcomingPaymentsService::class)->generate($this->company->id, 30);

        $this->assertCount(1, $report->in);
        $this->assertSame('instrument', $report->in[0]->source);
        $this->assertSame($instrument->reference, $report->in[0]->document_number);
        $this->assertSame('2026-07-20', $report->in[0]->due_date);
        $this->assertSame('100.000', $report->total_in);

        $allocation->delete();
        $invoice->refresh()->update(['status' => DocumentStatus::Posted, 'balance_due' => '100.000']);
        $instrument->update([
            'status' => InstrumentStatus::Bounced,
            'dishonor_routing' => DishonorRouting::Receivable,
        ]);
        $this->assertSame('100.000', $invoice->fresh()?->balance_due);

        $reopened = $this->app->make(UpcomingPaymentsService::class)->generate($this->company->id, 30);

        $this->assertCount(1, $reopened->in);
        $this->assertSame('document', $reopened->in[0]->source);
        $this->assertSame('INV-TRAITE-001', $reopened->in[0]->document_number);
        $this->assertSame('100.000', $reopened->total_in);
    }

    public function test_outbound_and_at_sight_instruments_feed_the_correct_forecast_sides(): void
    {
        $outbound = $this->instrument(
            partner: $this->supplier,
            direction: InstrumentDirection::Outbound,
            amount: '40.000',
            maturityDate: '2026-07-16',
        );
        $atSight = $this->instrument(
            partner: $this->customer,
            direction: InstrumentDirection::Inbound,
            amount: '15.000',
            maturityDate: null,
        );

        $report = $this->app->make(UpcomingPaymentsService::class)->generate($this->company->id, 30);

        $this->assertCount(1, $report->in);
        $this->assertSame($atSight->reference, $report->in[0]->document_number);
        $this->assertSame('2026-07-11', $report->in[0]->due_date);
        $this->assertSame(0, $report->in[0]->days_until_due);
        $this->assertSame('portfolio', $report->in[0]->certainty);
        $this->assertCount(1, $report->out);
        $this->assertSame($outbound->reference, $report->out[0]->document_number);
        $this->assertSame('instrument', $report->out[0]->source);
        $this->assertSame('15.000', $report->total_in);
        $this->assertSame('40.000', $report->total_out);
        $this->assertSame('-25.000', $report->net);
    }

    private function instrument(
        Partner $partner,
        InstrumentDirection $direction,
        string $amount,
        ?string $maturityDate,
    ): PaymentInstrument {
        return PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->method->id,
            'reference' => 'EF-'.uniqid(),
            'partner_id' => $partner->id,
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => '2026-07-11',
            'maturity_date' => $maturityDate,
            'status' => InstrumentStatus::Received,
            'direction' => $direction,
            'kind' => InstrumentKind::Effet,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $this->repository->id,
        ]);
    }
}
