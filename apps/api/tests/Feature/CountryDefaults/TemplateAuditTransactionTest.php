<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\AdminAuditLog;
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
use App\Modules\Tenant\Domain\Tenant;
use App\Services\AdminAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TemplateAuditTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_and_clone_roll_back_when_the_in_transaction_audit_fails(): void
    {
        $actor = $this->actor();
        $draft = $this->validTemplate('FR', TemplateStatus::Draft);
        $source = $this->validTemplate('FR', TemplateStatus::Published, $actor);
        $this->installFailingAudit();

        try {
            app(TemplatePublishingService::class)->publish($draft->id, 'PCG 2026', ['FR'], $actor);
            self::fail('Audit failure must abort publish.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced audit failure', $exception->getMessage());
        }
        self::assertSame(TemplateStatus::Draft, $draft->refresh()->status);

        try {
            app(TemplatePublishingService::class)->cloneToDraft($source->id, 'Rollback clone', $actor);
            self::fail('Audit failure must abort clone.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced audit failure', $exception->getMessage());
        }
        self::assertDatabaseMissing('admin_templates', ['name' => 'Rollback clone']);
    }

    public function test_archive_and_delete_roll_back_when_audit_fails(): void
    {
        $actor = $this->actor();
        $published = $this->validTemplate('FR', TemplateStatus::Published, $actor);
        $draft = $this->validTemplate('FR', TemplateStatus::Draft);
        $this->installFailingAudit();

        try {
            app(TemplatePublishingService::class)->archive($published->id, $actor);
            self::fail('Audit failure must abort archive.');
        } catch (RuntimeException) {
            self::assertSame(TemplateStatus::Published, $published->refresh()->status);
        }

        try {
            app(TemplatePublishingService::class)->delete($draft->id, $actor);
            self::fail('Audit failure must abort delete.');
        } catch (RuntimeException) {
            self::assertDatabaseHas('admin_templates', ['id' => $draft->id]);
        }
    }

    public function test_assignment_create_repoint_and_remove_roll_back_when_audit_fails(): void
    {
        $actor = $this->actor();
        $first = $this->validTemplate('FR', TemplateStatus::Published, $actor);
        $second = $this->validTemplate('FR', TemplateStatus::Published, $actor);
        $this->installFailingAudit();
        $service = app(TemplateAssignmentService::class);

        try {
            $service->assign('FR', TemplateDomain::ChartOfAccounts, $first->id, $actor);
            self::fail('Audit failure must abort assignment create.');
        } catch (RuntimeException) {
            self::assertDatabaseMissing('country_template_assignments', ['country_code' => 'FR']);
        }

        $assignmentId = Str::uuid()->toString();
        DB::connection($first->getConnectionName())->table('country_template_assignments')->insert([
            'id' => $assignmentId,
            'country_code' => 'FR',
            'domain' => TemplateDomain::ChartOfAccounts->value,
            'template_id' => $first->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignment = CountryTemplateAssignment::query()->findOrFail($assignmentId);
        try {
            $service->assign('FR', TemplateDomain::ChartOfAccounts, $second->id, $actor);
            self::fail('Audit failure must abort assignment repoint.');
        } catch (RuntimeException) {
            self::assertSame($first->id, $assignment->refresh()->template_id);
        }

        try {
            $service->remove('FR', TemplateDomain::ChartOfAccounts, $actor);
            self::fail('Audit failure must abort assignment removal.');
        } catch (RuntimeException) {
            self::assertDatabaseHas('country_template_assignments', ['id' => $assignment->id]);
        }
    }

    private function installFailingAudit(): void
    {
        $connection = (new AdminTemplate)->getConnectionName();
        $audit = new class($connection) extends AdminAuditService
        {
            public function __construct(private readonly ?string $connection) {}

            /**
             * @param  array<string, mixed>|null  $oldValues
             * @param  array<string, mixed>|null  $newValues
             */
            public function log(
                SuperAdmin $admin,
                string $action,
                ?Tenant $tenant = null,
                ?string $entityType = null,
                ?string $entityId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $notes = null,
            ): AdminAuditLog {
                if (DB::connection($this->connection)->transactionLevel() < 1) {
                    throw new RuntimeException('audit was invoked outside the central transaction');
                }

                throw new RuntimeException('forced audit failure');
            }
        };
        $this->app->instance(AdminAuditService::class, $audit);
    }

    private function validTemplate(string $country, TemplateStatus $status, ?SuperAdmin $actor = null): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Audit fixture '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        $sort = 1;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            $this->row($template, sprintf('R%03d', $sort), $entry['purpose']->expectedAccountType(), $sort++, $entry['purpose'], true);
        }
        foreach (ProtectedAccountCodeRegistry::forCountry($country) as $protected) {
            $this->row($template, $protected['code'], AccountType::from($protected['expected_type']), $sort++, null, $protected['requires_system']);
        }

        if ($status === TemplateStatus::Draft) {
            return $template;
        }

        if (! $actor instanceof SuperAdmin) {
            throw new RuntimeException('Published audit fixtures require a certifying actor.');
        }

        return app(TemplatePublishingService::class)->publish($template->id, 'PCG 2026', [$country], $actor);
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
            'name' => 'Audit operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
