<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class VerifySynerivaWebhookSignature
{
    private const MAX_TIMESTAMP_AGE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Syneriva-Signature');
        $timestamp = $request->header('X-Syneriva-Timestamp');
        $secret = config('services.platform.webhook_secret');

        if ($signature === null || $timestamp === null || $secret === null) {
            throw new HttpException(403, 'Missing webhook signature headers.');
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            throw new HttpException(403, 'Webhook timestamp expired.');
        }

        $expectedSignature = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            (string) $secret,
        );

        if (! hash_equals($expectedSignature, $signature)) {
            throw new HttpException(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
