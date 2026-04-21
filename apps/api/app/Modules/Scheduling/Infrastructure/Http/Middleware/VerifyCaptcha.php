<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Http\Middleware;

use App\Modules\Scheduling\Infrastructure\Captcha\CaptchaVerifierInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the `X-Captcha-Token` header against the bound CaptchaVerifier.
 *
 * Returns 403 on missing / invalid tokens so storefront clients cannot
 * bypass CAPTCHA by omitting the header. The middleware accepts an optional
 * $action parameter (e.g. `storefront_booking`) so different endpoints can
 * assert distinct actions.
 */
final class VerifyCaptcha
{
    public function __construct(
        private readonly CaptchaVerifierInterface $verifier,
    ) {}

    public function handle(Request $request, Closure $next, string $action = 'storefront_booking'): Response
    {
        $token = (string) $request->header('X-Captcha-Token', '');

        if ($token === '') {
            return new JsonResponse(
                [
                    'message' => 'CAPTCHA token missing.',
                    'error_code' => 'captcha_missing',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (! $this->verifier->verify($token, $request->ip(), $action)) {
            return new JsonResponse(
                [
                    'message' => 'CAPTCHA verification failed.',
                    'error_code' => 'captcha_invalid',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
