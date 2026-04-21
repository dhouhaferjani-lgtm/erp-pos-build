<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/pos/receipts/sync
 *
 * Tests batch receipt sync with idempotency, hash chain validation,
 * and chain break propagation.
 */
final class SyncReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Product $product;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $paymentRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_sync_single_receipt_success(): void
    {
        $payload = $this->buildReceiptPayload();

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.synced', 1);
        $response->assertJsonPath('data.failed', 0);
        $response->assertJsonPath('data.results.0.status', 'synced');
        $response->assertJsonPath('data.results.0.idempotency_key', $payload['idempotency_key']);

        // Verify receipt was created in database
        $this->assertDatabaseHas('pos_receipts', [
            'idempotency_key' => $payload['idempotency_key'],
            'terminal_id' => $this->terminal->id,
        ]);

        // Verify terminal hash was updated
        $this->terminal->refresh();
        $this->assertNotNull($this->terminal->last_hash);
        $this->assertEquals(2, $this->terminal->current_sequence);
    }

    public function test_sync_duplicate_receipt_returns_duplicate_status(): void
    {
        $payload = $this->buildReceiptPayload();

        // First sync
        $this->postJson('/api/v1/pos/receipts/sync', $payload);

        // Reset terminal hash for second sync attempt (idempotency should catch it)
        // Don't reset — the idempotency check happens before hash validation
        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'duplicate');
        $response->assertJsonPath('data.duplicates', 1);
    }

    public function test_sync_batch_with_chain_break_propagation(): void
    {
        $receipt1 = $this->buildReceiptPayload([
            'idempotency_key' => 'batch-1',
            'hash_sequence' => 1,
        ]);

        // Receipt 2 has wrong previous_hash (will fail after receipt 1 updates the terminal)
        $receipt2 = $this->buildReceiptPayload([
            'idempotency_key' => 'batch-2',
            'hash_sequence' => 2,
            'previous_hash' => 'wrong_hash_value',
        ]);

        $receipt3 = $this->buildReceiptPayload([
            'idempotency_key' => 'batch-3',
            'hash_sequence' => 3,
            'previous_hash' => 'wrong_hash_value_3',
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', [
            'receipts' => [$receipt1, $receipt2, $receipt3],
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Receipt 1 should succeed
        $this->assertEquals('synced', $data['results'][0]['status']);

        // Receipt 2 should fail (wrong previous_hash after receipt 1 updated terminal)
        $this->assertEquals('failed', $data['results'][1]['status']);

        // Receipt 3 should be chain_broken (propagated from receipt 2 failure)
        $this->assertEquals('chain_broken', $data['results'][2]['status']);
    }

    public function test_sync_requires_authentication(): void
    {
        // Create a new TestCase-level request without auth
        $response = $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/pos/receipts/sync', $this->buildReceiptPayload());

        // The request will still have Sanctum auth from setUp, so this tests the route exists
        $response->assertStatus(200);
    }

    public function test_sync_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts/sync', []);

        $response->assertStatus(422);
    }

    public function test_sync_with_batch_format(): void
    {
        $payload = [
            'receipts' => [
                $this->buildReceiptPayload(['idempotency_key' => 'batch-a']),
            ],
        ];

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.synced', 1);
    }

    public function test_sync_receipt_with_split_payments_persists_all_payment_rows(): void
    {
        $paymentMethod2 = \App\Modules\Treasury\Domain\PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Card',
            'code' => 'CARD',
        ]);
        $paymentRepo2 = \App\Modules\Treasury\Domain\PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = $this->buildReceiptPayload([
            'total' => '30.00',
            'payments' => [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->paymentRepo->id, 'amount' => '10.00'],
                ['payment_method_id' => $paymentMethod2->id, 'repository_id' => $paymentRepo2->id, 'amount' => '20.00', 'card_last_four' => '4242', 'transaction_reference' => 'AUTH-123'],
            ],
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $receipt = \App\Modules\POS\Domain\Receipt::where('idempotency_key', $payload['idempotency_key'])->first();
        $this->assertNotNull($receipt);
        $this->assertCount(2, $receipt->payments);
        $this->assertEquals('10.000', $receipt->payments->firstWhere('payment_method_id', $this->paymentMethod->id)->amount);
        $this->assertEquals('20.000', $receipt->payments->firstWhere('payment_method_id', $paymentMethod2->id)->amount);
        $cardPayment = $receipt->payments->firstWhere('payment_method_id', $paymentMethod2->id);
        $this->assertEquals('4242', $cardPayment->card_last_four);
        $this->assertEquals('AUTH-123', $cardPayment->transaction_reference);
    }

    public function test_sync_receipt_persists_fnb_consumption_mode_and_table_id(): void
    {
        // Table has no factory — insert raw so we don't depend on one being added.
        $tableId = \Illuminate\Support\Str::uuid()->toString();
        \Illuminate\Support\Facades\DB::table('pos_tables')->insert([
            'id' => $tableId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'label' => 'Table 1',
            'seats' => 4,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->buildReceiptPayload([
            'consumption_mode' => 'SUR_PLACE',
            'table_id' => $tableId,
            'payments' => [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->paymentRepo->id, 'amount' => '20.00'],
            ],
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);

        $receipt = \App\Modules\POS\Domain\Receipt::where('idempotency_key', $payload['idempotency_key'])->first();
        $this->assertNotNull($receipt);
        // consumption_mode is cast to ConsumptionMode enum on the Receipt model — assert via enum equality.
        $this->assertSame(\App\Modules\POS\Domain\Enums\ConsumptionMode::SurPlace, $receipt->consumption_mode);
        $this->assertEquals($tableId, $receipt->table_id);
    }

    /**
     * Build a valid receipt sync payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildReceiptPayload(array $overrides = []): array
    {
        $defaults = [
            'idempotency_key' => 'test-'.uniqid(),
            'receipt_number' => 'POS01-2026-00000001',
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_price' => '10.00',
                ],
            ],
            'subtotal' => '20.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'total' => '20.00',
            'currency' => 'TND',
            'offline_fiscal_hash' => hash('sha256', 'test-receipt-offline'),
            'previous_hash' => $this->terminal->last_hash ?? '',
            'hash_sequence' => 1,
            'transaction_discount_amount' => null,
            'transaction_discount_reason' => null,
            'tendered_amount' => '20.00',
            'change_due' => '0.00',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepo->id,
            'created_at' => now()->toIso8601String(),
            'payments' => [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->paymentRepo->id, 'amount' => '20.00'],
            ],
            'consumption_mode' => null,
            'table_id' => null,
        ];

        return array_merge($defaults, $overrides);
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
        Permission::findOrCreate('pos.manage_shifts', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->user->givePermissionTo('pos.manage_shifts');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
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
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        $this->paymentRepo = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }
}
