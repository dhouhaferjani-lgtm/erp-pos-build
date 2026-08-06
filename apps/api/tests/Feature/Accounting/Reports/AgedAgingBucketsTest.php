<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Accounting\Application\Services\Reports\AgedPayablesService;
use App\Modules\Accounting\Application\Services\Reports\AgedReceivablesService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-6 D4 — the aged AR/AP aging buckets were sign-inverted.
 *
 * Carbon's signed `diffInDays($other, false)` returns ARGUMENT − RECEIVER, and
 * both services called it as `$asOfDate->diffInDays($reference, false)` — i.e.
 * `reference − asOf`, which is NEGATIVE for a document that is already overdue.
 * `determineBucket()` maps any negative day count to `current`, so every overdue
 * receivable on the tenant was reported as Current and nothing had ever aged out
 * of it. The inversion ran both ways: a not-yet-due invoice produced a POSITIVE
 * count and was aged as though it were late.
 *
 * `AgedPayablesService` carried the byte-identical defect and is pinned here too.
 *
 * docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md (D4)
 */
final class AgedAgingBucketsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-06'));

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'name' => 'Clinique Ennasr',
        ]);
        $this->supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
            'name' => 'Medis Distribution',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- D4 ----

    public function test_a_45_day_overdue_invoice_is_aged_into_the_31_60_bucket(): void
    {
        // Due 2026-05-22, as-of 2026-07-06 => 45 days overdue.
        $this->invoice('INV-OVERDUE-45', '900.000', dueDate: '2026-05-22', balanceDue: '900.000');

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('0.0000', $report->total_current, 'A 45-day-overdue invoice must not be reported as Current');
        self::assertSame('900.0000', $report->total_days_30, 'A 45-day-overdue invoice belongs in the 31-60 bucket');
        self::assertSame('900.0000', $report->grand_total);
        self::assertCount(1, $report->lines);
        self::assertSame('900.0000', $report->lines[0]->days_30);
        self::assertSame('0.0000', $report->lines[0]->current);
    }

    public function test_aging_buckets_span_the_whole_ladder_in_the_right_direction(): void
    {
        $this->invoice('INV-NOT-DUE', '10.000', dueDate: '2026-08-06', balanceDue: '10.000');   // due in 31 days
        $this->invoice('INV-DUE-TODAY', '20.000', dueDate: '2026-07-06', balanceDue: '20.000'); // 0 days overdue
        $this->invoice('INV-30', '30.000', dueDate: '2026-06-16', balanceDue: '30.000');        // 20 days overdue
        $this->invoice('INV-60', '40.000', dueDate: '2026-05-22', balanceDue: '40.000');        // 45 days overdue
        $this->invoice('INV-90', '50.000', dueDate: '2026-04-22', balanceDue: '50.000');        // 75 days overdue
        $this->invoice('INV-120', '60.000', dueDate: '2026-03-23', balanceDue: '60.000');       // 105 days overdue
        $this->invoice('INV-OVER-90', '70.000', dueDate: '2026-01-06', balanceDue: '70.000');   // 181 days overdue

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('60.0000', $report->total_current, 'Not-yet-due and 0-30 days overdue are Current');
        self::assertSame('40.0000', $report->total_days_30);
        self::assertSame('50.0000', $report->total_days_60);
        self::assertSame('60.0000', $report->total_days_90);
        self::assertSame('70.0000', $report->total_over_90);
        self::assertSame('280.0000', $report->grand_total);
    }

    public function test_a_45_day_overdue_purchase_order_is_aged_into_the_31_60_bucket(): void
    {
        $this->purchaseOrder('PO-OVERDUE-45', '750.000', dueDate: '2026-05-22', balanceDue: '750.000');

        $report = app(AgedPayablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('0.0000', $report->total_current, 'A 45-day-overdue payable must not be reported as Current');
        self::assertSame('750.0000', $report->total_days_30);
        self::assertSame('750.0000', $report->grand_total);
    }

    // ------------------------------------------------------------ helpers ---

    /**
     * `$balanceDue` defaults to NULL on purpose: that is what a real
     * never-allocated document carries, because the cache is trigger-maintained.
     */
    private function invoice(
        string $number,
        string $total,
        string $dueDate,
        ?string $balanceDue = null,
    ): Document {
        return $this->document(DocumentType::Invoice, $this->customer, $number, $total, $dueDate, $balanceDue);
    }

    private function purchaseOrder(
        string $number,
        string $total,
        string $dueDate,
        ?string $balanceDue = null,
    ): Document {
        return $this->document(DocumentType::PurchaseOrder, $this->supplier, $number, $total, $dueDate, $balanceDue);
    }

    private function document(
        DocumentType $type,
        Partner $partner,
        string $number,
        string $total,
        string $dueDate,
        ?string $balanceDue,
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => '2026-01-06',
            'due_date' => $dueDate,
            'currency' => 'TND',
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $balanceDue,
        ]);
    }
}
