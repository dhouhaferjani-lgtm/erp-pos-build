<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Notifications\UserInvitation;
use App\Modules\Identity\Application\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Application\Services\EmailVerificationService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Application\Services\TenantLinkSigner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantQualifiedLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_signer_round_trips_and_rejects_tampering(): void
    {
        $signer = app(TenantLinkSigner::class);
        $tenantId = '019e0000-0000-7000-8000-000000000abc';

        $signed = $signer->sign($tenantId);

        $this->assertNotSame($tenantId, $signed, 'qualifier must be opaque, not the raw id');
        $this->assertSame($tenantId, $signer->extract($signed));
        $this->assertNull($signer->extract($signed.'tampered'));
        $this->assertNull($signer->extract(null));
        $this->assertNull($signer->extract(''));
    }

    public function test_verify_email_notification_link_carries_the_signed_tenant(): void
    {
        [$tenant, $user] = $this->makeUser('vn', 'vn@example.com');

        $signed = app(TenantLinkSigner::class)->sign($tenant->id);
        $mail = (new VerifyEmailNotification('a'.str_repeat('b', 63), $signed))->toMail($user);

        $this->assertStringContainsString('tenant=', $mail->actionUrl);
        $this->assertSame($tenant->id, $this->extractTenantFromUrl($mail->actionUrl));
    }

    public function test_send_verification_email_signs_the_users_tenant(): void
    {
        Notification::fake();
        [$tenant, $user] = $this->makeUser('sv', 'sv@example.com');

        app(EmailVerificationService::class)->sendVerificationEmail($user);

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user, $tenant) {
            $url = $n->toMail($user)->actionUrl;

            return $this->extractTenantFromUrl($url) === $tenant->id;
        });
    }

    public function test_user_invitation_link_carries_the_signed_tenant(): void
    {
        [$tenant, $admin] = $this->makeTenantWithAdmin();
        Notification::fake();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'role' => 'operator',
        ])->assertCreated();

        $invitee = User::where('email', 'invitee@example.com')->firstOrFail();

        Notification::assertSentTo($invitee, UserInvitation::class, function (UserInvitation $n) use ($invitee, $tenant) {
            $url = $n->toMail($invitee)->actionUrl;

            return $this->extractTenantFromUrl($url) === $tenant->id;
        });
    }

    public function test_verify_email_endpoint_accepts_a_tenant_qualifier(): void
    {
        [$tenant, $user] = $this->makeUser('ve', 've@example.com');
        $service = app(EmailVerificationService::class);
        // Create a real token by sending (faked) then reading it from the DB.
        Notification::fake();
        $service->sendVerificationEmail($user);
        $token = \App\Modules\Identity\Domain\EmailVerificationToken::where('user_id', $user->id)->firstOrFail()->token;

        $signed = app(TenantLinkSigner::class)->sign($tenant->id);

        $response = $this->postJson('/api/v1/auth/verify-email', [
            'token' => $token,
            'tenant' => $signed,
        ]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_forgot_password_sends_reset_link_with_signed_tenant(): void
    {
        Notification::fake();
        [$tenant, $user] = $this->makeUser('fp', 'fp@example.com');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'fp@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, \App\Modules\Identity\Application\Notifications\ResetPasswordNotification::class, function ($n) use ($user, $tenant) {
            $url = $n->toMail($user)->actionUrl;

            return $this->extractTenantFromUrl($url) === $tenant->id;
        });
    }

    private function extractTenantFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);
        $tenant = $params['tenant'] ?? null;

        return is_string($tenant) ? app(TenantLinkSigner::class)->extract($tenant) : null;
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeUser(string $slug, string $email): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        app(IdentityIndexService::class)->record($email, $tenant->id, $user->id);

        return [$tenant, $user];
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeTenantWithAdmin(): array
    {
        [$tenant, $admin] = $this->makeUser('inv', 'admin-inv@example.com');

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin->assignRole('admin');

        $company = \App\Modules\Company\Domain\Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inv Co',
            'legal_name' => 'Inv Co',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        \App\Modules\Company\Domain\UserCompanyMembership::create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
            'role' => \App\Modules\Company\Domain\Enums\MembershipRole::Owner,
            'is_primary' => true,
            'status' => \App\Modules\Company\Domain\Enums\MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return [$tenant, $admin];
    }
}
