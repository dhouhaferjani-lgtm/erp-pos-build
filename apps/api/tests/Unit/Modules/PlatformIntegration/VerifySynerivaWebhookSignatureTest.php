<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Infrastructure\Middleware\VerifySynerivaWebhookSignature;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VerifySynerivaWebhookSignatureTest extends TestCase
{
    private VerifySynerivaWebhookSignature $middleware;

    private string $secret = 'test-webhook-secret-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.webhook_secret' => $this->secret]);
        $this->middleware = new VerifySynerivaWebhookSignature;
    }

    public function test_valid_signature_passes(): void
    {
        $body = '{"event":"enrichment.completed","tracking_id":"trk-123"}';
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        $request = Request::create('/webhook', 'POST', content: $body);
        $request->headers->set('X-Syneriva-Signature', $signature);
        $request->headers->set('X-Syneriva-Timestamp', $timestamp);

        $response = $this->middleware->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_invalid_signature_rejects(): void
    {
        $body = '{"event":"enrichment.completed"}';
        $timestamp = (string) time();

        $request = Request::create('/webhook', 'POST', content: $body);
        $request->headers->set('X-Syneriva-Signature', 'sha256=invalidsignaturevalue');
        $request->headers->set('X-Syneriva-Timestamp', $timestamp);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid webhook signature.');

        $this->middleware->handle($request, fn () => response('ok'));
    }

    public function test_missing_signature_returns_403(): void
    {
        $body = '{"event":"enrichment.completed"}';
        $timestamp = (string) time();

        $request = Request::create('/webhook', 'POST', content: $body);
        $request->headers->set('X-Syneriva-Timestamp', $timestamp);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Missing webhook signature headers.');

        $this->middleware->handle($request, fn () => response('ok'));
    }

    public function test_missing_timestamp_returns_403(): void
    {
        $body = '{"event":"enrichment.completed"}';

        $request = Request::create('/webhook', 'POST', content: $body);
        $request->headers->set('X-Syneriva-Signature', 'sha256=somesignature');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Missing webhook signature headers.');

        $this->middleware->handle($request, fn () => response('ok'));
    }

    public function test_expired_timestamp_returns_403(): void
    {
        $body = '{"event":"enrichment.completed"}';
        $timestamp = (string) (time() - 600); // 10 minutes ago
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        $request = Request::create('/webhook', 'POST', content: $body);
        $request->headers->set('X-Syneriva-Signature', $signature);
        $request->headers->set('X-Syneriva-Timestamp', $timestamp);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Webhook timestamp expired.');

        $this->middleware->handle($request, fn () => response('ok'));
    }

    public function test_tampered_body_returns_403(): void
    {
        $originalBody = '{"event":"enrichment.completed","tracking_id":"trk-123"}';
        $tamperedBody = '{"event":"enrichment.completed","tracking_id":"trk-hacked"}';
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$originalBody, $this->secret);

        $request = Request::create('/webhook', 'POST', content: $tamperedBody);
        $request->headers->set('X-Syneriva-Signature', $signature);
        $request->headers->set('X-Syneriva-Timestamp', $timestamp);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid webhook signature.');

        $this->middleware->handle($request, fn () => response('ok'));
    }
}
