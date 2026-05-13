<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Authorization for time-entry creation is handled by the controller —
 * managers pass via `workshop.technicians.manage_time_entries`, technicians
 * pass when creating entries for their OWN profile. We can't express the
 * "self-or-manage" rule in FormRequest::authorize() without the route
 * param, so we defer to the controller.
 */
final class StoreTimeEntryRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'work_order_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('workshop_work_orders', $company->tenant_id, $company->id),
            ],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'entry_type' => ['required', new Enum(TimeEntryType::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
