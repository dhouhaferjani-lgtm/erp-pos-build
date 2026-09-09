<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Rules\ValidLocationAccess;
use Illuminate\Foundation\Http\FormRequest;

final class CreateTransferFromRequestsRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|ValidLocationAccess>> */
    public function rules(): array
    {
        return [
            'source_location_id' => ['bail', 'required', 'uuid', new ValidLocationAccess($this->locationContext, $this->companyContext, $this->companyContext->requireCompanyId())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.request_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
