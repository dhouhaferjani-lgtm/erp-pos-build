<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Identity\EnforceTokenTenantClaimTest;
use Tests\TestCase;

/**
 * W2-1 / LEDGER C-13(iii) — `CompanyContextMiddleware` error contract.
 *
 * Two independent guarantees the SPA depends on:
 *
 * 1. A client that holds a STALE company selection (another account's company,
 *    persisted origin-wide under `autoerp-company-selection`) must get a
 *    machine-readable 403 it can key on to reset its selection and re-bootstrap
 *    — not an opaque failure. Campaign wave 2, defect W2-1: a fresh signup on a
 *    browser that already knew another company 403'd on EVERY authenticated
 *    call with no in-product recovery.
 *
 * 2. A MALFORMED `X-Company-Id` (anything that is not a UUID) must never reach
 *    the `company_id` uuid predicate. On PostgreSQL an unparsable uuid literal
 *    raises SQLSTATE 22P02 and the request 500s — LEDGER C-13(iii). The header
 *    is client-supplied, so this is a 400-class client error and must be typed.
 *
 * The probe route mounts the middleware directly (mirroring
 * {@see EnforceTokenTenantClaimTest}) so the assertions
 * isolate the middleware's own contract from unrelated `api`-group concerns.
 */
final class CompanyContextMiddlewareTypedErrorsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Company $memberCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->memberCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->memberCompany->id,
            'role' => 'admin',
            'status' => MembershipStatus::Active->value,
            'is_primary' => true,
        ]);

        Route::middleware(['auth:sanctum', SetPermissionsTeam::class, CompanyContextMiddleware::class])
            ->get('/_test/company-context-probe', fn () => response()->json(['ok' => true]));
    }

    public function test_valid_company_header_passes_and_echoes_the_bound_company(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->memberCompany->id)
            ->getJson('/_test/company-context-probe');

        $response->assertOk();
        $this->assertSame($this->memberCompany->id, $response->headers->get('X-Company-Id'));
    }

    public function test_company_the_user_is_not_a_member_of_returns_a_typed_403(): void
    {
        $foreign = Company::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $foreign->id)
            ->getJson('/_test/company-context-probe');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');
    }

    public function test_user_with_no_membership_at_all_returns_a_typed_403(): void
    {
        $orphan = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($orphan, 'sanctum')
            ->getJson('/_test/company-context-probe');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'NO_COMPANY_ACCESS');
    }

    /**
     * LEDGER C-13(iii) — the header is client-supplied and must be shape-checked
     * BEFORE it is used as a uuid predicate. Without the guard this is a 500 on
     * PostgreSQL (SQLSTATE 22P02) and a misleading COMPANY_ACCESS_DENIED on
     * SQLite; both are wrong, and neither tells the client its selection is junk.
     */
    #[DataProvider('malformedCompanyIdProvider')]
    public function test_malformed_company_id_header_returns_a_typed_400_and_never_a_500(string $malformed): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $malformed)
            ->getJson('/_test/company-context-probe');

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'INVALID_COMPANY_ID');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCompanyIdProvider(): array
    {
        return [
            'plain word' => ['not-a-uuid'],
            'sql fragment' => ["' OR 1=1 --"],
            'truncated uuid' => ['01a034af-94ea-713d-8ce0'],
            'numeric' => ['12345'],
            'uuid with trailing junk' => ['01a034af-94ea-713d-8ce0-462216bf6ab5x'],
        ];
    }

    /**
     * Defense in depth for C-13(iii): the service itself must not hand a
     * non-uuid to the `company_id` predicate, whichever caller reaches it
     * (`OwnerReportScope` also calls this with a request-derived id).
     */
    public function test_user_has_access_to_company_is_false_for_a_malformed_company_id(): void
    {
        $context = new CompanyContext;

        $this->assertFalse($context->userHasAccessToCompany($this->user, 'not-a-uuid'));
        $this->assertFalse($context->userHasAccessToCompany($this->user, ''));
        $this->assertTrue($context->userHasAccessToCompany($this->user, $this->memberCompany->id));
    }
}
