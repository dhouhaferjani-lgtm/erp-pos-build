<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Exists;

final class VerifyManagerPinRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string|Exists>>
     */
    public function rules(): array
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        return [
            // api.pos-stabilization.031 — users (tenant only; users has no
            // company_id col).
            'user_id' => [
                'required', 'uuid',
                ScopedExists::tenant('users', $tenantId),
            ],
            'pin' => ['required', 'string', 'min:4', 'max:12'],
        ];
    }
}
