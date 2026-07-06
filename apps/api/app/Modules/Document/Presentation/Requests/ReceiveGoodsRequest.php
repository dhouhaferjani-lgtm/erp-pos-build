<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use Illuminate\Foundation\Http\FormRequest;

final class ReceiveGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $canEditPrice = $user !== null && $user->can('goods-receipt.edit-price');

        return [
            'quantities' => ['required_with:received_unit_prices', 'array'],
            'quantities.*' => ['numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'free_quantities' => ['sometimes', 'array'],
            'free_quantities.*' => ['numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'batches' => ['sometimes', 'array'],
            'batches.*.batch_number' => ['required_with:batches.*', 'string', 'max:255'],
            'batches.*.expiry_date' => ['required_with:batches.*', 'date'],
            'batches.*.manufacturing_date' => ['nullable', 'date'],
            'received_unit_prices' => $canEditPrice ? ['sometimes', 'array'] : ['prohibited'],
            'received_unit_prices.*' => ['numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
            'price_override_reason' => ['nullable', 'string', 'max:255'],
            'save_as_draft' => ['sometimes', 'boolean'],
        ];
    }
}
