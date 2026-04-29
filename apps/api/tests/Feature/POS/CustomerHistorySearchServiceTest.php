<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\CustomerHistorySearchService;
use App\Modules\POS\Domain\Events\BroadCustomerSearchAlert;
use App\Modules\POS\Domain\Exceptions\InsufficientSearchSpecificityException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Infrastructure\RateLimit\CustomerHistorySearchRateLimiter;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for CustomerHistorySearchService (Task 25).
 *
 * Covers:
 *   - Partial name input rejected with InsufficientSearchSpecificityException + audit row
 *   - Full email accepted
 *   - E.164 phone accepted
 *   - Partner UUID from QR scan accepted
 *   - Phase 1 single-terminal scope: only terminal A receipts returned at terminal A
 *   - Window days respected: receipts older than window are excluded
 *   - Audit row written for every search (allowed + rejected)
 *   - Rate limit: per-cashier daily cap
 *   - BroadCustomerSearchAlert fired when same partner searched repeatedly
 */
final class CustomerHistorySearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerHistorySearchService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private ReservationSettings $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CustomerHistorySearchService(
            rateLimiter: new CustomerHistorySearchRateLimiter(app(RateLimiter::class)),
            events: app(Dispatcher::class),
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

        $this->policy = new ReservationSettings(
            customerHistorySearchMaxPerCashierPerDay: 15,
            customerHistoryWindowDays: 14,
            customerHistorySearchAlertThresholds: [
                'rejected_specificity_per_hour' => 3,
                'same_partner_per_day' => 8,
                'cross_company_immediate' => true,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Minimum specificity — rejection
    // -------------------------------------------------------------------------

    public function test_rejects_partial_name_input(): void
    {
        $this->expectException(InsufficientSearchSpecificityException::class);

        $this->service->search('John', $this->cashier, $this->terminal, $this->policy);
    }

    public function test_rejects_partial_name_and_writes_audit_row(): void
    {
        try {
            $this->service->search('John', $this->cashier, $this->terminal, $this->policy);
        } catch (InsufficientSearchSpecificityException) {
            // Expected.
        }

        $this->assertDatabaseHas('customer_history_searches', [
            'cashier_id' => $this->cashier->id,
            'terminal_id' => $this->terminal->id,
            'was_rejected' => true,
            'rejection_reason' => 'input_not_specific',
        ]);
    }

    public function test_rejects_short_string(): void
    {
        $this->expectException(InsufficientSearchSpecificityException::class);

        $this->service->search('abc', $this->cashier, $this->terminal, $this->policy);
    }

    // -------------------------------------------------------------------------
    // Minimum specificity — acceptance
    // -------------------------------------------------------------------------

    public function test_accepts_full_email(): void
    {
        $partner = $this->createPartner(['email' => 'customer@example.com', 'phone' => null]);
        $this->createReceipt($partner);

        $results = $this->service->search('customer@example.com', $this->cashier, $this->terminal, $this->policy);

        $this->assertCount(1, $results);
    }

    public function test_accepts_e164_phone(): void
    {
        $partner = $this->createPartner(['phone' => '+33612345678', 'email' => null]);
        $this->createReceipt($partner);

        $results = $this->service->search('+33612345678', $this->cashier, $this->terminal, $this->policy);

        $this->assertCount(1, $results);
    }

    public function test_accepts_partner_uuid_from_qr_scan(): void
    {
        $partner = $this->createPartner([]);
        $this->createReceipt($partner);

        $results = $this->service->search($partner->id, $this->cashier, $this->terminal, $this->policy);

        $this->assertCount(1, $results);
    }

    // -------------------------------------------------------------------------
    // Phase 1 single-terminal scope
    // -------------------------------------------------------------------------

    public function test_scopes_results_to_this_terminal_only(): void
    {
        $partner = $this->createPartner(['email' => 'bounded@example.com']);

        // Terminal A: 2 receipts.
        $this->createReceipt($partner);
        $this->createReceipt($partner);

        // Terminal B: 3 receipts for the same partner.
        $terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        Receipt::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminalB->id,
            'cashier_id' => $this->cashier->id,
            'partner_id' => $partner->id,
        ]);

        // Searching at terminal A should return only terminal A receipts.
        $results = $this->service->search('bounded@example.com', $this->cashier, $this->terminal, $this->policy);

        $this->assertCount(2, $results);

        foreach ($results as $receipt) {
            $this->assertSame($this->terminal->id, $receipt->terminal_id,
                'Every returned receipt must belong to the queried terminal (Phase 1 single-terminal scope).'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Window days
    // -------------------------------------------------------------------------

    public function test_respects_window_days(): void
    {
        $policy = new ReservationSettings(customerHistoryWindowDays: 7);
        $partner = $this->createPartner(['email' => 'windowed@example.com']);

        // Recent receipt (within window).
        $this->createReceipt($partner, ['posted_at' => Carbon::now()->subDays(3)]);

        // Old receipt (outside window).
        $this->createReceipt($partner, ['posted_at' => Carbon::now()->subDays(10)]);

        $results = $this->service->search('windowed@example.com', $this->cashier, $this->terminal, $policy);

        $this->assertCount(1, $results);
    }

    // -------------------------------------------------------------------------
    // Audit logging
    // -------------------------------------------------------------------------

    public function test_writes_audit_row_on_every_successful_search(): void
    {
        $partner = $this->createPartner(['email' => 'audited@example.com']);
        $this->createReceipt($partner);

        $this->service->search('audited@example.com', $this->cashier, $this->terminal, $this->policy);

        $this->assertDatabaseHas('customer_history_searches', [
            'cashier_id' => $this->cashier->id,
            'terminal_id' => $this->terminal->id,
            'partner_id' => $partner->id,
            'was_rejected' => false,
        ]);
    }

    public function test_writes_audit_row_on_rejected_search(): void
    {
        try {
            $this->service->search('partial-name', $this->cashier, $this->terminal, $this->policy);
        } catch (InsufficientSearchSpecificityException) {
            // Expected.
        }

        $this->assertDatabaseCount('customer_history_searches', 1);
    }

    public function test_audit_row_stores_hash_not_raw_input(): void
    {
        $rawInput = 'customer@example.com';
        $partner = $this->createPartner(['email' => $rawInput]);
        $this->createReceipt($partner);

        $this->service->search($rawInput, $this->cashier, $this->terminal, $this->policy);

        $row = DB::table('customer_history_searches')
            ->where('cashier_id', $this->cashier->id)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertNotSame($rawInput, $row->search_terms_hash);
        $this->assertSame(hash('sha256', $rawInput), $row->search_terms_hash);
    }

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    public function test_rate_limit_per_cashier_per_day(): void
    {
        $policy = new ReservationSettings(customerHistorySearchMaxPerCashierPerDay: 3);
        $partner = $this->createPartner(['email' => 'ratelimited@example.com']);

        // Perform 3 successful searches (up to the limit).
        for ($i = 0; $i < 3; $i++) {
            $this->service->search('ratelimited@example.com', $this->cashier, $this->terminal, $policy);
        }

        // The 4th search should return an empty collection (not an exception).
        $results = $this->service->search('ratelimited@example.com', $this->cashier, $this->terminal, $policy);

        $this->assertCount(0, $results);
    }

    public function test_rate_limit_writes_audit_row_with_rate_limit_reason(): void
    {
        $policy = new ReservationSettings(customerHistorySearchMaxPerCashierPerDay: 1);
        $partner = $this->createPartner(['email' => 'throttled@example.com']);

        // First search succeeds.
        $this->service->search('throttled@example.com', $this->cashier, $this->terminal, $policy);

        // Second search is rate-limited.
        $this->service->search('throttled@example.com', $this->cashier, $this->terminal, $policy);

        $this->assertDatabaseHas('customer_history_searches', [
            'cashier_id' => $this->cashier->id,
            'was_rejected' => true,
            'rejection_reason' => 'rate_limit_exceeded',
        ]);
    }

    // -------------------------------------------------------------------------
    // BroadCustomerSearchAlert event
    // -------------------------------------------------------------------------

    public function test_emits_broad_customer_search_alert_when_same_partner_searched_repeatedly(): void
    {
        // Event::fake() replaces the dispatcher in the container. Rebuild the service
        // AFTER faking so it holds the fake dispatcher reference.
        Event::fake([BroadCustomerSearchAlert::class]);

        $fakeService = new CustomerHistorySearchService(
            rateLimiter: new CustomerHistorySearchRateLimiter(app(RateLimiter::class)),
            events: app(Dispatcher::class), // now resolves to the EventFake
        );

        $policy = new ReservationSettings(
            customerHistorySearchMaxPerCashierPerDay: 50,
            customerHistorySearchAlertThresholds: [
                'rejected_specificity_per_hour' => 3,
                'same_partner_per_day' => 3, // Lower threshold for test speed
                'cross_company_immediate' => false,
            ],
        );

        $partner = $this->createPartner(['email' => 'alert@example.com']);

        // Perform searches up to and beyond the threshold.
        for ($i = 0; $i < 4; $i++) {
            $fakeService->search('alert@example.com', $this->cashier, $this->terminal, $policy);
        }

        Event::assertDispatched(BroadCustomerSearchAlert::class, function (BroadCustomerSearchAlert $event): bool {
            return $event->alertReason === 'same_partner_repeated'
                && $event->cashierId === $this->cashier->id;
        });
    }

    public function test_no_alert_emitted_below_threshold(): void
    {
        Event::fake([BroadCustomerSearchAlert::class]);

        $fakeService = new CustomerHistorySearchService(
            rateLimiter: new CustomerHistorySearchRateLimiter(app(RateLimiter::class)),
            events: app(Dispatcher::class),
        );

        $policy = new ReservationSettings(
            customerHistorySearchMaxPerCashierPerDay: 50,
            customerHistorySearchAlertThresholds: [
                'rejected_specificity_per_hour' => 3,
                'same_partner_per_day' => 8,
                'cross_company_immediate' => false,
            ],
        );

        $partner = $this->createPartner(['email' => 'noalert@example.com']);

        // Only 2 searches — below the threshold of 8.
        for ($i = 0; $i < 2; $i++) {
            $fakeService->search('noalert@example.com', $this->cashier, $this->terminal, $policy);
        }

        Event::assertNotDispatched(BroadCustomerSearchAlert::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPartner(array $overrides = []): Partner
    {
        return Partner::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(Partner $partner, array $overrides = []): Receipt
    {
        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'partner_id' => $partner->id,
        ], $overrides));
    }
}
