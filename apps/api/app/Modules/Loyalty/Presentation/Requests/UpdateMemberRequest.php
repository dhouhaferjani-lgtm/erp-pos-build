<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
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
        /** @var User|null $user */
        $user = $this->user();
        $tenantId = $user?->tenant_id;
        $memberId = $this->route('id');

        $companyId = $this->companyContext->requireCompanyId();
        $scopedTenantId = $this->companyContext->requireCompany()->tenant_id;

        return [
            'phone' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('loyalty_members', 'phone')
                    ->where('tenant_id', $tenantId)
                    ->ignore($memberId)
                    ->whereNull('deleted_at'),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'customer_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $scopedTenantId, $companyId),
            ],
            'external_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
