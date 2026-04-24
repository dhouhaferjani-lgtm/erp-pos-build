<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZReportHashServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZReportHashService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ZReportHashService;

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
        $this->shift = $this->createShift();
    }

    public function test_calculate_hash_returns_sha256(): void
    {
        $zReport = $this->createZReport([
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
        ]);

        $hash = $this->service->calculateHash($zReport, null);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_calculate_hash_uses_genesis_when_no_previous_hash(): void
    {
        $zReport = $this->createZReport([
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
        ]);

        $hashWithNull = $this->service->calculateHash($zReport, null);

        $reportDataJson = json_encode($zReport->report_data, JSON_UNESCAPED_UNICODE);
        $expectedPayload = sprintf(
            'GENESIS|%d|%s|%s|%s',
            $zReport->z_number,
            $zReport->terminal_id,
            $zReport->generated_at->toIso8601String(),
            $reportDataJson
        );
        $expectedHash = hash('sha256', $expectedPayload);

        $this->assertEquals($expectedHash, $hashWithNull);
    }

    public function test_calculate_hash_is_deterministic(): void
    {
        $zReport = $this->createZReport([
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
        ]);

        $hash1 = $this->service->calculateHash($zReport, null);
        $hash2 = $this->service->calculateHash($zReport, null);

        $this->assertEquals($hash1, $hash2);
    }

    public function test_calculate_hash_changes_with_different_previous_hash(): void
    {
        $zReport = $this->createZReport([
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
        ]);

        $hashWithNull = $this->service->calculateHash($zReport, null);
        $hashWithPrevious = $this->service->calculateHash($zReport, str_repeat('b', 64));

        $this->assertNotEquals($hashWithNull, $hashWithPrevious);
    }

    public function test_get_next_z_number_returns_1_for_first_report(): void
    {
        $nextNumber = $this->service->getNextZNumber($this->terminal);

        $this->assertEquals(1, $nextNumber);
    }

    public function test_get_next_z_number_increments(): void
    {
        $this->createZReport([
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
        ]);

        $this->createZReport([
            'z_number' => 2,
            'fiscal_hash' => str_repeat('b', 64),
            'previous_z_hash' => str_repeat('a', 64),
        ]);

        $nextNumber = $this->service->getNextZNumber($this->terminal);

        $this->assertEquals(3, $nextNumber);
    }

    public function test_verify_chain_returns_true_for_valid_chain(): void
    {
        $reportData = ['sales_count' => 5, 'gross_sales' => '500.00'];

        $z1 = $this->createZReport([
            'z_number' => 1,
            'report_data' => $reportData,
            'previous_z_hash' => null,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash1 = $this->service->calculateHash($z1, null);
        $z1->update(['fiscal_hash' => $hash1]);

        $z2 = $this->createZReport([
            'z_number' => 2,
            'report_data' => $reportData,
            'previous_z_hash' => $hash1,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash2 = $this->service->calculateHash($z2, $hash1);
        $z2->update(['fiscal_hash' => $hash2]);

        $z3 = $this->createZReport([
            'z_number' => 3,
            'report_data' => $reportData,
            'previous_z_hash' => $hash2,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash3 = $this->service->calculateHash($z3, $hash2);
        $z3->update(['fiscal_hash' => $hash3]);

        $this->assertTrue($this->service->verifyZReportChain($this->terminal));
    }

    public function test_verify_chain_returns_false_for_tampered_hash(): void
    {
        $reportData = ['sales_count' => 5, 'gross_sales' => '500.00'];

        $z1 = $this->createZReport([
            'z_number' => 1,
            'report_data' => $reportData,
            'previous_z_hash' => null,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash1 = $this->service->calculateHash($z1, null);
        $z1->update(['fiscal_hash' => $hash1]);

        $z2 = $this->createZReport([
            'z_number' => 2,
            'report_data' => $reportData,
            'previous_z_hash' => $hash1,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash2 = $this->service->calculateHash($z2, $hash1);
        $z2->update(['fiscal_hash' => $hash2]);

        $z3 = $this->createZReport([
            'z_number' => 3,
            'report_data' => $reportData,
            'previous_z_hash' => $hash2,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash3 = $this->service->calculateHash($z3, $hash2);
        $z3->update(['fiscal_hash' => $hash3]);

        $z2->update(['fiscal_hash' => str_repeat('f', 64)]);

        $this->assertFalse($this->service->verifyZReportChain($this->terminal));
    }

    public function test_find_chain_break_returns_null_for_valid_chain(): void
    {
        $reportData = ['sales_count' => 3, 'gross_sales' => '300.00'];

        $z1 = $this->createZReport([
            'z_number' => 1,
            'report_data' => $reportData,
            'previous_z_hash' => null,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash1 = $this->service->calculateHash($z1, null);
        $z1->update(['fiscal_hash' => $hash1]);

        $z2 = $this->createZReport([
            'z_number' => 2,
            'report_data' => $reportData,
            'previous_z_hash' => $hash1,
            'fiscal_hash' => str_repeat('0', 64),
        ]);
        $hash2 = $this->service->calculateHash($z2, $hash1);
        $z2->update(['fiscal_hash' => $hash2]);

        $this->assertNull($this->service->findChainBreak($this->terminal));
    }

    public function test_verify_chain_returns_true_for_empty_terminal(): void
    {
        $this->assertTrue($this->service->verifyZReportChain($this->terminal));
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

    private function createShift(): Shift
    {
        return Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private int $zReportSequence = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createZReport(array $overrides = []): ZReport
    {
        $this->zReportSequence++;

        // Each Z report must belong to a unique shift (unique constraint on shift_id).
        // Create a fresh shift for every Z report unless an explicit shift_id is provided.
        $shiftId = $overrides['shift_id'] ?? Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => $this->zReportSequence,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ])->id;

        $defaults = [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $shiftId,
            'z_number' => $this->zReportSequence,
            'fiscal_hash' => hash('sha256', "z-report-{$this->zReportSequence}"),
            'previous_z_hash' => null,
            'report_data' => [
                'sales_count' => 10,
                'gross_sales' => '1000.00',
                'net_sales' => '840.34',
                'tax_amount' => '159.66',
            ],
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ];

        // Remove shift_id from overrides since we've already computed it above.
        $overrides = array_diff_key($overrides, ['shift_id' => true]);
        return ZReport::create(array_merge($defaults, $overrides));
    }
}
