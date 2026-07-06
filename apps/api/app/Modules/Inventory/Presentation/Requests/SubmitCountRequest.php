<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SubmitCountRequest extends FormRequest
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
     * @return array<string, array<int, string|\Closure>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Device-authored claims for skew correction (mobile offline queue).
            // Both are optional; older mobile builds omit them and submissions
            // fall back to pure server-stamped timestamps (no skew correction).
            // Enforce strict ISO-8601 format: accept UTC (Z) or offset (+/-HH:MM) forms.
            'counted_at_device' => ['nullable', 'bail', $this->iso8601TimestampRule()],
            'device_now' => ['nullable', 'bail', $this->iso8601TimestampRule()],
        ];
    }

    /**
     * Validation rule for strict ISO-8601 UTC timestamps.
     * Accepts: 2026-07-06T10:00:00Z, 2026-07-06T10:00:00.123Z,
     *          2026-07-06T10:00:00+02:00, 2026-07-06T10:00:00.123+02:00
     */
    private function iso8601TimestampRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (null === $value || '' === $value) {
                return; // null/empty handled by nullable
            }

            // ISO-8601 timestamp regex: allows Z or +/-HH:MM offset
            $iso8601Pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

            if (! preg_match($iso8601Pattern, (string) $value)) {
                $fail("The {$attribute} field must be a valid ISO-8601 timestamp (e.g., 2026-07-06T10:00:00Z or 2026-07-06T10:00:00+02:00).");

                return;
            }

            // Verify it can be parsed as a valid date
            try {
                Carbon::parse((string) $value);
            } catch (\Exception) {
                $fail("The {$attribute} field must be a valid date.");
            }
        };
    }

    /**
     * Return the validated quantity as a canonical numeric string at quantity scale (4 d.p.).
     * The 'numeric' validation rule guarantees the value is a valid numeric string.
     *
     * @return numeric-string
     */
    public function quantity(): string
    {
        /** @var numeric-string $raw */
        $raw = (string) $this->input('quantity');

        return bcadd($raw, '0', 4);
    }

    /**
     * The device-clock instant the item was physically counted, if the
     * device supplied one (raw claim — not corrected for skew).
     */
    public function countedAtDevice(): ?CarbonInterface
    {
        $raw = $this->input('counted_at_device');

        return $raw !== null ? Carbon::parse($raw) : null;
    }

    /**
     * The device clock's own reading of "now" at submission time, used to
     * derive the clock skew for correcting `countedAtDevice()`.
     */
    public function deviceNow(): ?CarbonInterface
    {
        $raw = $this->input('device_now');

        return $raw !== null ? Carbon::parse($raw) : null;
    }
}
