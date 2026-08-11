<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\AdminAuditLog;
use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TemplatePublishGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_locks_and_certifies_a_valid_timbre_template(): void
    {
        $actor = $this->actor();
        $template = $this->validDraft(['TN']);

        $published = app(TemplatePublishingService::class)->publish(
            $template->id,
            'PCN 2026',
            [' tn '],
            $actor,
        );

        self::assertSame(TemplateStatus::Published, $published->status);
        self::assertSame(['TN'], $published->certified_country_codes);
        self::assertSame('PCN 2026', $published->standard_ref);
        self::assertSame('v1', $published->capability_registry_version);
        self::assertSame($actor->id, $published->certified_by);
        self::assertNotNull($published->published_at);
        self::assertSame('c3436e61299a8fc0a7cdee4f4eb449e54bbef9738dccee7e22f61ec3854aafc7', $published->content_hash);
        self::assertDatabaseHas('admin_audit_logs', [
            'action' => 'country_defaults.template.published',
            'entity_id' => $template->id,
        ]);
    }

    public function test_each_required_purpose_is_a_publish_blocker(): void
    {
        $actor = $this->actor();
        $required = array_values(array_filter(
            ProvisioningRequiredPurposesV1::entries(),
            static fn (array $entry): bool => $entry['classification'] === 'REQUIRED',
        ));

        foreach ($required as $entry) {
            $template = $this->validDraft(['FR']);
            $template->accounts()->where('system_purpose', $entry['purpose']->value)->delete();

            try {
                app(TemplatePublishingService::class)->publish($template->id, 'PCG 2026', ['FR'], $actor);
                self::fail("Missing {$entry['purpose']->value} must block publication.");
            } catch (DomainException $exception) {
                self::assertStringContainsString($entry['purpose']->value, $exception->getMessage());
            }
        }
    }

    public function test_purpose_type_and_system_invariants_are_enforced(): void
    {
        $actor = $this->actor();

        $wrongType = $this->validDraft(['FR']);
        $wrongType->accounts()->where('system_purpose', SystemAccountPurpose::Bank->value)->update([
            'type' => AccountType::Expense->value,
        ]);
        $this->assertPublishFails($wrongType, ['FR'], 'expected account type', $actor);

        $notSystem = $this->validDraft(['FR']);
        $notSystem->accounts()->where('system_purpose', SystemAccountPurpose::Bank->value)->update([
            'is_system' => false,
        ]);
        $this->assertPublishFails($notSystem, ['FR'], 'is_system', $actor);
    }

    public function test_every_protected_code_requires_the_declared_type_and_system_semantics(): void
    {
        $actor = $this->actor();

        foreach (ProtectedAccountCodeRegistry::forCountry('TN') as $protected) {
            $missing = $this->validDraft(['TN']);
            $missing->accounts()->where('code', $protected['code'])->delete();
            $this->assertPublishFails($missing, ['TN'], $protected['code'], $actor);

            $wrongType = $this->validDraft(['TN']);
            $wrongType->accounts()->where('code', $protected['code'])->update([
                'type' => $protected['expected_type'] === 'asset' ? 'expense' : 'asset',
            ]);
            $this->assertPublishFails($wrongType, ['TN'], $protected['code'], $actor);

            if ($protected['requires_system']) {
                $notSystem = $this->validDraft(['TN']);
                $notSystem->accounts()->where('code', $protected['code'])->update(['is_system' => false]);
                $this->assertPublishFails($notSystem, ['TN'], $protected['code'], $actor);
            }
        }
    }

    public function test_structural_constraints_reject_duplicate_code_purpose_sort_and_cross_template_parent(): void
    {
        $template = $this->validDraft(['FR']);
        $row = $template->accounts()->firstOrFail();

        foreach (
            [
                ['code' => $row->code, 'system_purpose' => null, 'sort_order' => 9998],
                ['code' => 'UNIQUE-PURPOSE', 'system_purpose' => $row->system_purpose, 'sort_order' => 9997],
                ['code' => 'UNIQUE-SORT', 'system_purpose' => null, 'sort_order' => $row->sort_order],
            ] as $overrides
        ) {
            $connection = DB::connection($template->getConnectionName());
            $connection->beginTransaction();
            try {
                AdminTemplateAccount::query()->create([
                    'template_id' => $template->id,
                    'code' => $overrides['code'],
                    'name' => 'Invalid duplicate',
                    'type' => AccountType::Asset,
                    'parent_code' => null,
                    'system_purpose' => $overrides['system_purpose'],
                    'is_system' => true,
                    'sort_order' => $overrides['sort_order'],
                ]);
                self::fail('Database uniqueness contract must reject duplicate structural content.');
            } catch (QueryException) {
                $connection->rollBack();
                self::assertNotSame('', $connection->getName());
            }
        }

        $other = $this->validDraft(['FR']);
        AdminTemplateAccount::query()->create([
            'template_id' => $other->id,
            'code' => 'ONLY-OTHER-TEMPLATE',
            'name' => 'Only other template',
            'type' => AccountType::Asset,
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => 9995,
        ]);
        $foreignCode = 'ONLY-OTHER-TEMPLATE';
        $connection = DB::connection($template->getConnectionName());
        $connection->beginTransaction();
        try {
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => 'CROSS-PARENT',
                'name' => 'Cross template parent',
                'type' => AccountType::Asset,
                'parent_code' => $foreignCode,
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => 9996,
            ]);
            self::fail('A parent must resolve inside the same template.');
        } catch (QueryException) {
            $connection->rollBack();
            self::assertNotSame('', $connection->getName());
        }
    }

    public function test_publish_rejects_a_self_parent_cycle(): void
    {
        $actor = $this->actor();
        $template = $this->validDraft(['FR']);
        $account = $template->accounts()->orderBy('sort_order')->firstOrFail();
        DB::connection($template->getConnectionName())->table('admin_template_accounts')
            ->where('id', $account->id)
            ->update(['parent_code' => $account->code]);

        $this->assertPublishFails($template, ['FR'], 'itself', $actor);
    }

    public function test_blank_standard_reference_and_non_draft_status_are_rejected_after_lock(): void
    {
        $actor = $this->actor();
        $blank = $this->validDraft(['FR']);
        $this->assertPublishFails($blank, ['FR'], 'standard_ref', $actor, '   ');

        $published = $this->validDraft(['FR']);
        DB::connection($published->getConnectionName())
            ->table('admin_templates')
            ->where('id', $published->id)
            ->update(['status' => TemplateStatus::Published->value]);
        $published->refresh();
        $this->assertPublishFails($published, ['FR'], 'draft', $actor);
    }

    public function test_template_delete_cascades_rows_while_parent_row_delete_is_restricted(): void
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Parent constraint fixture',
            'status' => TemplateStatus::Draft,
        ]);
        $parent = AdminTemplateAccount::query()->create([
            'template_id' => $template->id,
            'code' => '100',
            'name' => 'Parent',
            'type' => AccountType::Asset,
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => 1,
        ]);
        AdminTemplateAccount::query()->create([
            'template_id' => $template->id,
            'code' => '101',
            'name' => 'Child',
            'type' => AccountType::Asset,
            'parent_code' => '100',
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => 2,
        ]);

        $connection = DB::connection($template->getConnectionName());
        $connection->beginTransaction();
        try {
            $connection->delete('delete from admin_template_accounts where id = ?', [$parent->id]);
            self::fail('Deleting a referenced parent row must be restricted.');
        } catch (QueryException) {
            $connection->rollBack();
            self::assertSame(2, $template->accounts()->count());
        }

        $template->delete();
        self::assertDatabaseMissing('admin_template_accounts', ['template_id' => $template->id]);

        $referenced = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Assignment FK fixture',
            'status' => TemplateStatus::Draft,
        ]);
        DB::connection($referenced->getConnectionName())->table('country_template_assignments')->insert([
            'id' => Str::uuid()->toString(),
            'country_code' => 'FR',
            'domain' => TemplateDomain::ChartOfAccounts->value,
            'template_id' => $referenced->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $connection->beginTransaction();
        try {
            $connection->table('admin_templates')->where('id', $referenced->id)->delete();
            self::fail('Deleting a template referenced by an assignment must be restricted by the database.');
        } catch (QueryException) {
            $connection->rollBack();
            self::assertDatabaseHas('admin_templates', ['id' => $referenced->id]);
        }
    }

    /** @param list<string> $scope */
    private function assertPublishFails(
        AdminTemplate $template,
        array $scope,
        string $message,
        SuperAdmin $actor,
        string $standardRef = 'Standard 2026',
    ): void {
        try {
            app(TemplatePublishingService::class)->publish($template->id, $standardRef, $scope, $actor);
            self::fail('Invalid template must not publish.');
        } catch (DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertSame(0, AdminAuditLog::query()->where('entity_id', $template->id)->count());
    }

    /** @param list<string> $scope */
    private function validDraft(array $scope): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Publish gate fixture '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        $sort = 1;

        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => sprintf('R%03d', $sort),
                'name' => $entry['purpose']->name,
                'type' => $entry['purpose']->expectedAccountType(),
                'parent_code' => null,
                'system_purpose' => $entry['purpose'],
                'is_system' => true,
                'sort_order' => $sort++,
            ]);
        }

        if (in_array('TN', $scope, true)) {
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => sprintf('R%03d', $sort),
                'name' => 'Sales stamp duty payable',
                'type' => SystemAccountPurpose::SalesStampDutyPayable->expectedAccountType(),
                'parent_code' => null,
                'system_purpose' => SystemAccountPurpose::SalesStampDutyPayable,
                'is_system' => true,
                'sort_order' => $sort++,
            ]);
        }

        $protectedCountry = $scope === ['*'] ? '*' : $scope[0];
        foreach (ProtectedAccountCodeRegistry::forCountry($protectedCountry) as $protected) {
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => $protected['code'],
                'name' => 'Protected '.$protected['code'],
                'type' => AccountType::from($protected['expected_type']),
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => $protected['requires_system'],
                'sort_order' => $sort++,
            ]);
        }

        return $template;
    }

    private function actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Country Defaults Certifier',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
