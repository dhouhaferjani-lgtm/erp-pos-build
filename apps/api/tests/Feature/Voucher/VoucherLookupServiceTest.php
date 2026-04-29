<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\GenericLookupResult;
use App\Modules\Voucher\Application\DTOs\InSessionLookupResult;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Application\Services\VoucherLookupService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherFraudAlert;
use App\Modules\Voucher\Domain\Events\VoucherLookupSoftAlert;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Modules\Voucher\Infrastructure\RateLimit\VoucherLookupRateLimiter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for VoucherLookupService (Task 15).
 *
 * Tests cover:
 *   - Generic lookup: active, expired, non-existent
 *   - Rate-limit trips → generic invalid (identical response, prevents enumeration)
 *   - In-session lookup: full disclosure, partner match/mismatch
 *   - In-session falls back to generic when receipt is not PendingSeal
 *   - 5 failed attempts auto-void + VoucherFraudAlert
 */
final class VoucherLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Terminal $terminal;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lookup Tenant',
            'slug' => 'test-voucher-lookup',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lookup Company',
            'legal_name' => 'Lookup Company LLC',
            'tax_id' => 'TAXLKP',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Cashier',
            'email' => 'cashier-lookup@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = $this->createTerminal('POS-LKP');
    }

    // -------------------------------------------------------------------------
    // Generic lookup — happy paths
    // -------------------------------------------------------------------------

    public function test_generic_lookup_returns_active_for_valid_voucher_without_balance(): void
    {
        $voucher = $this->issueVoucher('50.00000');

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertInstanceOf(GenericLookupResult::class, $result);
        $this->assertTrue($result->exists);
        $this->assertSame('active', $result->status);
        // Must NOT contain balance or expiry
        $array = $result->toArray();
        $this->assertArrayNotHasKey('balance', $array);
        $this->assertArrayNotHasKey('expires_at', $array);
        $this->assertArrayNotHasKey('redemption_mode', $array);
    }

    public function test_generic_lookup_returns_invalid_for_nonexistent_code(): void
    {
        $result = $this->makeService()->lookupGeneric(
            'POSC-FAKE-1234-5678',
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertInstanceOf(GenericLookupResult::class, $result);
        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    public function test_generic_lookup_returns_expired_for_expired_voucher(): void
    {
        $voucher = $this->issueVoucher('30.00000');
        // Manually push expires_at into the past
        $voucher->update(['expires_at' => Carbon::now()->subDay(), 'status' => VoucherStatus::Expired]);

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertInstanceOf(GenericLookupResult::class, $result);
        $this->assertTrue($result->exists);
        $this->assertSame('expired', $result->status);
    }

    public function test_generic_lookup_returns_invalid_for_voided_voucher(): void
    {
        $voucher = $this->issueVoucher('40.00000');
        $voucher->update(['status' => VoucherStatus::Voided, 'current_balance' => '0.00000']);

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        // Voided → same as "invalid" (not distinguishable from missing)
        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    // -------------------------------------------------------------------------
    // Generic lookup — rate limit paths (all return generic invalid)
    // -------------------------------------------------------------------------

    public function test_generic_lookup_rate_limit_per_terminal_returns_generic_invalid(): void
    {
        $voucher = $this->issueVoucher('50.00000');
        $rateLimiter = app(RateLimiter::class);

        // Exhaust terminal counter manually (200 hits = limit reached)
        $terminalKey = "voucher_lookup:terminal:{$this->terminal->id}:".date('Y-m-d');
        for ($i = 0; $i < VoucherLookupRateLimiter::PER_VOUCHER_FAILED_24H + 200; $i++) {
            $rateLimiter->hit($terminalKey, 86400);
        }

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        // Rate-limited → identical to "code not found"
        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    public function test_generic_lookup_rate_limit_per_cashier_returns_generic_invalid(): void
    {
        $voucher = $this->issueVoucher('50.00000');
        $rateLimiter = app(RateLimiter::class);

        // Exhaust cashier counter (100 hits)
        $cashierKey = "voucher_lookup:cashier:{$this->cashier->id}:".date('Y-m-d');
        for ($i = 0; $i < 105; $i++) {
            $rateLimiter->hit($cashierKey, 86400);
        }

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    public function test_generic_lookup_per_tenant_failed_hard_block(): void
    {
        $rateLimiter = app(RateLimiter::class);

        // Exhaust tenant failed counter (200 failed lookups)
        $tenantKey = "voucher_lookup:tenant_failed:{$this->tenant->id}:".date('Y-m-d-H');
        for ($i = 0; $i < 205; $i++) {
            $rateLimiter->hit($tenantKey, 3600);
        }

        // Now any lookup should get generic invalid (tenant hard block)
        $result = $this->makeService()->lookupGeneric(
            'POSC-ZZZZ-9999-0000',
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    public function test_generic_lookup_per_code_prefix_throttle(): void
    {
        $voucher = $this->issueVoucher('50.00000');
        $rateLimiter = app(RateLimiter::class);

        // Extract prefix from the voucher code (chars 4-7 after stripping separators)
        $stripped = str_replace(['-', ' '], '', $voucher->code);
        $prefix = substr($stripped, 4, 4);

        // Exhaust code-prefix counter (30 hits)
        $prefixKey = "voucher_lookup:prefix:{$prefix}:".date('Y-m-d-H');
        for ($i = 0; $i < 35; $i++) {
            $rateLimiter->hit($prefixKey, 3600);
        }

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    // -------------------------------------------------------------------------
    // In-session lookup
    // -------------------------------------------------------------------------

    public function test_in_session_lookup_returns_full_balance_and_expiry(): void
    {
        $expiresAt = Carbon::now()->addDays(30);
        $voucher = $this->issueVoucher('75.00000', $expiresAt);

        $openReceipt = $this->makeOpenReceipt();

        $result = $this->makeService()->lookupForPayment(
            $voucher->code,
            $openReceipt,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        $this->assertInstanceOf(InSessionLookupResult::class, $result);
        $this->assertSame($voucher->id, $result->voucherId);
        $this->assertEquals(0, bccomp($result->balance, '75.00000', 5));
        $this->assertSame('EUR', $result->currency);
        $this->assertSame(RedemptionMode::Bearer, $result->redemptionMode);
        $this->assertNotNull($result->expiresAt);
        $this->assertTrue($result->partnerIdMatch); // Bearer → always true
    }

    public function test_in_session_lookup_falls_back_to_generic_when_rate_limited(): void
    {
        $voucher = $this->issueVoucher('50.00000');
        $openReceipt = $this->makeOpenReceipt();
        $rateLimiter = app(RateLimiter::class);

        // Exhaust cashier counter
        $cashierKey = "voucher_lookup:cashier:{$this->cashier->id}:".date('Y-m-d');
        for ($i = 0; $i < 105; $i++) {
            $rateLimiter->hit($cashierKey, 86400);
        }

        $result = $this->makeService()->lookupForPayment(
            $voucher->code,
            $openReceipt,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        // Even in-session → rate-limit trips produce generic invalid
        $this->assertInstanceOf(GenericLookupResult::class, $result);
        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    public function test_in_session_without_open_receipt_falls_back_to_generic(): void
    {
        $voucher = $this->issueVoucher('50.00000');

        // Receipt that is Fiscalized (not PendingSeal)
        $fiscalizedReceipt = $this->makeFiscalizedReceipt();

        $result = $this->makeService()->lookupForPayment(
            $voucher->code,
            $fiscalizedReceipt,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        // Falls back to generic — full details not disclosed
        $this->assertInstanceOf(GenericLookupResult::class, $result);
    }

    // -------------------------------------------------------------------------
    // Partner match / mismatch
    // -------------------------------------------------------------------------

    public function test_partner_id_match_for_customer_bound_voucher(): void
    {
        $partnerA = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
        ]);

        $partnerB = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
        ]);

        $voucher = $this->issueCustomerBoundVoucher('60.00000', $partnerA->id);

        // Matching partner → partner_id_match = true
        $receiptA = $this->makeOpenReceipt($partnerA->id);
        $resultA = $this->makeService()->lookupForPayment(
            $voucher->code,
            $receiptA,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );
        $this->assertInstanceOf(InSessionLookupResult::class, $resultA);
        $this->assertTrue($resultA->partnerIdMatch);

        // Flush rate-limit counters between the two calls (avoid limit trip)
        Cache::flush();

        // Non-matching partner → partner_id_match = false, but voucher is still returned
        $receiptB = $this->makeOpenReceipt($partnerB->id);
        $resultB = $this->makeService()->lookupForPayment(
            $voucher->code,
            $receiptB,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );
        $this->assertInstanceOf(InSessionLookupResult::class, $resultB);
        $this->assertFalse($resultB->partnerIdMatch);
    }

    // -------------------------------------------------------------------------
    // Per-IP rate limit
    // -------------------------------------------------------------------------

    public function test_generic_lookup_per_ip_hard_block_returns_generic_invalid(): void
    {
        $voucher = $this->issueVoucher('50.00000');
        $rateLimiter = app(RateLimiter::class);

        $ip = '192.168.1.100';
        $safe = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $ip);
        $ipKey = "voucher_lookup:ip:{$safe}:".date('Y-m-d-H');

        // Exhaust the per-IP hourly counter (300 hits)
        for ($i = 0; $i < 305; $i++) {
            $rateLimiter->hit($ipKey, 3600);
        }

        $result = $this->makeService()->lookupGeneric(
            $voucher->code,
            $this->terminal,
            $this->cashier,
            $ip,
        );

        // IP hard-blocked → identical to "code not found"
        $this->assertFalse($result->exists);
        $this->assertSame('invalid', $result->status);
    }

    // -------------------------------------------------------------------------
    // Per-tenant soft alert
    // -------------------------------------------------------------------------

    public function test_per_tenant_soft_alert_dispatched_at_50_failed_lookups(): void
    {
        Event::fake([VoucherLookupSoftAlert::class]);

        $rateLimiter = app(RateLimiter::class);

        // Pre-seed 49 failed attempts (one below the soft-alert threshold)
        $tenantKey = "voucher_lookup:tenant_failed:{$this->tenant->id}:".date('Y-m-d-H');
        for ($i = 0; $i < 49; $i++) {
            $rateLimiter->hit($tenantKey, 3600);
        }

        // The 50th failed lookup (non-existent code) should cross the threshold and emit the event.
        $this->makeService()->lookupGeneric(
            'POSC-ZZZZ-8888-9999',
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        // VoucherLookupSoftAlert must be dispatched exactly once at count=50
        Event::assertDispatched(VoucherLookupSoftAlert::class, function (VoucherLookupSoftAlert $event): bool {
            return $event->tenantId === $this->tenant->id
                && $event->count === 50;
        });
    }

    public function test_per_tenant_soft_alert_not_dispatched_before_threshold(): void
    {
        Event::fake([VoucherLookupSoftAlert::class]);

        $rateLimiter = app(RateLimiter::class);

        // Pre-seed 48 failed attempts (two below the threshold)
        $tenantKey = "voucher_lookup:tenant_failed:{$this->tenant->id}:".date('Y-m-d-H');
        for ($i = 0; $i < 48; $i++) {
            $rateLimiter->hit($tenantKey, 3600);
        }

        // 49th failed lookup — not yet at threshold
        $this->makeService()->lookupGeneric(
            'POSC-ZZZZ-7777-8888',
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        Event::assertNotDispatched(VoucherLookupSoftAlert::class);
    }

    // -------------------------------------------------------------------------
    // Auto-void after 5 failed attempts
    // -------------------------------------------------------------------------

    public function test_5_failed_attempts_auto_voids_voucher_and_emits_fraud_alert(): void
    {
        Event::fake([VoucherFraudAlert::class]);

        $voucher = $this->issueVoucher('50.00000');
        $openReceipt = $this->makeOpenReceipt();

        // Make 5 failed in-session lookups using a wrong code that resolves to this
        // voucher's rate-limit key. We simulate this by targeting the voucher directly:
        // after 5 failed lookups against the actual voucher, the 6th should see it voided.
        // Strategy: we do 5 lookups with the correct code but make the voucher appear
        // not-redeemable by temporarily marking it voided, then restoring, then simulating.
        //
        // Cleaner approach: use the rateLimiter to pre-set 4 failed attempts, then do 1 live
        // lookup against a non-PendingSeal receipt (which counts as a failed in-session lookup).
        //
        // Actually the simplest: look up in-session with valid code against a valid open receipt;
        // the voucher is active so it succeeds. Instead, we need 5 FAILED lookups.
        // A failed in-session lookup happens when the voucher's status is not active.
        // So: make the voucher active, do 4 lookup-against-wrong-code, then 1 against our voucher.
        //
        // Simplest: pre-seed the per-voucher counter to 4, then trigger 1 more failure.
        $rateLimiter = app(RateLimiter::class);
        $voucherKey = "voucher_lookup:voucher_failed:{$voucher->id}";

        // Pre-seed 4 failed attempts
        for ($i = 0; $i < 4; $i++) {
            $rateLimiter->hit($voucherKey, 86400);
        }

        // Temporarily mark the voucher as expired so the in-session lookup records a failure
        $voucher->update(['status' => VoucherStatus::Expired, 'expires_at' => Carbon::now()->subDay()]);

        // 5th failed attempt: in-session lookup against an expired/invalid voucher
        $result = $this->makeService()->lookupForPayment(
            $voucher->code,
            $openReceipt,
            $this->terminal,
            $this->cashier,
            '127.0.0.1',
        );

        // Result is generic invalid (voucher was expired anyway)
        $this->assertInstanceOf(GenericLookupResult::class, $result);
        $this->assertFalse($result->exists);

        // The voucher should now be Voided (auto-void triggered)
        $fresh = $voucher->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(VoucherStatus::Voided, $fresh->status);
        $this->assertEquals(0, bccomp($fresh->current_balance, '0', 5));

        // Voucher ledger must have a Voided event
        $voidedEntry = VoucherLedger::where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Voided->value)
            ->first();
        $this->assertNotNull($voidedEntry);

        // VoucherFraudAlert event must have been dispatched
        Event::assertDispatched(VoucherFraudAlert::class, function (VoucherFraudAlert $event) use ($voucher): bool {
            return $event->voucherId === $voucher->id
                && $event->attemptsCount >= VoucherLookupRateLimiter::PER_VOUCHER_FAILED_24H;
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): VoucherLookupService
    {
        return app(VoucherLookupService::class);
    }

    /**
     * Issue a Bearer voucher via the real VoucherIssuanceService.
     *
     * @param  numeric-string  $amount
     */
    private function issueVoucher(string $amount, ?Carbon $expiresAt = null): Voucher
    {
        Event::fake(); // suppress issuance events

        $request = new VoucherIssuanceRequest(
            amount: $amount,
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: null,
            issuedAtTerminalId: $this->terminal->id,
            expiresAt: $expiresAt,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
        );

        return app(VoucherIssuanceService::class)->issueFromRefund($request);
    }

    /**
     * Issue a CustomerBound voucher.
     *
     * @param  numeric-string  $amount
     */
    private function issueCustomerBoundVoucher(string $amount, string $partnerId): Voucher
    {
        Event::fake();

        $request = new VoucherIssuanceRequest(
            amount: $amount,
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: $partnerId,
            issuedAtTerminalId: $this->terminal->id,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::CustomerBound,
        );

        return app(VoucherIssuanceService::class)->issueFromRefund($request);
    }

    /**
     * Create a PendingSeal (open) receipt for in-session lookups.
     */
    private function makeOpenReceipt(?string $partnerId = null): Receipt
    {
        return Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'partner_id' => $partnerId,
            'receipt_type' => ReceiptType::Sale,
        ]);
    }

    /**
     * Create a Fiscalized receipt (not open — falls back to generic lookup).
     */
    private function makeFiscalizedReceipt(): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'receipt_type' => ReceiptType::Sale,
        ]);
    }

    private function createTerminal(string $code): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => $code,
            'name' => "Terminal {$code}",
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'fiscal_schema_version' => 2,
        ]);
    }
}
