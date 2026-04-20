<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Captcha;

/**
 * Public verification surface for CAPTCHA tokens submitted from the storefront.
 *
 * The adapter (RecaptchaVerifier) wraps google/recaptcha. A fake binding is
 * substituted in the testing environment so feature tests do not depend on a
 * live Google endpoint.
 */
interface CaptchaVerifierInterface
{
    /**
     * Verify a CAPTCHA token for the given action.
     *
     * Returns true when the token is valid AND its score meets the configured
     * minimum threshold. Invalid, expired, or low-score tokens return false.
     */
    public function verify(string $token, ?string $remoteIp, string $action): bool;
}
