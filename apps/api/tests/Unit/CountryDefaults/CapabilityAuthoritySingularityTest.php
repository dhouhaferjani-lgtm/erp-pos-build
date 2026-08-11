<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use App\Modules\CountryDefaults\Providers\CountryDefaultsServiceProvider;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;

final class CapabilityAuthoritySingularityTest extends TestCase
{
    public function test_only_one_concrete_capability_authority_and_one_version_set_exist(): void
    {
        // Production break caught: a second predicate, country set, or capability-version authority appears.
        $implementations = [];
        $countrySets = [];
        $versions = [];
        $methodOwners = [];

        foreach ($this->productionPhpFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'implements CountryAccountingCapabilities')) {
                $implementations[] = $file;
            }
            if (str_contains($source, 'STAMP_DUTY_COUNTRIES')) {
                $countrySets[] = $file;
            }
            if (str_contains($source, 'CAPABILITY_VERSION')) {
                $versions[] = $file;
            }
            if (str_contains($source, 'function supportsStampDuty')) {
                $methodOwners[] = $file;
            }
        }

        $serviceFile = (new ReflectionClass(CountryAccountingCapabilitiesService::class))->getFileName();
        $contractFile = (new ReflectionClass(CountryAccountingCapabilities::class))->getFileName();
        $taxRegistry = $this->apiRoot().'/app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php';

        self::assertSame([$serviceFile], $implementations);
        self::assertSame([$serviceFile], $countrySets);
        self::assertSame([$serviceFile], $versions);
        sort($methodOwners);
        $expectedOwners = [$contractFile, $serviceFile, $taxRegistry];
        sort($expectedOwners);
        self::assertSame($expectedOwners, $methodOwners);
    }

    public function test_provider_binds_the_shared_contract_to_the_sole_implementation(): void
    {
        // Production break caught: consumers resolve an unbound or alternative capability implementation.
        self::assertInstanceOf(
            CountryAccountingCapabilitiesService::class,
            $this->app->make(CountryAccountingCapabilities::class),
        );
        self::assertContains(CountryDefaultsServiceProvider::class, require $this->apiRoot().'/bootstrap/providers.php');
    }

    public function test_tax_seeder_stamp_capability_and_country_authority_agree(): void
    {
        // Production break caught: an active stamp-duty tax seeder is added without capability certification drift.
        $capabilities = new CountryAccountingCapabilitiesService;
        $seeders = [
            'TN' => TunisiaTaxConfigurationSeeder::class,
            'FR' => FranceTaxConfigurationSeeder::class,
        ];

        foreach ($seeders as $country => $seeder) {
            $source = (string) file_get_contents((string) (new ReflectionClass($seeder))->getFileName());
            $seedsActiveStampDuty = str_contains($source, "'is_stamp_duty' => true")
                && preg_match("/'is_active'\s*=>\s*true/", $source) === 1;

            self::assertSame($seedsActiveStampDuty, $capabilities->supportsStampDuty($country));
        }
    }

    /** @return list<string> */
    private function productionPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->apiRoot().'/app'));
        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function apiRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
