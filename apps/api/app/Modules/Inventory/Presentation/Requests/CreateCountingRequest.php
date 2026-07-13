<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCountingRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'scope_type' => ['required', Rule::enum(CountingScopeType::class)],

            'scope_filters' => ['sometimes', 'array'],
            'scope_filters.product_ids' => ['sometimes', 'array'],
            'scope_filters.product_ids.*' => ['string', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'scope_filters.category_ids' => ['sometimes', 'array'],
            'scope_filters.category_ids.*' => ['string'],
            'scope_filters.location_ids' => ['sometimes', 'array'],
            'scope_filters.location_ids.*' => ['string', ScopedExists::company('locations', $company->id)],
            'scope_filters.location_id' => ['sometimes', 'string', ScopedExists::company('locations', $company->id)],
            'scope_filters.zone_ids' => ['sometimes', 'array'],
            // Soft-delete-aware exists (review MINOR): a tombstoned node id
            // must fail here, not only in validateZonesBelongToLocation.
            'scope_filters.zone_ids.*' => [
                'string',
                'uuid',
                Rule::exists('location_nodes', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $company->tenant_id)->whereNull('deleted_at')),
            ],

            // Include zero/negative/no-stock-row active products (opt-in; defaults
            // to true when the target location is in onboarding mode). Set on the
            // counting as `includes_zero_stock` at item generation.
            'include_zero_stock' => ['sometimes', 'boolean'],
            // Soft sales-advisory flag; rejected for zone scope (see below).
            'block_sales' => ['sometimes', 'boolean'],
            // Basket-window guard for late in-flight sales; 0 disables it (a
            // legitimate admin choice). Model default is 15 when omitted.
            'ambiguity_window_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],

            'execution_mode' => ['sometimes', Rule::enum(CountingExecutionMode::class)],

            'requires_count_2' => ['sometimes', 'boolean'],
            'requires_count_3' => ['sometimes', 'boolean'],
            'allow_unexpected_items' => ['sometimes', 'boolean'],

            'count_1_user_id' => ['required', 'string', ScopedExists::tenant('users', $company->tenant_id)],
            'count_2_user_id' => ['required_if:requires_count_2,true', 'nullable', 'string', ScopedExists::tenant('users', $company->tenant_id)],
            'count_3_user_id' => ['required_if:requires_count_3,true', 'nullable', 'string', ScopedExists::tenant('users', $company->tenant_id)],

            'scheduled_start' => ['nullable', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],

            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateScopeFilters($validator);
            $this->validateExecutionMode($validator);
        });
    }

    /**
     * Validate scope filters based on scope type.
     */
    private function validateScopeFilters(Validator $validator): void
    {
        $scopeType = $this->input('scope_type');
        $filters = $this->input('scope_filters', []);

        switch ($scopeType) {
            case 'product_location':
                if (empty($filters['product_ids'])) {
                    $validator->errors()->add('scope_filters.product_ids', 'Products are required for this scope');
                }
                if (empty($filters['location_id'])) {
                    $validator->errors()->add('scope_filters.location_id', 'Location is required for this scope');
                }
                break;

            case 'product':
                if (empty($filters['product_ids'])) {
                    $validator->errors()->add('scope_filters.product_ids', 'Products are required for this scope');
                }
                break;

            case 'location':
                if (empty($filters['location_ids'])) {
                    $validator->errors()->add('scope_filters.location_ids', 'Locations are required for this scope');
                }
                break;

            case 'category':
                if (empty($filters['category_ids'])) {
                    $validator->errors()->add('scope_filters.category_ids', 'Categories are required for this scope');
                }
                break;

            case 'zone':
                if (empty($filters['zone_ids'])) {
                    $validator->errors()->add('scope_filters.zone_ids', 'At least one zone is required for this scope');
                }
                if (empty($filters['location_id'])) {
                    $validator->errors()->add('scope_filters.location_id', 'A location is required for this scope');
                }
                // Zone counts are a soft advisory over live shelves; hard sales
                // blocking is never supported for them (spec §3).
                if ($this->boolean('block_sales')) {
                    $validator->errors()->add('block_sales', 'Sales blocking is not supported for zone-scoped counts');
                }
                if (! empty($filters['zone_ids']) && ! empty($filters['location_id'])) {
                    $this->validateZonesBelongToLocation($validator, $filters['zone_ids'], $filters['location_id']);
                }
                break;
        }
    }

    /**
     * `scope_filters.zone_ids.*` is only tenant-scoped (location_nodes carries
     * no company_id — see the A2 migration), so a bare exists check lets
     * company A reference a zone that belongs to company B's location within
     * the same tenant. `scope_filters.location_id` IS company-validated
     * (ScopedExists::company above), so pinning every zone to that location
     * transitively re-establishes the company boundary.
     *
     * @param  array<int, mixed>  $zoneIds
     */
    private function validateZonesBelongToLocation(Validator $validator, array $zoneIds, string $locationId): void
    {
        $zoneIds = array_values(array_filter($zoneIds, static fn (mixed $id): bool => is_string($id) && $id !== ''));

        if ($zoneIds === []) {
            return;
        }

        $matchedIds = LocationNode::query()
            ->whereIn('id', $zoneIds)
            ->where('location_id', $locationId)
            ->pluck('id')
            ->all();

        $foreignIds = array_diff($zoneIds, $matchedIds);

        if ($foreignIds !== []) {
            $validator->errors()->add(
                'scope_filters.zone_ids',
                'The following zones do not belong to the selected location: '.implode(', ', $foreignIds)
            );
        }
    }

    /**
     * Validate execution mode when same user assigned to multiple counts.
     */
    private function validateExecutionMode(Validator $validator): void
    {
        $userIds = array_filter([
            $this->input('count_1_user_id'),
            $this->input('count_2_user_id'),
            $this->input('count_3_user_id'),
        ]);

        $uniqueUsers = array_unique($userIds);

        // If same user assigned to multiple counts, must use sequential mode
        if (count($userIds) !== count($uniqueUsers) && $this->input('execution_mode') === 'parallel') {
            $validator->errors()->add(
                'execution_mode',
                'Sequential mode is required when the same user is assigned to multiple counts'
            );
        }
    }
}
