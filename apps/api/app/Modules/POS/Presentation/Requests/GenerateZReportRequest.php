<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for generating a Z report with optional cash-count inputs.
 *
 * Validation rules:
 * - cash_counts: nullable array of per-payment-method cash counts
 * - variance_reason: optional free-text justification for a variance
 * - blind_count_used: whether the cashier counted without seeing the expected figure
 * - manager_user_id: optional manager UUID for critical-variance override
 * - manager_pin: optional PIN supplied by the manager for the override
 *
 * Custom rule (withValidator):
 * When manager_user_id is present the referenced user must hold the
 * pos.close_shift_with_variance permission and must belong to the same tenant
 * as the authenticated user.
 */
final class GenerateZReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('pos.generate_z_report') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'terminal_id' => ['required', 'string', 'uuid', 'exists:pos_terminals,id'],
            'cash_counts' => 'nullable|array',
            'cash_counts.*.payment_method_id' => 'required|uuid|exists:payment_methods,id',
            'cash_counts.*.currency_code' => ['required', 'string', 'size:3'],
            'cash_counts.*.actual_amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'variance_reason' => ['nullable', 'string', 'max:500'],
            'blind_count_used' => 'nullable|boolean',
            'manager_user_id' => 'nullable|uuid|exists:users,id',
            'manager_pin' => 'nullable|string|min:4|max:12',
        ];
    }

    /**
     * Apply cross-field validation after the base rules pass.
     *
     * When manager_user_id is present:
     * 1. The referenced user must belong to the same tenant as the current user.
     * 2. The referenced user must hold the pos.close_shift_with_variance permission.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->filled('manager_user_id')) {
                return;
            }

            /** @var User|null $manager */
            $manager = User::find($this->input('manager_user_id'));

            if ($manager === null) {
                // exists:users,id already handles this; defensive no-op
                return;
            }

            // Cross-tenant guard: manager must be in same tenant as the current user.
            $currentUser = $this->user();
            if ($currentUser instanceof User && $manager->tenant_id !== $currentUser->tenant_id) {
                $v->errors()->add('manager_user_id', 'Manager must be in the same tenant.');

                return;
            }

            if (! $manager->hasPermissionTo('pos.close_shift_with_variance')) {
                $v->errors()->add('manager_user_id', 'Manager does not hold pos.close_shift_with_variance.');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Typed accessors (PHPStan-friendly)
    // -------------------------------------------------------------------------

    public function getTerminalId(): string
    {
        return (string) $this->input('terminal_id');
    }

    /**
     * @return list<array{payment_method_id: string, currency_code: string, actual_amount: string}>
     */
    public function getCashCountsInput(): array
    {
        return array_values($this->input('cash_counts', []) ?: []);
    }

    public function getVarianceReason(): ?string
    {
        return $this->input('variance_reason');
    }

    public function getBlindCountUsed(): bool
    {
        return (bool) $this->input('blind_count_used', false);
    }

    public function getManagerUserId(): ?string
    {
        return $this->input('manager_user_id');
    }

    public function getManagerPin(): ?string
    {
        return $this->input('manager_pin');
    }
}
