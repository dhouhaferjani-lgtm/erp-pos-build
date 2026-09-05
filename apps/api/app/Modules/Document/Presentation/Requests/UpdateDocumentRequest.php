<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Catalog\Presentation\Rules\TaxConfigurationCountryCoherent;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\PriceEntryMode;
use App\Modules\Document\Presentation\Requests\Concerns\AppliesDiscountToleranceRule;
use App\Modules\Document\Presentation\Rules\DueDateNotBeforeDocumentDate;
use App\Modules\Document\Presentation\Rules\LineDiscountAmountWithinGross;
use App\Modules\Document\Presentation\Validation\DiscountPolicyDocumentValidator;
use App\Modules\Identity\Domain\User;
use App\Modules\Procurement\Application\PurchaseBonusGate;
use App\Services\CompanyConfigService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Presentation\Validation\ScopedExists;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDocumentRequest extends FormRequest
{
    use AppliesDiscountToleranceRule;

    private ?string $resolvedStoredDocumentDate = null;

    private bool $storedDocumentDateResolved = false;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CompanyConfigService $configService,
        private readonly PurchaseBonusGate $purchaseBonusGate,
        private readonly DiscountPolicyDocumentValidator $discountPolicyValidator,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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

        // Codex round-1 Finding 1: scope by tenant + company.
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $scopedTenantId = $company->tenant_id;

        $hasVehicleModule = $user->tenant !== null
            && $this->configService->getConfigForTenant($user->tenant)->hasModule('Vehicle');
        $purchaseBonusEnabled = $this->purchaseBonusGate->enabledFor($company);

        // DEV-QA-008/057, gate r1 F2. `after_or_equal:document_date` degrades to
        // a no-op on a partial `PATCH {due_date}` because the referenced field
        // is absent from the request; the rule object below falls back to the
        // PERSISTED `document_date` instead, which is the value the row will
        // still carry after this update.
        $submittedDocumentDate = $this->input('document_date');
        $documentDateGuard = new DueDateNotBeforeDocumentDate(
            submittedDocumentDate: is_string($submittedDocumentDate) ? $submittedDocumentDate : null,
            storedDocumentDate: $this->storedDocumentDate(),
        );

        $rules = [
            'partner_id' => [
                'sometimes',
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
            // `issue_date` is the FRONTEND alias only — see the twin comment in
            // `CreateDocumentRequest::rules()`. Undeclared on purpose, so it
            // cannot reach `validated()` on any transport.
            'document_date' => ['sometimes', 'date'],
            // DEV-QA-008/057 — the guard was ABSENT on update, so a draft could
            // be patched to a due/valid date preceding its issue date.
            'due_date' => ['nullable', 'date', $documentDateGuard],
            'valid_until' => ['nullable', 'date', $documentDateGuard],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['sometimes', 'array', 'min:1'],
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
            'lines.*.description' => ['required_with:lines', 'string', 'min:1', 'max:500'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.free_quantity' => $purchaseBonusEnabled
                ? ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/']
                : ['prohibited'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.price_entry_mode' => $purchaseBonusEnabled
                ? ['nullable', Rule::in(PriceEntryMode::values())]
                : ['nullable', Rule::in([PriceEntryMode::Unit->value])],
            'lines.*.is_bonus_line' => $purchaseBonusEnabled
                ? ['nullable', 'boolean']
                : ['prohibited'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.discount_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'regex:/^\d+(\.\d{1,3})?$/',
                new LineDiscountAmountWithinGross(
                    lines: is_array($this->input('lines')) ? $this->input('lines') : [],
                    scale: $this->scaleResolver->getScale($company->currency),
                ),
            ],
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
            'lines.*.free_quantity.regex' => 'Line free quantity must have at most 4 decimal places',
            'lines.*.unit_price.regex' => 'Line unit price must have at most 3 decimal places',
            'lines.*.line_total.regex' => 'Line total must have at most 3 decimal places',
            'lines.*.discount_percent.regex' => 'Line discount percentage must have at most 2 decimal places',
            'lines.*.discount_amount.regex' => 'Line discount amount must have at most 3 decimal places',
            'lines.*.tax_rate.regex' => 'Line tax rate must have at most 2 decimal places',
        ];
    }

    protected function prepareForValidation(): void
    {
        // DEV-QA-008/057 — normalize the FE `issue_date` alias to the canonical
        // `document_date` BEFORE validation so the `due_date` / `valid_until`
        // guards fire on the update path too. Gate r1 F4: the alias is NOT
        // stripped from the request bag — see the twin comment in
        // `CreateDocumentRequest::prepareForValidation()`.
        if ($this->has('issue_date') && ! $this->has('document_date')) {
            $this->merge(['document_date' => $this->input('issue_date')]);
        }

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

    /**
     * The persisted `document_date` of the document this request updates, as
     * `Y-m-d` — the comparand `after_or_equal:document_date` could never reach.
     *
     * Resolved from the route parameter rather than a bound model: every update
     * route in `Document/Presentation/routes.php` declares a PLAIN string
     * parameter (`/quotes/{quote}`, `/orders/{order}`, `/invoices/{invoice}`,
     * `/purchase-orders/{purchaseOrder}`, `/return-notes/{returnNote}`) and each
     * controller does its own `find()`, so there is no `Document` instance in the
     * route bag to read. `Str::isUuid()` guards the lookup because `documents.id`
     * is a PostgreSQL `uuid` column and a non-UUID value in a `where` on it
     * raises a 22P02 rather than returning no rows.
     *
     * Scoped to the active company: this value only ever produces a 422, never a
     * write, but it must not be readable across the company boundary either.
     * Resolving to null (unknown id, foreign company) leaves the guard silent —
     * the controller's own `find()` then answers with the 404 that request
     * actually deserves.
     */
    private function storedDocumentDate(): ?string
    {
        if ($this->storedDocumentDateResolved) {
            return $this->resolvedStoredDocumentDate;
        }

        $this->storedDocumentDateResolved = true;

        $route = $this->route();

        if (! $route instanceof Route) {
            return null;
        }

        foreach ($route->parameters() as $parameter) {
            if (! is_string($parameter) || ! Str::isUuid($parameter)) {
                continue;
            }

            $document = Document::query()
                ->where('company_id', $this->companyContext->requireCompanyId())
                ->find($parameter);

            if ($document === null) {
                continue;
            }

            $documentDate = $document->getAttribute('document_date');

            $this->resolvedStoredDocumentDate = $documentDate instanceof CarbonInterface
                ? $documentDate->toDateString()
                : null;

            return $this->resolvedStoredDocumentDate;
        }

        return null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lines = $this->input('lines');

            if (! is_array($lines)) {
                return;
            }

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $mode = (string) ($line['price_entry_mode'] ?? PriceEntryMode::Unit->value);

                if ($mode === PriceEntryMode::Total->value && ! array_key_exists('line_total', $line)) {
                    $validator->errors()->add("lines.{$index}.line_total", 'Line total is required when price entry mode is total.');
                }
            }

            $this->discountPolicyValidator->validate($this, $validator);
        });
    }
}
