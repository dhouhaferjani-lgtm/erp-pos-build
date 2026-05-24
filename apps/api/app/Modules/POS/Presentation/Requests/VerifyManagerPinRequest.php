<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\ApprovalScope;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

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
     * @return array<string, list<string|Exists|In>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        return [
            // api.pos-stabilization.031 — users (tenant only; users has no
            // company_id col).
            'user_id' => [
                'required', 'uuid',
                ScopedExists::tenant('users', $tenantId),
            ],
            'pin' => ['required', 'string', 'min:4', 'max:12'],
            'company_id' => ['required', 'uuid', Rule::in([$companyId])],
            'terminal_id' => [
                'required', 'uuid',
                Rule::exists('pos_terminals', 'id')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->where('type', '!=', TerminalType::VirtualAdmin->value)),
            ],
            'approval_scope' => ['required', 'string', Rule::in(array_column(ApprovalScope::cases(), 'value'))],
            'target_event_type' => ['required', 'string', 'min:2', 'max:64'],
            'target_reference_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
