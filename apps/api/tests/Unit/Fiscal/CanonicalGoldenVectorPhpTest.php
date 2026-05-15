<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use PHPUnit\Framework\TestCase;

final class CanonicalGoldenVectorPhpTest extends TestCase
{
    public function test_every_golden_vector_hash_matches(): void
    {
        $vectors = $this->vectors();

        $this->assertNotEmpty($vectors);
        foreach ($vectors as $vector) {
            $this->assertSame(
                $vector['expected_sha256_hex'],
                hash('sha256', $vector['expected_canonical_string']),
                "Golden vector '{$vector['name']}' hash mismatch",
            );
        }
    }

    public function test_matrix_coverage(): void
    {
        $names = array_column($this->vectors(), 'name');

        foreach ([
            'tnd_3dp',
            '2dp',
            '0dp',
            'negative_amount',
            'empty_arrays_null_optionals',
            'multibyte_nfc',
            'line_separator_normalization',
            'non_ascii_key_order',
        ] as $required) {
            $this->assertTrue(
                (bool) array_filter($names, fn ($name): bool => str_contains($name, $required)),
                "Golden-vector matrix missing case: {$required}",
            );
        }
    }

    /**
     * The PHP side NEVER serializes fiscal events. It only confirms hashes for
     * the canonical bytes authored by the device.
     *
     * @return list<array{name: string, payload_dto_input: array<string, mixed>, expected_canonical_string: string, expected_sha256_hex: string}>
     */
    private function vectors(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/Fiscal/canonical-golden-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
