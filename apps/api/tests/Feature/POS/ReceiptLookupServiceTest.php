<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\CustomerHistorySearchService;
use App\Modules\POS\Application\Services\Fiscal\ReceiptQrTokenSigner;
use App\Modules\POS\Application\Services\ReceiptLookupService;
use App\Modules\POS\Domain\Exceptions\InvalidReceiptTokenException;
use App\Modules\POS\Domain\Exceptions\ReceiptNotFoundException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalJsonEncoder;
use App\Modules\POS\Domain\TenantSigningKey;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Infrastructure\RateLimit\CustomerHistorySearchRateLimiter;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ReceiptLookupService (Task 24).
 *
 * Covers:
 *   - findByQrToken: valid token returns receipt
 *   - findByQrToken: Phase 1 single-terminal scope
 *   - findByQrToken: cross-tenant rejection
 *   - findByQrToken: voided receipts excluded
 *   - findByReceiptNumber: valid input returns receipt
 *   - findByReceiptNumber: other terminal rejection
 */
final class ReceiptLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptLookupService $service;

    private ReceiptQrTokenSigner $signer;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private TenantSigningKey $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new ReceiptQrTokenSigner(new CanonicalJsonEncoder);

        $this->service = new ReceiptLookupService(
            signer: $this->signer,
            customerHistorySearchService: new CustomerHistorySearchService(
                rateLimiter: new CustomerHistorySearchRateLimiter(app(RateLimiter::class)),
                events: app(Dispatcher::class),
            ),
        );

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->signingKey = TenantSigningKey::factory()
            ->forTenant($this->tenant)
            ->withKid('current')
            ->create();
    }

    // -------------------------------------------------------------------------
    // findByQrToken — happy path
    // -------------------------------------------------------------------------

    public function test_find_by_qr_token_returns_receipt_for_valid_token(): void
    {
        $receipt = $this->createReceipt();

        $token = $this->signer->sign($receipt, $this->signingKey);

        $found = $this->service->findByQrToken($token, $this->terminal);

        $this->assertSame($receipt->id, $found->id);
    }

    // -------------------------------------------------------------------------
    // findByQrToken — Phase 1 single-terminal scope
    // -------------------------------------------------------------------------

    public function test_find_by_qr_token_throws_for_receipt_on_other_terminal(): void
    {
        $this->expectException(ReceiptNotFoundException::class);

        // Create a receipt on terminal B.
        $terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $receiptOnB = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminalB->id,
            'cashier_id' => $this->cashier->id,
        ]);

        // Sign the receipt but verify at terminal A — should fail (Phase 1 scope).
        // To get the token to pass MAC verification but fail the DB scope, we need
        // to sign with a key that terminal A also has (same tenant, same kid).
        // Since both terminals share the same tenant, the MAC will pass,
        // but the terminal_id filter will block the lookup.
        $token = $this->signer->sign($receiptOnB, $this->signingKey);

        // Verify at terminal A — the receipt exists but on terminal B, not A.
        $this->service->findByQrToken($token, $this->terminal);
    }

    // -------------------------------------------------------------------------
    // findByQrToken — cross-tenant rejection
    // -------------------------------------------------------------------------

    public function test_find_by_qr_token_throws_for_receipt_in_other_tenant(): void
    {
        $this->expectException(InvalidReceiptTokenException::class);

        $receipt = $this->createReceipt();
        $token = $this->signer->sign($receipt, $this->signingKey);

        // Try to verify using a terminal from a different tenant.
        $tenantB = Tenant::factory()->create();
        $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);
        $locationB = Location::factory()->create(['company_id' => $companyB->id]);
        $terminalB = Terminal::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
            'location_id' => $locationB->id,
        ]);

        $this->service->findByQrToken($token, $terminalB);
    }

    // -------------------------------------------------------------------------
    // findByQrToken — voided receipts excluded
    // -------------------------------------------------------------------------

    public function test_find_by_qr_token_throws_for_voided_receipt(): void
    {
        $this->expectException(ReceiptNotFoundException::class);

        $receipt = $this->createReceipt(['is_voided' => true]);
        $token = $this->signer->sign($receipt, $this->signingKey);

        $this->service->findByQrToken($token, $this->terminal);
    }

    // -------------------------------------------------------------------------
    // findByReceiptNumber — happy path
    // -------------------------------------------------------------------------

    public function test_find_by_receipt_number_works_for_typed_input(): void
    {
        $receipt = $this->createReceipt(['receipt_number' => 'T001-C001-L01-POS01-2026-00000042']);

        $found = $this->service->findByReceiptNumber('T001-C001-L01-POS01-2026-00000042', $this->terminal);

        $this->assertSame($receipt->id, $found->id);
    }

    // -------------------------------------------------------------------------
    // findByReceiptNumber — single-terminal scope
    // -------------------------------------------------------------------------

    public function test_find_by_receipt_number_throws_for_other_terminal_receipt(): void
    {
        $this->expectException(ReceiptNotFoundException::class);

        $terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminalB->id,
            'cashier_id' => $this->cashier->id,
            'receipt_number' => 'T002-C001-L01-POS02-2026-00000001',
        ]);

        // Search at terminal A for a receipt that exists only on terminal B.
        $this->service->findByReceiptNumber('T002-C001-L01-POS02-2026-00000001', $this->terminal);
    }

    public function test_find_by_receipt_number_throws_for_missing_number(): void
    {
        $this->expectException(ReceiptNotFoundException::class);

        $this->service->findByReceiptNumber('NONEXISTENT-NUMBER', $this->terminal);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(array $overrides = []): Receipt
    {
        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
        ], $overrides));
    }
}
