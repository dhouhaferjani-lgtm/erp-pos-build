<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImpersonationResponseMaskingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $subject;

    private SuperAdmin $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ResolveTenancy::class, CompanyContextMiddleware::class]);
        $this->tenant = Tenant::factory()->create();
        $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operator = $this->superAdmin('operator@example.test');

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('products.view', 'sanctum');
        $this->subject->givePermissionTo('products.view');
        config()->set('support_access.permissions.read_only', ['products.view']);

        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-mask', static fn () => response()->json([
            'data' => [
                'password' => 'hunter2',
                'accessToken' => 'token-value',
                'client_secret' => 'secret-value',
                'api-key' => 'api-key-value',
                'cvv' => '123',
                'card_number' => '4111111111114242',
                'iban' => 'TN5910006035183598478831',
                'nationalId' => '01234567',
                'bank_account' => '0099887766554433',
                'last_four' => '4242',
                'nested' => [['authorization_token' => 'nested-token', 'name' => 'Visible']],
            ],
        ]))->name('test.impersonation-mask');

        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get(
            '/_test/impersonation-export',
            static fn () => response('bank-export-bytes', 200, ['Content-Type' => 'text/csv']),
        )->name('test.impersonation-export');
    }

    public function test_impersonated_json_is_recursively_redacted_with_only_last_four_preserved(): void
    {
        $grant = $this->grant();
        $started = $this->app->make(SessionLifecycleService::class)
            ->start($this->operator, $grant->id, $this->subject->id);

        $this->withToken($started->plain_text_token)->getJson('/_test/impersonation-mask')
            ->assertOk()
            ->assertJsonPath('data.password', '[REDACTED]')
            ->assertJsonPath('data.accessToken', '[REDACTED]')
            ->assertJsonPath('data.client_secret', '[REDACTED]')
            ->assertJsonPath('data.api-key', '[REDACTED]')
            ->assertJsonPath('data.cvv', '[REDACTED]')
            ->assertJsonPath('data.card_number', '••••4242')
            ->assertJsonPath('data.iban', '••••8831')
            ->assertJsonPath('data.nationalId', '••••4567')
            ->assertJsonPath('data.bank_account', '••••4433')
            ->assertJsonPath('data.last_four', '4242')
            ->assertJsonPath('data.nested.0.authorization_token', '[REDACTED]')
            ->assertJsonPath('data.nested.0.name', 'Visible');
    }

    public function test_ordinary_json_is_unchanged(): void
    {
        $token = $this->subject->createToken('ordinary', ['tenant:'.$this->tenant->id])->plainTextToken;

        $this->withToken($token)->getJson('/_test/impersonation-mask')
            ->assertOk()
            ->assertJsonPath('data.password', 'hunter2')
            ->assertJsonPath('data.card_number', '4111111111114242')
            ->assertJsonPath('data.nested.0.authorization_token', 'nested-token');
    }

    public function test_non_json_responses_are_blocked_during_impersonation_but_not_for_ordinary_tokens(): void
    {
        $started = $this->app->make(SessionLifecycleService::class)
            ->start($this->operator, $this->grant()->id, $this->subject->id);

        $this->withToken($started->plain_text_token)->get('/_test/impersonation-export')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IMPERSONATION_EXPORT_BLOCKED')
            ->assertDontSee('bank-export-bytes');

        $ordinary = $this->subject->createToken('ordinary', ['tenant:'.$this->tenant->id])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($ordinary)->get('/_test/impersonation-export')
            ->assertOk()
            ->assertSee('bank-export-bytes');
    }

    public function test_no_generic_reveal_endpoint_is_exposed(): void
    {
        $this->getJson('/api/v1/support-access/reveal')->assertNotFound();
        $this->postJson('/api/v1/support-access/reveal')->assertNotFound();
    }

    private function grant(): ImpersonationGrant
    {
        return ImpersonationGrant::query()->create([
            'tenant_id' => $this->tenant->id,
            'subject_user_id' => $this->subject->id,
            'operator_id' => $this->operator->id,
            'type' => GrantType::PerIncident,
            'status' => GrantStatus::Active,
            'reason' => 'Investigate masked payload',
            'ticket_ref' => 'SUP-8301',
            'requested_at' => now()->subMinute(),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHours(2),
            'tenant_approved_by' => Str::uuid()->toString(),
            'tenant_approved_at' => now()->subMinute(),
        ]);
    }

    private function superAdmin(string $email): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
