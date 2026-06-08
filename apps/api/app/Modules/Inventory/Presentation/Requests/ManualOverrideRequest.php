<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualOverrideRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Detailed notes are required when manually overriding a count',
            'notes.min' => 'Please provide more detailed notes (at least 10 characters)',
        ];
    }

    /**
     * Return the validated quantity as a canonical numeric string at quantity scale (4 d.p.).
     * The 'numeric' validation rule guarantees the value is a valid numeric string.
     *
     * @return numeric-string
     */
    public function quantity(): string
    {
        /** @var numeric-string $raw */
        $raw = (string) $this->input('quantity');

        return bcadd($raw, '0', 4);
    }
}
