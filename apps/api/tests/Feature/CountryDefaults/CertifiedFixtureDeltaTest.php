<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class CertifiedFixtureDeltaTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    #[DataProvider('countryFixtures')]
    public function test_manifest_derived_missing_set_is_empty_delta_is_empty_and_full_gate_passes(
        string $fixture,
        string $scopeCode,
        bool $expectsStamp,
    ): void {
        $template = $this->m4Draft($fixture);
        $purposes = $template->accounts()->whereNotNull('system_purpose')->get()->map(static function ($account): string {
            $purpose = $account->getRawOriginal('system_purpose');
            if (! is_string($purpose)) {
                self::fail('Purpose-bearing fixture row lost its string purpose value.');
            }

            return $purpose;
        })->all();
        $required = array_values(array_map(
            static fn (array $entry): string => $entry['purpose']->value,
            array_filter(ProvisioningRequiredPurposesV1::entries(), static fn (array $entry): bool => $entry['classification'] === 'REQUIRED'),
        ));

        self::assertSame([], array_values(array_diff($required, $purposes)));
        self::assertSame($expectsStamp, in_array(SystemAccountPurpose::SalesStampDutyPayable->value, $purposes, true));
        $before = $this->canonicalBytes($template->id);
        $published = app(TemplatePublishingService::class)->publish(
            $template->id,
            'Certified legacy v1',
            [$scopeCode],
            $this->m4Actor(),
        );
        self::assertSame(TemplateStatus::Published, $published->status);
        self::assertSame($before, $this->canonicalBytes($published->id), 'M0 reconciliation pins an empty certification content delta.');
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function countryFixtures(): iterable
    {
        yield 'TN' => ['tn', 'TN', true];
        yield 'FR' => ['fr', 'FR', false];
        yield 'Generic' => ['generic', '*', false];
    }

    private function canonicalBytes(string $templateId): string
    {
        $template = AdminTemplate::query()->findOrFail($templateId);
        $rows = $template->accounts()->orderBy('sort_order')->get()->map(static fn ($account): array => [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'parent_code' => $account->parent_code,
            'system_purpose' => $account->system_purpose,
            'is_system' => $account->is_system,
            'sort_order' => $account->sort_order,
        ])->all();

        return app(CanonicalCoaSerializer::class)->serialize(array_values($rows));
    }
}
