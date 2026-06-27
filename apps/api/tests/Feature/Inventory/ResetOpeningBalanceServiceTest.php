<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Application\Services\ResetOpeningBalanceService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Exceptions\OpeningLockedException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\InventoryServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 9 — service-layer tests for ResetOpeningBalanceService.
 *
 * Scenarios:
 *   1. Happy path: post an opening (qty 10 cost 5 TND), reset it →
 *      - original + reversal movement both present (2 Opening rows)
 *      - reversal movement has reverses_movement_id set
 *      - stock level → '0.0000'
 *      - hasActiveOpening → false
 *      - contra GL entry exists (Dr OBE / Cr Inventory for 50.000 TND)
 *      - re-entry (posting a NEW opening after reset) SUCCEEDS
 *   2. Blocked: opening + a downstream non-opening movement → OpeningLockedException
 */
final class ResetOpeningBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Happy-path
    // ---------------------------------------------------------------

    public function test_reset_reverses_opening_and_allows_reentry(): void
    {
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();

        /** @var OpeningBalancePostingService $posting */
        $posting = app(OpeningBalancePostingService::class);

        $posting->post(new OpeningBalancePosting(
            tenantId: $tenant->id,
            companyId: $company->id,
            userId: $user->id,
            entryDate: now(),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: 'Opening balance: '.$product->sku,
            notes: null,
            lines: [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)],
        ));

        /** @var ResetOpeningBalanceService $reset */
        $reset = app(ResetOpeningBalanceService::class);
        $reset->reset($company->id, $tenant->id, $product->id, $user->id);

        // Both the original Opening movement AND a reversal row must exist.
        $openingMovements = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('movement_type', MovementType::Opening)
            ->get();

        $this->assertSame(2, $openingMovements->count());

        // The reversal has reverses_movement_id set.
        $reversalMovement = StockMovement::query()
            ->where('product_id', $product->id)
            ->whereNotNull('reverses_movement_id')
            ->first();

        $this->assertNotNull($reversalMovement);

        // Stock level back to zero.
        $this->assertSame(
            '0.0000',
            StockLevel::query()->where('product_id', $product->id)->value('quantity'),
        );

        // Product cost cleared.
        $freshProduct = $product->fresh();
        $this->assertNotNull($freshProduct);
        $this->assertSame('0.000000', (string) $freshProduct->cost_price);
        $this->assertNull($freshProduct->cost_updated_at);

        // hasActiveOpening now false (re-enterable).
        /** @var InventoryServiceInterface $inventoryService */
        $inventoryService = app(InventoryServiceInterface::class);
        $this->assertFalse($inventoryService->hasActiveOpening($company->id, $product->id));

        // Contra GL entry exists: source_type = inventory_opening_balance_reversal.
        $contraEntry = JournalEntry::query()
            ->where('company_id', $company->id)
            ->where('source_type', 'inventory_opening_balance_reversal')
            ->with('lines')
            ->first();

        $this->assertNotNull($contraEntry, 'Contra GL entry must exist after reset');
        $this->assertSame(JournalEntryStatus::Posted, $contraEntry->status);
        $this->assertTrue($contraEntry->is_historical);

        // Lines: Dr OBE / Cr Inventory, each for 50.000 TND (qty 10 × cost 5.000).
        $this->assertSame(2, $contraEntry->lines->count());

        $inventoryAccountId = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::Inventory)
            ->value('id');

        $obeAccountId = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::OpeningBalanceEquity)
            ->value('id');

        $obeDebitLine = $contraEntry->lines->firstWhere('account_id', $obeAccountId);
        $inventoryCreditLine = $contraEntry->lines->firstWhere('account_id', $inventoryAccountId);

        $this->assertNotNull($obeDebitLine, 'OBE debit line must exist');
        $this->assertNotNull($inventoryCreditLine, 'Inventory credit line must exist');

        $this->assertSame('50.000', $obeDebitLine->debit);
        $this->assertSame('0.000', $obeDebitLine->credit);
        $this->assertSame('0.000', $inventoryCreditLine->debit);
        $this->assertSame('50.000', $inventoryCreditLine->credit);

        // Re-entry: posting a NEW opening after reset succeeds.
        $reentryResult = $posting->post(new OpeningBalancePosting(
            tenantId: $tenant->id,
            companyId: $company->id,
            userId: $user->id,
            entryDate: now(),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: 'Re-entry opening: '.$product->sku,
            notes: null,
            lines: [OpeningBalanceLine::make($product->id, null, $location->id, '5', '8.000', 3)],
        ));

        $this->assertNotEmpty($reentryResult->movementIdsInInputOrder);

        // Stock level now reflects the re-entry qty.
        $this->assertSame(
            '5.0000',
            StockLevel::query()->where('product_id', $product->id)->value('quantity'),
        );

        // hasActiveOpening is true again.
        $this->assertTrue($inventoryService->hasActiveOpening($company->id, $product->id));
    }

    // ---------------------------------------------------------------
    // Guard: downstream movements block reset
    // ---------------------------------------------------------------

    public function test_reset_blocked_when_downstream_movement_exists(): void
    {
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();

        /** @var OpeningBalancePostingService $posting */
        $posting = app(OpeningBalancePostingService::class);

        // Post opening.
        $posting->post(new OpeningBalancePosting(
            tenantId: $tenant->id,
            companyId: $company->id,
            userId: $user->id,
            entryDate: now(),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: 'Opening balance: '.$product->sku,
            notes: null,
            lines: [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)],
        ));

        // Simulate a downstream (non-opening) movement — e.g. an adjustment.
        StockMovement::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'movement_type' => MovementType::Adjustment,
            'quantity' => '3.0000',
            'quantity_before' => '10.0000',
            'quantity_after' => '13.0000',
        ]);

        $this->expectException(OpeningLockedException::class);

        /** @var ResetOpeningBalanceService $reset */
        $reset = app(ResetOpeningBalanceService::class);
        $reset->reset($company->id, $tenant->id, $product->id, $user->id);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array{0: Tenant, 1: Company, 2: Product, 3: Location, 4: User}
     */
    private function seedOpeningContext(): array
    {
        $tenant = Tenant::create([
            'name' => 'Reset OB Tenant',
            'slug' => 'reset-ob-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reset OB Company',
            'legal_name' => 'Reset OB Company LLC',
            'tax_id' => 'RESET-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND', // scale-3
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reset OB User',
            'email' => 'reset-ob-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'WH-RESET-01',
            'name' => 'Reset OB Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => 'RESET-PROD-'.uniqid(),
            'name' => 'Reset OB Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.000000',
        ]);

        // GL accounts required by OpeningBalancePostingService and ResetOpeningBalanceService.
        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '3100',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        return [$tenant, $company, $product, $location, $user];
    }
}
