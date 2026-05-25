<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            // Tamper-proof tenant qualifier from the reset link (topology r7 B1);
            // read by the pre-auth ResolveTenancy middleware before the broker runs.
            //
            // P1-1 (Codex 2026-05-25): REQUIRED. `password_reset_tokens` is shared
            // and email-keyed in Phase 0a, so an email-only broker reset on a
            // multi-tenant email can update the wrong tenant's user. Redemption
            // must therefore be bound to the signed tenant carried by the link.
            // The string must be a decryptable qualifier — undecryptable values
            // are rejected in the controller (resolves to a null tenant).
            'tenant' => ['required', 'string'],
        ];
    }
}
