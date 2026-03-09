<?php

declare(strict_types=1);

namespace App\Modules\Contact\Presentation\Requests;

use App\Modules\Contact\Domain\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateContactRequest extends FormRequest
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
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'national_id' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'party_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
