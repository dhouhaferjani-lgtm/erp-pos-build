<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Fiscal\V3;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gate 1 — V3 golden-hash fixture existence + content-hash check.
 *
 * Problem: if a fixture JSON is deleted or silently mutated, the PHPUnit tests
 * that load `expected_hash` from it would fail at `file_get_contents` or
 * produce a silent skip before their assertions run.  Neither mode produces a
 * loud, attributable failure.
 *
 * This test hardcodes the SHA-256 hash of every fixture file's *raw bytes*.
 * When a fixture is legitimately updated the test fails with the old hash,
 * the developer reads the new hash from the output, updates the constant, and
 * the change is reviewed in the PR diff — making intentional fixture mutations
 * an explicit two-step process.
 *
 * Hash manifest generated 2026-04-30 from HEAD bbdbd610 of feat/refund-flow-fixes.
 *
 * @see project_fiscal_chain_ci_gates.md
 */
final class FixtureIntegrityTest extends TestCase
{
    /** @var array<string, string> filename → expected SHA-256 of raw file bytes */
    private const EXPECTED_HASHES = [
        '01-cash-only-eur.json' => '8fc899db9106aec97d19e6f9a09078d5ecc5fa5ce9a70c39a05eaec83833a289',
        '02-mixed-tender-tnd.json' => 'e3b0d308bb6f8951107d88d0706fba273c18bfc8dd855577051b3608c1df5ce1',
        '03-voucher-tender-eur.json' => 'd82ecec7b6652e82b7275db1e7a86468fdf5f3e8fa46a42cf75bac3a1cfe67fd',
        '04-stacked-vouchers-eur.json' => '730355c13434c54ec046fed1a1a09a9fb01630c8f6c0e9a61f0211c3e027dd13',
        '05-return-with-voucher-issuance-eur.json' => '6765348c03f6ea8dcedd2ac437e70a181142d950d05982e062fe45e20f3afe0e',
        '06-exchange-pair-eur.json' => 'ccac1a7f7a317c3d3932900ea50e90774931edf60281ec11f62461d062a34ebb',
        '07-tnd-residual.json' => 'fc806b6458ee5084c7d93f2a95cdaeea0b8a2fd511032ec96a13ae96bfac9062',
        '08-store-voucher-binding-eur.json' => '762c5c27733549fedb85862554d7f5682b8e86097a68875daaebb6d953d286da',
    ];

    private const FIXTURE_DIR = 'tests/Fixtures/Fiscal/v3-golden-hashes';

    /**
     * Each fixture file in EXPECTED_HASHES must exist on disk.
     *
     * Separate from the hash-equality assertion so a missing file produces a
     * clear "file does not exist" failure rather than a hash mismatch.
     */
    public function test_all_expected_fixture_files_exist(): void
    {
        foreach (array_keys(self::EXPECTED_HASHES) as $filename) {
            $path = base_path(self::FIXTURE_DIR.'/'.$filename);

            $this->assertFileExists(
                $path,
                "V3 golden-hash fixture '{$filename}' is missing from ".self::FIXTURE_DIR.'. '.
                'Deleting or renaming this file silently breaks the parity tests. '.
                'If you intentionally removed it, remove the corresponding entry from '.
                FixtureIntegrityTest::class.'::EXPECTED_HASHES.'
            );
        }
    }

    /**
     * No extra fixture files must appear in the directory without being added
     * to EXPECTED_HASHES.
     *
     * This prevents a new fixture from landing without a corresponding hash
     * guard entry.
     */
    public function test_fixture_directory_contains_no_unguarded_files(): void
    {
        $dir = base_path(self::FIXTURE_DIR);

        $this->assertDirectoryExists($dir, 'Fixture directory '.self::FIXTURE_DIR.' must exist.');

        $actualFiles = array_values(array_filter(
            array_map('basename', glob($dir.'/*.json') ?: []),
            static fn (string $f): bool => $f !== 'README.md',
        ));
        sort($actualFiles);

        $guardedFiles = array_keys(self::EXPECTED_HASHES);
        sort($guardedFiles);

        $unguarded = array_diff($actualFiles, $guardedFiles);

        $this->assertEmpty(
            $unguarded,
            'New fixture file(s) found in '.self::FIXTURE_DIR.' without a hash entry in '.
            FixtureIntegrityTest::class.'::EXPECTED_HASHES: '.implode(', ', $unguarded).'. '.
            'Add the expected SHA-256 hash of each new fixture file to the manifest.'
        );
    }

    /**
     * The raw bytes of every fixture file must match the hardcoded SHA-256.
     */
    #[DataProvider('fixtureHashProvider')]
    public function test_fixture_file_content_hash_matches_manifest(string $filename, string $expectedHash): void
    {
        $path = base_path(self::FIXTURE_DIR.'/'.$filename);

        // Skip gracefully if the existence test already caught the missing file —
        // this prevents a cascade of confusing hash errors for the same root cause.
        if (! file_exists($path)) {
            $this->markTestSkipped("Fixture file '{$filename}' does not exist — see test_all_expected_fixture_files_exist for details.");
        }

        $actualBytes = file_get_contents($path);
        $this->assertNotFalse($actualBytes, "Could not read fixture file: {$path}");

        $actualHash = hash('sha256', $actualBytes);

        $this->assertSame(
            $expectedHash,
            $actualHash,
            "V3 golden-hash fixture '{$filename}' has been tampered with or updated without updating the hash manifest.\n".
            "  Expected SHA-256: {$expectedHash}\n".
            "  Actual SHA-256:   {$actualHash}\n\n".
            'If this change is intentional, update the constant in '.
            FixtureIntegrityTest::class."::EXPECTED_HASHES['{$filename}'] to '{$actualHash}' ".
            'and ensure the TS counterpart in fixtureCrossLanguageSync.test.ts is also updated.'
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function fixtureHashProvider(): array
    {
        $result = [];
        foreach (self::EXPECTED_HASHES as $filename => $hash) {
            $result[$filename] = [$filename, $hash];
        }

        return $result;
    }
}
