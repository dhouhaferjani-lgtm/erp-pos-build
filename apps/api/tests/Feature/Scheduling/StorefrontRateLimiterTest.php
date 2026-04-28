<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Confirms the two named rate limiters registered for the scheduling
 * storefront exist and behave within their expected ceilings:
 *
 * - storefront-booking-ip: 10 requests per minute per (IP|company_id)
 * - storefront-booking-company-phone: 5 bookings per day per (company_id|sha256(phone))
 *
 * The limiters are consumed by the Task-15 storefront controllers; Task 1
 * only asserts the keys exist and return the correct ceilings.
 */
final class StorefrontRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['throttle:storefront-booking-ip'])
            ->post('/__test/scheduling/booking-ip/{company_id}', static fn () => response()->json(['ok' => true]));

        Route::middleware(['throttle:storefront-booking-company-phone'])
            ->post('/__test/scheduling/booking-phone/{company_id}', static fn () => response()->json(['ok' => true]));
    }

    public function test_ip_limiter_returns_429_on_11th_request_in_a_minute(): void
    {
        $companyId = '00000000-0000-0000-0000-000000000001';

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/__test/scheduling/booking-ip/{$companyId}")->assertOk();
        }

        $this->postJson("/__test/scheduling/booking-ip/{$companyId}")->assertStatus(429);
    }

    public function test_ip_limiter_is_keyed_by_company_so_different_companies_have_separate_buckets(): void
    {
        $companyA = '00000000-0000-0000-0000-00000000000a';
        $companyB = '00000000-0000-0000-0000-00000000000b';

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/__test/scheduling/booking-ip/{$companyA}")->assertOk();
        }
        // Company A is saturated
        $this->postJson("/__test/scheduling/booking-ip/{$companyA}")->assertStatus(429);
        // Company B still has its own quota
        $this->postJson("/__test/scheduling/booking-ip/{$companyB}")->assertOk();
    }

    public function test_company_phone_limiter_rejects_6th_daily_booking_for_same_phone(): void
    {
        $companyId = '00000000-0000-0000-0000-00000000000c';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/__test/scheduling/booking-phone/{$companyId}", ['phone' => '+21611223344'])->assertOk();
        }

        $this->postJson("/__test/scheduling/booking-phone/{$companyId}", ['phone' => '+21611223344'])->assertStatus(429);
    }

    public function test_company_phone_limiter_is_keyed_by_phone_so_different_phones_have_separate_buckets(): void
    {
        $companyId = '00000000-0000-0000-0000-00000000000d';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/__test/scheduling/booking-phone/{$companyId}", ['phone' => '+21600000001'])->assertOk();
        }
        $this->postJson("/__test/scheduling/booking-phone/{$companyId}", ['phone' => '+21600000001'])->assertStatus(429);
        $this->postJson("/__test/scheduling/booking-phone/{$companyId}", ['phone' => '+21600000002'])->assertOk();
    }

    public function test_limiter_keys_are_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('storefront-booking-ip'));
        $this->assertNotNull(RateLimiter::limiter('storefront-booking-company-phone'));
    }
}
