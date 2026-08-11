<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CountryCodeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_normalizes_every_valid_assignment_write(): void
    {
        $template = $this->publishedTemplate();

        $assignment = CountryTemplateAssignment::query()->create([
            'country_code' => ' tn ',
            'domain' => TemplateDomain::ChartOfAccounts,
            'template_id' => $template->id,
        ]);
        self::assertSame('TN', $assignment->country_code);

        $assignment->country_code = ' fr ';
        $assignment->save();
        self::assertSame('FR', $assignment->refresh()->country_code);

        $assignment->country_code = '*';
        $assignment->save();
        self::assertSame('*', $assignment->refresh()->country_code);
    }

    public function test_model_rejects_malformed_assignment_country_codes(): void
    {
        $template = $this->publishedTemplate();

        foreach (['', 'FRA', 'T1', 'tn-1'] as $countryCode) {
            try {
                CountryTemplateAssignment::query()->create([
                    'country_code' => $countryCode,
                    'domain' => TemplateDomain::ChartOfAccounts,
                    'template_id' => $template->id,
                ]);
                self::fail("Malformed assignment country code {$countryCode} must be rejected.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('country code', $exception->getMessage());
            }
        }
    }

    private function publishedTemplate(): AdminTemplate
    {
        return AdminTemplate::withoutEvents(static fn (): AdminTemplate => AdminTemplate::query()->create([
            'id' => Str::uuid()->toString(),
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Normalization template',
            'status' => TemplateStatus::Published,
            'content_hash' => str_repeat('a', 64),
            'standard_ref' => 'PCN 2026',
            'certified_country_codes' => ['TN', 'FR', '*'],
            'capability_registry_version' => 'v1',
            'published_at' => now(),
        ]));
    }
}
