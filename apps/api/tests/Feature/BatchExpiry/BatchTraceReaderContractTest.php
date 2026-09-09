<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader;
use App\Shared\Contracts\BatchTraceability\PosBatchTraceReader;

require_once __DIR__.'/BatchReadLocationScopeTest.php';

final class BatchTraceReaderContractTest extends BatchPermissionFixture
{
    private function documentLine(Batch $batch, string $companyId, ?string $parentLocation, ?string $lineLocation = null): DocumentLine
    {
        $document = Document::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $companyId,
            'location_id' => $parentLocation, 'document_date' => '2026-09-01']);

        return DocumentLine::create(['document_id' => $document->id, 'product_id' => $this->product->id,
            'batch_id' => $batch->id, 'location_id' => $lineLocation, 'line_number' => 1,
            'description' => 'Trace product', 'quantity' => '1.1234', 'unit_price' => '10.000', 'line_total' => '11.234']);
    }

    private function posAllocation(Batch $batch, string $companyId, Location $location): ReceiptLineBatchAllocation
    {
        $terminal = Terminal::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $companyId, 'location_id' => $location->id]);
        $receipt = Receipt::factory()->withTotal('10.000')->create(['tenant_id' => $this->tenant->id,
            'company_id' => $companyId, 'location_id' => $location->id, 'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id, 'customer_name' => 'Trace customer', 'customer_identifier' => 'TRACE-CUSTOMER']);
        $line = ReceiptLine::create(['receipt_id' => $receipt->id, 'line_number' => 1, 'product_code' => $this->product->sku,
            'product_name' => 'Trace product', 'quantity' => '1.0000', 'unit' => 'pcs', 'unit_price' => '10.000',
            'line_total' => '10.000', 'tax_rate' => '0.000', 'tax_amount' => '0.000', 'discount_amount' => '0.000']);

        return ReceiptLineBatchAllocation::create(['receipt_id' => $receipt->id, 'receipt_line_id' => $line->id,
            'batch_id' => $batch->id, 'quantity' => '1.0000', 'batch_number' => $batch->batch_number]);
    }

    public function test_document_and_pos_adapters_apply_company_and_location_scope(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $foreignCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $foreignLocation = Location::factory()->create(['company_id' => $foreignCompany->id]);
        $visible = $this->documentLine($batch, $this->company->id, $other->id, $this->location->id);
        $this->documentLine($batch, $this->company->id, $this->location->id, $other->id);
        $this->documentLine($batch, $foreignCompany->id, $foreignLocation->id, $this->location->id);
        $visiblePos = $this->posAllocation($batch, $this->company->id, $this->location);
        $this->posAllocation($batch, $this->company->id, $other);
        $this->posAllocation($batch, $foreignCompany->id, $foreignLocation);
        $document = app(DocumentBatchTraceReader::class);
        $pos = app(PosBatchTraceReader::class);
        $scope = [$this->location->id];
        $rows = $document->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, $scope);
        self::assertSame([$visible->document->document_number], array_column($rows, 'documentNumber'));
        self::assertSame([$visiblePos->receipt->receipt_number], array_column($pos->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, $scope), 'receiptNumber'));
        foreach ([$document, $pos] as $reader) {
            self::assertSame([$batch->id], $reader->batchIdsVisibleAtLocations($this->tenant->id, $this->company->id, $scope));
            self::assertSame([], $reader->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, []));
            self::assertSame([], $reader->forwardForBatch('11111111-1111-4111-8111-111111111111', $this->company->id, $batch->id, null));
        }
        self::assertCount(2, $document->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, null));
        self::assertCount(2, $pos->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, null));
    }

    public function test_nullable_location_is_visible_only_to_unrestricted_membership(): void
    {
        $batch = $this->lot();
        $this->documentLine($batch, $this->company->id, null);
        $reader = app(DocumentBatchTraceReader::class);
        self::assertCount(1, $reader->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, null));
        self::assertSame([], $reader->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, [$this->location->id]));
        self::assertSame([], $reader->batchIdsVisibleAtLocations($this->tenant->id, $this->company->id, [$this->location->id]));
        $fallback = $this->documentLine($batch, $this->company->id, $this->location->id);
        self::assertSame([$fallback->document->document_number], array_column($reader->forwardForBatch($this->tenant->id, $this->company->id, $batch->id, [$this->location->id]), 'documentNumber'));
    }

    public function test_forward_and_backward_json_contracts_are_field_for_field_compatible(): void
    {
        $batch = $this->lot();
        $line = $this->documentLine($batch, $this->company->id, $this->location->id);
        $allocation = $this->posAllocation($batch, $this->company->id, $this->location);
        $document = $line->document;
        $receipt = $allocation->receipt;
        $this->restrict([$this->location->id]);
        $response = $this->getJson('/api/v1/batches/'.$batch->uuid.'/traceability')->assertOk();
        self::assertSame(['type' => 'document', 'document_number' => $document->document_number,
            'document_type' => $document->type->value, 'document_date' => $document->document_date->toJSON(),
            'partner_name' => $document->partner->name, 'partner_id' => $document->partner_id,
            'product_name' => 'Trace product', 'quantity' => '1.1234'], $response->json('data.document_sales.0'));
        self::assertSame(['type' => 'pos_receipt', 'receipt_number' => $receipt->receipt_number,
            'sale_date' => $receipt->created_at->toDateString(), 'customer_name' => 'Trace customer',
            'customer_identifier' => 'TRACE-CUSTOMER', 'batch_number' => $batch->batch_number,
            'quantity' => '1.0000'], $response->json('data.pos_sales.0'));
        $backward = $this->getJson('/api/v1/partners/'.$document->partner_id.'/batch-history')->assertOk();
        self::assertSame(['batch_number' => $batch->batch_number, 'batch_id' => $batch->id,
            'expiry_date' => $batch->expiry_date->toDateString(), 'is_recalled' => false, 'is_expired' => false,
            'product_name' => 'Trace product', 'product_id' => $this->product->id, 'quantity' => '1.1234',
            'document_number' => $document->document_number, 'document_type' => $document->type->value,
            'document_date' => $document->document_date->toJSON()], $backward->json('data.0'));
        config(['lot_action_permissions.enforce' => false]);
        self::assertSame($response->json(), $this->getJson('/api/v1/batches/'.$batch->uuid.'/traceability')->assertOk()->json());
        self::assertSame($backward->json(), $this->getJson('/api/v1/partners/'.$document->partner_id.'/batch-history')->assertOk()->json());
    }
}
