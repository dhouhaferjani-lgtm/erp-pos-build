<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Application\DTOs\AuthUserData;
use App\Modules\Identity\Application\DTOs\LoginResponseData;
use App\Modules\Identity\Application\Services\EmailVerificationService;
use App\Modules\Identity\Domain\Device;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Requests\CheckEmailRequest;
use App\Modules\Identity\Presentation\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Presentation\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Requests\RegisterRequest;
use App\Modules\Identity\Presentation\Requests\ResetPasswordRequest;
use App\Modules\Identity\Presentation\Requests\VerifyEmailRequest;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * T1.4 — POS-tauri client header. The cashier's desktop app at
     * `apps/pos/src/lib/api.ts` sets this header on every outbound
     * request; when it lands on the login/register endpoint we issue
     * a 12-month token instead of relying on the default 30-day
     * sanctum.expiration policy. Web back-office tokens stay on the
     * default — they pass no header and fall through to NULL
     * `expires_at` (lifetime governed by config).
     */
    private const POS_CLIENT_TYPE_HEADER = 'X-Client-Type';

    private const POS_CLIENT_TYPE_VALUE = 'pos-tauri';

    /**
     * Tauri desktop targets — the runtime supports Windows, macOS, and
     * Linux. Browsers report `web` or omit the platform field, which
     * makes this list a useful additional spoofing barrier.
     */
    private const POS_DESKTOP_PLATFORMS = ['windows', 'macos', 'linux'];

    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
        private readonly TenantInitializationService $tenantInitializationService,
    ) {}

    /**
     * T1.4 — POS request triple-gate. Returns `true` only if the request
     * satisfies ALL THREE signals that mark it as a POS-tauri terminal:
     *
     *   1. `X-Client-Type: pos-tauri` (the literal value)
     *   2. A non-empty `device_id` in the login body (the Tauri client
     *      always sends one; web back-office requests do not)
     *   3. `platform` is a Tauri desktop target (windows / macos /
     *      linux) — browsers report `web` or omit the field
     *
     * Web back-office requests fail every gate (no header, no
     * device_id, no platform). The legitimate POS path passes all three.
     *
     * Both `tokenExpiresAt` (12-month per-row TTL) and `tokenAbilities`
     * (scoped `pos:*` instead of `*`) branch on the same gate so a
     * single signal classifies the request.
     *
     * **Accepted residual risk (Codex round-3 P2 carry from PR #96):**
     * every gate signal is client-controlled. A scripted attacker with
     * valid credentials can craft a request that satisfies all three
     * gates and obtain a POS-flavoured token. The activation hardening
     * plan §Phase 2 accepted this risk class — see
     * `docs/superpowers/plans/2026-04-30-pos-activation-hardening.md`
     * lines 105-107 — with two compensating controls already in place:
     *
     *   - **Server-side revocation:** admins revoke a token via
     *     Filament back-office; the next `checkSession()` call returns
     *     401 → forced logout.
     *   - **Per-shift re-validation:** POS calls `checkSession()` on
     *     every shift open, re-checking that the token is still in
     *     `personal_access_tokens` and the user is still active.
     *
     * **PR #101 follow-up (this method):** the ability scoping below
     * adds defense-in-depth on top of the gate. Even if the gate is
     * spoofed, the resulting POS-flavoured token carries `['pos:*']`
     * abilities only — any future route guarded by `auth:sanctum,*`
     * with a non-`pos:*` ability check will reject it. Web back-office
     * routes are the obvious target; today none of them check, but the
     * scoping is in place for when they do.
     *
     * **Long-term hardening (Path B from the PR #96 round-3 review):**
     * replace this with a dedicated terminal-pairing endpoint that
     * issues long tokens after a cryptographic device handshake —
     * server-verified trusted state instead of client-asserted intent.
     * Queued as a separate workstream.
     */
    private function isPosClient(Request $request): bool
    {
        if ($request->header(self::POS_CLIENT_TYPE_HEADER) !== self::POS_CLIENT_TYPE_VALUE) {
            return false;
        }

        $deviceId = $request->input('device_id');
        if (! is_string($deviceId) || $deviceId === '') {
            return false;
        }

        $platform = $request->input('platform');
        if (! is_string($platform) || ! in_array($platform, self::POS_DESKTOP_PLATFORMS, true)) {
            return false;
        }

        return true;
    }

    /**
     * T1.4 — choose token expiry based on the request's client signals.
     * Returns `null` for the default (web back-office) path so Sanctum's
     * per-request `sanctum.expiration` policy stays in charge, or a
     * concrete `now()->addYear()` for POS terminals where a stable
     * 12-month lifetime is required so cashiers don't see "Session
     * expired" mid-shift after monthly token churn.
     */
    private function tokenExpiresAt(Request $request): ?\DateTimeInterface
    {
        return $this->isPosClient($request) ? now()->addYear() : null;
    }

    /**
     * PR #101 follow-up to T1.4 — scope POS-issued tokens to `['pos:*']`
     * instead of the catch-all `['*']`. Defense-in-depth on top of the
     * triple-gate: a POS token cannot be used to access a web back-office
     * route guarded by `auth:sanctum,*` with a non-`pos:*` ability check.
     *
     * Web back-office tokens keep the historical `['*']` (catch-all)
     * because the back-office surface has no consistent ability scoping
     * yet — narrowing here would silently break unrelated endpoints.
     * When the back-office surface adopts ability checks, we can scope
     * those tokens too.
     *
     * @return array<int, string>
     */
    private function tokenAbilities(Request $request): array
    {
        return $this->isPosClient($request) ? ['pos:*'] : ['*'];
    }

    /**
     * Authenticate user and return token.
     *
     * Supports both session-based SPA auth and token-based API auth:
     * - SPA (stateful): Uses Auth::attempt() to establish session, token optional
     * - API (stateless): Returns token for Authorization header
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // First check if user exists and is active before attempting auth
        $user = User::where('email', $validated['email'])->first();

        if ($user !== null && ! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is not active. Please contact support.'],
            ]);
        }

        // Use Auth::attempt() to validate credentials AND establish session
        // This is required for Sanctum SPA cookie-based authentication
        if (! Auth::attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Regenerate session to prevent session fixation attacks
        $request->session()->regenerate();

        // Get the authenticated user (now properly loaded via Auth)
        /** @var User $user */
        $user = Auth::user();

        // Record login
        $user->recordLogin($request->ip() ?? 'unknown');

        // Handle device registration
        $device = $this->handleDevice($user, $validated);

        // Create token for mobile/API clients that need it
        // SPA clients will use the session cookie instead.
        // T1.4 — POS-tauri client gets a 12-month per-token expiry; web
        // back-office tokens fall through to NULL expires_at and use the
        // global sanctum.expiration policy. PR #101 follow-up: POS
        // tokens are also scoped to `['pos:*']` abilities so a spoofed
        // long token can't reach web back-office routes that may later
        // adopt ability checks.
        // Tenant-isolation Invariant D (master plan §15): prepend a
        // `tenant:<uuid>` ability so EnforceTokenTenantClaim can reject
        // stale tokens after a user's tenant_id changes.
        $tokenName = $validated['device_name'] ?? 'api-token';
        $token = $user->createToken(
            $tokenName,
            array_merge(['tenant:'.$user->tenant_id], $this->tokenAbilities($request)),
            $this->tokenExpiresAt($request),
        );

        $response = new LoginResponseData(
            user: AuthUserData::fromUser($user),
            token: $token->plainTextToken,
            tokenType: 'Bearer',
            deviceId: $device?->device_id,
        );

        return response()->json([
            'data' => $response,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Register a new user with tenant and company.
     *
     * Creates:
     * - Tenant (subscription account with trial plan)
     * - User (the person, linked to tenant)
     * - Company (the legal entity, linked to tenant)
     * - UserCompanyMembership (owner role)
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // T1.4 — compute the per-token expiry BEFORE entering the
        // transaction closure so the closure doesn't capture the Request.
        // POS-tauri client → 12-month token; default → NULL (use the
        // global sanctum.expiration policy). PR #101 follow-up: also
        // pre-compute the abilities (POS → `['pos:*']`, default → `['*']`)
        // so the closure stays Request-free.
        $tokenExpiresAt = $this->tokenExpiresAt($request);
        $tokenAbilities = $this->tokenAbilities($request);

        $result = DB::transaction(function () use ($validated, $tokenExpiresAt, $tokenAbilities) {
            // 1. Create Tenant (subscription account)
            $timezone = $validated['timezone'] ?? $this->getDefaultTimezone($validated['country_code']);
            $locale = $validated['locale'] ?? $this->getDefaultLocale($validated['country_code']);
            $dateFormat = 'd/m/Y';

            $tenant = Tenant::create([
                'name' => $validated['company_name'],
                'slug' => Str::slug($validated['company_name']).'-'.Str::random(6),
                'vertical' => $validated['vertical'], // Selected business vertical
                'enabled_extras' => [],
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'country_code' => strtoupper($validated['country_code']),
                'currency_code' => $validated['currency'] ?? $this->getDefaultCurrency($validated['country_code']),
                'timezone' => $timezone,
                'locale' => $locale,
                'date_format' => $dateFormat,
                'settings' => [
                    'timezone' => $timezone,
                    'locale' => $locale,
                    'date_format' => $dateFormat,
                    'fiscal_year_start' => '01-01',
                ],
                'trial_ends_at' => now()->addDays(14),
                'subscription_ends_at' => null,
            ]);

            // 2. Create User
            $user = User::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'status' => 'active',
                'email_verified_at' => null, // Requires email verification
                'preferences' => [],
            ]);

            // 3. Create Company (legal entity)
            $company = Company::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['company_name'],
                'legal_name' => $validated['company_legal_name'] ?? $validated['company_name'],
                'country_code' => strtoupper($validated['country_code']),
                'tax_id' => $validated['tax_id'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'currency' => $validated['currency'] ?? $this->getDefaultCurrency($validated['country_code']),
                'locale' => $validated['locale'] ?? $this->getDefaultLocale($validated['country_code']),
                'timezone' => $validated['timezone'] ?? $this->getDefaultTimezone($validated['country_code']),
                'date_format' => 'd/m/Y',
                'fiscal_year_start_month' => 1,
                'status' => CompanyStatus::Active,
                'is_headquarters' => true,

                // Address fields from signup form
                'address_street' => $validated['address_street'] ?? null,
                'address_city' => $validated['address_city'] ?? null,
                'address_postal_code' => $validated['address_postal_code'] ?? null,
                'address_state' => $validated['address_state'] ?? null,
                'email' => $validated['email'], // Use user email as company email
            ]);

            // 3.5. Create default location (always needed for operations)
            Location::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'name' => 'Main Location',
                'code' => 'MAIN',
                'type' => 'shop',
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => false,
                'address_street' => $company->address_street,
                'address_city' => $company->address_city,
                'address_postal_code' => $company->address_postal_code,
                'address_country' => $company->country_code,
                'phone' => $company->phone,
                'email' => $company->email,
            ]);

            // 4. Create UserCompanyMembership (owner role)
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Owner,
                'is_primary' => true,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]);

            // 5. Initialize tenant with country-specific data
            // This assigns the 'admin' Spatie role (for sidebar access) and seeds:
            // - Country-specific chart of accounts (Tunisia/France)
            // - Fiscal years and periods
            // - Standard payment methods
            $this->tenantInitializationService->initializeForNewRegistration(
                $tenant,
                $company,
                $user
            );

            // Handle device registration if provided
            $device = $this->handleDevice($user, $validated);

            // Create auth token. T1.4 — same per-client branching as
            // login: POS-tauri → 12mo + `['pos:*']` abilities, default →
            // NULL expiry + `['*']` abilities (global policy).
            // Tenant-isolation Invariant D (master plan §15): prepend a
            // `tenant:<uuid>` ability so EnforceTokenTenantClaim can reject
            // stale tokens after a user's tenant_id changes.
            $tokenName = $validated['device_name'] ?? 'api-token';
            $token = $user->createToken(
                $tokenName,
                array_merge(['tenant:'.$user->tenant_id], $tokenAbilities),
                $tokenExpiresAt,
            );

            return [
                'user' => $user,
                'company' => $company,
                'token' => $token->plainTextToken,
                'device' => $device,
            ];
        });

        // Send verification email asynchronously (after transaction)
        $this->emailVerificationService->sendVerificationEmail($result['user']);

        $response = new LoginResponseData(
            user: AuthUserData::fromUser($result['user']),
            token: $result['token'],
            tokenType: 'Bearer',
            deviceId: $result['device']?->device_id,
        );

        return response()->json([
            'data' => $response,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Check if an email is available for registration.
     *
     * Security note: this endpoint deliberately discloses email
     * existence (the registration UX needs to redirect users to login
     * rather than re-register). The enumeration risk is mitigated by
     * the dedicated `throttle:check-email` per-IP limiter (10/min) —
     * see RateLimiter::for('check-email', ...) in AppServiceProvider.
     * F.3 narrowed the limiter from `throttle:login` (which keys on
     * email-or-IP and is defeated by rotating emails) to a hard IP
     * cap.
     */
    #[CrossTenantRoute(reason: 'Pre-auth: email-availability lookup before registration; queries User::where(email) globally to detect any pre-existing account (any tenant) so the registration flow can present a "sign in" CTA instead of "register". Mounted public on the unauthenticated route group; no tenant context exists at call time. Existence-disclosure mitigated by F.3 dedicated per-IP rate limit.')]
    public function checkEmail(CheckEmailRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $exists = User::where('email', $validated['email'])->exists();

        return response()->json([
            'available' => ! $exists,
        ]);
    }

    /**
     * Log the user out.
     *
     * Handles both session-based and token-based logout.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Revoke all tokens for this user (covers both session-based and token-based auth)
        $user->tokens()->delete();

        // Invalidate and regenerate session for SPA auth
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'data' => ['message' => 'Successfully logged out'],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Log the user out from all devices.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Revoke all tokens
        $user->tokens()->delete();

        // Deactivate all devices
        $user->devices()->update(['is_active' => false]);

        return response()->json([
            'data' => ['message' => 'Successfully logged out from all devices'],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => AuthUserData::fromUser($user),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Verify user's email address.
     */
    #[CrossTenantRoute(reason: 'Pre-auth: email-verification token redemption from the verification link emailed at registration; resolves the user via the signed token (EmailVerificationService) before any session/tenant context exists. Token-bearer is the implicit subject; no Sanctum auth at this entry point.')]
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->emailVerificationService->verifyEmail($validated['token']);

        if (! $result['success']) {
            return response()->json([
                'error' => [
                    'code' => 'VERIFICATION_FAILED',
                    'message' => __('auth.'.$this->getTranslationKey($result['message'])),
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 400);
        }

        return response()->json([
            'data' => ['message' => __('auth.verify_email_success')],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Resend verification email to the authenticated user.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->emailVerificationService->resendVerificationEmail($user);

        if (! $result['success']) {
            return response()->json([
                'error' => [
                    'code' => 'RESEND_FAILED',
                    'message' => __('auth.'.$this->getTranslationKey($result['message'])),
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 400);
        }

        return response()->json([
            'data' => ['message' => __('auth.verify_email_sent')],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Send a password reset link to the given email.
     */
    #[CrossTenantRoute(reason: 'Pre-auth: password-reset request — accepts an email and dispatches a Password::sendResetLink (Laravel password broker) which finds the user globally by email and emails a signed reset token. No session/tenant context exists at call time.')]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            Password::sendResetLink(['email' => $validated['email']]);
        } catch (\Throwable) {
            // Silently handle errors (e.g., missing route, mail config) to prevent email enumeration
        }

        // Always return success to prevent email enumeration
        return response()->json([
            'data' => ['message' => 'If an account exists with that email, a password reset link has been sent.'],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Reset the user's password using a valid token.
     */
    #[CrossTenantRoute(reason: 'Pre-auth: password-reset token redemption — verifies the signed reset token via Laravel\'s Password broker, updates the user\'s password, and invalidates remember tokens. Token-bearer is the implicit subject; no Sanctum auth at this entry point.')]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->update([
                    'password' => Hash::make($password),
                ]);

                // Revoke all existing tokens for security
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'data' => ['message' => 'Password has been reset successfully.'],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Map error messages to translation keys.
     */
    private function getTranslationKey(string $message): string
    {
        return match ($message) {
            'Invalid verification token.' => 'verify_email_invalid_token',
            'Verification token has expired. Please request a new one.' => 'verify_email_expired_token',
            'Email is already verified.' => 'verify_email_already_verified',
            default => 'verify_email_invalid_token',
        };
    }

    /**
     * Handle device registration/update during login.
     *
     * @param  array<string, mixed>  $validated
     */
    private function handleDevice(User $user, array $validated): ?Device
    {
        $deviceId = $validated['device_id'] ?? null;
        $deviceName = $validated['device_name'] ?? null;

        if ($deviceId === null && $deviceName === null) {
            return null;
        }

        $deviceData = [
            'name' => $deviceName ?? 'Unknown Device',
            'type' => $this->detectDeviceType($validated['platform'] ?? null),
            'platform' => $validated['platform'] ?? null,
            'platform_version' => $validated['platform_version'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'last_used_at' => now(),
            'is_active' => true,
        ];

        if ($deviceId !== null) {
            $device = $user->devices()->where('device_id', $deviceId)->first();
            if ($device !== null) {
                $device->update($deviceData);

                return $device;
            }

            $deviceData['device_id'] = $deviceId;
        }

        return $user->devices()->create($deviceData);
    }

    /**
     * Detect device type from platform.
     */
    private function detectDeviceType(?string $platform): string
    {
        return match ($platform) {
            'ios', 'android' => 'mobile',
            'windows', 'macos', 'linux' => 'desktop',
            'web' => 'desktop',
            default => 'desktop',
        };
    }

    /**
     * Get default currency for a country code from the countries table.
     */
    private function getDefaultCurrency(string $countryCode): string
    {
        /** @var Country|null $country */
        $country = Country::find(strtoupper($countryCode));

        return $country->currency_code ?? 'EUR';
    }

    /**
     * Get default timezone for a country code from the countries table.
     */
    private function getDefaultTimezone(string $countryCode): string
    {
        /** @var Country|null $country */
        $country = Country::find(strtoupper($countryCode));

        return $country->default_timezone ?? 'UTC';
    }

    /**
     * Get default locale for a country code from the countries table.
     *
     * Returns the short locale (e.g., 'fr' from 'fr_TN') for i18n compatibility.
     */
    private function getDefaultLocale(string $countryCode): string
    {
        /** @var Country|null $country */
        $country = Country::find(strtoupper($countryCode));
        $locale = $country->default_locale ?? null;

        if ($locale === null) {
            return 'en';
        }

        // Return short locale (e.g., 'fr' from 'fr_TN') for i18n compatibility
        return explode('_', $locale)[0];
    }
}
