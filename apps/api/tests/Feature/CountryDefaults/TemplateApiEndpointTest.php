<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\AdminAuditLog;
use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\CountryDefaults\Application\DTOs\TemplateValidationErrorData;
use App\Modules\CountryDefaults\Application\DTOs\TemplateValidationReportData;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use Tests\TestCase;

final class TemplateApiEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_report_wire_contract_uses_stable_codes_and_context(): void
    {
        $report = new TemplateValidationReportData(
            valid: false,
            scope: ['TN'],
            errors: [new TemplateValidationErrorData(
                code: 'missing_required_purpose',
                parameters: ['purpose' => 'supplier_payable'],
            )],
        );

        self::assertSame([
            'valid' => false,
            'scope' => ['TN'],
            'errors' => [[
                'code' => 'missing_required_purpose',
                'parameters' => ['purpose' => 'supplier_payable'],
            ]],
        ], $report->toArray());
    }

    public function test_template_api_exercises_draft_rows_validation_publish_clone_archive_and_delete_lifecycle(): void
    {
        $actor = $this->admin();
        $created = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'French Plan',
            'description' => 'Initial draft',
        ])->assertCreated();
        $id = $created->json('data.id');
        self::assertIsString($id);

        $this->actingAs($actor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/templates?domain=chart_of_accounts')
            ->assertOk()->assertJsonFragment(['id' => $id]);
        $this->actingAs($actor, 'sanctum-admin')->getJson("/api/v1/admin/country-defaults/templates/{$id}")
            ->assertOk()->assertJsonPath('data.name', 'French Plan');
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$id}", [
            'name' => 'French Plan v1',
            'description' => 'Reviewed draft',
            'standard_ref' => 'PCG preview',
        ])->assertOk()->assertJsonPath('data.name', 'French Plan v1');

        $rows = $this->validRows('FR');
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$id}/rows", [
            'rows' => $rows,
        ])->assertOk()->assertJsonCount(count($rows), 'data.rows');

        $persistedRows = $this->actingAs($actor, 'sanctum-admin')
            ->getJson("/api/v1/admin/country-defaults/templates/{$id}")
            ->json('data.rows');
        self::assertIsArray($persistedRows);
        $persistedRows[0]['name'] = 'Updated through row upsert';
        [$persistedRows[0]['sort_order'], $persistedRows[1]['sort_order']] = [
            $persistedRows[1]['sort_order'],
            $persistedRows[0]['sort_order'],
        ];
        $persistedRows[] = [
            'code' => 'TEMP',
            'name' => 'Temporary bulk row',
            'type' => 'asset',
            'parent_code' => null,
            'system_purpose' => null,
            'is_system' => false,
            'sort_order' => count($persistedRows) + 1,
        ];
        $upserted = $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$id}/rows", [
            'rows' => $persistedRows,
        ])->assertOk();
        $upserted->assertJsonFragment(['name' => 'Updated through row upsert']);
        $upsertedRows = $upserted->json('data.rows');
        self::assertIsArray($upsertedRows);
        $withoutTemporary = array_values(array_filter(
            $upsertedRows,
            static fn (array $row): bool => $row['code'] !== 'TEMP',
        ));
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$id}/rows", [
            'rows' => $withoutTemporary,
        ])->assertOk()->assertJsonMissing(['code' => 'TEMP']);

        $this->actingAs($actor, 'sanctum-admin')->getJson("/api/v1/admin/country-defaults/templates/{$id}/validation?scope=FR")
            ->assertOk()->assertJsonPath('data.valid', true);
        $this->actingAs($actor, 'sanctum-admin')->getJson("/api/v1/admin/country-defaults/templates/{$id}/validation")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.scope', []);

        $this->actingAs($actor, 'sanctum-admin')->postJson("/api/v1/admin/country-defaults/templates/{$id}/publish", [
            'standard_ref' => 'PCG 2026',
            'certified_country_codes' => ['FR'],
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$id}", ['name' => 'Illegal'])
            ->assertConflict();

        $clone = $this->actingAs($actor, 'sanctum-admin')->postJson("/api/v1/admin/country-defaults/templates/{$id}/clone", [
            'name' => 'French Plan v2',
        ])->assertCreated();
        $cloneId = $clone->json('data.id');
        self::assertIsString($cloneId);
        $this->actingAs($actor, 'sanctum-admin')->deleteJson("/api/v1/admin/country-defaults/templates/{$cloneId}")
            ->assertNoContent();

        $this->actingAs($actor, 'sanctum-admin')->postJson("/api/v1/admin/country-defaults/templates/{$id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_template_requests_reject_invalid_enums_uuids_and_publish_failures_as_intentional_4xx(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'tax_rates',
            'name' => 'Invalid',
        ])->assertUnprocessable();
        $this->actingAs($actor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/templates/not-a-uuid')
            ->assertUnprocessable();

        $draft = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'Empty',
        ])->json('data.id');
        self::assertIsString($draft);
        $this->actingAs($actor, 'sanctum-admin')->postJson("/api/v1/admin/country-defaults/templates/{$draft}/publish", [
            'standard_ref' => 'PCG',
            'certified_country_codes' => ['FR'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'TEMPLATE_VALIDATION_FAILED');
    }

    public function test_validation_report_exposes_the_failed_rule_and_rejects_invalid_scope_algebra(): void
    {
        $actor = $this->admin();
        $draft = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'Invalid preview',
        ])->assertCreated()->json('data.id');
        self::assertIsString($draft);

        $this->actingAs($actor, 'sanctum-admin')
            ->getJson("/api/v1/admin/country-defaults/templates/{$draft}/validation")
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.errors.0.code', 'account_rows_required')
            ->assertJsonPath('data.errors.0.parameters', []);

        $this->actingAs($actor, 'sanctum-admin')
            ->getJson("/api/v1/admin/country-defaults/templates/{$draft}/validation?scope=")
            ->assertOk()
            ->assertJsonPath('data.scope', []);

        $this->actingAs($actor, 'sanctum-admin')
            ->getJson("/api/v1/admin/country-defaults/templates/{$draft}/validation?scope=TN,%20FR")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.errors.scope.0', 'Exact certification scope cannot mix timbre and non-timbre countries.');
    }

    public function test_unexpected_template_logic_exception_is_not_mislabeled_as_lifecycle_conflict(): void
    {
        $actor = $this->admin();
        $draft = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'Invariant probe',
        ])->assertCreated()->json('data.id');
        self::assertIsString($draft);
        AdminTemplate::updating(static function (AdminTemplate $template) use ($draft): void {
            if ($template->id === $draft) {
                throw new LogicException('country_defaults_unexpected_logic_failure');
            }
        });
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('country_defaults_unexpected_logic_failure');
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$draft}", [
            'name' => 'Must not be reported as conflict',
        ]);
    }

    public function test_unrelated_template_row_query_exception_is_rethrown(): void
    {
        $actor = $this->admin();
        $draft = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'Row infrastructure probe',
        ])->assertCreated()->json('data.id');
        self::assertIsString($draft);
        $connection = DB::connection((new AdminTemplate)->getConnectionName());
        $this->installUnrelatedTemplateRowFailure($connection->getDriverName());
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$draft}/rows", [
                'rows' => [[
                    'code' => '1000',
                    'name' => 'Infrastructure probe',
                    'type' => 'asset',
                    'parent_code' => null,
                    'system_purpose' => null,
                    'is_system' => false,
                    'sort_order' => 1,
                ]],
            ]);
            self::fail('An unrelated template-row storage failure must propagate as QueryException.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('country_defaults_rows_unrelated_failure', $exception->getMessage());
        } finally {
            $this->removeUnrelatedTemplateRowFailure($connection->getDriverName());
        }
    }

    public function test_sqlite_shaped_audit_foreign_key_failure_is_rethrown_from_row_update(): void
    {
        $actor = $this->admin();
        $draft = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'Audit foreign-key probe',
        ])->assertCreated()->json('data.id');
        self::assertIsString($draft);
        AdminAuditLog::creating(static function (): never {
            $previous = new PDOException('FOREIGN KEY constraint failed', 23000);
            $previous->errorInfo = ['23000', 19, 'FOREIGN KEY constraint failed'];

            throw new QueryException(
                'sqlite',
                'insert into "admin_audit_logs" ("super_admin_id") values (?)',
                ['missing-admin'],
                $previous,
            );
        });
        $this->withoutExceptionHandling();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$draft}/rows", [
            'rows' => [[
                'code' => '1000',
                'name' => 'Audit foreign-key probe',
                'type' => 'asset',
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => 1,
            ]],
        ]);
    }

    public function test_sqlite_unique_constraint_requires_an_exact_template_row_column_list(): void
    {
        $actor = $this->admin();
        $draft = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/templates', [
            'domain' => 'chart_of_accounts',
            'name' => 'Unique signature probe',
        ])->assertCreated()->json('data.id');
        self::assertIsString($draft);
        AdminAuditLog::creating(static function (): never {
            $message = 'UNIQUE constraint failed: admin_template_accounts.template_id, admin_template_accounts.code_shadow';
            $previous = new PDOException($message, 23000);
            $previous->errorInfo = ['23000', 19, $message];

            throw new QueryException(
                'sqlite',
                'insert into "admin_audit_logs" ("super_admin_id") values (?)',
                ['missing-admin'],
                $previous,
            );
        });
        $this->withoutExceptionHandling();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('admin_template_accounts.code_shadow');
        $this->actingAs($actor, 'sanctum-admin')->putJson("/api/v1/admin/country-defaults/templates/{$draft}/rows", [
            'rows' => [[
                'code' => '1000',
                'name' => 'Unique signature probe',
                'type' => 'asset',
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => 1,
            ]],
        ]);
    }

    /** @return list<array<string, bool|int|string|null>> */
    private function validRows(string $countryCode): array
    {
        $rows = [];
        $sort = 1;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            $rows[] = [
                'code' => sprintf('R%03d', $sort),
                'name' => $entry['purpose']->name,
                'type' => $entry['purpose']->expectedAccountType()->value,
                'parent_code' => null,
                'system_purpose' => $entry['purpose']->value,
                'is_system' => true,
                'sort_order' => $sort++,
            ];
        }
        foreach (ProtectedAccountCodeRegistry::forCountry($countryCode) as $protected) {
            $rows[] = [
                'code' => $protected['code'],
                'name' => 'Protected '.$protected['code'],
                'type' => AccountType::from($protected['expected_type'])->value,
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => $protected['requires_system'],
                'sort_order' => $sort++,
            ];
        }

        return $rows;
    }

    private function admin(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Template operator',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function installUnrelatedTemplateRowFailure(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION country_defaults_rows_unrelated_failure() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'country_defaults_rows_unrelated_failure' USING ERRCODE = '57014';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER country_defaults_rows_unrelated_failure
                BEFORE INSERT ON admin_template_accounts
                FOR EACH ROW EXECUTE FUNCTION country_defaults_rows_unrelated_failure();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER country_defaults_rows_unrelated_failure
            BEFORE INSERT ON admin_template_accounts
            BEGIN
                SELECT RAISE(ABORT, 'country_defaults_rows_unrelated_failure');
            END;
            SQL);
    }

    private function removeUnrelatedTemplateRowFailure(string $driver): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS country_defaults_rows_unrelated_failure'.($driver === 'pgsql' ? ' ON admin_template_accounts' : ''));
        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS country_defaults_rows_unrelated_failure()');
        }
    }
}
