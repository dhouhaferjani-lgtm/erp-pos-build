<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualOverrideRequest extends FormRequest
{
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // House rule 19 (LEDGER C-14(ii) follow-up, gate r1 IMPORTANT-4).
            // `numeric` is kept and the regex is the SCALE CEILING — quantity is
            // 4 d.p. Same shape as the sibling `SetOpeningCostRequest` (:37).
            // Without it two live defects: `'1e3'` passes `numeric` and then
            // blows up inside `quantity()`'s `bcadd` ("not well-formed") for a
            // catch-all 500, and `'12.99999'` is accepted and silently
            // TRUNCATED to `12.9999` at rest — bcadd truncates, it does not
            // round — which is the number `finalize()` then posts to stock.
            // `bail` stops at the first failure so the 500 can never be reached.
            'quantity' => ['bail', 'required', 'numeric', 'regex:/^-?\d+(\.\d{1,4})?$/', 'min:0'],
            'notes' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Detailed notes are required when manually overriding a count',
            'notes.min' => 'Please provide more detailed notes (at least 10 characters)',
            'quantity.regex' => 'Quantity must have at most 4 decimal places.',
        ];
    }

    /**
     * Return the validated quantity as a canonical numeric string at quantity scale (4 d.p.).
     *
     * `bcadd` here is a NORMALISER, not a rounder: the scale-4 regex ceiling in
     * rules() has already refused anything with more precision (and anything
     * `numeric` accepts but bcmath cannot parse, such as `1e3`), so this only
     * pads `7` to `7.0000`. It must never be reached by a value it would alter.
     *
     * @return numeric-string
     */
    public function quantity(): string
    {
        /** @var numeric-string $raw */
        $raw = (string) $this->input('quantity');

        return bcadd($raw, '0', 4);
    }
}
