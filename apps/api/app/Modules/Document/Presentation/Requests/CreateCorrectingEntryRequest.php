<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The payload of `POST /documents/{document}/correcting-entries` (R2-F4).
 *
 * Authorization is NOT done here: the route carries `can:documents.correct`, so
 * a request that reaches this class is already gated. `authorize()` returning
 * true is therefore the house shape, not an omission.
 *
 * Rule 19 — every money field keeps `numeric` AND adds the three-decimal regex
 * ceiling for the currency scale. `journal_lines.debit`/`.credit` are decimal
 * columns; a fourth decimal would be silently truncated at rest, which on a
 * CORRECTION would mean the accountant's stated repair is not the repair that
 * was made.
 */
final class CreateCorrectingEntryRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],

            'legs' => ['required', 'array', 'min:1', 'max:50'],
            'legs.*.account_id' => ['required', 'uuid'],
            // Money scale ceiling (rule 19): KEEP `numeric` and ADD the
            // three-decimal regex, never replace one with the other. `string`
            // additionally refuses a JSON number, which would arrive here having
            // already been through a float. Amounts stay strings all the way
            // into `journal_lines`.
            'legs.*.debit' => ['required', 'string', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
            'legs.*.credit' => ['required', 'string', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
            'legs.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'legs.*.debit.regex' => 'A debit may carry at most three decimals.',
            'legs.*.credit.regex' => 'A credit may carry at most three decimals.',
        ];
    }
}
