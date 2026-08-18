<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteClaimNotFinalisedException;
use App\Modules\Document\Domain\Exceptions\InvalidDeliveryNoteClaimRequestException;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingClaimService;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimRequest;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimSet;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeliveryNoteBillingClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Connection $db;

    private int $documentSequence = 0;

    private Partner $partner;

    private DeliveryNoteBillingClaimServiceTestHarness $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = DB::connection();
        $this->service = new DeliveryNoteBillingClaimServiceTestHarness($this->db);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_successful_claim_reserves_in_sorted_order_and_finalises_matching_pairs(): void
    {
        $later = $this->deliveryNote(['unrelated' => 'keep-later']);
        $earlier = $this->deliveryNote(['unrelated' => 'keep-earlier']);
        $ids = [$later->id, $earlier->id];
        $sortedIds = $ids;
        sort($sortedIds, SORT_STRING);
        $reserveWriteOrder = [];

        DB::listen(static function (QueryExecuted $query) use (&$reserveWriteOrder, $sortedIds): void {
            $sql = strtolower($query->sql);
            $isReservePayloadWrite = str_contains($sql, 'update "documents"')
                && str_contains($sql, 'invoiced_via');
            $isMarkerInsert = str_contains($sql, 'insert into "delivery_note_billing_marks"');
            if (! $isReservePayloadWrite && ! $isMarkerInsert) {
                return;
            }

            foreach ($sortedIds as $id) {
                if (in_array($id, $query->bindings, true)) {
                    $reserveWriteOrder[] = $id;

                    return;
                }
            }
        });

        $invoice = null;
        $set = $this->db->transaction(function () use ($ids, $sortedIds, &$invoice): DeliveryNoteClaimSet {
            $callerLevel = $this->db->transactionLevel();

            return $this->service->claim(
                $this->request($ids),
                function (DeliveryNoteClaimSet $reserved) use ($sortedIds, $callerLevel, &$invoice): string {
                    $this->assertSame($callerLevel, $this->db->transactionLevel(), 'The claim service must not open a transaction.');
                    $this->assertSame($sortedIds, $reserved->deliveryNoteIds);
                    $this->assertSame(2, $reserved->count());

                    $markers = DB::table('delivery_note_billing_marks')
                        ->whereIn('delivery_note_id', $sortedIds)
                        ->orderBy('delivery_note_id')
                        ->get();
                    $this->assertCount(2, $markers);
                    foreach ($markers as $marker) {
                        $this->assertNull($marker->invoice_id);
                        $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $marker->invoiced_via);
                    }

                    foreach (Document::query()->whereIn('id', $sortedIds)->get() as $deliveryNote) {
                        $payload = $deliveryNote->payload ?? [];
                        $this->assertArrayHasKey('invoiced_at', $payload);
                        $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $payload['invoiced_via'] ?? null);
                        $this->assertArrayNotHasKey('invoice_id', $payload);
                    }

                    $invoice = $this->numberedInvoice();

                    return $invoice->id;
                },
            );
        });

        $this->assertInstanceOf(Document::class, $invoice);
        $this->assertSame(2, $set->count());
        $this->assertSame($sortedIds, $reserveWriteOrder);
        $this->assertMatchingFinalisedPairs($sortedIds, $invoice->id);
        $this->assertSame('keep-later', $later->fresh()->payload['unrelated'] ?? null);
        $this->assertSame('keep-earlier', $earlier->fresh()->payload['unrelated'] ?? null);
        $this->assertSame(0, DB::table('delivery_note_billing_marks')
            ->whereNull('invoice_id')
            ->where('invoiced_via', '!=', DeliveryNoteBillingLane::LegacyUnknown->value)
            ->count());
    }

    public function test_second_claim_loses_the_payload_cas_before_creating_an_invoice(): void
    {
        $deliveryNote = $this->deliveryNote();
        $firstInvoice = null;
        $this->db->transaction(function () use ($deliveryNote, &$firstInvoice): void {
            $this->service->claim($this->request([$deliveryNote->id]), function () use (&$firstInvoice): string {
                $firstInvoice = $this->numberedInvoice();

                return $firstInvoice->id;
            });
        });

        $closureCalled = false;
        $this->expectException(DeliveryNoteAlreadyClaimedException::class);

        try {
            $this->db->transaction(fn (): DeliveryNoteClaimSet => $this->service->claim(
                $this->request([$deliveryNote->id]),
                function () use (&$closureCalled): string {
                    $closureCalled = true;

                    return $this->numberedInvoice()->id;
                },
            ));
        } finally {
            $this->assertFalse($closureCalled);
            $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->count());
            $this->assertSame($firstInvoice?->id, $deliveryNote->fresh()->payload['invoice_id'] ?? null);
        }
    }

    public function test_mismatched_company_and_document_type_lose_before_creating_an_invoice(): void
    {
        $foreignCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $deliveryNote = $this->deliveryNote();
        $invoice = $this->document(DocumentType::Invoice, []);

        foreach ([
            new DeliveryNoteClaimRequest([$deliveryNote->id], $foreignCompany->id, DeliveryNoteBillingLane::Consolidation),
            $this->request([$invoice->id]),
        ] as $request) {
            $closureCalled = false;

            try {
                $this->db->transaction(fn (): DeliveryNoteClaimSet => $this->service->claim(
                    $request,
                    function () use (&$closureCalled): string {
                        $closureCalled = true;

                        return $this->numberedInvoice()->id;
                    },
                ));
                $this->fail('A company/type mismatch must lose the claim.');
            } catch (DeliveryNoteAlreadyClaimedException) {
                $this->assertFalse($closureCalled);
            }
        }

        $this->assertSame(0, DB::table('delivery_note_billing_marks')->count());
        $this->assertArrayNotHasKey('invoiced_at', $deliveryNote->fresh()->payload ?? []);
    }

    public function test_named_delivery_note_marker_collision_is_translated(): void
    {
        $deliveryNote = $this->deliveryNote();
        DB::table('delivery_note_billing_marks')->insert([
            'delivery_note_id' => $deliveryNote->id,
            'invoice_id' => null,
            'invoiced_via' => DeliveryNoteBillingLane::LegacyUnknown->value,
            'invoiced_at' => now(),
            'company_id' => $this->company->id,
        ]);
        $closureCalled = false;

        try {
            $this->db->transaction(fn (): DeliveryNoteClaimSet => $this->service->claim(
                $this->request([$deliveryNote->id]),
                function () use (&$closureCalled): string {
                    $closureCalled = true;

                    return $this->numberedInvoice()->id;
                },
            ));
            $this->fail('The named marker primary-key collision must lose the claim.');
        } catch (DeliveryNoteAlreadyClaimedException) {
            $this->assertFalse($closureCalled);
        }

        $this->assertArrayNotHasKey('invoiced_at', $deliveryNote->fresh()->payload ?? []);
    }

    public function test_unrelated_unique_violation_propagates_unchanged(): void
    {
        $existing = $this->deliveryNote();
        DB::table('delivery_note_billing_marks')->insert([
            'delivery_note_id' => $existing->id,
            'invoice_id' => null,
            'invoiced_via' => DeliveryNoteBillingLane::LegacyUnknown->value,
            'invoiced_at' => now(),
            'company_id' => $this->company->id,
        ]);
        Schema::table('delivery_note_billing_marks', static function (Blueprint $table): void {
            $table->unique('company_id', 'delivery_note_billing_marks_company_id_unique');
        });
        $target = $this->deliveryNote();
        $closureCalled = false;

        try {
            $this->db->transaction(fn (): DeliveryNoteClaimSet => $this->service->claim(
                $this->request([$target->id]),
                function () use (&$closureCalled): string {
                    $closureCalled = true;

                    return $this->numberedInvoice()->id;
                },
            ));
            $this->fail('The unrelated unique violation must propagate.');
        } catch (QueryException $exception) {
            $this->assertContains((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505']);
            $this->assertStringContainsString('company_id', strtolower($exception->getMessage()));
            $this->assertFalse($closureCalled);
        } catch (DeliveryNoteAlreadyClaimedException) {
            $this->fail('An unrelated unique constraint must not be translated into a lost claim.');
        }

        $this->assertArrayNotHasKey('invoiced_at', $target->fresh()->payload ?? []);
    }

    public function test_marker_short_finalise_rolls_back_claim_invoice_and_number_use(): void
    {
        $this->assertShortFinaliseRollsBack(function (DeliveryNoteClaimSet $set, Document $invoice): void {
            DB::table('delivery_note_billing_marks')
                ->where('delivery_note_id', $set->deliveryNoteIds[0])
                ->update(['invoice_id' => $invoice->id]);
        });
    }

    public function test_payload_short_finalise_rolls_back_claim_invoice_and_number_use(): void
    {
        $this->assertShortFinaliseRollsBack(function (DeliveryNoteClaimSet $set, Document $invoice): void {
            $deliveryNote = Document::query()->findOrFail($set->deliveryNoteIds[0]);
            $deliveryNote->update([
                'payload' => array_merge($deliveryNote->payload ?? [], ['invoice_id' => $invoice->id]),
            ]);
        });
    }

    public function test_closure_exception_between_reserve_and_finalise_rolls_back_every_artifact(): void
    {
        $deliveryNotes = [$this->deliveryNote(), $this->deliveryNote()];
        $ids = array_map(static fn (Document $document): string => $document->id, $deliveryNotes);
        $invoiceId = null;

        try {
            $this->db->transaction(function () use ($ids, &$invoiceId): void {
                $this->service->claim($this->request($ids), function () use (&$invoiceId): string {
                    $invoice = $this->numberedInvoice();
                    $invoiceId = $invoice->id;

                    throw new DomainException('Invoice write failed after reservation.');
                });
            });
            $this->fail('The closure failure must propagate.');
        } catch (DomainException $exception) {
            $this->assertSame('Invoice write failed after reservation.', $exception->getMessage());
        }

        $this->assertArtifactsRolledBack($ids, $invoiceId);
    }

    public function test_runtime_request_rejects_the_migration_only_legacy_lane(): void
    {
        $this->expectException(InvalidDeliveryNoteClaimRequestException::class);

        new DeliveryNoteClaimRequest(
            [$this->deliveryNote()->id],
            $this->company->id,
            DeliveryNoteBillingLane::LegacyUnknown,
        );
    }

    /** @param list<string> $ids */
    private function assertArtifactsRolledBack(array $ids, ?string $invoiceId): void
    {
        $this->assertSame(0, DB::table('delivery_note_billing_marks')->whereIn('delivery_note_id', $ids)->count());
        foreach (Document::query()->whereIn('id', $ids)->get() as $deliveryNote) {
            $payload = $deliveryNote->payload ?? [];
            $this->assertArrayNotHasKey('invoiced_at', $payload);
            $this->assertArrayNotHasKey('invoice_id', $payload);
            $this->assertArrayNotHasKey('invoiced_via', $payload);
        }
        if ($invoiceId !== null) {
            $this->assertFalse(Document::query()->whereKey($invoiceId)->exists());
        }
        $this->assertSame(0, DB::table('document_sequences')
            ->where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice->value)
            ->count());
    }

    /** @param list<string> $ids */
    private function assertMatchingFinalisedPairs(array $ids, string $invoiceId): void
    {
        $payloadInvoiceId = $this->db->getDriverName() === 'pgsql'
            ? "document.payload->>'invoice_id'"
            : "json_extract(document.payload, '$.invoice_id')";

        $this->assertSame(count($ids), DB::table('delivery_note_billing_marks')
            ->whereIn('delivery_note_id', $ids)
            ->where('invoice_id', $invoiceId)
            ->count());
        $this->assertSame(count($ids), Document::query()
            ->whereIn('id', $ids)
            ->whereRaw($this->documentPayloadInvoiceIdSql().' = ?', [$invoiceId])
            ->count());
        $this->assertSame(count($ids), DB::table('delivery_note_billing_marks as mark')
            ->join('documents as document', 'document.id', '=', 'mark.delivery_note_id')
            ->whereIn('mark.delivery_note_id', $ids)
            ->where('mark.invoice_id', $invoiceId)
            ->whereRaw($payloadInvoiceId.' = mark.invoice_id')
            ->count());
    }

    /** @param callable(DeliveryNoteClaimSet, Document): void $doctor */
    private function assertShortFinaliseRollsBack(callable $doctor): void
    {
        $deliveryNotes = [$this->deliveryNote(), $this->deliveryNote()];
        $ids = array_map(static fn (Document $document): string => $document->id, $deliveryNotes);
        $invoiceId = null;

        try {
            $this->db->transaction(function () use ($ids, $doctor, &$invoiceId): void {
                $set = $this->service->reserveForTest($this->request($ids));
                $invoice = $this->numberedInvoice();
                $invoiceId = $invoice->id;
                $doctor($set, $invoice);
                $this->service->finaliseForTest($set, $invoice->id);
            });
            $this->fail('A short finalise write must abort the caller transaction.');
        } catch (DeliveryNoteClaimNotFinalisedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertArtifactsRolledBack($ids, $invoiceId);
    }

    private function deliveryNote(array $payload = []): Document
    {
        return $this->document(DocumentType::DeliveryNote, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function document(DocumentType $type, array $payload): Document
    {
        $this->documentSequence++;

        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $type->getPrefix().'-CLAIM-'.str_pad((string) $this->documentSequence, 4, '0', STR_PAD_LEFT),
            'document_date' => '2026-08-18',
            'currency' => 'TND',
            'payload' => $payload,
            'fiscal_category' => FiscalCategory::fromDocumentType($type),
            'fiscal_status' => FiscalStatus::Draft,
        ]);
    }

    private function documentPayloadInvoiceIdSql(): string
    {
        return $this->db->getDriverName() === 'pgsql'
            ? "documents.payload->>'invoice_id'"
            : "json_extract(documents.payload, '$.invoice_id')";
    }

    private function numberedInvoice(): Document
    {
        $number = (new DocumentNumberingService)->generateNumber(
            $this->tenant->id,
            $this->company->id,
            DocumentType::Invoice,
        );

        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => '2026-08-18',
            'currency' => 'TND',
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
        ]);
    }

    /** @param list<string> $ids */
    private function request(array $ids): DeliveryNoteClaimRequest
    {
        return new DeliveryNoteClaimRequest(
            $ids,
            $this->company->id,
            DeliveryNoteBillingLane::Consolidation,
        );
    }
}

final class DeliveryNoteBillingClaimServiceTestHarness extends DeliveryNoteBillingClaimService
{
    public function reserveForTest(DeliveryNoteClaimRequest $request): DeliveryNoteClaimSet
    {
        return $this->reserve($request);
    }

    public function finaliseForTest(DeliveryNoteClaimSet $set, string $invoiceId): void
    {
        $this->finalise($set, $invoiceId);
    }
}
