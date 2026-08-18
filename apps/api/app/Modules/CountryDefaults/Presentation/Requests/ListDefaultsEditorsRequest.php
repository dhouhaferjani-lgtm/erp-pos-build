<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ListDefaultsEditorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|\Stringable|Rule|ValidationRule>> */
    public function rules(): array
    {
        return [];
    }
}
