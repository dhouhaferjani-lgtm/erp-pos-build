<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Backfill/override the opening unit cost of a counting item (D3).
 *
 * Cost is carried at COST_SCALE = 6 (`inventory_counting_items.opening_unit_cost`
 * is `decimal(_,6)`). Per the precision contract, the value arrives as a
 * canonical decimal STRING with a regex ceiling of 6 fractional digits — never
 * a float. An explicit '0' is permitted and means a deliberate zero-cost opening
 * (distinct from an unset cost, which blocks the opening line).
 */
class SetOpeningCostRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware (`can:inventory.adjust`).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // `numeric` keeps the value a valid number; the regex is the scale
            // ceiling (money is 6 d.p. for cost — NOT currency-scaled). Negative
            // costs are rejected. `bail` stops at the first failure.
            'unit_cost' => ['bail', 'required', 'string', 'numeric', 'regex:/^\d+(\.\d{1,6})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_cost.regex' => 'The opening cost must be a non-negative decimal with at most 6 fractional digits.',
        ];
    }

    /**
     * Return the validated cost as a canonical numeric string at COST_SCALE (6 d.p.).
     *
     * @return numeric-string
     */
    public function unitCost(): string
    {
        /** @var numeric-string $raw */
        $raw = (string) $this->input('unit_cost');

        return bcadd($raw, '0', 6);
    }
}
