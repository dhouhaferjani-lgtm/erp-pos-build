<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Fiscal\V3;

use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalPayloadBuilder;
use Tests\TestCase;

final class CanonicalPayloadBuilderTest extends TestCase
{
    public function test_builds_v3_payload_for_cash_only_eur_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }

    public function test_builds_v3_payload_for_mixed_tender_tnd_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/02-mixed-tender-tnd.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }

    public function test_builds_v3_payload_for_voucher_tender_eur_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/03-voucher-tender-eur.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }

    public function test_builds_v3_payload_for_stacked_vouchers_eur_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/04-stacked-vouchers-eur.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }

    public function test_builds_v3_payload_for_return_with_voucher_issuance_eur_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/05-return-with-voucher-issuance-eur.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }

    public function test_builds_v3_payload_for_exchange_pair_eur_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/06-exchange-pair-eur.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }

    public function test_builds_v3_payload_for_tnd_residual_receipt(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/07-tnd-residual.json')), true);

        $builder = new CanonicalPayloadBuilder;
        $actual = $builder->build($fixture['input']);

        $this->assertSame($fixture['expected_canonical'], $actual);
        $this->assertSame($fixture['expected_hash'], hash('sha256', $actual));
    }
}
