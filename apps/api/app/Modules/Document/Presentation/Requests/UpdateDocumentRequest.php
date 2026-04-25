<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Services\CompanyConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
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

        // Check if Vehicle module is enabled for this tenant
        $configService = app(CompanyConfigService::class);
        $hasVehicleModule = $user->tenant !== null
            && $configService->getConfigForTenant($user->tenant)->hasModule('Vehicle');

        return [
            'partner_id' => [
                'sometimes',
                'uuid',
                Rule::exists('partners', 'id')->where('tenant_id', $tenantId),
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
            'document_date' => ['sometimes', 'date'],
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => [
                'nullable',
                'uuid',
                'prohibits:lines.*.service_id',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.service_id' => [
                'nullable',
                'uuid',
                'prohibits:lines.*.product_id',
                Rule::exists('services', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.description' => ['required_with:lines', 'string', 'min:1', 'max:500'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
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
