<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Infrastructure\Export\LegacyCoaGoldenExporter;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LegacyGoldenParityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('legacyCharts')]
    public function test_frozen_seeder_export_golden_and_bootstrap_rows_have_identical_bytes(
        string $country,
        string $bootstrapKey,
    ): void {
        $exported = app(LegacyCoaGoldenExporter::class)->export($country);
        $goldenPath = base_path("tests/Fixtures/CountryDefaults/goldens/{$country}.legacy-v1.txt");
        $hashPath = $goldenPath.'.sha256';
        $golden = (string) file_get_contents($goldenPath);

        self::assertSame($golden, $exported);
        self::assertSame(hash('sha256', $golden), trim((string) file_get_contents($hashPath)));
        self::assertFalse(str_ends_with($golden, "\n"));

        $template = AdminTemplate::query()->where('bootstrap_key', $bootstrapKey)->firstOrFail();
        $rows = $template->accounts()->orderBy('sort_order')->get()->map(static fn ($row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'type' => $row->type,
            'parent_code' => $row->parent_code,
            'system_purpose' => $row->system_purpose,
            'is_system' => $row->is_system,
            'sort_order' => $row->sort_order,
        ])->all();
        self::assertSame($golden, app(CanonicalCoaSerializer::class)->serialize(array_values($rows)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyCharts(): iterable
    {
        yield 'Tunisia' => ['tn', 'coa.tn.legacy-v1'];
        yield 'France' => ['fr', 'coa.fr.legacy-v1'];
        yield 'Generic' => ['generic', 'coa.generic.legacy-v1'];
    }
}
