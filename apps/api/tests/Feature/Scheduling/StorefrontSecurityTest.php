<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Infrastructure\Captcha\AlwaysPassCaptchaVerifier;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 15 — storefront security tests (rate limits + availability leakage).
 *
 * The IP + phone limiters are declared in AppServiceProvider and consumed
 * via throttle:storefront-booking-ip / throttle:storefront-booking-company-phone.
 * These tests confirm the full HTTP path (including CAPTCHA passthrough)
 * returns 429 once the ceiling is exceeded, and that the availability
 * endpoint never leaks bay_id / technician_id.
 */
final class StorefrontSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_ip_rate_limit_returns_429_after_10_requests_per_minute(): void
    {
        $payload = $this->validPayload();
        $headers = ['X-Captcha-Token' => AlwaysPassCaptchaVerifier::VALID_TOKEN];

        // First 10 booking attempts should succeed at the limiter level (201 or 422
        // depending on the payload state; either way it's not 429).
        for ($i = 0; $i < 10; $i++) {
            // Use a unique phone on each iteration so the *phone* daily cap
            // never fires — we're isolating the IP limiter here.
            $payload['contact_phone'] = '+21611111'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $payload['phone'] = $payload['contact_phone'];
            $resp = $this->withHeaders($headers)->postJson($this->bookingUrl(), $payload);
            $this->assertNotSame(429, $resp->status(), "Request {$i} unexpectedly 429'd.");
        }

        // 11th request in the same minute → IP limit exhausted.
        $payload['contact_phone'] = '+21611111999';
        $payload['phone'] = $payload['contact_phone'];
        $response = $this->withHeaders($headers)->postJson($this->bookingUrl(), $payload);
        $response->assertStatus(429);
    }

    public function test_phone_daily_cap_returns_429_after_5_bookings_same_phone(): void
    {
        $phone = '+21622223333';
        $headers = ['X-Captcha-Token' => AlwaysPassCaptchaVerifier::VALID_TOKEN];

        for ($i = 0; $i < 5; $i++) {
            $payload = $this->validPayload();
            $payload['contact_phone'] = $phone;
            $payload['phone'] = $phone;
            // Stagger scheduled_start so bay/time overlap isn't the failure mode.
            $payload['scheduled_start'] = Carbon::now()->addDays($i + 1)->setTime(9, 0)->toDateTimeImmutable()
                ->format(\DateTimeInterface::ATOM);

            $resp = $this->withHeaders($headers)->postJson($this->bookingUrl(), $payload);
            $this->assertNotSame(429, $resp->status(), "Request {$i} unexpectedly 429'd.");
        }

        $payload = $this->validPayload();
        $payload['contact_phone'] = $phone;
        $payload['phone'] = $phone;
        $payload['scheduled_start'] = Carbon::now()->addDays(10)->setTime(9, 0)->toDateTimeImmutable()
            ->format(\DateTimeInterface::ATOM);
        $response = $this->withHeaders($headers)->postJson($this->bookingUrl(), $payload);
        $response->assertStatus(429);
    }

    public function test_availability_endpoint_does_not_leak_bay_or_technician(): void
    {
        // Seed a bay + appointment so the free-slot computation has material
        // context — the response MUST still strip internal identifiers.
        $bay = Bay::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $start = Carbon::now()->addDay()->setTime(10, 0)->toDateTimeImmutable();
        $end = $start->modify('+1 hour');
        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'bay_id' => $bay->id,
            'primary_technician_profile_id' => null,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
        ]);

        $from = Carbon::now()->addDay()->format('Y-m-d');
        $to = Carbon::now()->addDay()->format('Y-m-d');
        $url = '/api/v1/storefront/'.$this->company->id.'/availability'.
            '?duration=60&from='.$from.'&to='.$to;

        $response = $this->getJson($url);
        $response->assertOk();

        $slots = $response->json('data');
        $this->assertIsArray($slots);

        foreach ($slots as $slot) {
            $this->assertIsArray($slot);
            $this->assertArrayHasKey('start', $slot);
            $this->assertArrayHasKey('end', $slot);
            $this->assertArrayHasKey('duration_minutes', $slot);
            $this->assertArrayNotHasKey('bay_id', $slot);
            $this->assertArrayNotHasKey('primary_technician_profile_id', $slot);
            $this->assertArrayNotHasKey('resource_id', $slot);
        }

        $raw = $response->getContent();
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('bay_id', $raw);
        $this->assertStringNotContainsString('primary_technician_profile_id', $raw);
        $this->assertStringNotContainsString($bay->id, $raw);
    }

    public function test_availability_endpoint_rejects_invalid_range(): void
    {
        $url = '/api/v1/storefront/'.$this->company->id.'/availability'.
            '?duration=60&from=2026-05-10&to=2026-05-01';

        $response = $this->getJson($url);
        $response->assertStatus(422);
    }

    public function test_availability_endpoint_rejects_excessive_range(): void
    {
        $url = '/api/v1/storefront/'.$this->company->id.'/availability'.
            '?duration=60&from=2026-05-01&to=2027-01-01';

        $response = $this->getJson($url);
        $response->assertStatus(422);
    }

    private function bookingUrl(): string
    {
        return '/api/v1/storefront/'.$this->company->id.'/appointments';
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        $start = Carbon::now()->addDays(5)->setTime(9, 0)->toDateTimeImmutable();

        return [
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane.doe@example.com',
            'contact_phone' => '+21699999999',
            'phone' => '+21699999999',
            'appointment_type' => 'standard_repair',
            'wait_type' => 'drop_off',
            'scheduled_start' => $start->format(\DateTimeInterface::ATOM),
            'duration_minutes' => 60,
            'vehicle_info' => [
                'plate' => 'TN-1234-5678',
                'make_model' => 'Peugeot 208 — 2021',
            ],
            'service_ids' => [
                [
                    'service_ref_type' => 'service',
                    'service_ref_id' => (string) Str::uuid(),
                    'display_name' => 'Oil change',
                    'estimated_duration_minutes' => 60,
                    'estimated_price' => '49.900',
                    'display_order' => 0,
                ],
            ],
        ];
    }
}
