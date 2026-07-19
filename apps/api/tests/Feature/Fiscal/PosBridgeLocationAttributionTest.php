<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** Locks the shared terminal→location resolver used by all three POS bridges. */
final class PosBridgeLocationAttributionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->tenantId = $tenant->id;
        $this->companyId = $company->id;
        $this->locationId = $location->id;
        $this->terminalId = $terminal->id;
    }

    public function test_receipt_account_payment_and_deposit_bridges_resolve_terminal_location(): void
    {
        $event = $this->event();
        foreach ([TreasuryReceiptBridge::class, TreasuryAccountPaymentBridge::class, TreasuryDepositBridge::class] as $bridgeClass) {
            $bridge = $this->app->make($bridgeClass);
            $method = new ReflectionMethod($bridge, 'resolveTerminalLocationId');
            $method->setAccessible(true);
            self::assertSame($this->locationId, $method->invoke($bridge, $event), $bridgeClass);
        }
    }

    public function test_server_authored_event_without_terminal_has_no_terminal_location(): void
    {
        $event = $this->event();
        $event->terminal_id = '';
        $bridge = $this->app->make(TreasuryDepositBridge::class);
        $method = new ReflectionMethod($bridge, 'resolveTerminalLocationId');
        $method->setAccessible(true);
        self::assertNull($method->invoke($bridge, $event));
    }

    private function event(): FiscalEvent
    {
        $event = new FiscalEvent;
        $event->setRawAttributes([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
        ]);
        $event->setAttribute('tenant_id', $this->tenantId);
        $event->setAttribute('company_id', $this->companyId);
        $event->setAttribute('terminal_id', $this->terminalId);

        return $event;
    }
}
