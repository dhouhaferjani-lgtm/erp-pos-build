<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalJsonEncoder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

class CashDrawerServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private CashDrawerService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CashDrawerService($this->mockCurrencyScale(3), new CanonicalJsonEncoder);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
        $this->shift = $this->createOpenShift();
    }

    public function test_record_opening_creates_operation(): void
    {
        $operation = $this->service->recordOpening($this->shift, '100.00', $this->cashier);

        $this->assertInstanceOf(CashDrawerOperation::class, $operation);
        $this->assertTrue($operation->exists);
        $this->assertEquals($this->shift->id, $operation->shift_id);
        $this->assertEquals('OPENING', $operation->operation_type);
        $this->assertEquals('100.000', $operation->amount);
        $this->assertEquals($this->cashier->id, $operation->user_id);
        $this->assertEquals('Shift opened with declared starting balance', $operation->reason);
        $this->assertNull($operation->receipt_id);
    }

    public function test_record_sale_creates_operation_with_receipt(): void
    {
        $receiptId = $this->createReceipt()->id;

        $operation = $this->service->recordSale($this->shift, '50.00', $this->cashier, $receiptId);

        $this->assertInstanceOf(CashDrawerOperation::class, $operation);
        $this->assertTrue($operation->exists);
        $this->assertEquals($this->shift->id, $operation->shift_id);
        $this->assertEquals('SALE', $operation->operation_type);
        $this->assertEquals('50.000', $operation->amount);
        $this->assertEquals($this->cashier->id, $operation->user_id);
        $this->assertEquals('Cash sale', $operation->reason);
        $this->assertEquals($receiptId, $operation->receipt_id);
    }

    public function test_record_refund_creates_operation_with_receipt(): void
    {
        $receiptId = $this->createReceipt()->id;

        $operation = $this->service->recordRefund($this->shift, '25.00', $this->cashier, $receiptId);

        $this->assertInstanceOf(CashDrawerOperation::class, $operation);
        $this->assertTrue($operation->exists);
        $this->assertEquals($this->shift->id, $operation->shift_id);
        $this->assertEquals('REFUND', $operation->operation_type);
        $this->assertEquals('25.000', $operation->amount);
        $this->assertEquals($this->cashier->id, $operation->user_id);
        $this->assertEquals('Cash refund', $operation->reason);
        $this->assertEquals($receiptId, $operation->receipt_id);
    }

    public function test_calculate_expected_cash_formula(): void
    {
        // OPENING(100) + SALE(50) + SALE(30) - REFUND(10) - DEPOSIT(20) - PAYOUT(5) = 145
        $this->service->recordOpening($this->shift, '100.00', $this->cashier);
        $this->service->recordSale($this->shift, '50.00', $this->cashier, $this->createReceipt()->id);
        $this->service->recordSale($this->shift, '30.00', $this->cashier, $this->createReceipt()->id);
        $this->service->recordRefund($this->shift, '10.00', $this->cashier, $this->createReceipt()->id);
        $this->service->recordDeposit(
            $this->shift,
            '20.00',
            $this->cashier,
            'Safe deposit',
            $this->cashDrawerApprovalEvidence('DEPOSIT', '20.00', 'Safe deposit'),
        );
        $this->service->recordPayout(
            $this->shift,
            '5.00',
            $this->cashier,
            'Supplier payment',
            $this->cashDrawerApprovalEvidence('PAYOUT', '5.00', 'Supplier payment'),
        );

        $expected = $this->service->calculateExpectedCash($this->shift);

        $this->assertEquals('145.000', $expected);
    }

    public function test_record_deposit_persists_approval_evidence(): void
    {
        $evidence = $this->cashDrawerApprovalEvidence('DEPOSIT', '20.00', 'Safe deposit');

        $operation = $this->service->recordDeposit($this->shift, '20.00', $this->cashier, 'Safe deposit', $evidence);

        $this->assertEquals($evidence['approval_id'], $operation->approval_id);
        $this->assertEquals($evidence['approval_fiscal_event_id'], $operation->approval_fiscal_event_id);
        $this->assertEquals('cash_drawer_control', $operation->approval_scope);
        $this->assertEquals($evidence['approval_supervisor_user_id'], $operation->approval_supervisor_user_id);
        $this->assertEquals($evidence['approval_target_hash'], $operation->approval_target_hash);
    }

    public function test_calculate_expected_cash_excludes_closing(): void
    {
        $this->service->recordOpening($this->shift, '100.00', $this->cashier);
        $this->service->recordSale($this->shift, '50.00', $this->cashier, $this->createReceipt()->id);
        $this->service->recordClosing($this->shift, '999.00', $this->cashier);

        $expected = $this->service->calculateExpectedCash($this->shift);

        $this->assertEquals('150.000', $expected);
    }

    public function test_get_total_by_type(): void
    {
        $this->service->recordSale($this->shift, '50.00', $this->cashier, $this->createReceipt()->id);
        $this->service->recordSale($this->shift, '30.00', $this->cashier, $this->createReceipt()->id);
        $this->service->recordSale($this->shift, '20.00', $this->cashier, $this->createReceipt()->id);

        $total = $this->service->getTotalByType($this->shift, 'SALE');

        $this->assertEquals('100.000', $total);
    }

    public function test_get_shift_operations_ordered_by_created_at(): void
    {
        $op1 = $this->service->recordOpening($this->shift, '100.00', $this->cashier);
        $op1->update(['created_at' => now()->subMinutes(3)]);

        $op2 = $this->service->recordSale($this->shift, '50.00', $this->cashier, $this->createReceipt()->id);
        $op2->update(['created_at' => now()->subMinutes(2)]);

        $op3 = $this->service->recordDeposit(
            $this->shift,
            '20.00',
            $this->cashier,
            'Deposit',
            $this->cashDrawerApprovalEvidence('DEPOSIT', '20.00', 'Deposit'),
        );
        $op3->update(['created_at' => now()->subMinutes(1)]);

        $operations = $this->service->getShiftOperations($this->shift);

        $this->assertCount(3, $operations);
        $this->assertEquals($op1->id, $operations[0]->id);
        $this->assertEquals($op2->id, $operations[1]->id);
        $this->assertEquals($op3->id, $operations[2]->id);
    }

    private function createTerminal(): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    /**
     * @return array{
     *     approval_id: string,
     *     approval_fiscal_event_id: string,
     *     approval_scope: string,
     *     approval_supervisor_user_id: string,
     *     approval_target_hash: string
     * }
     */
    private function cashDrawerApprovalEvidence(string $operationType, string $amount, string $reason): array
    {
        $targetReferenceId = Str::uuid()->toString();
        $target = [
            'amount' => $amount,
            'operation_type' => $operationType,
            'reason' => $reason,
            'shift_id' => $this->shift->id,
            'target_reference_id' => $targetReferenceId,
        ];
        $targetHash = hash('sha256', (new CanonicalJsonEncoder)->encode($target));
        $approvalId = Str::uuid()->toString();
        $eventId = Str::uuid()->toString();
        $payload = [
            'approval_id' => $approvalId,
            'approval_scope' => 'cash_drawer_control',
            'cashier_user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-cash-drawer-policy-v1',
            'reason_code' => 'cash_drawer_control',
            'reason_text' => $reason,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->cashier->id,
            'supervisor_user_snapshot' => ['name' => $this->cashier->name, 'roles' => []],
            'target' => $target + ['target_hash' => $targetHash],
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::OPERATOR_APPROVAL_GRANTED->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => DB::table('fiscal_events')->count() + 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_parse_status' => 'parsed',
        ]);

        return [
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $eventId,
            'approval_scope' => 'cash_drawer_control',
            'approval_supervisor_user_id' => $this->cashier->id,
            'approval_target_hash' => $targetHash,
        ];
    }

    private function createOpenShift(): Shift
    {
        return Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private int $receiptSequence = 0;

    private function createReceipt(): Receipt
    {
        $this->receiptSequence++;

        return Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->receiptSequence),
            'chain_sequence' => $this->receiptSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "cash-drawer-test-{$this->receiptSequence}"),
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
            'total' => '119.00',
            'currency' => 'TND',
            'is_voided' => false,
        ]);
    }
}
