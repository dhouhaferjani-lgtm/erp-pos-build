<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Traits;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Unit tests for the AssertsApiValidation trait.
 *
 * Production-critical guarantee: the project helper `assertJsonValidationErrors`
 * MUST read from `error.errors`, NOT from the top-level `errors` key. Laravel's
 * stock helper defaults to `errors`, which silently passes (or no-ops) against
 * the application's `{ error: { code, errors } }` validation envelope. The
 * project helper closes that hole.
 *
 * @internal
 */
final class AssertsApiValidationTest extends TestCase
{
    use AssertsApiValidation;

    public function test_helper_finds_errors_at_error_dot_errors(): void
    {
        $response = $this->makeJsonResponse(422, [
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'The given data was invalid.',
                'errors' => [
                    'name' => ['The name field is required.'],
                    'email' => ['The email must be a valid email address.'],
                ],
            ],
        ]);

        // Should not throw — both keys are present at error.errors.
        $this->assertJsonValidationErrors($response, ['name', 'email']);
    }

    public function test_helper_fails_when_errors_envelope_is_absent(): void
    {
        // Legacy / stock Laravel envelope: errors at top level. The project
        // helper must NOT silently pass on this shape — it must fail loudly,
        // since the application never actually emits this shape.
        $response = $this->makeJsonResponse(422, [
            'errors' => [
                'name' => ['The name field is required.'],
            ],
        ]);

        $this->expectException(AssertionFailedError::class);

        $this->assertJsonValidationErrors($response, ['name']);
    }

    public function test_helper_fails_when_specific_field_missing_from_envelope(): void
    {
        $response = $this->makeJsonResponse(422, [
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'name' => ['The name field is required.'],
                ],
            ],
        ]);

        $this->expectException(AssertionFailedError::class);

        // `email` is not present → must fail.
        $this->assertJsonValidationErrors($response, ['email']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeJsonResponse(int $status, array $payload): TestResponse
    {
        $base = new Response(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json'],
        );

        return TestResponse::fromBaseResponse($base);
    }
}
