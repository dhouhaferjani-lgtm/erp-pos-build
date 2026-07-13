<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class ExportFrontendPermissionsMapCommandTest extends TestCase
{
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
}
