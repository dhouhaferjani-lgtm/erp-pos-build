<?php

declare(strict_types=1);

namespace Tests\Unit\SupportAccess;

use App\Modules\SupportAccess\Domain\Services\SessionChainHasher;
use App\Modules\SupportAccess\Domain\Services\SessionChainVerifier;
use PHPUnit\Framework\TestCase;

final class SessionChainVerifierTest extends TestCase
{
    private const FIRST_HASH = 'b62a4928f80c09ef01696f93f1825e2d957a2e6d919dd8e5e2de6de8b27e5e6a';

    private const SECOND_HASH = '34beeaca6f92f03fbff36d9bcb96a92c6a79814f47b6a93168dd445220cd6ac0';

    public function test_canonical_hashes_match_independently_derived_vectors(): void
    {
        $hasher = new SessionChainHasher;
        $first = $this->firstEvent();
        $second = $this->secondEvent();

        self::assertSame(self::FIRST_HASH, $hasher->hash($first));
        self::assertSame(self::SECOND_HASH, $hasher->hash($second));

        $first['details'] = array_reverse($first['details'], true);
        self::assertSame(self::FIRST_HASH, $hasher->hash($first), 'JSON object key order must not affect the chain.');
    }

    public function test_valid_chain_and_head_verify(): void
    {
        $result = (new SessionChainVerifier(new SessionChainHasher))->verify([
            $this->firstEvent(),
            $this->secondEvent(),
        ], self::SECOND_HASH);

        self::assertTrue($result->valid);
        self::assertNull($result->failed_sequence);
        self::assertNull($result->error);
    }

    public function test_tampering_any_security_field_reports_first_bad_sequence(): void
    {
        $mutations = [
            'http_method' => static fn (array $event): array => array_replace($event, ['http_method' => 'DELETE']),
            'path' => static fn (array $event): array => array_replace($event, ['path' => '/tampered']),
            'operator' => static fn (array $event): array => array_replace($event, ['operator_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']),
            'session' => static fn (array $event): array => array_replace($event, ['session_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']),
            'details' => static fn (array $event): array => array_replace($event, ['details' => ['ticket_ref' => 'CHANGED']]),
            'sequence' => static fn (array $event): array => array_replace($event, ['sequence' => 3]),
            'previous hash' => static fn (array $event): array => array_replace($event, ['previous_hash' => str_repeat('f', 64)]),
        ];

        foreach ($mutations as $label => $mutate) {
            $result = (new SessionChainVerifier(new SessionChainHasher))->verify([
                $this->firstEvent(),
                $mutate($this->secondEvent()),
            ], self::SECOND_HASH);

            self::assertFalse($result->valid, "{$label} tamper must fail verification.");
            self::assertSame(2, $result->failed_sequence, "{$label} must identify sequence 2.");
        }
    }

    public function test_head_mismatch_fails_verification(): void
    {
        $result = (new SessionChainVerifier(new SessionChainHasher))->verify([
            $this->firstEvent(),
            $this->secondEvent(),
        ], str_repeat('f', 64));

        self::assertFalse($result->valid);
        self::assertSame(2, $result->failed_sequence);
        self::assertSame('head_mismatch', $result->error);
    }

    /** @return array<string, mixed> */
    private function firstEvent(): array
    {
        return [
            'version' => 1,
            'event_id' => '22222222-2222-4222-8222-222222222222',
            'session_id' => '11111111-1111-4111-8111-111111111111',
            'sequence' => 1,
            'previous_hash' => str_repeat('0', 64),
            'event_type' => 'request_authorized',
            'outcome' => 'allowed',
            'operator_id' => '33333333-3333-4333-8333-333333333333',
            'subject_user_id' => '44444444-4444-4444-8444-444444444444',
            'tenant_id' => '55555555-5555-4555-8555-555555555555',
            'request_id' => '66666666-6666-4666-8666-666666666666',
            'http_method' => 'GET',
            'path' => '/api/v1/auth/me',
            'details' => [
                'route_name' => 'auth.me',
                'ticket_ref' => 'SUP-7001',
                'response_status' => null,
                'error_code' => null,
                'resource_type' => null,
                'resource_id' => null,
            ],
            'occurred_at' => '2026-08-06T12:00:00.000000Z',
            'hash' => self::FIRST_HASH,
        ];
    }

    /** @return array<string, mixed> */
    private function secondEvent(): array
    {
        $event = $this->firstEvent();
        $event['event_id'] = '77777777-7777-4777-8777-777777777777';
        $event['sequence'] = 2;
        $event['previous_hash'] = self::FIRST_HASH;
        $event['http_method'] = 'POST';
        $event['path'] = '/api/v1/products';
        $event['details']['route_name'] = 'products.store';
        $event['details']['response_status'] = 201;
        $event['occurred_at'] = '2026-08-06T12:01:00.000000Z';
        $event['hash'] = self::SECOND_HASH;

        return $event;
    }
}
