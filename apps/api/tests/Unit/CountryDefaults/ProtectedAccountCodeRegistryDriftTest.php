<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use Database\Seeders\ExpenseCategorySeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ProtectedAccountCodeRegistryDriftTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function variants(): iterable
    {
        yield 'Tunisia' => ['TN'];
        yield 'France' => ['FR'];
        yield 'generic wildcard' => ['*'];
    }

    #[DataProvider('variants')]
    public function test_instrument_protection_matches_the_live_resolver(string $countryCode): void
    {
        // Production break caught: a literal treasury resolver code/type changes without publish-gate protection.
        $reflection = new ReflectionClass(InstrumentAccountResolver::class);
        $resolver = $reflection->newInstanceWithoutConstructor();
        $codeMethod = new ReflectionMethod(InstrumentAccountResolver::class, 'accountCode');
        $typeMethod = new ReflectionMethod(InstrumentAccountResolver::class, 'accountType');

        $expected = [];
        foreach (InstrumentAccountPurpose::cases() as $purpose) {
            $code = $codeMethod->invoke($resolver, $purpose, $countryCode === '*' ? 'ZZ' : $countryCode);
            $expected[(string) $code] = (string) $typeMethod->invoke($resolver, $purpose);
        }
        ksort($expected);

        $actual = [];
        foreach (ProtectedAccountCodeRegistry::forCountry($countryCode) as $entry) {
            if ($entry['protection_source'] !== 'treasury_instrument_literal') {
                continue;
            }
            self::assertTrue($entry['requires_system']);
            self::assertNotSame('', $entry['reason']);
            $actual[$entry['code']] = $entry['expected_type'];
        }
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    #[DataProvider('variants')]
    public function test_demo_consumer_protection_matches_expense_seeder_selection(string $countryCode): void
    {
        // Production break caught: expense demo code maps drift from the protected publish-gate set.
        $reflection = new ReflectionClass(ExpenseCategorySeeder::class);
        $seeder = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ExpenseCategorySeeder::class, 'categoriesForCountry');
        /** @var array<string, string|null> $categories */
        $categories = $method->invoke($seeder, $countryCode === '*' ? 'ZZ' : $countryCode);
        $expected = array_values(array_filter($categories, static fn (?string $code): bool => $code !== null));
        sort($expected);

        $actual = [];
        foreach (ProtectedAccountCodeRegistry::forCountry($countryCode) as $entry) {
            if ($entry['protection_source'] !== 'expense_category_demo_consumer') {
                continue;
            }
            self::assertSame('expense', $entry['expected_type']);
            self::assertFalse($entry['requires_system']);
            self::assertNotSame('', $entry['reason']);
            $actual[] = $entry['code'];
        }
        sort($actual);

        self::assertSame($expected, $actual);
    }
}
