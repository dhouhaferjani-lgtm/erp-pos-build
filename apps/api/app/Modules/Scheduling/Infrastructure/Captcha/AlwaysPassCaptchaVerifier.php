<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Captcha;

/**
 * Testing-only CAPTCHA verifier that accepts the sentinel token `"test-ok"`
 * and rejects everything else. Bound in place of RecaptchaVerifier when
 * APP_ENV=testing so feature tests do not depend on a live Google endpoint.
 */
final class AlwaysPassCaptchaVerifier implements CaptchaVerifierInterface
{
    public const VALID_TOKEN = 'test-ok';

    public function verify(string $token, ?string $remoteIp, string $action): bool
    {
        return $token === self::VALID_TOKEN;
    }
}
