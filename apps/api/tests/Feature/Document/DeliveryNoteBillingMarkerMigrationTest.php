<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DeliveryNoteBillingMarkerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_18_000002_create_delivery_note_billing_marks_table.php';

    private Company $company;

    private Partner $partner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Billing marker migration tenant',
            'slug' => 'billing-marker-migration-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = $this->company('Billing marker migration company');
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Schema::dropIfExists('delivery_note_billing_marks');
    }

    public function test_it_backfills_every_stamped_delivery_note_with_safe_attribution_and_auditable_counts(): void
    {
        $validInvoice = $this->invoice($this->company, 'INV-VALID');
        $sameCompanyQuote = $this->document($this->company, DocumentType::Quote, 'QUO-NOT-AN-INVOICE');
        $foreignCompany = $this->company('Foreign invoice company');
        $foreignInvoice = $this->invoice($foreignCompany, 'INV-FOREIGN');

        $unstamped = $this->deliveryNote('DN-UNSTAMPED');
        $valid = $this->deliveryNote('DN-VALID', $this->stamp([
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]));
        $orderConversion = $this->deliveryNote('DN-ORDER-CONVERSION', $this->stamp([
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]));
        $prePostDelivery = $this->deliveryNote('DN-PRE-POST-DELIVERY', $this->stamp([
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::PrePostDelivery->value,
        ]));
        $missing = $this->deliveryNote('DN-MISSING', $this->stamp());
        $missingNull = $this->deliveryNote('DN-MISSING-NULL', $this->stamp([
            'invoice_id' => null,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]));
        $malformed = $this->deliveryNote('DN-MALFORMED', $this->stamp([
            'invoice_id' => 'not-a-uuid',
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]));
        $blank = $this->deliveryNote('DN-BLANK', $this->stamp([
            'invoice_id' => ' ',
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]));
        $numeric = $this->deliveryNote('DN-NUMERIC', $this->stamp([
            'invoice_id' => 123,
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]));
        $array = $this->deliveryNote('DN-ARRAY', $this->stamp([
            'invoice_id' => ['not' => 'a UUID'],
            'invoiced_via' => DeliveryNoteBillingLane::PrePostDelivery->value,
        ]));
        $dangling = $this->deliveryNote('DN-DANGLING', $this->stamp([
            'invoice_id' => (string) Str::uuid(),
            'invoiced_via' => DeliveryNoteBillingLane::PrePostDelivery->value,
        ]));
        $crossCompany = $this->deliveryNote('DN-CROSS-COMPANY', $this->stamp([
            'invoice_id' => $foreignInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]));
        $nonInvoice = $this->deliveryNote('DN-NON-INVOICE', $this->stamp([
            'invoice_id' => $sameCompanyQuote->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]));
        $noLane = $this->deliveryNote('DN-NO-LANE', $this->stamp([
            'invoice_id' => $validInvoice->id,
        ]));

        // M5-terminal treasury F-6 — `invoiced_at` was the ONE dirty shape the backfill did
        // not validate: it went raw into a NOT NULL timestamptz, so a truthy-but-unparseable
        // legacy value raised 22007/22P02 and ABORTED the whole migration, contradicting the
        // backfill's own contract that dirty rows are counted, never fatal. That abort would
        // land during `tenants:migrate`, which this repository auto-runs on every push to
        // origin/dev. These four shapes must be counted and skipped, like every other.
        $invoicedAtTrue = $this->deliveryNote('DN-INVOICED-AT-TRUE', [
            'invoiced_at' => true,
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]);
        $invoicedAtWord = $this->deliveryNote('DN-INVOICED-AT-WORD', [
            'invoiced_at' => 'yes',
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]);
        $invoicedAtBlank = $this->deliveryNote('DN-INVOICED-AT-BLANK', [
            'invoiced_at' => '   ',
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]);
        $invoicedAtArray = $this->deliveryNote('DN-INVOICED-AT-ARRAY', [
            'invoiced_at' => ['not' => 'a timestamp'],
            'invoice_id' => $validInvoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]);

        $this->assertFalse(Schema::hasTable('delivery_note_billing_marks'));
        Log::spy();
        $this->runMigration();

        $this->assertTrue(Schema::hasTable('delivery_note_billing_marks'));
        $this->assertSame(13, DB::table('delivery_note_billing_marks')->count());
        $this->assertSame($validInvoice->id, $this->marker($valid)->invoice_id);
        $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $this->marker($valid)->invoiced_via);
        $this->assertSame($validInvoice->id, $this->marker($orderConversion)->invoice_id);
        $this->assertSame(DeliveryNoteBillingLane::OrderConversion->value, $this->marker($orderConversion)->invoiced_via);
        $this->assertSame($validInvoice->id, $this->marker($prePostDelivery)->invoice_id);
        $this->assertSame(DeliveryNoteBillingLane::PrePostDelivery->value, $this->marker($prePostDelivery)->invoiced_via);

        foreach ([$missing, $missingNull, $malformed, $blank, $numeric, $array, $dangling, $crossCompany, $nonInvoice] as $dirtyDeliveryNote) {
            $marker = $this->marker($dirtyDeliveryNote);
            $this->assertNull($marker->invoice_id);
            $this->assertSame(DeliveryNoteBillingLane::LegacyUnknown->value, $marker->invoiced_via);
        }

        $this->assertSame($validInvoice->id, $this->marker($noLane)->invoice_id);
        $this->assertSame(DeliveryNoteBillingLane::LegacyUnknown->value, $this->marker($noLane)->invoiced_via);
        $this->assertNull($this->marker($unstamped));

        // F-6: skipped, counted, and above all NOT fatal to the migration.
        foreach ([$invoicedAtTrue, $invoicedAtWord, $invoicedAtBlank, $invoicedAtArray] as $undatedDeliveryNote) {
            $this->assertNull($this->marker($undatedDeliveryNote));
        }

        $tenantId = $this->tenant->id;
        Log::shouldHaveReceived('info')
            ->withArgs(static fn (string $message, array $context): bool => $message === 'delivery_note_billing_marks.backfill'
                && $context === [
                    'tenant_id' => $tenantId,
                    'database' => DB::connection()->getDatabaseName(),
                    'rows_written' => 13,
                    'missing_invoice_id' => 2,
                    'unparseable_invoice_id' => 4,
                    'dangling_invoice_id' => 1,
                    'cross_company_invoice_id' => 1,
                    'non_invoice_document_id' => 1,
                    'missing_invoiced_via' => 2,
                    'unparseable_invoiced_at' => 4,
                ])
            ->once();

        $this->expectException(QueryException::class);
        DB::table('documents')->where('id', $validInvoice->id)->delete();
    }

    public function test_it_is_a_guarded_no_op_when_re_run_and_down_only_removes_the_marker_table(): void
    {
        $invoice = $this->invoice($this->company, 'INV-RE-RUN');
        $deliveryNote = $this->deliveryNote('DN-RE-RUN', $this->stamp([
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]));

        $this->runMigration();
        $lateDeliveryNote = $this->deliveryNote('DN-AFTER-COMPLETION', $this->stamp([
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]));

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();
        $this->runMigration();
        $rerunQueries = $queries;

        $this->assertSame(1, DB::table('delivery_note_billing_marks')->count());
        $this->assertSame($invoice->id, $this->marker($deliveryNote)->invoice_id);
        $this->assertNull($this->marker($lateDeliveryNote));
        $dataQueries = array_filter(
            $rerunQueries,
            static fn (string $sql): bool => str_contains($sql, 'from "documents"')
                || str_contains($sql, 'into "delivery_note_billing_marks"')
                || str_contains($sql, 'update "delivery_note_billing_marks"')
                || str_contains($sql, 'delete from "delivery_note_billing_marks"'),
        );
        $this->assertSame([], array_values($dataQueries));
        Log::shouldNotHaveReceived('info');

        $migration = require database_path('migrations/tenant/'.self::MIGRATION);
        $migration->down();

        $this->assertFalse(Schema::hasTable('delivery_note_billing_marks'));
        $this->assertNotNull(Document::find($deliveryNote->id));
        $this->assertNotNull(Document::find($invoice->id));
    }

    public function test_it_reads_large_delivery_note_histories_in_bounded_chunks(): void
    {
        $now = now();
        $rows = [];
        for ($index = 0; $index < 101; $index++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->partner->id,
                'type' => DocumentType::DeliveryNote->value,
                'status' => DocumentStatus::Confirmed->value,
                'document_number' => 'DN-CHUNK-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'document_date' => '2026-08-18',
                'currency' => 'TND',
                'payload' => '{}',
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::DeliveryNote)->value,
                'fiscal_status' => FiscalStatus::Draft->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 25) as $chunk) {
            DB::table('documents')->insert($chunk);
        }

        $documentReads = [];
        DB::listen(static function ($query) use (&$documentReads): void {
            if (str_contains($query->sql, 'from "documents"')
                && str_contains($query->sql, 'order by "id" asc')) {
                $documentReads[] = $query->sql;
            }
        });

        $this->runMigration();

        $this->assertGreaterThanOrEqual(2, count($documentReads));
        $this->assertSame(0, DB::table('delivery_note_billing_marks')->count());
    }

    public function test_an_existing_incomplete_marker_table_is_not_treated_as_a_completed_run(): void
    {
        Schema::create('delivery_note_billing_marks', function (Blueprint $table): void {
            $table->uuid('delivery_note_id')->primary();
            $table->uuid('invoice_id')->nullable();
            $table->string('invoiced_via', 32);
            $table->timestampTz('invoiced_at');
            $table->uuid('company_id');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery_note_billing_marks exists but is incomplete');

        $this->runMigration();
    }

    public function test_an_empty_invocation_creates_the_table_and_logs_attributable_zero_counts(): void
    {
        Log::spy();

        $this->runMigration();

        $this->assertTrue(Schema::hasTable('delivery_note_billing_marks'));
        $database = DB::connection()->getDatabaseName();
        Log::shouldHaveReceived('info')
            ->withArgs(static fn (string $message, array $context): bool => $message === 'delivery_note_billing_marks.backfill'
                && $context === [
                    'tenant_id' => null,
                    'database' => $database,
                    'rows_written' => 0,
                    'missing_invoice_id' => 0,
                    'unparseable_invoice_id' => 0,
                    'dangling_invoice_id' => 0,
                    'cross_company_invoice_id' => 0,
                    'non_invoice_document_id' => 0,
                    'missing_invoiced_via' => 0,
                    'unparseable_invoiced_at' => 0,
                ])
            ->once();
    }

    private function company(string $name): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(10)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function deliveryNote(string $number, array $payload = []): Document
    {
        return $this->document($this->company, DocumentType::DeliveryNote, $number, $payload);
    }

    private function document(Company $company, DocumentType $type, string $number, array $payload = []): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => '2026-08-18',
            'currency' => 'TND',
            'payload' => $payload,
            'fiscal_category' => FiscalCategory::fromDocumentType($type),
            'fiscal_status' => FiscalStatus::Draft,
        ]);
    }

    private function invoice(Company $company, string $number): Document
    {
        return $this->document($company, DocumentType::Invoice, $number);
    }

    private function marker(Document $deliveryNote): ?object
    {
        return DB::table('delivery_note_billing_marks')
            ->where('delivery_note_id', $deliveryNote->id)
            ->first();
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/tenant/'.self::MIGRATION);
        $migration->up();
    }

    /** @param array<string, mixed> $overrides */
    private function stamp(array $overrides = []): array
    {
        return array_merge([
            'invoiced_at' => '2026-08-18T10:11:12+00:00',
        ], $overrides);
    }
}
