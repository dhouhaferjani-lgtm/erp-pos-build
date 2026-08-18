<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task 5 — per-line disposition + receipt-facts validation in StoreReturnRequest.
 *
 * Covers:
 *  (a) disposition='restock' + physical_receipt=false  → 422 (structural combo violation)
 *  (b) disposition='not_received' + physical_receipt=false → passes validation (201)
 *  (c) unknown disposition string → 422 (enum violation)
 *  (d) disposition='restock' + physical_receipt=true + resalable=true → passes (happy path)
 *  (e) physical_receipt=false + resalable=true → 422 (physical_receipt=false forbids non-null resalable)
 */
final class StoreReturnRequestDispositionTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    // ---------------------------------------------------------------
    // (a) restock + physical_receipt=false → FAIL
    // ---------------------------------------------------------------

    public function test_restock_disposition_with_physical_receipt_false_fails_validation(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                    'physical_receipt' => false,
                    'resalable' => true,
                    'disposition' => 'restock',
                ],
            ],
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $payload,
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['lines.0.disposition']);
    }

    // ---------------------------------------------------------------
    // (b) not_received + physical_receipt=false → PASS
    // ---------------------------------------------------------------

    public function test_not_received_disposition_with_physical_receipt_false_passes(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                    'physical_receipt' => false,
                    'disposition' => 'not_received',
                ],
            ],
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $payload,
        );

        // Validation must pass — 201 returned by the controller.
        $response->assertStatus(201);
    }

    // ---------------------------------------------------------------
    // (c) unknown disposition string → FAIL
    // ---------------------------------------------------------------

    public function test_unknown_disposition_string_fails_validation(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                    'disposition' => 'completely_unknown_value',
                ],
            ],
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $payload,
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['lines.0.disposition']);
    }

    // ---------------------------------------------------------------
    // (d) restock + physical_receipt=true + resalable=true → PASS
    // ---------------------------------------------------------------

    public function test_restock_disposition_with_physical_receipt_true_and_resalable_true_passes(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                    'physical_receipt' => true,
                    'resalable' => true,
                    'disposition' => 'restock',
                ],
            ],
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $payload,
        );

        $response->assertStatus(201);
    }

    // ---------------------------------------------------------------
    // (e) physical_receipt=false + resalable=true → FAIL
    // ---------------------------------------------------------------

    public function test_physical_receipt_false_with_resalable_true_fails_validation(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                    'physical_receipt' => false,
                    'resalable' => true,
                    // no disposition — testing the resalable=true+physical_receipt=false combo
                ],
            ],
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $payload,
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['lines.0.resalable']);
    }

    // ---------------------------------------------------------------
    // Helpers (mirrored from ReceiptReturnFlowTest)
    // ---------------------------------------------------------------

    private function createReceiptLine(Receipt $receipt): ReceiptLine
    {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    private function withVoidReturnApproval(Receipt $receipt, array $requestData): array
    {
        $lineIds = collect($requestData['lines'] ?? [])
            ->pluck('line_id')
            ->map(static fn (mixed $lineId): string => (string) $lineId)
            ->sort()
            ->values()
            ->all();
        $reason = (string) ($requestData['notes'] ?? '');
        $target = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'line_ids' => $lineIds,
            'reason' => $reason,
        ];

        $approvalId = Str::uuid()->toString();
        $approvalEventId = Str::uuid()->toString();
        $overrideEventId = Str::uuid()->toString();

        $approvalPayload = [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->user->id,
            'supervisor_user_snapshot' => ['name' => $this->user->name, 'roles' => ['manager']],
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];
        $this->storeFiscalEvent($approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, $approvalPayload);

        $overridePayload = [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => 'POS_RECEIPT_RETURN',
                'target_reference_id' => $receipt->id,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'supervisor_user_id' => $this->user->id,
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];
        $this->storeFiscalEvent(
            $overrideEventId,
            FiscalEventType::OVERRIDE_VOID_OR_RETURN,
            $overridePayload,
            $approvalEventId,
        );

        return $requestData + [
            'refund_request_id' => Str::uuid()->toString(),
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $approvalEventId,
            'approval_scope' => 'void_or_return_override',
            'approval_supervisor_user_id' => $this->user->id,
            'approval_override_event_id' => $overrideEventId,
            'authorized_by_user_id' => $this->user->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeFiscalEvent(
        string $id,
        FiscalEventType $eventType,
        array $payload,
        ?string $referenceEventId = null,
    ): void {
        FiscalEvent::query()->create([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => DB::table('fiscal_events')->count() + 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'reference_event_id' => $referenceEventId,
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $id),
            'payload' => $payload,
            'payload_parse_status' => 'parsed',
        ]);
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
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        Permission::findOrCreate('pos.process_returns', 'sanctum');
        $this->user->givePermissionTo('pos.view_receipts');
        $this->user->givePermissionTo('pos.process_returns');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private function createSaleReceipt(): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'currency' => 'EUR',
        ]);
    }
}
