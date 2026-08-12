<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Export\LegacyCoaGoldenExporter;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class LegacyGoldenParityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('legacyCharts')]
    public function test_frozen_seeder_export_golden_and_bootstrap_rows_have_identical_bytes(
        string $country,
        string $bootstrapKey,
    ): void {
        $exporter = app(LegacyCoaGoldenExporter::class);
        $exported = $exporter->exportLegacyDefinitions($country);
        $goldenPath = base_path("tests/Fixtures/CountryDefaults/goldens/{$country}.legacy-v1.txt");
        $hashPath = $goldenPath.'.sha256';
        $golden = (string) file_get_contents($goldenPath);

        self::assertSame($golden, $exported);
        self::assertSame(hash('sha256', $golden), trim((string) file_get_contents($hashPath)));
        self::assertFalse(str_ends_with($golden, "\n"));

        $template = AdminTemplate::query()->where('bootstrap_key', $bootstrapKey)->firstOrFail();
        self::assertSame($golden, $exporter->exportPersistedTemplate($template));
    }

    public function test_legacy_route_assigns_source_insertion_order_but_persisted_route_preserves_explicit_order(): void
    {
        $legacy = app(LegacyCoaGoldenExporter::class)->exportLegacyDefinitions('generic');
        $legacyRows = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", $legacy),
        );
        self::assertSame(range(1, 61), array_column($legacyRows, 'sort_order'));

        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Explicit persisted order',
            'status' => TemplateStatus::Draft,
        ]);
        foreach ([10 => 'B', 20 => 'A'] as $sortOrder => $code) {
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => $code,
                'name' => $code,
                'type' => 'asset',
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => $sortOrder,
            ]);
        }

        $persisted = app(LegacyCoaGoldenExporter::class)->exportPersistedTemplate($template);
        self::assertSame([10, 20], array_column(array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", $persisted),
        ), 'sort_order'));
    }

    public function test_certification_hashing_requires_native_icu_and_reports_the_active_version(): void
    {
        $version = (new CanonicalCoaSerializer)->nativeIcuVersion();
        self::assertSame(INTL_ICU_VERSION, $version);
        self::assertTrue((new \ReflectionClass(\Normalizer::class))->isInternal());

        $autoload = base_path('vendor/autoload.php');
        $script = sprintf(
            'require %s; $class=%s; (new $class)->serialize([["code"=>"1","name"=>"One","type"=>"asset","parent_code"=>null,"system_purpose"=>null,"is_system"=>false,"sort_order"=>1]]);',
            var_export($autoload, true),
            var_export(CanonicalCoaSerializer::class, true),
        );
        $polyfillOnly = new Process([PHP_BINARY, '-n', '-r', $script]);
        $polyfillOnly->run();

        self::assertFalse($polyfillOnly->isSuccessful());
        self::assertStringContainsString('native ICU', $polyfillOnly->getErrorOutput().$polyfillOnly->getOutput());
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyCharts(): iterable
    {
        yield 'Tunisia' => ['tn', 'coa.tn.legacy-v1'];
        yield 'France' => ['fr', 'coa.fr.legacy-v1'];
        yield 'Generic' => ['generic', 'coa.generic.legacy-v1'];
    }
}
