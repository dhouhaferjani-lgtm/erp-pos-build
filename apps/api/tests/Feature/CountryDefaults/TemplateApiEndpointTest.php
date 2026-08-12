<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TemplateApiEndpointTest extends TestCase
{
    use RefreshDatabase;

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
}
