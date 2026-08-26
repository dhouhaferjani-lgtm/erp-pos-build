<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\ShiftAlreadyOpenException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

final class ShiftManagementServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private ShiftManagementService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        // Bind CompanyContext so CurrencyScaleResolver inside CashDrawerService has context
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $cashDrawerService = $this->app->make(CashDrawerService::class);
        $this->service = new ShiftManagementService($cashDrawerService, $this->mockCurrencyScale(3));

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
    }

    public function test_open_shift_creates_shift_with_correct_attributes(): void
    {
        $shift = $this->service->openShift($this->terminal, $this->cashier, '100.00');

        $this->assertNotNull($shift->id);
        $this->assertEquals($this->terminal->id, $shift->terminal_id);
        $this->assertEquals($this->cashier->id, $shift->cashier_id);
        $this->assertEquals(1, $shift->shift_number);
        $this->assertEquals('100.0000', $shift->opening_cash);
        $this->assertEquals(ShiftStatus::Open, $shift->status);
        $this->assertNotNull($shift->opened_at);
    }

    public function test_open_shift_increments_shift_number(): void
    {
        $firstShift = $this->service->openShift($this->terminal, $this->cashier, '100.00');
        $this->service->closeShift($firstShift, '100.00', $this->cashier);

        $secondShift = $this->service->openShift($this->terminal, $this->cashier, '150.00');

        $this->assertEquals(2, $secondShift->shift_number);
    }

    /**
     * LEDGER C-17(viii) — the v2/legacy half.
     *
     * `TerminalController::release()` probes `pos_shifts` for an OPEN row under
     * `lockForUpdate()` on the terminal row, but a lock only serialises writers
     * who take the same lock. This path took none, so a shift opened inside the
     * release's window slipped past a probe that had just decided there was
     * none. v3 terminals reach `ZSessionLifecycleProjection` instead and are
     * pinned there; this is the path a v2 web terminal still uses.
     *
     * PostgreSQL only: `SQLiteGrammar::compileLock()` compiles `FOR UPDATE`
     * away, so the ordering is unobservable on the SQLite leg.
     */
    public function test_open_shift_takes_the_terminal_row_lock_before_inserting_the_shift(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FOR UPDATE is compiled away by SQLiteGrammar::compileLock().');
        }

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->service->openShift($this->terminal, $this->cashier, '100.00');

        $lockAt = null;
        $insertAt = null;
        foreach ($statements as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from "pos_terminals"') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($insertAt === null && str_contains($sql, 'insert into "pos_shifts"')) {
                $insertAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'openShift() must take the terminal row FOR UPDATE.');
        $this->assertNotNull($insertAt, 'The shift was never inserted — the trace point moved.');
        $this->assertLessThan($insertAt, $lockAt, 'The terminal row lock must be taken BEFORE the shift insert.');
    }

    public function test_open_shift_throws_when_shift_already_open(): void
    {
        $this->service->openShift($this->terminal, $this->cashier, '100.00');

        $this->expectException(ShiftAlreadyOpenException::class);

        $this->service->openShift($this->terminal, $this->cashier, '200.00');
    }

    public function test_open_shift_creates_opening_cash_drawer_operation(): void
    {
        $shift = $this->service->openShift($this->terminal, $this->cashier, '100.00');

        $this->assertDatabaseHas('pos_cash_drawer_operations', [
            'shift_id' => $shift->id,
            'operation_type' => 'OPENING',
            'amount' => '100.000',
            'user_id' => $this->cashier->id,
        ]);
    }

    public function test_close_shift_updates_status_and_calculates_variance(): void
    {
        $shift = $this->service->openShift($this->terminal, $this->cashier, '100.00');

        $closedShift = $this->service->closeShift($shift, '95.00', $this->cashier);

        $this->assertEquals(ShiftStatus::Closed, $closedShift->status);
        $this->assertEquals('100.0000', $closedShift->expected_cash);
        $this->assertEquals('95.0000', $closedShift->actual_cash);
        $this->assertEquals('-5.0000', $closedShift->variance);
        $this->assertNotNull($closedShift->closed_at);
        $this->assertEquals($this->cashier->id, $closedShift->closed_by);
    }

    public function test_close_shift_throws_when_shift_not_open(): void
    {
        $shift = $this->service->openShift($this->terminal, $this->cashier, '100.00');
        $this->service->closeShift($shift, '100.00', $this->cashier);

        $closedShift = $shift->fresh();

        $this->expectException(ShiftNotOpenException::class);

        $this->service->closeShift($closedShift, '100.00', $this->cashier);
    }

    public function test_get_current_shift_returns_open_shift(): void
    {
        $shift = $this->service->openShift($this->terminal, $this->cashier, '100.00');

        $currentShift = $this->service->getCurrentShift($this->terminal);

        $this->assertNotNull($currentShift);
        $this->assertEquals($shift->id, $currentShift->id);
        $this->assertEquals(ShiftStatus::Open, $currentShift->status);
    }

    public function test_has_open_shift_returns_false_when_no_shift(): void
    {
        $this->assertFalse($this->service->hasOpenShift($this->terminal));
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
}
