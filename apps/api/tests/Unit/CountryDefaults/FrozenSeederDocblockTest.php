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

    /** @return iterable<string, array{class-string}> */
    public static function frozenSeeders(): iterable
    {
        yield 'Tunisia' => [TunisiaChartOfAccountsSeeder::class];
        yield 'France' => [FranceChartOfAccountsSeeder::class];
        yield 'Generic' => [GenericChartOfAccountsSeeder::class];
    }

    /** @param class-string $seeder */
    #[DataProvider('frozenSeeders')]
    public function test_frozen_seeder_has_marker_and_no_content_drift(string $seeder): void
    {
        // Production break caught: a compatibility chart changes after the frozen SHA or loses its warning.
        $reflection = new ReflectionClass($seeder);
        self::assertStringContainsString(self::MARKER, (string) $reflection->getDocComment());

        $file = (string) $reflection->getFileName();
        $relative = substr($file, strlen(dirname(__DIR__, 5)) + 1);
        $frozen = shell_exec(sprintf(
            'git -C %s show 7d85232cc:%s',
            escapeshellarg(dirname(__DIR__, 5)),
            escapeshellarg($relative),
        ));
        self::assertNotNull($frozen);

        $currentWithoutMarker = str_replace("\n *\n * ".self::MARKER, '', (string) file_get_contents($file));
        self::assertSame($frozen, $currentWithoutMarker);
    }
}
