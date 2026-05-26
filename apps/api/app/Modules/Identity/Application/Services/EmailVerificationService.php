<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Domain\EmailVerificationToken;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantLinkSigner;
use Illuminate\Support\Str;

class EmailVerificationService
{
    /**
     * Token expiry in hours.
     */
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly TenantLinkSigner $tenantLinkSigner,
    ) {}

    /**
     * Create a verification token for a user and send the email.
     */
    public function sendVerificationEmail(User $user): void
    {
        // Delete any existing tokens for this user
        EmailVerificationToken::where('user_id', $user->id)->delete();

        // Create new token
        $token = $this->createToken($user);

        // Send verification email with a tenant-qualified link (topology r7 B1)
        // so the pre-auth resolver can open the right tenant DB post-flip.
        $user->notify(new VerifyEmailNotification(
            $token->token,
            $this->tenantLinkSigner->sign($user->tenant_id),
        ));
    }

    /**
     * Verify a user's email with the given token.
     *
     * @return array{success: bool, message: string}
     */
    public function verifyEmail(string $token): array
    {
        $verificationToken = EmailVerificationToken::where('token', $token)->first();

        if ($verificationToken === null) {
            return [
                'success' => false,
                'message' => 'Invalid verification token.',
            ];
        }

        if ($verificationToken->isExpired()) {
            $verificationToken->delete();

            return [
                'success' => false,
                'message' => 'Verification token has expired. Please request a new one.',
            ];
        }

        // Mark email as verified
        $user = $verificationToken->user;
        $user->email_verified_at = now();
        $user->save();

        // Delete the token
        $verificationToken->delete();

        return [
            'success' => true,
            'message' => 'Email verified successfully.',
        ];
    }

    /**
     * Resend verification email to a user.
     *
     * @return array{success: bool, message: string}
     */
    public function resendVerificationEmail(User $user): array
    {
        if ($user->hasVerifiedEmail()) {
            return [
                'success' => false,
                'message' => 'Email is already verified.',
            ];
        }

        $this->sendVerificationEmail($user);

        return [
            'success' => true,
            'message' => 'Verification email sent successfully.',
        ];
    }

    /**
     * Create a new verification token for a user.
     */
    private function createToken(User $user): EmailVerificationToken
    {
        return EmailVerificationToken::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'token' => Str::random(64),
            'expires_at' => now()->addHours(self::TOKEN_EXPIRY_HOURS),
            'created_at' => now(),
        ]);
    }
}
