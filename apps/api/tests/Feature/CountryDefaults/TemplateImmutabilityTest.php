<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class TemplateImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_certification_content_scope_and_account_rows_are_immutable(): void
    {
        $template = $this->publishedTemplate();

        foreach (
            [
                ['name', 'Changed'],
                ['content_hash', str_repeat('b', 64)],
                ['standard_ref', 'Changed standard'],
                ['certified_country_codes', ['DE']],
                ['capability_registry_version', 'v2'],
                ['certified_by', null],
                ['published_at', now()->toImmutable()->addDay()],
            ] as [$attribute, $value]
        ) {
            $fresh = AdminTemplate::query()->findOrFail($template->id);
            $fresh->{$attribute} = $value;
            try {
                $fresh->save();
                self::fail("Published attribute {$attribute} must be immutable.");
            } catch (LogicException $exception) {
                self::assertStringContainsString('immutable', $exception->getMessage());
            }
        }

        $row = $template->accounts()->firstOrFail();
        foreach (['update', 'delete', 'create'] as $mutation) {
            try {
                if ($mutation === 'update') {
                    $row->name = 'Changed';
                    $row->save();
                } elseif ($mutation === 'delete') {
                    $row->delete();
                } else {
                    AdminTemplateAccount::query()->create([
                        'template_id' => $template->id,
                        'code' => 'NEW',
                        'name' => 'New',
                        'type' => 'asset',
                        'parent_code' => null,
                        'system_purpose' => null,
                        'is_system' => false,
                        'sort_order' => 99,
                    ]);
                }
                self::fail("Published row {$mutation} must be immutable.");
            } catch (LogicException $exception) {
                self::assertStringContainsString('immutable', $exception->getMessage());
            }
        }
    }

    public function test_bootstrap_key_is_guarded_and_immutable(): void
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Bootstrap guard',
            'status' => TemplateStatus::Draft,
            'bootstrap_key' => 'must.not.mass.assign',
        ]);
        self::assertNull($template->bootstrap_key);

        $template->forceFill(['bootstrap_key' => 'coa.test.legacy-v1'])->save();
        try {
            $template->forceFill(['bootstrap_key' => 'coa.changed'])->save();
            self::fail('A non-null bootstrap key must be immutable.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function test_direct_save_cannot_bypass_the_publish_service(): void
    {
        $draft = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Direct publish guard',
            'status' => TemplateStatus::Draft,
        ]);
        $draft->status = TemplateStatus::Published;

        try {
            $draft->save();
            self::fail('Direct draft-to-published transition must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('lifecycle', $exception->getMessage());
        }
    }

    public function test_clone_archive_delete_and_assignment_reference_rules(): void
    {
        $actor = $this->actor();
        $publishing = app(TemplatePublishingService::class);
        $source = $this->publishedTemplate($actor);

        $clone = $publishing->cloneToDraft($source->id, 'Clone', $actor);
        self::assertSame(TemplateStatus::Draft, $clone->status);
        self::assertSame($source->id, $clone->cloned_from_id);
        self::assertNull($clone->content_hash);
        self::assertNull($clone->certified_country_codes);
        self::assertNull($clone->bootstrap_key);
        self::assertSame($source->accounts()->count(), $clone->accounts()->count());

        $publishing->archive($source->id, $actor);
        self::assertSame(TemplateStatus::Archived, $source->refresh()->status);

        $publishing->delete($clone->id, $actor);
        self::assertDatabaseMissing('admin_templates', ['id' => $clone->id]);

        $referenced = $this->publishedTemplate($actor);
        CountryTemplateAssignment::query()->create([
            'country_code' => 'FR',
            'domain' => TemplateDomain::ChartOfAccounts,
            'template_id' => $referenced->id,
        ]);

        try {
            $referenced->status = TemplateStatus::Archived;
            $referenced->save();
            self::fail('Direct save must not bypass assigned-template archive checks.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        self::assertSame(TemplateStatus::Published, $referenced->refresh()->status);

        try {
            $referenced->delete();
            self::fail('Direct delete must not bypass the published lifecycle guard.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        self::assertDatabaseHas('country_template_assignments', ['template_id' => $referenced->id]);

        foreach (['archive', 'delete'] as $operation) {
            try {
                $publishing->{$operation}($referenced->id, $actor);
                self::fail("Referenced template {$operation} must fail.");
            } catch (DomainException $exception) {
                self::assertStringContainsString('assignment', $exception->getMessage());
            }
        }
    }

    private function publishedTemplate(?SuperAdmin $actor = null): AdminTemplate
    {
        $actor ??= $this->actor();
        $template = AdminTemplate::withoutEvents(static fn (): AdminTemplate => AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Published fixture '.Str::random(8),
            'status' => TemplateStatus::Published,
            'content_hash' => str_repeat('a', 64),
            'standard_ref' => 'PCG 2026',
            'certified_country_codes' => ['FR'],
            'capability_registry_version' => 'v1',
            'certified_by' => $actor->id,
            'published_at' => now(),
            'created_by' => $actor->id,
        ]));
        AdminTemplateAccount::withoutEvents(static fn (): AdminTemplateAccount => AdminTemplateAccount::query()->create([
            'template_id' => $template->id,
            'code' => '100',
            'name' => 'Fixture',
            'type' => 'asset',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => 1,
        ]));

        return $template;
    }

    private function actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Lifecycle operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
