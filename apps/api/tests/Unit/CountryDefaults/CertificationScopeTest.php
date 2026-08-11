<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CertificationScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function assignmentTruthTable(): iterable
    {
        yield 'TN exact accepts TN' => [['TN'], 'TN', true];
        yield 'TN exact rejects FR' => [['TN'], 'FR', false];
        yield 'TN exact rejects unknown ISO-like country' => [['TN'], 'ZZ', false];
        yield 'TN exact rejects wildcard' => [['TN'], '*', false];
        yield 'FR exact rejects TN' => [['FR'], 'TN', false];
        yield 'FR exact accepts normalized FR' => [[' fr '], 'fr', true];
        yield 'FR exact rejects unknown ISO-like country' => [['FR'], 'ZZ', false];
        yield 'wildcard rejects TN' => [['*'], 'TN', false];
        yield 'wildcard rejects FR' => [['*'], 'FR', false];
        yield 'wildcard rejects unknown ISO-like country' => [['*'], 'ZZ', false];
        yield 'wildcard accepts only wildcard assignment' => [['*'], '*', true];
    }

    /** @param list<string> $codes */
    #[DataProvider('assignmentTruthTable')]
    public function test_assignment_truth_table(array $codes, string $assignment, bool $expected): void
    {
        // Production break caught: exact and wildcard scopes become assignable outside their algebra.
        $scope = new CertificationScope($codes, new CountryAccountingCapabilitiesService);

        self::assertSame($expected, $scope->allowsAssignment($assignment));
    }

    public function test_exact_scope_is_normalized_deduplicated_and_sorted(): void
    {
        // Production break caught: equivalent exact sets serialize differently or retain duplicates.
        $scope = new CertificationScope([' fr ', 'DE', 'fr'], new CountryAccountingCapabilitiesService);

        self::assertSame(['DE', 'FR'], $scope->countryCodes());
        self::assertFalse($scope->isWildcard());
        self::assertFalse($scope->includesTimbreCountry());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'empty set' => [[]];
        yield 'wildcard mixed with exact' => [['FR', '*']];
        yield 'timbre mixed with non-timbre' => [['TN', 'FR']];
        yield 'unknown non-timbre mixed with timbre' => [['TN', 'ZZ']];
        yield 'empty code' => [['']];
        yield 'non alpha-2 exact code' => [['FRA']];
    }

    /** @param list<string> $codes */
    #[DataProvider('invalidScopes')]
    public function test_invalid_scopes_are_rejected(array $codes): void
    {
        // Production break caught: malformed or capability-incoherent certification scopes are accepted.
        $this->expectException(InvalidArgumentException::class);

        new CertificationScope($codes, new CountryAccountingCapabilitiesService);
    }

    public function test_malformed_assignment_code_is_rejected(): void
    {
        // Production break caught: assignment validation silently normalizes a non ISO-like identifier.
        $scope = new CertificationScope(['FR'], new CountryAccountingCapabilitiesService);

        $this->expectException(InvalidArgumentException::class);
        $scope->allowsAssignment('FRA');
    }
}
