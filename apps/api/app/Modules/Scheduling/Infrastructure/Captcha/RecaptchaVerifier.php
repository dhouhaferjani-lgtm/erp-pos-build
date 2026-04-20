<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Captcha;

use ReCaptcha\ReCaptcha;

/**
 * Production CAPTCHA verifier backed by google/recaptcha (v3).
 *
 * Returns false whenever the token is missing, invalid, or scores below the
 * configured threshold. Never throws on verification-failure — verification
 * failures are a normal control-flow signal (mirrors the library's own model).
 */
final readonly class RecaptchaVerifier implements CaptchaVerifierInterface
{
    public function __construct(
        private string $secretKey,
        private float $minScore,
        private ?string $expectedHostname = null,
    ) {}

    public function verify(string $token, ?string $remoteIp, string $action): bool
    {
        if ($token === '') {
            return false;
        }
        if ($this->secretKey === '') {
            return false;
        }

        $recaptcha = new ReCaptcha($this->secretKey);
        $recaptcha->setExpectedAction($action)
            ->setScoreThreshold($this->minScore);

        if ($this->expectedHostname !== null && $this->expectedHostname !== '') {
            $recaptcha->setExpectedHostname($this->expectedHostname);
        }

        $response = $recaptcha->verify($token, $remoteIp);

        return $response->isSuccess();
    }
}
