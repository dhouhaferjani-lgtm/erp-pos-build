<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\SyncReceiptPayload;
use App\Modules\POS\Application\Services\ReceiptSyncService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\SyncStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Codex review B4 (2026-04-30) — defense-in-depth at the sync writer.
 *
 * The HTTP request validator (SyncReceiptsRequest) rejects sync payloads where
 * a payment row's snapshot `method_code` is instrument-bearing (per the
 * PaymentInstrumentKind enum) but the instrument pair is null or empty. But
 * a programmatic caller — a queue retry job, a backfill script, a future
 * controller — bypasses FormRequest validation and constructs SyncReceiptPayload
 * directly. The writer must raise the same v3 fiscal-hash invariant.
 *
 * The throw must happen BEFORE the receipt finalization call so the enclosing
 * DB::transaction() rolls back cleanly with no partial chain state. The sync
 * service catches Throwable and reports the failure as `SyncStatus::Failed`,
 * so the assertion here is on the result status + error message rather than
 * a bubbled exception.
 */
final class ReceiptSyncServiceInstrumentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Product $product;

    private PaymentMethod $voucherMethod;

    private PaymentRepository $voucherRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
        // Direct service calls bypass CompanyContextMiddleware, so seed the
        // context manually — the sync service requires it via requireCompanyId().
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_sync_writer_rejects_store_voucher_payment_row_with_null_instrument_fields(): void
    {
        $payload = $this->buildVoucherPayloadWithNullInstrumentFields();

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        $results = $service->syncBatch([$payload]);

        $this->assertCount(1, $results);
        $this->assertSame(SyncStatus::Failed, $results[0]->status);
        $errorMessage = (string) $results[0]->error;
        $this->assertStringContainsString('store_voucher', $errorMessage);
        $this->assertStringContainsString('instrument', $errorMessage);

        // No receipt should have been persisted (the wrapping transaction rolled back).
        $this->assertDatabaseMissing('pos_receipts', [
            'idempotency_key' => $payload->idempotencyKey,
        ]);
    }

    public function test_sync_writer_rejects_store_voucher_payment_row_with_empty_instrument_serial(): void
    {
        $payload = $this->buildVoucherPayloadWithNullInstrumentFields(
            instrumentType: 'store_voucher',
            instrumentSerial: '', // empty must count as missing
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        $results = $service->syncBatch([$payload]);

        $this->assertSame(SyncStatus::Failed, $results[0]->status);
        $this->assertStringContainsString('instrument', (string) $results[0]->error);
    }

    private function buildVoucherPayloadWithNullInstrumentFields(
        ?string $instrumentType = null,
        ?string $instrumentSerial = null,
    ): SyncReceiptPayload {
        return new SyncReceiptPayload(
            idempotencyKey: 'sync-writer-guard-'.uniqid(),
            receiptNumber: 'POS-V2-WG-'.uniqid(),
            terminalId: $this->terminal->id,
            operatorId: $this->user->id,
            lines: [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ],
            ],
            subtotal: '10.00',
            taxAmount: '0.00',
            discountAmount: '0.00',
            total: '10.00',
            currency: 'EUR',
            offlineFiscalHash: str_repeat('a', 64),
            previousHash: null,
            hashSequence: 1,
            transactionDiscountAmount: null,
            transactionDiscountReason: null,
            tenderedAmount: '10.00',
            changeDue: '0.00',
            paymentMethodId: $this->voucherMethod->id,
            paymentRepositoryId: $this->voucherRepo->id,
            createdAt: now()->toIso8601String(),
            payments: [
                [
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'amount' => '10.00',
                    // method_code = store_voucher → instrument-bearing per the
                    // PaymentInstrumentKind enum. Both fields below are missing
                    // (null) or empty — the writer guard must refuse.
                    'method_code' => 'store_voucher',
                    'instrument_type' => $instrumentType,
                    'instrument_serial' => $instrumentSerial,
                ],
            ],
            consumptionMode: null,
            tableId: null,
            fiscalSchemaVersion: 2,
        );
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
        ]);

        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'tax_rate' => 0,
        ]);

        $this->voucherMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Voucher',
            'code' => 'store_voucher',
        ]);

        $this->voucherRepo = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }
}
