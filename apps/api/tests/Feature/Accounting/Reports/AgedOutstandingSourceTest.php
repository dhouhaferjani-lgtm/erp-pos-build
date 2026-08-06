<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Accounting\Application\Services\Reports\AgedPayablesService;
use App\Modules\Accounting\Application\Services\Reports\AgedReceivablesService;
use App\Modules\Accounting\Presentation\Controllers\ReportsController;
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
use App\Modules\Treasury\Domain\PaymentAllocation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * W-6 D2 — aged AR/AP were blind to never-paid documents.
 *
 * `documents.balance_due` is a PostgreSQL trigger cache fired by
 * `payment_allocations` / `credit_note_allocations` DML ONLY
 * (`2026_01_08_214145_add_balance_due_cache_trigger.php`). A posted document that
 * was never allocated against has no trigger event, so its `balance_due` stays
 * NULL forever — and the reports' `where('balance_due', '>', 0)` predicate hid it.
 * On the demo tenant that was 165 posted invoices / 59 532.410 TND invisible to a
 * report whose own grand total was 32 892.422.
 *
 * The fix is the owner-preferred migration-free one: read the COMPUTED
 * outstanding (`Document::outstandingBalance()`), which mirrors the trigger's own
 * formula `total − Σpayment_allocations − Σcredit_note_allocations` and needs no
 * cache to be warm.
 *
 * docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md (D2)
 */
final class AgedOutstandingSourceTest extends TestCase
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

    // ---------------------------------------------------------------- D2 ----

    public function test_a_posted_never_allocated_invoice_is_visible_to_aged_receivables(): void
    {
        // The exact D2 shape: posted, wholly unpaid, so no allocation DML ever
        // fired and the PostgreSQL balance_due cache stayed NULL.
        $invoice = $this->invoice('INV-NEVER-PAID', '900.000', dueDate: '2026-07-20', balanceDue: null);
        self::assertNull($invoice->balance_due, 'Precondition: a never-allocated document has a NULL balance_due cache');

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('900.0000', $report->grand_total, 'A posted unpaid invoice must reach aged receivables');
        self::assertCount(1, $report->lines);
        self::assertSame('Clinique Ennasr', $report->lines[0]->customer_name);
        self::assertSame('900.0000', $report->lines[0]->current);
    }

    public function test_aged_receivables_reports_the_outstanding_amount_not_the_cached_column(): void
    {
        $invoice = $this->invoice('INV-PARTIAL', '900.000', dueDate: '2026-07-20', balanceDue: null);
        PaymentAllocation::create(['document_id' => $invoice->id, 'amount' => '350.000']);

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('550.0000', $report->grand_total, 'Outstanding = total - allocations');
    }

    public function test_a_fully_allocated_invoice_with_a_cold_cache_is_excluded(): void
    {
        $invoice = $this->invoice('INV-SETTLED', '900.000', dueDate: '2026-07-20', balanceDue: null);
        PaymentAllocation::create(['document_id' => $invoice->id, 'amount' => '900.000']);

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('0.0000', $report->grand_total, 'A settled invoice must not be reported outstanding');
        self::assertCount(0, $report->lines);
    }

    /**
     * TREASURY GATE, CRITICAL 2 — a NON-NULL `balance_due` is AUTHORITATIVE.
     *
     * `ArApOpeningService:293-313` creates posted historical invoices with
     * `balance_due = open_amount` and `total = total`, where `open_amount <= total`
     * is an explicitly supported input (`:204-209`) — a partially settled legacy
     * invoice migrated at go-live. NO `payment_allocations` row is ever written for
     * these, so a formula of `total − allocations` reports the FULL total and
     * overstates the receivable by everything the customer already paid before the
     * migration. This is the first-tenant go-live path (`PartiesBalancesPhase`).
     *
     * The computed formula therefore applies ONLY where the cache is genuinely
     * blind — `balance_due IS NULL` — which is the actual D2 defect.
     */
    public function test_an_opening_balance_document_reports_its_open_amount_not_its_total(): void
    {
        $this->invoice('HIST-INV-000001', '1000.000', dueDate: '2026-07-20', balanceDue: '300.000');

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame(
            '300.0000',
            $report->grand_total,
            'A migrated opening balance is outstanding for its open_amount, never its total',
        );
        self::assertSame('300.0000', $report->lines[0]->current);
    }

    public function test_an_opening_balance_purchase_order_reports_its_open_amount(): void
    {
        $this->purchaseOrder('HIST-PO-000001', '1000.000', dueDate: '2026-07-20', balanceDue: '300.000');

        $report = app(AgedPayablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('300.0000', $report->grand_total);
    }

    public function test_a_fully_settled_opening_balance_is_excluded(): void
    {
        $this->invoice('HIST-INV-000002', '1000.000', dueDate: '2026-07-20', balanceDue: '0.000');

        $report = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('0.0000', $report->grand_total);
        self::assertCount(0, $report->lines);
    }

    public function test_a_posted_never_allocated_purchase_order_is_visible_to_aged_payables(): void
    {
        $purchaseOrder = $this->purchaseOrder('PO-NEVER-PAID', '750.000', dueDate: '2026-07-20', balanceDue: null);
        self::assertNull($purchaseOrder->balance_due);

        $report = app(AgedPayablesService::class)->generate($this->company->id, Carbon::parse('2026-07-06'));

        self::assertSame('750.0000', $report->grand_total);
        self::assertCount(1, $report->lines);
        self::assertSame('750.0000', $report->lines[0]->current);
    }

    /**
     * TREASURY GATE — `Accounting/Presentation/routes.php` and
     * `Document/Presentation/routes.php` both registered
     * `GET api/v1/reports/aged-receivables` under the SAME route name. Laravel's
     * `RouteCollection::addToCollections()` keys on method+URI, so the LAST
     * provider to boot (`AccountingServiceProvider`, after `DocumentServiceProvider`
     * per `bootstrap/providers.php`) fully overwrites the first — Accounting's
     * D2+D4-fixed controller is served; the Document module's registration is
     * DEAD CODE that still carries both defects (`balance_due ?? total`,
     * `balance_due ?? '0.00'`, `whereRaw('balance_due > 0')`) and a DIFFERENT
     * bucket contract. Deleted here; this pins the served route so the
     * collision cannot silently come back if `bootstrap/providers.php`'s order
     * ever changes or a route cache is built differently.
     */
    public function test_the_aged_receivables_route_resolves_to_the_accounting_controller(): void
    {
        $route = Route::getRoutes()->getByName('reports.aged-receivables');

        self::assertNotNull($route, 'The named route must exist');
        self::assertSame(
            ReportsController::class,
            $route->getAction('controller') !== null
                ? explode('@', (string) $route->getAction('controller'))[0]
                : null,
            'reports.aged-receivables must resolve to the Accounting module controller — its Document-module '
                .'twin still carries the D2/D4 defects this lane fixed',
        );
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
