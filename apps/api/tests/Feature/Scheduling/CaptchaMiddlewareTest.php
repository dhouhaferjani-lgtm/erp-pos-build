<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Infrastructure\Captcha\AlwaysPassCaptchaVerifier;
use App\Modules\Scheduling\Infrastructure\Captcha\CaptchaVerifierInterface;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies the scheduling.captcha middleware alias rejects missing/invalid
 * tokens with 403 and lets the request through when the bound verifier
 * accepts the token.
 *
 * The testing environment binds AlwaysPassCaptchaVerifier (accepts only the
 * sentinel token "test-ok"), so tests do not depend on a live Google endpoint.
 */
final class CaptchaMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('scheduling.captcha')->post('/__test/scheduling/captcha', static fn () => response()->json(['ok' => true]));
    }

    public function test_missing_captcha_token_is_rejected_with_403(): void
    {
        $response = $this->postJson('/__test/scheduling/captcha');

        $response->assertForbidden();
        $response->assertJson(['error_code' => 'captcha_missing']);
    }

    public function test_invalid_captcha_token_is_rejected_with_403(): void
    {
        $response = $this->withHeaders(['X-Captcha-Token' => 'not-the-valid-token'])
            ->postJson('/__test/scheduling/captcha');

        $response->assertForbidden();
        $response->assertJson(['error_code' => 'captcha_invalid']);
    }

    public function test_valid_captcha_token_passes_through(): void
    {
        $response = $this->withHeaders(['X-Captcha-Token' => AlwaysPassCaptchaVerifier::VALID_TOKEN])
            ->postJson('/__test/scheduling/captcha');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_container_binds_always_pass_verifier_in_testing_env(): void
    {
        $verifier = $this->app->make(CaptchaVerifierInterface::class);

        $this->assertInstanceOf(AlwaysPassCaptchaVerifier::class, $verifier);
    }
}
