<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class FrozenSeederProvisioningIsolationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function provisioningConsumers(): iterable
    {
        yield 'registration' => ['app/Modules/Tenant/Application/Services/TenantInitializationService.php'];
        yield 'accounting service' => ['app/Modules/Accounting/Application/Services/ChartOfAccountsService.php'];
        yield 'additional company' => ['app/Modules/Company/Presentation/Controllers/CompanyController.php'];
        yield 'database seeder' => ['database/seeders/DatabaseSeeder.php'];
        yield 'demo tenant' => ['database/seeders/DemoTenantSeeder.php'];
        yield 'coffee shop' => ['database/seeders/CoffeeShopSeeder.php'];
        yield 'Tunisian parapharmacy' => ['database/seeders/TunisianParapharmacySeeder.php'];
        yield 'parapharmacy base' => ['database/seeders/ParapharmacySeeder.php'];
        yield 'Tunisia demo pharmacy' => ['database/seeders/DemoPharmacySeeder.php'];
    }

    #[DataProvider('provisioningConsumers')]
    public function test_each_provisioning_consumer_is_isolated_from_frozen_seeder_classes(string $relativePath): void
    {
        $source = (string) file_get_contents(base_path($relativePath));
        if (! str_ends_with($relativePath, 'ChartOfAccountsService.php')) {
            foreach ($this->frozenClasses() as $class) {
                self::assertStringNotContainsString($class, $source, "{$relativePath} directly references {$class}");
            }
        } else {
            self::assertStringContainsString("config('country_defaults.provisioning_enabled'", $source);
            self::assertStringContainsString('$this->templateResolver->resolve', $source);
            self::assertStringContainsString('$this->templateSeeder->seed', $source);
        }

        if (! str_ends_with($relativePath, 'DemoPharmacySeeder.php')) {
            self::assertStringContainsString(
                'ChartOfAccountsService',
                $source,
                "{$relativePath} must route new-company chart creation through the activation-aware service.",
            );
        }
    }

    public function test_no_unapproved_production_file_imports_a_frozen_seeder(): void
    {
        $allowed = [
            'app/Modules/Accounting/Application/Services/ChartOfAccountsService.php',
            'app/Modules/CountryDefaults/Infrastructure/Export/LegacyCoaGoldenExporter.php',
            'database/migrations/tenant/2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php',
            'database/seeders/FranceChartOfAccountsSeeder.php',
            'database/seeders/GenericChartOfAccountsSeeder.php',
            'database/seeders/TunisiaChartOfAccountsSeeder.php',
        ];
        $violations = [];
        foreach (['app', 'database'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                if (in_array($relative, $allowed, true)) {
                    continue;
                }
                $names = $this->codeNames((string) file_get_contents($file->getPathname()));
                foreach ($this->frozenClasses() as $class) {
                    if (in_array($class, $names, true)) {
                        $violations[] = "{$relative}:{$class}";
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Unexpected production frozen-seeder dependencies.');
    }

    /** @return list<string> */
    private function frozenClasses(): array
    {
        return [
            'TunisiaChartOfAccountsSeeder',
            'FranceChartOfAccountsSeeder',
            'GenericChartOfAccountsSeeder',
        ];
    }

    /** @return list<string> */
    private function codeNames(string $source): array
    {
        $names = [];
        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                continue;
            }
            $parts = explode('\\', $token[1]);
            $names[] = end($parts) ?: $token[1];
        }

        return $names;
    }
}
