<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\Conversion\Converters\PurchaseQuoteRequestToPurchaseOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Document\Domain\Services\PurchaseOrderService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateRfqData;
use App\Modules\Procurement\Application\PurchaseQuoteRequestAwardService;
use App\Modules\Procurement\Application\PurchaseQuoteRequestService;
use App\Modules\Procurement\Application\UpdateRfqData;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseQuoteRequestAwardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $clonedConnections = [];

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'RFQ Award Tenant',
            'slug' => 'rfq-award-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'RFQ Award Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Creme solaire SPF50',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->clonedConnections as $name) {
            try {
                $connection = DB::connection($name);
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // Nothing to clean up for connections that were not opened.
            }

            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }

        $this->clonedConnections = [];

        parent::tearDown();
    }

    public function test_award_creates_draft_po_and_closes_losing_siblings(): void
    {
        [$a, $b, $c] = $this->respondedGroup();

        $po = app(PurchaseQuoteRequestAwardService::class)->award($b->id, $this->tenant->id, $this->company->id);

        $this->assertSame(DocumentType::PurchaseOrder, $po->type);
        $this->assertSame(DocumentStatus::Draft, $po->status);
        $this->assertSame($b->id, $po->source_document_id);
        $this->assertSame($b->partner_id, $po->partner_id);
        $this->assertSame('8.200', $po->lines->first()?->unit_price);

        $this->assertSame(DocumentStatus::Cancelled, $a->refresh()->status);
        $this->assertSame('lost', $a->payload['rfq']['closed_reason']);
        $this->assertSame(DocumentStatus::Cancelled, $c->refresh()->status);
        $this->assertSame('lost', $c->payload['rfq']['closed_reason']);
    }

    /**
     * Ticket 2026-08-03-w4-purchasing-inventory-defects.md #3 (MTP-RFQ-06).
     *
     * CreatePurchaseQuoteRequestRequest / UpdatePurchaseQuoteRequestRequest never
     * declare a `tax_rate` field, so an RFQ line's tax_rate is always null, and the
     * award converter used to copy it straight through — an RFQ-awarded PO carried
     * NO tax rate at all, so PurchaseOrderService::confirmAndAllocateCosts (which
     * computes tax FROM the line's tax_rate) could never invent one: tax_amount
     * stayed 0.000 even after confirm. Fixed by resolving a default rate (product
     * tax_rate / tax configuration / company default — the exact chain
     * DraftPurchaseOrderService already uses for the replenishment path) at the
     * converter boundary when the RFQ line carries none.
     */
    public function test_award_carries_a_default_tax_rate_when_the_rfq_line_has_none(): void
    {
        $taxedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Taxed RFQ product',
            'tax_rate' => '19.00',
        ]);

        $supplier = $this->supplier('Taxed RFQ Supplier');

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [
                    [
                        'product_id' => $taxedProduct->id,
                        'quantity' => '10.0000',
                        'unit_price' => null,
                        'description' => 'Taxed RFQ line',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );

        $rfq = $created->first();
        $this->assertNotNull($rfq);

        $responded = app(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [
                    [
                        'id' => $rfq->lines->first()?->id,
                        'product_id' => $taxedProduct->id,
                        'quantity' => '10.0000',
                        'unit_price' => '12.500',
                        'description' => 'Taxed RFQ line',
                    ],
                ],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );

        $po = app(PurchaseQuoteRequestAwardService::class)->award($responded->id, $this->tenant->id, $this->company->id);

        // RFQ line carried no tax_rate — the PO line must now carry the resolved
        // default (the product's own tax_rate, since the line specified none).
        $poLine = $po->lines->first();
        $this->assertNotNull($poLine);
        $this->assertSame('19.00', $poLine->tax_rate);
        $this->assertSame('125.000', $po->subtotal);
        // Gate finding M-3: line-level tax fields must be populated the same way
        // DraftPurchaseOrderService::persistLines() populates them on the
        // sibling replenishment path — not left NULL.
        $this->assertSame('23.750', $poLine->tax_amount, '19% of the line total 125.000');
        $this->assertTrue((bool) $poLine->tax_recoverable);
        $this->assertSame('23.750', $poLine->recoverable_tax_amount);
        $this->assertSame('0.000', $poLine->non_recoverable_tax_amount);

        // Gate finding I-1: the awarded DRAFT must show its OWN taxed header, not
        // the RFQ's always-untaxed subtotal/tax_amount/total copied verbatim. The
        // converter now recomputes the header from the resolved line rates
        // (Concerns/CopiesDocumentData::recalculateTotals()) BEFORE confirm ever
        // runs — matching how a hand-authored or replenishment-sourced draft PO
        // already shows its taxed totals immediately.
        $this->assertSame('23.750', $po->tax_amount, 'the DRAFT header must already carry 19% VAT, not wait for confirm');
        $this->assertSame('148.750', $po->total);
        // Gate finding I-2: balance_due must never diverge from total. Recomputed
        // by the same call, so it agrees with total from the moment of award.
        $this->assertSame('148.750', $po->balance_due);

        // Confirm is where a PO's taxes are calculated and snapshotted
        // (PurchaseOrderService::confirmAndAllocateCosts) — it recomputes tax_amount
        // and total from the now-correctly-rated lines via TaxCalculationService,
        // independently confirming the draft's own header was already right.
        $confirmed = app(PurchaseOrderService::class)->confirm($po);

        $this->assertSame('23.750', $confirmed->tax_amount, '19% of 125.000');
        $this->assertSame('148.750', $confirmed->total);
        // Gate finding I-2: PurchaseOrderService::confirmAndAllocateCosts updates
        // ONLY tax_amount and total, never balance_due — so this is the one place
        // the pre-fix mismatch (total 148.750, balance_due still 125.000) would
        // have surfaced. It must still agree post-confirm.
        $this->assertSame($confirmed->total, $confirmed->balance_due, 'balance_due must equal total after confirm, not the stale draft value');
    }

    /**
     * Gate finding M-1(a) — an explicit line rate must win over the product
     * default. The RFQ create/update contract has no field for it today (the
     * ticket's whole premise), so the line is stamped directly at the DB row
     * AFTER recordResponse() (which would otherwise wipe it via replaceLines'
     * delete+recreate) to prove the resolver's precedence, not assume it.
     */
    public function test_award_prefers_an_explicit_line_tax_rate_over_the_product_default(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Explicit-rate-wins product',
            'tax_rate' => '19.00',
        ]);
        $supplier = $this->supplier('Explicit Rate Supplier');

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [[
                    'product_id' => $product->id,
                    'quantity' => '4.0000',
                    'unit_price' => null,
                    'description' => 'Explicit-rate-wins line',
                ]],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );
        $rfq = $created->first();
        $this->assertNotNull($rfq);

        $responded = app(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [[
                    'id' => $rfq->lines->first()?->id,
                    'product_id' => $product->id,
                    'quantity' => '4.0000',
                    'unit_price' => '10.000',
                    'description' => 'Explicit-rate-wins line',
                ]],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );

        $respondedLine = $responded->lines->first();
        $this->assertNotNull($respondedLine);
        $respondedLine->update(['tax_rate' => '7.00']);

        $po = app(PurchaseQuoteRequestAwardService::class)->award($responded->id, $this->tenant->id, $this->company->id);

        $this->assertSame('7.00', $po->lines->first()?->tax_rate, 'explicit line rate must win over the product default (19.00)');
    }

    /**
     * Gate finding M-1(b) — a legitimately 0%-configured product must NOT be
     * bumped to a nonzero company default. `hasNumericValue()` is a presence
     * test, not a truthiness test, so '0.00' must be distinguished from NULL.
     * The company default is set to a NONZERO rate specifically so a bug that
     * treats '0.00' as "missing" would be caught (it would resolve to '19.00').
     */
    public function test_award_does_not_bump_a_configured_zero_product_rate_to_the_company_default(): void
    {
        $this->company->update(['default_tax_rate' => '19.00']);

        $exemptProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Configured-zero product',
            'tax_rate' => '0.00',
        ]);
        $supplier = $this->supplier('Configured Zero Supplier');

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [[
                    'product_id' => $exemptProduct->id,
                    'quantity' => '3.0000',
                    'unit_price' => null,
                    'description' => 'Configured-zero line',
                ]],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );
        $rfq = $created->first();
        $this->assertNotNull($rfq);

        $responded = app(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [[
                    'id' => $rfq->lines->first()?->id,
                    'product_id' => $exemptProduct->id,
                    'quantity' => '3.0000',
                    'unit_price' => '10.000',
                    'description' => 'Configured-zero line',
                ]],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );

        $po = app(PurchaseQuoteRequestAwardService::class)->award($responded->id, $this->tenant->id, $this->company->id);

        $this->assertSame('0.00', $po->lines->first()?->tax_rate, 'a configured 0% product rate must NOT be bumped to the nonzero company default');
        $this->assertSame('0.000', $po->tax_amount);
        $this->assertSame('30.000', $po->total);
    }

    /**
     * Gate finding M-1(c) — a product-less RFQ line (no product to consult at
     * all in the resolver's chain) must fall all the way through to the
     * company default, not error or silently resolve to '0.00'. The RFQ
     * create/update FormRequests require product_id (CreatePurchaseQuoteRequestRequest
     * / UpdatePurchaseQuoteRequestRequest), and the CreateRfqData/UpdateRfqData
     * DTOs type `product_id` as a non-nullable `string` too, so this state is
     * never reachable through the normal create/respond calls. Forced at the DB
     * row directly (mirroring the explicit-rate-wins test's technique above) —
     * a legitimate defensive case (e.g. a product later hard-deleted out from
     * under a line) the resolver must still handle without erroring.
     */
    public function test_award_resolves_company_default_tax_rate_for_a_product_less_rfq_line(): void
    {
        $this->company->update(['default_tax_rate' => '13.00']);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Orphaned-away product',
            // Deliberately a DIFFERENT nonzero rate than the company default —
            // if the resolver wrongly kept consulting this product after its id
            // is cleared from the line, the assertion below would catch it.
            'tax_rate' => '19.00',
        ]);
        $supplier = $this->supplier('Product-less RFQ Supplier');

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [[
                    'product_id' => $product->id,
                    'quantity' => '5.0000',
                    'unit_price' => null,
                    'description' => 'Product-less freight line',
                ]],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );
        $rfq = $created->first();
        $this->assertNotNull($rfq);

        $responded = app(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [[
                    'id' => $rfq->lines->first()?->id,
                    'product_id' => $product->id,
                    'quantity' => '5.0000',
                    'unit_price' => '2.000',
                    'description' => 'Product-less freight line',
                ]],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );

        $respondedLine = $responded->lines->first();
        $this->assertNotNull($respondedLine);
        $respondedLine->update(['product_id' => null]);

        $po = app(PurchaseQuoteRequestAwardService::class)->award($responded->id, $this->tenant->id, $this->company->id);

        $this->assertSame('13.00', $po->lines->first()?->tax_rate, 'a product-less line must fall through to the company default, not error and not use the orphaned product rate');
    }

    public function test_second_award_is_rejected_once_group_has_live_po(): void
    {
        [$a, $b] = $this->respondedGroup(2);

        app(PurchaseQuoteRequestAwardService::class)->award($b->id, $this->tenant->id, $this->company->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RFQ_GROUP_ALREADY_AWARDED');

        app(PurchaseQuoteRequestAwardService::class)->award($a->id, $this->tenant->id, $this->company->id);
    }

    public function test_ordered_sibling_lock_contends_from_two_connections(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $source = (string) file_get_contents(app_path('Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php'));

            $this->assertStringContainsString("->orderBy('id')", $source);
            $this->assertStringContainsString('->lockForUpdate()', $source);
            $this->assertStringContainsString("firstWhere('id', \$winner->id)", $source);
            $this->assertStringNotContainsString('whereKey($rfqId)->lockForUpdate()', $source);

            return;
        }

        $connA = $this->cloneConnection('rfq_award_lock_a');
        $connB = $this->cloneConnection('rfq_award_lock_b');

        [$tenantId, $companyId, $partnerId, $groupId] = $this->insertCommittedLockFixture($connA);

        $lockSql = "SELECT id FROM documents
            WHERE tenant_id = ?
              AND company_id = ?
              AND type = ?
              AND payload->'rfq'->>'group_id' = ?
            ORDER BY id
            FOR UPDATE";
        $lockNowaitSql = $lockSql.' NOWAIT';
        $bindings = [$tenantId, $companyId, DocumentType::PurchaseQuoteRequest->value, $groupId];

        try {
            $connA->beginTransaction();
            $lockedRows = $connA->select($lockSql, $bindings);
            $this->assertCount(2, $lockedRows);

            $sqlState = null;
            try {
                $connB->select($lockNowaitSql, $bindings);
                $this->fail('Connection B acquired the RFQ sibling row locks while connection A held them.');
            } catch (QueryException $exception) {
                $sqlState = $exception->getCode();
            }

            $this->assertSame('55P03', (string) $sqlState);

            $connA->rollBack();

            $rowsAfterRelease = $connB->select($lockNowaitSql, $bindings);
            $this->assertCount(2, $rowsAfterRelease);
        } finally {
            if ($connA->transactionLevel() > 0) {
                $connA->rollBack();
            }

            $connA->table('documents')->where('tenant_id', $tenantId)->delete();
            $connA->table('partners')->where('id', $partnerId)->delete();
            $connA->table('companies')->where('id', $companyId)->delete();
            $connA->table('tenants')->where('id', $tenantId)->delete();
        }
    }

    public function test_record_response_rejects_cancelled_loser(): void
    {
        [$a, $b] = $this->respondedGroup(2);

        app(PurchaseQuoteRequestAwardService::class)->award($b->id, $this->tenant->id, $this->company->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RFQ_CANCELLED');

        app(PurchaseQuoteRequestService::class)->recordResponse(
            $a->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [
                    [
                        'id' => $a->lines->first()?->id,
                        'product_id' => $this->product->id,
                        'quantity' => '10.0000',
                        'unit_price' => '6.500',
                        'description' => 'Cancelled loser response',
                    ],
                ],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );
    }

    public function test_mark_sent_rejects_cancelled_loser(): void
    {
        [$a, $b] = $this->respondedGroup(2);

        app(PurchaseQuoteRequestAwardService::class)->award($b->id, $this->tenant->id, $this->company->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RFQ_CANCELLED');

        app(PurchaseQuoteRequestService::class)->markSent($a->id, $this->tenant->id, $this->company->id);
    }

    public function test_reopen_restores_unsent_loser_to_draft(): void
    {
        $supplierA = $this->supplier('Responded Supplier');
        $supplierB = $this->supplier('Draft Supplier');

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplierA->id, $supplierB->id],
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '10.0000',
                        'unit_price' => null,
                        'description' => 'Mixed reopen line',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );

        /** @var Document $responded */
        $responded = $created[0];
        /** @var Document $draft */
        $draft = $created[1];

        $responded = app(PurchaseQuoteRequestService::class)->recordResponse(
            $responded->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [
                    [
                        'id' => $responded->lines->first()?->id,
                        'product_id' => $this->product->id,
                        'quantity' => '10.0000',
                        'unit_price' => '7.200',
                        'description' => 'Responded mixed line',
                    ],
                ],
                validityDate: null,
                supplierReference: null,
                leadTimeDays: null,
            ),
        );

        $po = app(PurchaseQuoteRequestAwardService::class)->award($responded->id, $this->tenant->id, $this->company->id);
        $po->status = DocumentStatus::Cancelled;
        $po->save();

        app(PurchaseQuoteRequestService::class)->reopenGroup((string) $responded->payload['rfq']['group_id'], $this->tenant->id, $this->company->id);

        $this->assertSame(DocumentStatus::Draft, $draft->refresh()->status);
        $this->assertNull($draft->payload['rfq']['closed_reason']);
    }

    public function test_reopen_requires_cancelled_po_then_allows_different_award(): void
    {
        [$a, $b, $c] = $this->respondedGroup();

        $po = app(PurchaseQuoteRequestAwardService::class)->award($b->id, $this->tenant->id, $this->company->id);

        try {
            app(PurchaseQuoteRequestService::class)->reopenGroup((string) $a->payload['rfq']['group_id'], $this->tenant->id, $this->company->id);
            $this->fail('Expected live PO reopen guard to reject the group.');
        } catch (\DomainException $exception) {
            $this->assertSame('RFQ_GROUP_ALREADY_AWARDED', $exception->getMessage());
        }

        $po->status = DocumentStatus::Cancelled;
        $po->save();

        $reopened = app(PurchaseQuoteRequestService::class)->reopenGroup((string) $a->payload['rfq']['group_id'], $this->tenant->id, $this->company->id);
        $this->assertSame(2, $reopened);
        $this->assertSame(DocumentStatus::Confirmed, $a->refresh()->status);
        $this->assertNull($a->payload['rfq']['closed_reason']);
        $this->assertSame(DocumentStatus::Confirmed, $c->refresh()->status);

        $newPo = app(PurchaseQuoteRequestAwardService::class)->award($a->id, $this->tenant->id, $this->company->id);

        $this->assertSame(DocumentType::PurchaseOrder, $newPo->type);
        $this->assertSame($a->id, $newPo->source_document_id);
    }

    public function test_converter_rejects_wrong_source_type_and_non_responded_rfq(): void
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier('PO Supplier')->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-WRONG-SOURCE',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(PurchaseQuoteRequestToPurchaseOrderConverter::class)->convert($po);
    }

    public function test_converter_rejects_non_responded_rfq(): void
    {
        $rfq = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$this->supplier('Supplier A')->id],
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '5.0000',
                        'unit_price' => null,
                        'description' => 'Creme solaire SPF50',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        )->first();

        $this->assertInstanceOf(Document::class, $rfq);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RFQ must have a recorded response before conversion');

        app(DocumentConverterRegistry::class)->convert($rfq, DocumentType::PurchaseOrder);
    }

    /**
     * @return list<Document>
     */
    private function respondedGroup(int $count = 3): array
    {
        $partners = [];
        for ($i = 1; $i <= $count; $i++) {
            $partners[] = $this->supplier("Supplier {$i}");
        }

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: array_map(fn (Partner $partner): string => $partner->id, $partners),
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '10.0000',
                        'unit_price' => null,
                        'description' => 'Creme solaire SPF50',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );

        $documents = [];
        foreach ($created as $index => $rfq) {
            $documents[] = app(PurchaseQuoteRequestService::class)->recordResponse(
                $rfq->id,
                $this->tenant->id,
                $this->company->id,
                new UpdateRfqData(
                    lines: [
                        [
                            'id' => $rfq->lines->first()?->id,
                            'product_id' => $this->product->id,
                            'quantity' => '10.0000',
                            'unit_price' => sprintf('%d.200', $index + 7),
                            'description' => 'Creme solaire SPF50',
                        ],
                    ],
                    validityDate: null,
                    supplierReference: null,
                    leadTimeDays: null,
                ),
            );
        }

        return $documents;
    }

    private function supplier(string $name): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'type' => PartnerType::Supplier,
        ]);
    }

    private function cloneConnection(string $name): Connection
    {
        $default = (string) config('database.default');
        /** @var array<string, mixed> $baseConfig */
        $baseConfig = config("database.connections.{$default}");

        config(["database.connections.{$name}" => $baseConfig]);
        DB::purge($name);
        $this->clonedConnections[] = $name;

        return DB::connection($name);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function insertCommittedLockFixture(Connection $connection): array
    {
        $tenantId = (string) Str::uuid();
        $companyId = (string) Str::uuid();
        $partnerId = (string) Str::uuid();
        $groupId = (string) Str::uuid7();
        $now = now();

        $connection->table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'RFQ Lock Tenant',
            'slug' => 'rfq-lock-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active->value,
            'plan' => SubscriptionPlan::Professional->value,
            'settings' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $connection->table('companies')->insert([
            'id' => $companyId,
            'tenant_id' => $tenantId,
            'name' => 'RFQ Lock Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $connection->table('partners')->insert([
            'id' => $partnerId,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => 'RFQ Lock Supplier',
            'type' => PartnerType::Supplier->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([1, 2] as $index) {
            $connection->table('documents')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'type' => DocumentType::PurchaseQuoteRequest->value,
                'fiscal_category' => FiscalCategory::NonFiscal->value,
                'fiscal_status' => FiscalStatus::Draft->value,
                'status' => DocumentStatus::Confirmed->value,
                'document_number' => "DP-LOCK-{$index}",
                'document_date' => now()->toDateString(),
                'currency' => 'TND',
                'subtotal' => '0.000',
                'discount_amount' => '0.000',
                'tax_amount' => '0.000',
                'total' => '0.000',
                'balance_due' => '0.000',
                'payload' => json_encode([
                    'rfq' => [
                        'group_id' => $groupId,
                        'validity_date' => null,
                        'supplier_reference' => null,
                        'lead_time_days' => null,
                        'response_recorded_at' => now()->toIso8601String(),
                        'sent_at' => null,
                        'closed_reason' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [$tenantId, $companyId, $partnerId, $groupId];
    }
}
