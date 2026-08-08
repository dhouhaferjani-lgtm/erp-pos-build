<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/stock-adjustments/{adjustment}/post` (DPA V7 / plan §2).
 *
 * Both flags are post-time ACKNOWLEDGEMENTS of a specific divergence at a
 * specific moment, so neither is ever persisted on the draft; the header records
 * that the override happened, not that it was intended (D15 / D1a).
 */
class PostStockAdjustmentRequest extends FormRequest
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
            'acknowledge_stale' => ['sometimes', 'boolean'],
            'ignore_reservations' => ['sometimes', 'boolean'],
        ];
    }
}
