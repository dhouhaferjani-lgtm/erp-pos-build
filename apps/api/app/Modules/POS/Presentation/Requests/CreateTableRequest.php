<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\POS\Domain\Enums\TableShape;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTableRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'floor_id' => ['nullable', 'uuid', 'exists:pos_floors,id'],
            'table_number' => ['required', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:100'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'shape' => ['nullable', Rule::enum(TableShape::class)],
        ];
    }
}
