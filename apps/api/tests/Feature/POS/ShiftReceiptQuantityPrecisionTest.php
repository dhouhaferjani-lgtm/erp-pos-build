<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ShiftReceiptQuantityPrecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_receipts_preserve_precision_for_repeated_distinct_and_missing_products(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->for($tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $user->givePermissionTo('pos.operate_terminal');
        Sanctum::actingAs($user);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
        ]);

        $unit = Unit::factory()->create(['decimal_places' => 2]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'unit_id' => $unit->id,
        ]);
        $distinctUnit = Unit::factory()->create(['decimal_places' => 3]);
        $distinctProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'unit_id' => $distinctUnit->id,
        ]);
        $receipt = Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'posted_at' => now()->subMinutes(5),
        ]);

        $currentLine = $this->createLine($receipt, 1, $product, '1.2000');
        $repeatedLine = $this->createLine($receipt, 2, $product, '2.3000');
        $distinctLine = $this->createLine($receipt, 3, $distinctProduct, '3.4560');
        $missingLine = $this->createLine($receipt, 4, null, '1.1250');

        $response = $this->getJson("/api/v1/pos/shifts/{$shift->id}/receipts");

        $response->assertOk();
        /** @var array<int, array{id: string, quantity: string, quantity_decimals?: int}> $lines */
        $lines = $response->json('data.0.lines');
        $byId = collect($lines)->keyBy('id');

        $currentPayload = $byId->get($currentLine->id);
        $repeatedPayload = $byId->get($repeatedLine->id);
        $distinctPayload = $byId->get($distinctLine->id);
        $missingPayload = $byId->get($missingLine->id);
        $this->assertIsArray($currentPayload);
        $this->assertIsArray($repeatedPayload);
        $this->assertIsArray($distinctPayload);
        $this->assertIsArray($missingPayload);
        $this->assertIsInt($currentPayload['quantity_decimals'] ?? null);
        $this->assertIsInt($repeatedPayload['quantity_decimals'] ?? null);
        $this->assertIsInt($distinctPayload['quantity_decimals'] ?? null);
        $this->assertIsInt($missingPayload['quantity_decimals'] ?? null);
        $this->assertSame(
            [2, 2, 3, 4],
            [
                $currentPayload['quantity_decimals'],
                $repeatedPayload['quantity_decimals'],
                $distinctPayload['quantity_decimals'],
                $missingPayload['quantity_decimals'],
            ],
        );
        $this->assertSame('1.2000', $currentPayload['quantity']);
        $this->assertSame('2.3000', $repeatedPayload['quantity']);
        $this->assertSame('3.4560', $distinctPayload['quantity']);
        $this->assertSame('1.1250', $missingPayload['quantity']);

        $this->assertSame('1.2000', $currentLine->fresh()?->quantity);
        $this->assertSame('2.3000', $repeatedLine->fresh()?->quantity);
        $this->assertSame('3.4560', $distinctLine->fresh()?->quantity);
        $this->assertSame('1.1250', $missingLine->fresh()?->quantity);
        $this->assertArrayNotHasKey('quantity_decimals', $currentLine->fresh()?->getAttributes() ?? []);
        $this->assertArrayNotHasKey('quantity_decimals', $repeatedLine->fresh()?->getAttributes() ?? []);
        $this->assertArrayNotHasKey('quantity_decimals', $distinctLine->fresh()?->getAttributes() ?? []);
        $this->assertArrayNotHasKey('quantity_decimals', $missingLine->fresh()?->getAttributes() ?? []);
    }

    private function createLine(Receipt $receipt, int $lineNumber, ?Product $product, string $quantity): ReceiptLine
    {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => $lineNumber,
            'product_id' => $product?->id,
            'product_code' => $product?->sku ?? 'ARCHIVED',
            'product_name' => $product?->name ?? 'Archived item',
            'quantity' => $quantity,
            'unit' => 'unit',
            'unit_price' => '10.000',
            'line_total' => '10.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);
    }
}
