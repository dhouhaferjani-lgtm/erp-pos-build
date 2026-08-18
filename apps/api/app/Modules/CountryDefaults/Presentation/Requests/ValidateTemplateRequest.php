<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

final class ValidateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $scope = $this->input('scope');
        $normalizedScope = is_string($scope)
            ? implode(',', array_map('trim', explode(',', $scope)))
            : $scope;
        $this->merge([
            'id' => $this->route('id'),
            'scope' => $normalizedScope === '' ? null : $normalizedScope,
        ]);
    }

    /** @return array<string, list<string|\Stringable|Rule|ValidationRule>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'scope' => ['sometimes', 'nullable', 'string', 'regex:/^(?:\*|[A-Za-z]{2})(?:,(?:\*|[A-Za-z]{2}))*$/'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(CountryAccountingCapabilities $capabilities): array
    {
        return [function (Validator $validator) use ($capabilities): void {
            $scope = $this->input('scope');
            if (! is_string($scope) || $validator->errors()->has('scope')) {
                return;
            }

            try {
                new CertificationScope(array_map('trim', explode(',', $scope)), $capabilities);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('scope', $exception->getMessage());
            }
        }];
    }
}
