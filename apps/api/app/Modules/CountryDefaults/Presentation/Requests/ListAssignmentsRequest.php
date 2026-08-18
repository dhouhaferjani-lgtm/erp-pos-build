<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|ValidationRule>> */
    public function rules(): array
    {
        return ['domain' => ['required', Rule::enum(TemplateDomain::class)]];
    }
}
