<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class VatExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, In|string>>
     */
    public function rules(): array
    {
        $validFormats = array_map(
            static fn (VatExportFormat $f): string => $f->value,
            VatExportFormat::cases(),
        );

        return [
            'format' => ['required', 'string', Rule::in($validFormats)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('format')) {
            $this->merge(['format' => strtoupper((string) $this->route('format'))]);
        }
    }
}
