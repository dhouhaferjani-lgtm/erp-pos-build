<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Concerns;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithOwnerReporting
{
    protected Tenant $tenant;

    protected Company $company;

    protected Company $childCompany;

    protected User $owner;

    protected User $userWithoutPermission;

    protected Location $locationA;

    protected Location $locationB;

    protected Terminal $terminalA;

    protected Terminal $terminalB;

    protected PaymentMethod $cashMethod;

    protected function setUpOwnerReportingFixtures(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Parent Company']);
        $this->childCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Child Company',
            'parent_company_id' => $this->company->id, 'is_headquarters' => false,
        ]);
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create(['user_id' => $this->owner->id, 'company_id' => $this->company->id, 'role' => 'owner']);
        UserCompanyMembership::create(['user_id' => $this->userWithoutPermission->id, 'company_id' => $this->company->id, 'role' => 'manager']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('dashboard.owner', 'sanctum');
        $this->owner->givePermissionTo('dashboard.owner');

        $this->locationA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Downtown']);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Airport']);
        $this->terminalA = Terminal::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->locationA->id]);
        $this->terminalB = Terminal::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->locationB->id]);
        $this->cashMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Cash', 'code' => 'CASH']);
    }

    protected function companyHeaders(): array
    {
        return ['X-Company-Id' => $this->company->id];
    }

    protected function seedReceipt(Location $location, Terminal $terminal, string $postedAt, string $total, bool $trainingFlag = false): Receipt
    {
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => $postedAt,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'training_flag' => $trainingFlag,
        ]);

        // Mirrors the proven OwnerReportingTest payment insert (sales only, positive amount).
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => $total,
        ]);

        return $receipt;
    }

    /**
     * Return receipt: negative total. DB CHECK requires receipt_type='return' to carry
     * original_receipt_id + return_reason. NO payment row is written (pos_receipt_payments
     * has CHECK amount > 0; the summary service reads pos_receipts, not payments, so a
     * return needs no tender row for these tests).
     */
    protected function seedReturn(Location $location, Terminal $terminal, string $postedAt, string $negativeTotal, Receipt $original, string $reason = 'customer_request'): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => $reason,
            'posted_at' => $postedAt,
            'subtotal' => $negativeTotal,
            'tax_amount' => '0.000',
            'total' => $negativeTotal,
            'training_flag' => false,
        ]);
    }

    protected function seedReceiptWithLine(Product $product, Location $location, Terminal $terminal, string $postedAt, string $lineTotal, string $quantity): Receipt
    {
        $receipt = $this->seedReceipt($location, $terminal, $postedAt, $lineTotal);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => $lineTotal,
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => $lineTotal,
            'discount_amount' => '0.00',
        ]);

        return $receipt;
    }
}
