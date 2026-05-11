<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class RequestTerminalRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return [
            // api.pos-stabilization.004 — scope locations by company.
            'location_id' => [
                'required',
                'uuid',
                ScopedExists::company('locations', $companyId),
            ],
            'hardware_identifier' => ['required', 'string', 'max:255'],
            'suggested_name' => ['required', 'string', 'max:255'],
        ];
    }
}
