<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Catalog\Presentation\Rules\TaxConfigurationCountryCoherent;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Presentation\Requests\Concerns\AppliesDiscountToleranceRule;
use App\Modules\Identity\Domain\User;
use App\Services\CompanyConfigService;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDocumentRequest extends FormRequest
{
    use AppliesDiscountToleranceRule;

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
        /** @var User|null $authenticatedUser */
        $authenticatedUser = $this->user();

        /** @var User $user */
        $user = $authenticatedUser;
        $tenantId = $user->tenant_id;

        // Codex round-1 Finding 1: scope validators by tenant + company so a
        // user in Company A cannot submit Company B (same tenant) UUIDs and
        // have foreign-company partner/product/service/location/document
        // names persisted into a Company A document line snapshot.
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $scopedTenantId = $company->tenant_id;

        // Check if Vehicle module is enabled for this tenant
        $configService = app(CompanyConfigService::class);
        $hasVehicleModule = $user->tenant !== null
            && $configService->getConfigForTenant($user->tenant)->hasModule('Vehicle');

        $rules = [
            'partner_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $scopedTenantId, $companyId),
            ],
            'vehicle_context' => $hasVehicleModule ? ['nullable', 'array'] : ['prohibited'],
            'vehicle_context.vehicle_id' => ['required_with:vehicle_context', 'uuid'],
            'vehicle_context.snapshot' => ['nullable', 'array'],
            'vehicle_context.snapshot.license_plate' => ['nullable', 'string', 'max:50'],
            'vehicle_context.snapshot.brand' => ['nullable', 'string', 'max:100'],
            'vehicle_context.snapshot.model' => ['nullable', 'string', 'max:100'],
            'vehicle_context.snapshot.year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle_context.mileage' => ['nullable', 'integer', 'min:0'],
            'vehicle_context.additional_data' => ['nullable', 'array'],
            'document_date' => ['required_without:issue_date', 'date'],
            'issue_date' => ['required_without:document_date', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:document_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'source_document_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('documents', $scopedTenantId, $companyId),
            ],
            'location_id' => [
                'nullable',
                'uuid',
                // Locations table is company-scoped (no tenant_id column).
                ScopedExists::company('locations', $companyId)
                    ->where('is_active', true),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => [
                'nullable',
                'uuid',
                'prohibits:lines.*.service_id',
                ScopedExists::tenantAndCompany('products', $scopedTenantId, $companyId),
            ],
            'lines.*.service_id' => [
                'nullable',
                'uuid',
                'prohibits:lines.*.product_id',
                ScopedExists::tenantAndCompany('services', $scopedTenantId, $companyId),
            ],
            'lines.*.location_id' => [
                'nullable',
                'uuid',
                ScopedExists::company('locations', $companyId)
                    ->where('is_active', true),
            ],
            'lines.*.description' => ['required', 'string', 'min:1', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.tax_configuration_id' => [
                'nullable',
                'uuid',
                Rule::exists('tax_configurations', 'id')
                    ->where('country_code', $company->country_code)
                    ->where('applies_to', 'LINE_ITEMS'),
                new TaxConfigurationCountryCoherent($company->country_code),
            ],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];

        return $this->withDiscountToleranceRules($rules);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.quantity.regex' => 'Line quantity must have at most 4 decimal places',
            'lines.*.unit_price.regex' => 'Line unit price must have at most 3 decimal places',
            'lines.*.discount_percent.regex' => 'Line discount percentage must have at most 2 decimal places',
            'lines.*.discount_amount.regex' => 'Line discount amount must have at most 3 decimal places',
            'lines.*.tax_rate.regex' => 'Line tax rate must have at most 2 decimal places',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('lines') && is_array($this->input('lines'))) {
            $lines = array_map(function (array $line): array {
                if (isset($line['description']) && is_string($line['description'])) {
                    $line['description'] = trim($line['description']);
                }
                if (isset($line['notes']) && is_string($line['notes'])) {
                    $line['notes'] = trim($line['notes']);
                }
                // Strip client-supplied snapshot — server sets it
                unset($line['designation_default_snapshot']);

                return $line;
            }, $this->input('lines'));
            $this->merge(['lines' => $lines]);
        }
    }
}
