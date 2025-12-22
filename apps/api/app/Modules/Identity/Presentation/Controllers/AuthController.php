<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Application\DTOs\AuthUserData;
use App\Modules\Identity\Application\DTOs\LoginResponseData;
use App\Modules\Identity\Application\Services\EmailVerificationService;
use App\Modules\Identity\Domain\Device;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Requests\RegisterRequest;
use App\Modules\Identity\Presentation\Requests\VerifyEmailRequest;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    /**
     * Authenticate user and return token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is not active. Please contact support.'],
            ]);
        }

        // Record login
        $user->recordLogin($request->ip() ?? 'unknown');

        // Handle device registration
        $device = $this->handleDevice($user, $validated);

        // Create token with device name
        $tokenName = $validated['device_name'] ?? 'api-token';
        $token = $user->createToken($tokenName, ['*']);

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

        $result = DB::transaction(function () use ($validated) {
            // 1. Create Tenant (subscription account)
            $tenant = Tenant::create([
                'name' => $validated['company_name'],
                'slug' => Str::slug($validated['company_name']).'-'.Str::random(6),
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'country_code' => strtoupper($validated['country_code']),
                'currency_code' => $validated['currency'] ?? $this->getDefaultCurrency($validated['country_code']),
                'settings' => [
                    'timezone' => $validated['timezone'] ?? $this->getDefaultTimezone($validated['country_code']),
                    'locale' => $validated['locale'] ?? $this->getDefaultLocale($validated['country_code']),
                    'date_format' => 'd/m/Y',
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

            // Handle device registration if provided
            $device = $this->handleDevice($user, $validated);

            // Create auth token
            $tokenName = $validated['device_name'] ?? 'api-token';
            $token = $user->createToken($tokenName, ['*']);

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
     * Log the user out.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Revoke current token
        $user->currentAccessToken()->delete();

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
     * Get default currency for a country code.
     */
    private function getDefaultCurrency(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'GR' => 'EUR',
            'GB' => 'GBP',
            'US' => 'USD',
            'TN' => 'TND',
            'MA' => 'MAD',
            'DZ' => 'DZD',
            'SA', 'AE', 'QA', 'KW', 'BH', 'OM' => 'SAR',
            default => 'EUR',
        };
    }

    /**
     * Get default timezone for a country code.
     */
    private function getDefaultTimezone(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT' => 'Europe/Paris',
            'GB', 'IE', 'PT' => 'Europe/London',
            'US' => 'America/New_York',
            'TN' => 'Africa/Tunis',
            'MA' => 'Africa/Casablanca',
            'DZ' => 'Africa/Algiers',
            'SA' => 'Asia/Riyadh',
            'AE' => 'Asia/Dubai',
            default => 'UTC',
        };
    }

    /**
     * Get default locale for a country code.
     */
    private function getDefaultLocale(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'FR', 'BE', 'TN', 'MA', 'DZ' => 'fr',
            'DE', 'AT' => 'de',
            'IT' => 'it',
            'ES' => 'es',
            'GB', 'US', 'IE' => 'en',
            'NL' => 'nl',
            'PT' => 'pt',
            'SA', 'AE', 'QA', 'KW', 'BH', 'OM' => 'ar',
            default => 'en',
        };
    }
}
