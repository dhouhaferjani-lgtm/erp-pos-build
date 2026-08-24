<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class ClaimTerminalRequest extends FormRequest
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
        $company = $this->companyContext->requireCompany();

        return [
            // api.pos-stabilization.001 — scope pos_terminals by caller tenant + company.
            'terminal_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('pos_terminals', $company->tenant_id, $company->id),
            ],
            // T-6: `pos_terminals.hardware_identifier` is varchar(100)
            // (`2026_01_08_190429_create_pos_terminals_table.php:51`). Validating
            // max:255 let an over-long device id reach the INSERT, where it became
            // an unhandled 22001 rather than a field error the caller can act on.
            'hardware_identifier' => ['required', 'string', 'max:100'],
        ];
    }
}
