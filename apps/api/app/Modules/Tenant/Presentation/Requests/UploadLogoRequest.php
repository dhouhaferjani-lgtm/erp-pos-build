<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadLogoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'max:2048', // 2MB
                'mimes:png,jpg,jpeg,svg',
            ],
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
            'logo.required' => 'A logo file is required.',
            'logo.file' => 'The logo must be a valid file.',
            'logo.max' => 'The logo file size cannot exceed 2MB.',
            'logo.mimes' => 'The logo must be a PNG, JPG, or SVG file.',
        ];
    }
}
