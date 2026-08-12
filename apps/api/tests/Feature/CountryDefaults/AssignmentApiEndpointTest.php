<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssignmentApiEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_api_returns_country_matrix_normalizes_and_repoints(): void
    {
        $actor = $this->admin();
        $first = $this->published('FR', $actor);
        $second = $this->published('FR', $actor);

        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/fr', [
            'domain' => 'chart_of_accounts',
            'template_id' => $first->id,
        ])->assertOk()->assertJsonPath('data.country_code', 'FR');
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $second->id,
        ])->assertOk()->assertJsonPath('data.template_id', $second->id);

        $this->actingAs($actor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/assignments?domain=chart_of_accounts')
            ->assertOk()
            ->assertJsonPath('meta.catalog_version', 'iso-3166-1-alpha-2-2024')
            ->assertJsonFragment(['country_code' => '*', 'pinned' => true])
            ->assertJsonFragment(['country_code' => 'FR', 'template_id' => $second->id]);
    }

    public function test_assignment_api_enforces_status_scope_domain_wildcard_and_typed_conflicts(): void
    {
        $actor = $this->admin();
        $fr = $this->published('FR', $actor);
        $draft = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Draft assignment target',
            'status' => TemplateStatus::Draft,
        ]);

        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/DE', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_CONFLICT');
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $draft->id,
        ])->assertConflict();
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/not-a-country', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])->assertUnprocessable();

        $wildcard = $this->published('*', $actor);
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/*', [
            'domain' => 'chart_of_accounts',
            'template_id' => $wildcard->id,
        ])->assertOk();
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/*', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])
            ->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_CONFLICT');

        DB::connection($fr->getConnectionName())->table('admin_templates')->where('id', $fr->id)->update(['domain' => 'other']);
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])->assertConflict();
    }

    private function published(string $scope, SuperAdmin $actor): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Published '.$scope.' '.Str::random(6),
            'status' => TemplateStatus::Draft,
        ]);
        DB::connection($template->getConnectionName())->transaction(function () use ($template, $scope): void {
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
            foreach (ProtectedAccountCodeRegistry::forCountry($scope) as $protected) {
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
        });

        return app(TemplatePublishingService::class)->publish($template->id, 'Standard 2026', [$scope], $actor);
    }

    private function admin(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Assignment operator',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
