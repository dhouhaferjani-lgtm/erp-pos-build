<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Rules\ValidLocationAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->toArray() === ['source_location_id' => ['You do not have permission to access this location.']]) {
            throw new HttpResponseException(response()->json(['error' => [
                'code' => 'LOCATION_ACCESS_DENIED',
                'message' => 'You do not have permission to act on this location.',
                'details' => ['location_id' => (string) $this->input('source_location_id'), 'user_id' => $this->user()?->getAuthIdentifier()],
            ]], 403));
        }
        parent::failedValidation($validator);
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
