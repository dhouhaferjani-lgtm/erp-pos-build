<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FrozenSeederDocblockTest extends TestCase
{
    private const MARKER = '@deprecated compatibility artifact; frozen at 7d85232cc';

    /** @return iterable<string, array{class-string, string}> */
    public static function frozenSeeders(): iterable
    {
        yield 'Tunisia' => [TunisiaChartOfAccountsSeeder::class, '4e5dae6271dfd835a1bd269936423493222553414ead469ffc6710a3c54624ad'];
        yield 'France' => [FranceChartOfAccountsSeeder::class, '44d1d7216fe7aa2b4f279d664bb78b309247fda01a7be82e622da2cea8318634'];
        yield 'Generic' => [GenericChartOfAccountsSeeder::class, 'd0f13078944e002527c77ef9e8c4ff1619bb35a9a17b7a59b906734530cbf836'];
    }

    /** @param class-string $seeder */
    #[DataProvider('frozenSeeders')]
    public function test_frozen_seeder_has_marker_and_no_content_drift(string $seeder, string $fingerprint): void
    {
        // Production break caught: a compatibility chart changes after the frozen SHA or loses its warning.
        $reflection = new ReflectionClass($seeder);
        self::assertStringContainsString(self::MARKER, (string) $reflection->getDocComment());

        $currentWithoutMarker = str_replace(
            "\n *\n * ".self::MARKER,
            '',
            (string) file_get_contents((string) $reflection->getFileName()),
        );
        self::assertSame($fingerprint, hash('sha256', $currentWithoutMarker));
    }
}
