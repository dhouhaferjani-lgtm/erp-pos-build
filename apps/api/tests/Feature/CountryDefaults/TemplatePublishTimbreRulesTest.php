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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TemplatePublishTimbreRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_timbre_and_non_timbre_scope_matrix_enforces_the_absorber_rule(): void
    {
        $actor = $this->actor();

        foreach ([['TN', true], ['FR', false], ['DE', false], ['*', false]] as [$country, $needsStamp]) {
            $template = $this->validDraft((string) $country, (bool) $needsStamp);
            $published = app(TemplatePublishingService::class)->publish(
                $template->id,
                'Standard 2026',
                [(string) $country],
                $actor,
            );
            self::assertSame(TemplateStatus::Published, $published->status);
        }

        $tnMissingStamp = $this->validDraft('TN', false);
        $this->assertPublishFails($tnMissingStamp, ['TN'], 'scope-required', $actor);

        foreach (['FR', 'DE', '*'] as $country) {
            $nonTimbreWithStamp = $this->validDraft($country, true);
            $this->assertPublishFails($nonTimbreWithStamp, [$country], 'absorber', $actor);
        }

        $mixed = $this->validDraft('TN', true);
        $this->assertPublishFails($mixed, ['TN', 'FR'], 'cannot mix', $actor);
    }

    public function test_assignment_reexecutes_non_timbre_stamp_and_protected_code_rules(): void
    {
        $actor = $this->actor();
        $template = $this->validDraft('FR', false);
        $published = app(TemplatePublishingService::class)->publish(
            $template->id,
            'PCG 2026',
            ['FR'],
            $actor,
        );

        $stampRow = AdminTemplateAccount::withoutEvents(static fn (): AdminTemplateAccount => AdminTemplateAccount::query()->create([
            'template_id' => $published->id,
            'code' => 'MUTATED-STAMP',
            'name' => 'Injected stamp absorber',
            'type' => SystemAccountPurpose::SalesStampDutyPayable->expectedAccountType(),
            'parent_code' => null,
            'system_purpose' => SystemAccountPurpose::SalesStampDutyPayable,
            'is_system' => true,
            'sort_order' => 9998,
        ]));

        try {
            app(TemplateAssignmentService::class)->assign(
                ' fr ',
                TemplateDomain::ChartOfAccounts,
                $published->id,
                $actor,
            );
            self::fail('Assignment must rerun the non-timbre stamp ban.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('absorber', $exception->getMessage());
        } finally {
            AdminTemplateAccount::withoutEvents(static fn () => $stampRow->delete());
        }

        $protected = ProtectedAccountCodeRegistry::forCountry('FR')[0];
        AdminTemplateAccount::withoutEvents(static fn () => $published->accounts()
            ->where('code', $protected['code'])
            ->update(['is_system' => false]));

        try {
            app(TemplateAssignmentService::class)->assign(
                'FR',
                TemplateDomain::ChartOfAccounts,
                $published->id,
                $actor,
            );
            self::fail('Assignment must rerun protected-code system checks.');
        } catch (DomainException $exception) {
            self::assertStringContainsString($protected['code'], $exception->getMessage());
        }
    }

    /** @param list<string> $scope */
    private function assertPublishFails(AdminTemplate $template, array $scope, string $message, SuperAdmin $actor): void
    {
        try {
            app(TemplatePublishingService::class)->publish($template->id, 'Standard 2026', $scope, $actor);
            self::fail('Invalid timbre certification must fail.');
        } catch (DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function validDraft(string $countryCode, bool $withStamp): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Timbre fixture '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        $sort = 1;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            $this->row($template, sprintf('R%03d', $sort), $entry['purpose']->expectedAccountType(), $sort++, $entry['purpose'], true);
        }
        if ($withStamp) {
            $this->row(
                $template,
                sprintf('R%03d', $sort),
                SystemAccountPurpose::SalesStampDutyPayable->expectedAccountType(),
                $sort++,
                SystemAccountPurpose::SalesStampDutyPayable,
                true,
            );
        }
        foreach (ProtectedAccountCodeRegistry::forCountry($countryCode) as $protected) {
            $this->row(
                $template,
                $protected['code'],
                AccountType::from($protected['expected_type']),
                $sort++,
                null,
                $protected['requires_system'],
            );
        }

        return $template;
    }

    private function row(
        AdminTemplate $template,
        string $code,
        AccountType $type,
        int $sortOrder,
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
            'sort_order' => $sortOrder,
        ]);
    }

    private function actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Certifier',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
