<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates every accepted input of GET /api/v1/stock-movements.
 *
 * The controller consumes only validated(), so an unlisted parameter can never
 * reach the query builder and an unbounded page size can never be requested.
 */
final class ListStockMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<object|string>> */
    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'],
            'location_ids.*' => ['uuid'],
            'product_id' => ['sometimes', 'nullable', 'uuid'],
            'movement_type' => [
                'sometimes',
                'string',
                Rule::in([
                    ...array_map(static fn (MovementType $type): string => $type->value, MovementType::cases()),
                    'transfer',
                ]),
            ],
            'reason' => [
                'sometimes',
                'string',
                Rule::in([
                    ...array_map(static fn (MovementReason $reason): string => $reason->value, MovementReason::cases()),
                    'write_off',
                ]),
            ],
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
