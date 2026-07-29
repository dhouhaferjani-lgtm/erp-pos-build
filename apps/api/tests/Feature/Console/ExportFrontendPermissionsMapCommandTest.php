<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class ExportFrontendPermissionsMapCommandTest extends TestCase
{
    /**
     * The committed artifact the frontend consumes, resolved relative to the
     * command's own default output path (`base_path('../web/...')`).
     */
    private const COMMITTED_MAP_PATH = '../web/src/hooks/permissionsMap.generated.ts';

    /**
     * Surfaced verbatim when the committed map has drifted from the seeder so a
     * developer knows exactly how to recover.
     */
    private const REGENERATE_HINT =
        'The committed frontend permission map (apps/web/src/hooks/permissionsMap.generated.ts) '
        .'is stale relative to RolesAndPermissionsSeeder. '
        .'Run `php artisan permissions:export-frontend-map` and commit the regenerated file.';

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputPath = sys_get_temp_dir().'/autoerp-permissions-map-'.bin2hex(random_bytes(8)).'.ts';
    }

    protected function tearDown(): void
    {
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }

        parent::tearDown();
    }

    public function test_it_exports_a_sorted_deterministic_frontend_map_without_a_database(): void
    {
        $this->artisan('permissions:export-frontend-map', ['--path' => $this->outputPath])
            ->assertSuccessful();

        $firstExport = file_get_contents($this->outputPath);
        self::assertIsString($firstExport);

        self::assertStringContainsString(
            "  'accounts.manage': ['accountant', 'admin', 'manager'],",
            $firstExport,
        );
        self::assertStringContainsString(
            "  'replenishment.create': ['admin', 'manager', 'operator'],",
            $firstExport,
        );
        self::assertStringContainsString(
            "  'treasury.manage': ['accountant', 'admin'],",
            $firstExport,
        );
        self::assertTrue(
            strpos($firstExport, "  'accounts.manage':") < strpos($firstExport, "  'replenishment.create':"),
        );

        $lines = explode("\n", $firstExport, 4);
        self::assertCount(4, $lines);
        self::assertMatchesRegularExpression('/^\/\/ Source hash: sha256:[a-f0-9]{64}$/', $lines[2]);
        self::assertSame(
            '// Source hash: sha256:'.hash('sha256', $lines[3]),
            $lines[2],
        );

        $this->artisan('permissions:export-frontend-map', ['--path' => $this->outputPath])
            ->assertSuccessful();

        self::assertSame($firstExport, file_get_contents($this->outputPath));
    }

    public function test_the_committed_frontend_map_is_fresh_against_the_seeder(): void
    {
        $committed = file_get_contents(base_path(self::COMMITTED_MAP_PATH));
        self::assertIsString($committed, self::REGENERATE_HINT);

        self::assertTrue(
            $this->mapsMatch($this->freshExport(), $committed),
            self::REGENERATE_HINT,
        );
    }

    public function test_a_stale_committed_map_is_detected_as_drift(): void
    {
        $fresh = $this->freshExport();

        // Simulate a stale committed map WITHOUT committing one: perturb a copy
        // of the fresh export (drop a role grant) so the guard has something to
        // catch. This proves the freshness assertion above would fail RED if the
        // real committed artifact ever drifted from the seeder.
        $stale = str_replace(
            "  'treasury.manage': ['accountant', 'admin'],",
            "  'treasury.manage': ['admin'],",
            $fresh,
        );
        self::assertNotSame($fresh, $stale, 'The fixture perturbation must actually mutate the map.');

        self::assertFalse(
            $this->mapsMatch($fresh, $stale),
            'A perturbed (stale) committed map must be detected as drift by the freshness guard.',
        );
    }

    /**
     * Regenerate the map to a throwaway path (no database required) and return
     * its contents.
     */
    private function freshExport(): string
    {
        $path = sys_get_temp_dir().'/autoerp-permissions-map-fresh-'.bin2hex(random_bytes(8)).'.ts';

        try {
            $this->artisan('permissions:export-frontend-map', ['--path' => $path])
                ->assertSuccessful();

            $contents = file_get_contents($path);
            self::assertIsString($contents);

            return $contents;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The exact predicate the freshness guard uses: line-ending-normalized
     * equality between a fresh export and the committed artifact.
     */
    private function mapsMatch(string $freshContents, string $committedContents): bool
    {
        return $this->normalize($freshContents) === $this->normalize($committedContents);
    }

    private function normalize(string $contents): string
    {
        return rtrim(str_replace("\r\n", "\n", $contents), "\n")."\n";
    }
}
