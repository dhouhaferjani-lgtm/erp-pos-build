<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * P1-3 (Codex 2026-05-25): removing check-email also removed the only per-IP
 * email-enumeration throttle. The `login` limiter is keyed by email, so an
 * attacker rotating distinct emails from one IP gets a fresh 5-attempt bucket
 * per email and can enumerate the org-picker surface unbounded.
 *
 * These tests pin a per-IP cap on top of the per-email bucket: rotating many
 * distinct emails from one IP must eventually 429.
 */
class LoginEnumerationThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    public function test_rotating_distinct_emails_from_one_ip_hits_429_after_the_per_ip_budget(): void
    {
        $sawTooMany = false;

        // Each request uses a UNIQUE email so the per-EMAIL bucket never fills;
        // only a per-IP cap can stop this. 60 attempts comfortably exceeds any
        // reasonable per-IP minute budget.
        for ($i = 0; $i < 60; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => "enum-{$i}@example.com",
                'password' => 'whatever-Wrong9!',
            ]);

            if ($response->getStatusCode() === 429) {
                $sawTooMany = true;
                break;
            }
        }

        $this->assertTrue(
            $sawTooMany,
            'Rotating distinct emails from one IP must be capped by a per-IP limiter (429).'
        );
    }

    public function test_a_single_legitimate_login_attempt_is_not_throttled(): void
    {
        // One attempt with one email must never be throttled — the per-IP cap is
        // generous enough not to block normal use.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'legit@example.com',
            'password' => 'whatever-Wrong9!',
        ]);

        $this->assertNotSame(429, $response->getStatusCode());
    }
}
