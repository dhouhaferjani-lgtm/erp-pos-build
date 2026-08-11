<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use App\Modules\CountryDefaults\Providers\CountryDefaultsServiceProvider;
use App\Modules\Taxation\Application\Registries\CountryTaxConfigurationRegistry;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
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
        self::assertSame([$serviceFile], $this->semanticCapabilityAuthorities($this->productionRoots()));
        sort($methodOwners);
        $expectedOwners = [$contractFile, $serviceFile, $taxRegistry];
        sort($expectedOwners);
        self::assertSame($expectedOwners, $methodOwners);
    }

    public function test_semantic_guard_detects_a_renamed_country_capability_authority_in_a_broad_root(): void
    {
        $root = sys_get_temp_dir().'/country-defaults-authority-'.bin2hex(random_bytes(8));
        $fixture = $root.'/database/seeders/ShadowPolicy.php';
        mkdir(dirname($fixture), 0777, true);
        file_put_contents($fixture, <<<'PHP'
<?php

// Deliberately unrelated identifiers and vocabulary: only normalized set membership matters.
final class ShadowPolicy
{
    private const REGIONS = ['TN'];

    public function admits(string $region): bool
    {
        $normalized = strtoupper(trim($region));

        return in_array($normalized, self::REGIONS, true);
    }
}
PHP);

        try {
            self::assertContains($fixture, $this->semanticCapabilityAuthorities([$root]));
        } finally {
            unlink($fixture);
            rmdir(dirname($fixture));
            rmdir(dirname(dirname($fixture)));
            rmdir($root);
        }
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

    public function test_taxation_consumes_only_the_shared_contract_through_mandatory_injection(): void
    {
        $taxationRoot = $this->apiRoot().'/app/Modules/Taxation';
        foreach ($this->phpFilesUnder([$taxationRoot]) as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'App\\Modules\\CountryDefaults\\Application\\Services',
                $source,
                "Taxation must not import a CountryDefaults implementation: {$file}",
            );
        }

        $registryParameter = (new ReflectionClass(CountryTaxConfigurationRegistry::class))
            ->getConstructor()?->getParameters()[0];
        $provisioningParameter = (new ReflectionClass(CompanyTaxProvisioningService::class))
            ->getConstructor()?->getParameters()[0];

        self::assertNotNull($registryParameter);
        self::assertFalse($registryParameter->isDefaultValueAvailable());
        self::assertNotNull($provisioningParameter);
        self::assertFalse($provisioningParameter->isDefaultValueAvailable());
        self::assertInstanceOf(CountryTaxConfigurationRegistry::class, $this->app->make(CountryTaxConfigurationRegistry::class));
        self::assertInstanceOf(CompanyTaxProvisioningService::class, $this->app->make(CompanyTaxProvisioningService::class));
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
        return $this->phpFilesUnder($this->productionRoots());
    }

    /** @return list<string> */
    private function productionRoots(): array
    {
        return array_map(
            fn (string $root): string => $this->apiRoot().'/'.$root,
            ['app', 'routes', 'config', 'database', 'bootstrap'],
        );
    }

    /**
     * Finds a normalized closed-set TN predicate without relying on vocabulary.
     * Names are intentionally irrelevant: this catches a renamed method, set, or wrapper while
     * excluding config-driven feature gates that do not own a second fixed country authority.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function semanticCapabilityAuthorities(array $roots): array
    {
        $authorities = [];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach ($this->phpFilesUnder($roots) as $file) {
            $source = (string) file_get_contents($file);
            $ast = $parser->parse($source) ?? [];
            $finder = new NodeFinder;
            foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
                $countrySetConstants = [];
                foreach ($class->getConstants() as $constantStatement) {
                    foreach ($constantStatement->consts as $constant) {
                        if ($this->isAlpha2CountrySet($constant->value)) {
                            $countrySetConstants[] = $constant->name->toString();
                        }
                    }
                }

                foreach ($class->getMethods() as $method) {
                    if (! $method->returnType instanceof Node\Identifier
                        || strtolower($method->returnType->toString()) !== 'bool') {
                        continue;
                    }

                    $hasNormalization = $finder->findFirst(
                        $method->stmts ?? [],
                        static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
                            && $node->name instanceof Node\Name
                            && in_array(strtolower($node->name->toString()), ['strtoupper', 'mb_strtoupper'], true),
                    ) !== null;
                    $hasMembership = $finder->findFirst(
                        $method->stmts ?? [],
                        static fn (Node $node): bool => ($node instanceof Node\Expr\FuncCall
                            && $node->name instanceof Node\Name
                            && strtolower($node->name->toString()) === 'in_array')
                            || $node instanceof Node\Expr\BinaryOp\Equal
                            || $node instanceof Node\Expr\BinaryOp\Identical
                            || $node instanceof Node\Expr\Match_,
                    ) !== null;
                    $usesCountrySet = $finder->findFirst(
                        $method->stmts ?? [],
                        fn (Node $node): bool => ($node instanceof Node\Expr\ClassConstFetch
                            && $node->name instanceof Node\Identifier
                            && in_array($node->name->toString(), $countrySetConstants, true))
                            || ($node instanceof Node\Expr\FuncCall
                                && $node->name instanceof Node\Name
                                && strtolower($node->name->toString()) === 'in_array'
                                && isset($node->args[1])
                                && $node->args[1] instanceof Node\Arg
                                && $this->isAlpha2CountrySet($node->args[1]->value))
                            || (($node instanceof Node\Expr\BinaryOp\Equal
                                || $node instanceof Node\Expr\BinaryOp\Identical)
                                && (($node->left instanceof Node\Scalar\String_ && $node->left->value === 'TN')
                                    || ($node->right instanceof Node\Scalar\String_ && $node->right->value === 'TN'))),
                    ) !== null;

                    if ($hasNormalization && $hasMembership && $usesCountrySet) {
                        $authorities[] = $file;
                        break 2;
                    }
                }
            }
        }

        sort($authorities);

        return $authorities;
    }

    private function isAlpha2CountrySet(Node $node): bool
    {
        if (! $node instanceof Node\Expr\Array_ || $node->items === []) {
            return false;
        }

        $countries = [];
        foreach ($node->items as $item) {
            if (! $item->value instanceof Node\Scalar\String_
                || preg_match('/^[A-Z]{2}$/', $item->value->value) !== 1) {
                return false;
            }
            $countries[] = $item->value->value;
        }

        return in_array('TN', $countries, true);
    }

    /** @param list<string> $roots
     * @return list<string>
     */
    private function phpFilesUnder(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $item) {
                if ($item instanceof SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
                    $files[] = $item->getPathname();
                }
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
