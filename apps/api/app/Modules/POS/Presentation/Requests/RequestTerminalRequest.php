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
            // T-6: `pos_terminals.hardware_identifier` is varchar(100)
            // (`2026_01_08_190429_create_pos_terminals_table.php:51`). Validating
            // max:255 let an over-long device id reach the INSERT, where it became
            // an unhandled 22001 rather than a field error the caller can act on.
            'hardware_identifier' => ['required', 'string', 'max:100'],
            'suggested_name' => ['required', 'string', 'max:255'],
        ];
    }
}
