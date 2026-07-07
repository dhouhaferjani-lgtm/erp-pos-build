<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reparent payload: `parent_id` must be present (null = move to root).
 * Same-location + cycle guards live in LocationNodeService::moveNode inside
 * the transaction — the request only shape-checks.
 */
class MoveNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'parent_id' => [
                'present',
                'nullable',
                'uuid',
                Rule::exists('location_nodes', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
        ];
    }
}
