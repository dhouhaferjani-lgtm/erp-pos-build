<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Custom validation assertions for API responses.
 *
 * The API returns validation errors in a custom format:
 * {
 *   "error": {
 *     "code": "VALIDATION_ERROR",
 *     "message": "...",
 *     "errors": {
 *       "field": ["error message"]
 *     }
 *   }
 * }
 *
 * This trait provides assertions that work with this format.
 */
trait AssertsApiValidation
{
    /**
     * Assert that the response has validation errors for the given keys.
     *
     * @param  array<int, string>  $keys  The field names that should have validation errors
     */
    protected function assertApiValidationErrors(TestResponse $response, array $keys): TestResponse
    {
        $response->assertUnprocessable();

        $json = $response->json();

        Assert::assertArrayHasKey(
            'error',
            $json,
            'Response does not contain "error" key. Response: '.json_encode($json)
        );

        Assert::assertArrayHasKey(
            'errors',
            $json['error'],
            'Response error does not contain "errors" key. Response: '.json_encode($json)
        );

        foreach ($keys as $key) {
            Assert::assertArrayHasKey(
                $key,
                $json['error']['errors'],
                "Failed to find validation error for key: '{$key}'. Available keys: ".implode(', ', array_keys($json['error']['errors'] ?? []))
            );
        }

        return $response;
    }

    /**
     * Assert that the response has no validation errors.
     */
    protected function assertNoApiValidationErrors(TestResponse $response): TestResponse
    {
        $json = $response->json();

        if (isset($json['error']['errors'])) {
            Assert::fail(
                'Response contains validation errors: '.json_encode($json['error']['errors'])
            );
        }

        return $response;
    }

    /**
     * Assert that the response has an error with a specific code.
     */
    protected function assertApiErrorCode(TestResponse $response, string $code): TestResponse
    {
        $json = $response->json();

        Assert::assertArrayHasKey('error', $json, 'Response does not contain "error" key');
        Assert::assertArrayHasKey('code', $json['error'], 'Response error does not contain "code" key');
        Assert::assertEquals($code, $json['error']['code'], "Expected error code '{$code}', got '{$json['error']['code']}'");

        return $response;
    }

    /**
     * Assert validation error for response (shorthand for common pattern).
     * Works like Laravel's assertJsonValidationErrors but for our custom format.
     *
     * @param  array<int, string>  $keys
     */
    protected function assertValidationErrors(TestResponse $response, array $keys): TestResponse
    {
        return $this->assertApiValidationErrors($response, $keys);
    }

    /**
     * Project-specific drop-in for Laravel's TestResponse::assertJsonValidationErrors.
     *
     * The application wraps validation failures inside `{ error: { code, errors } }`,
     * so the stock helper (which defaults to the top-level `errors` key) silently
     * passes on the wrong envelope. This helper reads from `error.errors` by
     * default and is the recommended call site for new tests.
     *
     * Naming intentionally shadows the stock TestResponse method so that imports
     * of this trait at the TestCase level provide a safe-by-default helper:
     *
     *   $this->assertJsonValidationErrors($response, ['email']);
     *
     * If a caller really needs a different response key (e.g. for a non-API
     * endpoint that emits the legacy shape), use the stock TestResponse helper
     * directly: `$response->assertJsonValidationErrors([...], 'errors')`.
     *
     * @param  array<int|string, string|array<int, string>>  $errors
     */
    protected function assertJsonValidationErrors(TestResponse $response, array $errors): TestResponse
    {
        return $response->assertJsonValidationErrors($errors, 'error.errors');
    }
}
