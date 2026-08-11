<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Application\Services\TemplateAssignmentService;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CountryCodeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_normalizes_every_valid_assignment_create_and_repoint(): void
    {
        $actor = $this->actor();
        $service = app(TemplateAssignmentService::class);
        $firstFr = $this->publishedTemplate('FR', $actor);
        $secondFr = $this->publishedTemplate('FR', $actor);
        $tn = $this->publishedTemplate('TN', $actor);
        $wildcard = $this->publishedTemplate('*', $actor);

        $fr = $service->assign(' fr ', TemplateDomain::ChartOfAccounts, $firstFr->id, $actor);
        self::assertSame('FR', $fr->country_code);
        $repointed = $service->assign('fr', TemplateDomain::ChartOfAccounts, $secondFr->id, $actor);
        self::assertSame($fr->id, $repointed->id);
        self::assertSame($secondFr->id, $repointed->template_id);

        self::assertSame('TN', $service->assign(' tn ', TemplateDomain::ChartOfAccounts, $tn->id, $actor)->country_code);
        self::assertSame('*', $service->assign(' * ', TemplateDomain::ChartOfAccounts, $wildcard->id, $actor)->country_code);
    }

    public function test_model_rejects_malformed_assignment_country_codes_before_lifecycle_dispatch(): void
    {
        foreach (['', 'FRA', 'T1', 'tn-1'] as $countryCode) {
            try {
                CountryTemplateAssignment::query()->create([
                    'country_code' => $countryCode,
                    'domain' => TemplateDomain::ChartOfAccounts,
                    'template_id' => Str::uuid()->toString(),
                ]);
                self::fail("Malformed assignment country code {$countryCode} must be rejected.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('country code', $exception->getMessage());
            }
        }
    }

    private function publishedTemplate(string $country, SuperAdmin $actor): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Normalization template '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        $sort = 1;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            $this->row(
                $template,
                sprintf('R%03d', $sort),
                $entry['purpose']->expectedAccountType(),
                $sort++,
                $entry['purpose'],
                true,
            );
        }
        if ($country === 'TN') {
            $this->row(
                $template,
                sprintf('R%03d', $sort),
                SystemAccountPurpose::SalesStampDutyPayable->expectedAccountType(),
                $sort++,
                SystemAccountPurpose::SalesStampDutyPayable,
                true,
            );
        }
        foreach (ProtectedAccountCodeRegistry::forCountry($country) as $protected) {
            $this->row(
                $template,
                $protected['code'],
                AccountType::from($protected['expected_type']),
                $sort++,
                null,
                $protected['requires_system'],
            );
        }

        return app(TemplatePublishingService::class)->publish($template->id, 'Standard 2026', [$country], $actor);
    }

    private function row(
        AdminTemplate $template,
        string $code,
        AccountType $type,
        int $sort,
        ?SystemAccountPurpose $purpose,
        bool $system,
    ): void {
        AdminTemplateAccount::query()->create([
            'template_id' => $template->id,
            'code' => $code,
            'name' => $purpose === null ? 'Protected '.$code : $purpose->name,
            'type' => $type,
            'parent_code' => null,
            'system_purpose' => $purpose,
            'is_system' => $system,
            'sort_order' => $sort,
        ]);
    }

    private function actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Normalization operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
