<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\SuperAdmin;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Presentation\Controllers\OnboardingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUG-005 / RCA B2 — `OnboardingController::status()` called
 * `CompanyContext::requireCompanyId()`, which throws a bare \RuntimeException
 * when no company context is bound.
 *
 * `CompanyContextMiddleware` deliberately skips non-`User` principals
 * (super-admin guard), so on that path the controller runs with an empty
 * context and the RuntimeException falls through every typed render callback →
 * an unhandled 500 with no diagnosable error code.
 *
 * The controller must answer the same 403 `NO_COMPANY_ACCESS` envelope the
 * middleware emits for a company-less user.
 */
final class OnboardingStatusNoCompanyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_returns_403_no_company_access_when_context_is_empty(): void
    {
        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->clear();

        $controller = new OnboardingController(
            new OnboardingChecklistService,
            $context,
        );

        $response = $controller->status(Request::create('/api/v1/onboarding/status', 'GET'));

        self::assertSame(403, $response->getStatusCode(), 'An empty company context must be a typed 403, not a 500');

        /** @var array{error: array{code: string, message: string}} $payload */
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame('NO_COMPANY_ACCESS', $payload['error']['code']);
        self::assertNotSame('', $payload['error']['message']);
    }

    public function test_super_admin_principal_never_500s_on_the_onboarding_endpoint(): void
    {
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Onboarding Probe Super Admin',
            'email' => 'onboarding-probe-superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/onboarding/status');

        self::assertLessThan(
            500,
            $response->getStatusCode(),
            'A super-admin principal (CompanyContextMiddleware skips it) must never 500 this endpoint'
        );
        $response->assertJsonStructure(['error' => ['code']]);
    }
}
