<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/** D-28 production-root coverage for C-1 and C-3. [PG] */
final class InventoryGlCompositeRootTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private Product $product;

    private Location $location;

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame(0, DB::transactionLevel(), 'composite test must begin at a real root');
        // A preceding RefreshDatabase class can leave Laravel's test-only
        // transaction manager installed on the shared connection. That manager
        // fires afterCommit at level one and would erase a nested writer's
        // buffer before the production root tail. Restore production semantics.
        DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
        $nonce = Str::lower(Str::random(10));
        $this->tenant = Tenant::create([
            'name' => "Composite {$nonce}",
            'slug' => "composite-{$nonce}",
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => "Composite {$nonce}",
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Composite User',
            'email' => "{$nonce}@example.test",
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'orders.view', 'orders.create', 'orders.update', 'orders.confirm',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.post', 'invoices.cancel',
            'deliveries.view', 'deliveries.create', 'deliveries.confirm',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        (new FranceChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Composite Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Composite Customer',
            'type' => PartnerType::Customer,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Composite Product',
            'sku' => "CMP-{$nonce}",
            'type' => ProductType::Part,
            'is_physical' => true,
            'sale_price' => '100.000',
            'cost_price' => '60.000000',
        ]);
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '100.0000',
            'reserved' => '0.0000',
        ]);
    }

    public function test_c3_and_c1_production_roots_flush_their_nested_writers(): void
    {
        $dn = $this->draftDeliveryNote('DN-C3', '1.0000');

        $this->actingAs($this->user)
            ->postJson("/api/v1/delivery-notes/{$dn->id}/confirm")
            ->assertOk();

        $movement = StockMovement::query()->where('reference_id', $dn->id)->sole();
        self::assertSame(1, JournalEntry::query()
            ->where('source_type', 'inventory_exit')
            ->where('source_id', $movement->id)
            ->count());

        $order = $this->confirmedOrder();
        $invoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice")
            ->assertCreated();
        $invoiceId = (string) $invoiceResponse->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoiceId}/confirm")->assertOk();

        $second = $this->draftDeliveryNote('DN-C1-SECOND', '1.0000', $order->id);
        $order->refresh();
        $payload = $order->payload ?? [];
        $payload['delivery_note_ids'] = array_values(array_unique(array_merge(
            $payload['delivery_note_ids'] ?? [],
            [$second->id],
        )));
        $order->update(['payload' => $payload]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm-deliveries-and-post")
            ->assertOk();
        self::assertCount(2, $response->json('meta.confirmed_delivery_notes'));

        $dnIds = collect($response->json('meta.confirmed_delivery_notes'))->pluck('id');
        $movementIds = StockMovement::query()->whereIn('reference_id', $dnIds)->pluck('id');
        self::assertCount(2, $movementIds);
        self::assertSame(2, JournalEntry::query()
            ->where('source_type', 'inventory_exit')
            ->whereIn('source_id', $movementIds)
            ->count());
        self::assertSame(DocumentStatus::Posted, Document::query()->findOrFail($invoiceId)->status);

        // C-2 guided cancel: ReturnNoteService confirms inside RefundService's
        // composite root, which must flush the deferred inventory-entry leg.
        $cancelOrder = $this->confirmedOrder();
        $cancelInvoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$cancelOrder->id}/convert-to-invoice")
            ->assertCreated();
        $cancelInvoiceId = (string) $cancelInvoiceResponse->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/invoices/{$cancelInvoiceId}/confirm")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$cancelInvoiceId}/confirm-deliveries-and-post")
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$cancelInvoiceId}/cancel", [
                'reason' => 'Composite C-2 proof',
                'return_decision' => [
                    'mode' => 'already_returned',
                    'returned_on' => now()->toDateString(),
                ],
            ])
            ->assertOk();
        $returnNote = Document::query()
            ->where('type', DocumentType::ReturnNote)
            ->where('source_document_id', $cancelInvoiceId)
            ->sole();
        $returnMovementIds = StockMovement::query()->where('reference_id', $returnNote->id)->pluck('id');
        self::assertNotEmpty($returnMovementIds);
        self::assertSame($returnMovementIds->count(), JournalEntry::query()
            ->where('source_type', 'inventory_entry')
            ->whereIn('source_id', $returnMovementIds)
            ->count());
    }

    public function test_c5_guided_delivery_root_flushes_its_nested_writer(): void
    {
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertOk();

        $deliveryNote = Document::query()
            ->where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $invoice->id)
            ->sole();
        $movement = StockMovement::query()->where('reference_id', $deliveryNote->id)->sole();

        self::assertSame(1, JournalEntry::query()
            ->where('source_type', 'inventory_exit')
            ->where('source_id', $movement->id)
            ->count());
    }

    private function confirmedOrder(): Document
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'location_id' => $this->location->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-'.Str::upper(Str::random(8)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '200.000',
            'tax_amount' => '0.000',
            'total' => '200.000',
        ]);
        $order->lines()->create([
            'line_number' => 1,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'description' => $this->product->name,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '200.000',
        ]);

        return $order;
    }

    private function draftDeliveryNote(string $number, string $quantity, ?string $sourceId = null): Document
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'location_id' => $this->location->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => $number.'-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'source_document_id' => $sourceId,
        ]);
        $dn->lines()->create([
            'line_number' => 1,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'description' => $this->product->name,
            'quantity' => $quantity,
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '100.000',
        ]);

        return $dn->load('lines');
    }
}
