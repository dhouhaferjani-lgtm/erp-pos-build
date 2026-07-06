<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\LocationZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();
        $zoneId = (string) $this->route('zone');

        // The zone's own location_id scopes the code-uniqueness check below.
        // A zone that doesn't belong to the current company (or doesn't
        // exist) resolves to a null location_id here; the controller still
        // 404s on the actual lookup, so this is safe.
        $zone = LocationZone::query()
            ->whereHas('location', function (Builder $query) use ($company): void {
                /** @var Builder<Location> $query */
                $query->where('company_id', $company->id);
            })
            ->find($zoneId);
        $locationId = $zone?->location_id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('location_zones', 'code')
                    ->where(fn ($query) => $query->where('location_id', $locationId))
                    ->ignore($zoneId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
