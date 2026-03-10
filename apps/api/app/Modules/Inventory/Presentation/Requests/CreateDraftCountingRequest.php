<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for creating a draft counting operation (mobile-initiated).
 *
 * Draft counts have minimal validation - manager can build incrementally.
 * Validation becomes strict when activating the draft.
 */
class CreateDraftCountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only managers and admins can create counting operations
        /** @var \App\Modules\Identity\Domain\User|null $user */
        $user = $this->user();

        return $user?->hasRole(['manager', 'admin']) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'scope_type' => ['required', Rule::enum(CountingScopeType::class)],
            'execution_mode' => ['sometimes', Rule::enum(CountingExecutionMode::class)],
            'requires_count_2' => ['sometimes', 'boolean'],
            'requires_count_3' => ['sometimes', 'boolean'],
            'allow_unexpected_items' => ['sometimes', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'created_on_mobile' => ['sometimes', 'boolean'],

            // Optional - can be added later before activation
            'count_1_user_id' => ['nullable', 'string', 'exists:users,id'],
            'count_2_user_id' => ['nullable', 'string', 'exists:users,id'],
            'count_3_user_id' => ['nullable', 'string', 'exists:users,id'],

            'scheduled_start' => ['nullable', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],

            // Scope filters optional - products added incrementally
            'scope_filters' => ['sometimes', 'array'],
            'scope_filters.product_ids' => ['sometimes', 'array'],
            'scope_filters.product_ids.*' => ['string', 'exists:products,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'count_1_user_id.exists' => 'The selected user does not exist.',
            'count_2_user_id.exists' => 'The selected user does not exist.',
            'count_3_user_id.exists' => 'The selected user does not exist.',
        ];
    }
}
