<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/stock-adjustments/{adjustment}/cancel` (DPA V7 / plan §2).
 *
 * There is no DELETE: cancel is the terminal state for an unwanted draft (D5).
 */
class CancelStockAdjustmentRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
