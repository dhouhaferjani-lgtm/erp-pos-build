<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Import\LegacyCoaBootstrapImporter;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        try {
            DB::connection($template->getConnectionName())->transaction(
                static fn (): bool => $template->forceFill(['bootstrap_key' => 'not.the.importer'])->save(),
            );
            self::fail('A bootstrap key may only be assigned by the bootstrap importer.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        $template->refresh();
        self::assertNull($template->bootstrap_key);
        try {
            DB::connection($template->getConnectionName())->transaction(
                static fn (): bool => $template->forceFill(['bootstrap_key' => 'coa.test.legacy-v1'])->save(),
            );
            self::fail('A draft may not acquire a bootstrap key through an arbitrary model write.');
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

    public function test_draft_header_update_requires_an_active_central_transaction(): void
    {
        $this->outsideCentralTransaction(function (): void {
            $draft = AdminTemplate::query()->create([
                'domain' => TemplateDomain::ChartOfAccounts,
                'name' => 'Header transaction guard',
                'status' => TemplateStatus::Draft,
            ]);
            $draft->name = 'Unsafe header edit';

            try {
                $draft->save();
                self::fail('Draft header edits outside a central transaction must fail.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('transaction', $exception->getMessage());
            }

            self::assertSame('Header transaction guard', $draft->refresh()->name);
        });
    }

    public function test_draft_account_create_requires_an_active_central_transaction(): void
    {
        $this->outsideCentralTransaction(function (): void {
            $draft = AdminTemplate::query()->create([
                'domain' => TemplateDomain::ChartOfAccounts,
                'name' => 'Account create transaction guard',
                'status' => TemplateStatus::Draft,
            ]);

            try {
                $this->createAccount($draft, '100', 1);
                self::fail('Draft account creates outside a central transaction must fail.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('transaction', $exception->getMessage());
            }

            self::assertSame(0, $draft->accounts()->count());
        });
    }

    public function test_draft_account_update_and_delete_require_an_active_central_transaction(): void
    {
        $this->outsideCentralTransaction(function (): void {
            $draft = AdminTemplate::query()->create([
                'domain' => TemplateDomain::ChartOfAccounts,
                'name' => 'Account mutation transaction guard',
                'status' => TemplateStatus::Draft,
            ]);
            $account = AdminTemplateAccount::withoutEvents(fn (): AdminTemplateAccount => $this->createAccount($draft, '100', 1));

            $account->name = 'Unsafe account edit';
            try {
                $account->save();
                self::fail('Draft account updates outside a central transaction must fail.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('transaction', $exception->getMessage());
            }

            try {
                $account->refresh()->delete();
                self::fail('Draft account deletes outside a central transaction must fail.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('transaction', $exception->getMessage());
            }
            self::assertDatabaseHas('admin_template_accounts', ['id' => $account->id, 'name' => 'Account 100']);
        });
    }

    public function test_locked_central_transaction_permits_ordinary_draft_edits(): void
    {
        $draft = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Safe draft edit',
            'status' => TemplateStatus::Draft,
        ]);
        $connection = DB::connection($draft->getConnectionName());

        $connection->transaction(function () use ($draft): void {
            $draft->name = 'Safely edited';
            $draft->save();
            $account = $this->createAccount($draft, '100', 1);
            $account->name = 'Safely edited account';
            $account->save();
            $account->delete();
        });

        self::assertSame('Safely edited', $draft->refresh()->name);
        self::assertSame(0, $draft->accounts()->count());
    }

    public function test_stale_draft_header_is_rechecked_under_lock_before_save(): void
    {
        $stale = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Stale draft',
            'status' => TemplateStatus::Draft,
        ]);
        DB::connection($stale->getConnectionName())->table('admin_templates')
            ->where('id', $stale->id)
            ->update(['status' => TemplateStatus::Published->value]);
        $stale->name = 'Unsafe stale overwrite';

        try {
            DB::connection($stale->getConnectionName())->transaction(static fn (): bool => $stale->save());
            self::fail('A stale draft instance must not overwrite a concurrently published template.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        self::assertSame('Stale draft', $stale->refresh()->name);
        self::assertSame(TemplateStatus::Published, $stale->status);
    }

    public function test_production_has_no_generic_bulk_template_mutation_bypass(): void
    {
        $production = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $production .= "\n".(string) file_get_contents($file->getPathname());
        }

        self::assertDoesNotMatchRegularExpression(
            '/AdminTemplate(?:Account)?::query\(\)(?:(?!;).)*->(?:update|delete)\s*\(/s',
            $production,
        );
        self::assertDoesNotMatchRegularExpression(
            '/->accounts\(\)(?:(?!;).)*->(?:update|delete)\s*\(/s',
            $production,
        );
        self::assertStringNotContainsString('AdminTemplate::withoutEvents', $production);
        self::assertStringNotContainsString('AdminTemplateAccount::withoutEvents', $production);
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
        DB::connection($referenced->getConnectionName())->table('country_template_assignments')->insert([
            'id' => Str::uuid()->toString(),
            'country_code' => 'FR',
            'domain' => TemplateDomain::ChartOfAccounts->value,
            'template_id' => $referenced->id,
            'created_at' => now(),
            'updated_at' => now(),
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

    public function test_clone_inserts_each_parent_before_children_regardless_of_sort_order(): void
    {
        $actor = $this->actor();
        $source = AdminTemplate::withoutEvents(static fn (): AdminTemplate => AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Non-topological published fixture',
            'status' => TemplateStatus::Published,
            'content_hash' => str_repeat('a', 64),
            'standard_ref' => 'PCG 2026',
            'certified_country_codes' => ['FR'],
            'capability_registry_version' => 'v1',
            'certified_by' => $actor->id,
            'published_at' => now(),
            'created_by' => $actor->id,
        ]));

        foreach ([
            ['code' => '70', 'parent_code' => null, 'sort_order' => 90],
            ['code' => '706', 'parent_code' => '70', 'sort_order' => 20],
            ['code' => '7061', 'parent_code' => '706', 'sort_order' => 1],
        ] as $row) {
            AdminTemplateAccount::withoutEvents(static fn (): AdminTemplateAccount => AdminTemplateAccount::query()->create([
                'template_id' => $source->id,
                'code' => $row['code'],
                'name' => 'Account '.$row['code'],
                'type' => 'expense',
                'parent_code' => $row['parent_code'],
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => $row['sort_order'],
            ]));
        }

        $clone = app(TemplatePublishingService::class)->cloneToDraft($source->id, 'Hierarchy clone', $actor);

        self::assertSame(
            [
                ['code' => '7061', 'parent_code' => '706', 'sort_order' => 1],
                ['code' => '706', 'parent_code' => '70', 'sort_order' => 20],
                ['code' => '70', 'parent_code' => null, 'sort_order' => 90],
            ],
            $clone->accounts()->orderBy('sort_order')->get(['code', 'parent_code', 'sort_order'])->toArray(),
        );
    }

    public function test_clone_keeps_a_legacy_numeric_self_parent_row_correctable(): void
    {
        $actor = $this->actor();
        $source = $this->publishedTemplate($actor);
        $account = $source->accounts()->firstOrFail();
        DB::connection($source->getConnectionName())->table('admin_template_accounts')
            ->where('id', $account->id)
            ->update(['parent_code' => $account->code]);

        $clone = app(TemplatePublishingService::class)->cloneToDraft($source->id, 'Correctable self-parent clone', $actor);

        self::assertSame('100', $clone->accounts()->sole()->parent_code);
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

    private function createAccount(AdminTemplate $template, string $code, int $sortOrder): AdminTemplateAccount
    {
        return AdminTemplateAccount::query()->create([
            'template_id' => $template->id,
            'code' => $code,
            'name' => 'Account '.$code,
            'type' => 'asset',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => $sortOrder,
        ]);
    }

    private function outsideCentralTransaction(callable $assertions): void
    {
        $connection = DB::connection((new AdminTemplate)->getConnectionName());
        $initialLevel = $connection->transactionLevel();
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        try {
            $assertions();
        } finally {
            $connection->table('country_template_assignments')->delete();
            $connection->table('admin_template_accounts')->delete();
            $connection->table('admin_templates')->delete();
            app(LegacyCoaBootstrapImporter::class)->importAll();
            self::assertSame(
                3,
                $connection->table('admin_templates')->whereNotNull('bootstrap_key')->count(),
                'Out-of-transaction guard cleanup must restore migration-owned bootstrap fixtures.',
            );
            while ($connection->transactionLevel() < $initialLevel) {
                $connection->beginTransaction();
            }
        }
    }
}
