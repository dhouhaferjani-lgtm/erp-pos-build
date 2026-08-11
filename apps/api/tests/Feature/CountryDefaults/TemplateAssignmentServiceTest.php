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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TemplateAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_create_repoint_and_remove_are_atomic_audited_mutations(): void
    {
        $actor = $this->actor();
        $first = $this->published('FR', $actor);
        $second = $this->published('FR', $actor);
        $service = app(TemplateAssignmentService::class);

        $assignment = $service->assign(' fr ', TemplateDomain::ChartOfAccounts, $first->id, $actor);
        self::assertSame('FR', $assignment->country_code);
        self::assertSame($first->id, $assignment->template_id);

        $repointed = $service->assign('FR', TemplateDomain::ChartOfAccounts, $second->id, $actor);
        self::assertSame($assignment->id, $repointed->id);
        self::assertSame($second->id, $repointed->template_id);

        $service->remove('FR', TemplateDomain::ChartOfAccounts, $actor);
        self::assertDatabaseMissing('country_template_assignments', ['id' => $assignment->id]);
        self::assertDatabaseCount('admin_audit_logs', 5);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.assignment.created']);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.assignment.repointed']);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.assignment.removed']);
    }

    public function test_assignment_rejects_draft_wrong_domain_and_out_of_scope_templates_after_lock(): void
    {
        $actor = $this->actor();
        $published = $this->published('FR', $actor);
        $service = app(TemplateAssignmentService::class);

        $draft = AdminTemplate::withoutEvents(static fn (): AdminTemplate => AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Draft',
            'status' => TemplateStatus::Draft,
        ]));

        foreach ([[$draft, 'published', 'FR'], [$published, 'scope', 'DE']] as $case) {
            [$template, $message, $country] = $case;
            try {
                $service->assign(
                    $country,
                    TemplateDomain::ChartOfAccounts,
                    $template->id,
                    $actor,
                );
                self::fail('Invalid assignment must be rejected.');
            } catch (DomainException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        DB::connection($published->getConnectionName())->table('admin_templates')
            ->where('id', $published->id)
            ->update(['domain' => 'other_domain']);
        try {
            $service->assign('FR', TemplateDomain::ChartOfAccounts, $published->id, $actor);
            self::fail('A wrong-domain template must be rejected.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('domain', $exception->getMessage());
        }
    }

    public function test_wildcard_assignment_is_pinned_but_exact_assignment_is_removable(): void
    {
        $actor = $this->actor();
        $wildcard = $this->published('*', $actor);
        $service = app(TemplateAssignmentService::class);
        $service->assign('*', TemplateDomain::ChartOfAccounts, $wildcard->id, $actor);

        try {
            $service->remove('*', TemplateDomain::ChartOfAccounts, $actor);
            self::fail('Wildcard assignment must be pinned.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('pinned', $exception->getMessage());
        }

        self::assertDatabaseHas('country_template_assignments', [
            'country_code' => '*',
            'domain' => TemplateDomain::ChartOfAccounts->value,
        ]);
    }

    private function published(string $country, SuperAdmin $actor): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Assignment fixture '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        $sort = 1;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            $this->row($template, sprintf('R%03d', $sort), $entry['purpose']->expectedAccountType(), $sort++, $entry['purpose'], true);
        }
        if ($country === 'TN') {
            $this->row($template, sprintf('R%03d', $sort), SystemAccountPurpose::SalesStampDutyPayable->expectedAccountType(), $sort++, SystemAccountPurpose::SalesStampDutyPayable, true);
        }
        foreach (ProtectedAccountCodeRegistry::forCountry($country) as $protected) {
            $this->row($template, $protected['code'], AccountType::from($protected['expected_type']), $sort++, null, $protected['requires_system']);
        }

        return app(TemplatePublishingService::class)->publish($template->id, 'Standard 2026', [$country], $actor);
    }

    private function row(AdminTemplate $template, string $code, AccountType $type, int $sort, ?SystemAccountPurpose $purpose, bool $system): void
    {
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
            'name' => 'Assignment operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
