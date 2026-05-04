<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class CreateJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $userOrNull */
        $userOrNull = $this->user();

        /** @var User $user */
        $user = $userOrNull;

        $tenantId = $user->tenant_id;
        $companyId = app(CompanyContext::class)->requireCompanyId();

        return [
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            // api.accounting.003: tenant+company-scoped exists upgrades the
            // pre-existing tenant-only pipe-form to also pin company_id, so a
            // sibling-company account UUID cannot satisfy the FK validator.
            'lines.*.account_id' => ['required', 'uuid', ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId)],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
