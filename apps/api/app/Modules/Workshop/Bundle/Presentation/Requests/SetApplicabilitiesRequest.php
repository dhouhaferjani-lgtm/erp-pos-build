<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Requests;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SetApplicabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop-bundles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applicabilities' => ['required', 'array'],
            'applicabilities.*.platform_vehicle_id' => ['nullable', 'uuid'],
            'applicabilities.*.vehicle_type' => ['nullable', new Enum(VehicleTypeRef::class)],
            'applicabilities.*.vehicle_display' => ['nullable', 'string', 'max:200'],
            'applicabilities.*.year_from' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'applicabilities.*.year_to' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ];
    }

    /**
     * Enforces the universal-vs-scoped XOR invariant: within a single
     * applicability row, `platform_vehicle_id` and `vehicle_type` must
     * be either both null (universal) or both non-null (specific).
     * This mirrors the frontend guard so API-direct clients cannot
     * bypass it and persist ambiguous applicability rows.
     *
     * Note: pre-existing mixed rows (e.g. the seeded DIAGNOSTIC-OBD
     * bundle's universal applicability row) are intentionally not
     * back-filled; this validator only enforces new writes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rows = $this->input('applicabilities');
            if (! is_array($rows)) {
                return;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $platformVehicleId = $row['platform_vehicle_id'] ?? null;
                $vehicleType = $row['vehicle_type'] ?? null;

                if (($platformVehicleId === null) !== ($vehicleType === null)) {
                    $v->errors()->add(
                        "applicabilities.{$index}.vehicle_type",
                        'Applicability row must be fully universal (both platform_vehicle_id and vehicle_type null) or fully scoped (both non-null).',
                    );
                }
            }
        });
    }
}
