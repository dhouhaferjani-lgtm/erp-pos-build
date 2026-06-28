<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Custom authentication messages
    'unauthorized' => 'You are not authorized to perform this action.',
    'permission_denied' => "You do not have permission for ':ability'. An administrator can grant it under Settings → Roles.",
    'permission_denied_generic' => 'You do not have permission to perform this action. An administrator can grant access under Settings → Roles.',
    'unauthenticated' => 'Please log in to continue.',
    'token_expired' => 'Your session has expired. Please log in again.',
    'token_invalid' => 'Invalid authentication token.',
    'account_disabled' => 'Your account has been disabled.',
    'email_not_verified' => 'Please verify your email address.',
    'logout_success' => 'You have been logged out successfully.',
    'login_success' => 'Login successful.',

    // Email-first login (topology §9.7 — generic, enumeration-aware)
    'no_organizations' => 'No organizations found for that email. Check your email or contact your administrator.',
    'invalid_credentials' => 'The provided credentials are incorrect.',
    'account_not_active' => 'Your account is not active. Please contact support.',
    'organization_unavailable' => 'This organization is currently unavailable. Please contact support.',
    // P1-1 — password reset redemption requires a decryptable, tenant-qualified link.
    'invalid_reset_link' => 'This password reset link is invalid or has expired. Please request a new one.',

    // Email verification
    'verify_email_subject' => 'Verify Your Email Address',
    'verify_email_greeting' => 'Hello :name,',
    'verify_email_body' => 'Please click the button below to verify your email address.',
    'verify_email_button' => 'Verify Email Address',
    'verify_email_expiry' => 'This verification link will expire in :hours hours.',
    'verify_email_salutation' => 'Best regards, The AutoERP Team',
    'verify_email_success' => 'Email verified successfully.',
    'verify_email_invalid_token' => 'Invalid verification token.',
    'verify_email_expired_token' => 'Verification token has expired. Please request a new one.',
    'verify_email_already_verified' => 'Email is already verified.',
    'verify_email_sent' => 'Verification email sent successfully.',
];
