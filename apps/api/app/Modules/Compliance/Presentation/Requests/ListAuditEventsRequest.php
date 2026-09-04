<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Bounded contract for GET /api/v1/audit/events (plan Task 4, S-3).
 *
 * The endpoint previously accepted a raw Request: a malformed `from` reached
 * Carbon::parse() and produced a 500, half-specified aggregate/date pairs
 * silently fell through to the unbounded company branch, and every response
 * serialized the full payload/metadata JSONB for up to 100 rows. This request
 * makes each pair all-or-nothing, caps the span at 92 days, bounds the page
 * size at 100 (default 50), and makes payload/metadata an explicit opt-in.
 *
 * Empty-parameter semantics (gate r1, B1). A present-but-empty optional
 * parameter (`?event_type=`, `?from=&to=`, `?per_page=`) means ABSENT, never a
 * filter value of `''`:
 *  - `prepareForValidation()` normalizes every present optional parameter whose
 *    value is a blank string to `null`, before any rule runs. This makes the
 *    contract self-contained: it no longer depends on the global
 *    TrimStrings + ConvertEmptyStringsToNull middleware to do the same thing.
 *  - Every optional parameter carries `nullable`, so a normalized `null` skips
 *    the non-implicit rules (`string`, `integer`, `date_format`, `in`, …)
 *    instead of failing them.
 *  - `required_with` is IMPLICIT, so it still runs against a `null` value:
 *    a half-pair with a blank half (`?aggregate_type=Document&aggregate_id=`,
 *    `?from=2026-09-01&to=`) is still a 422. A fully blank pair is two absent
 *    values and validates, exactly as omitting both would.
 *
 * Span contract: at most MAX_SPAN_DAYS (92) days between `from` and `to`, i.e.
 * `diffInDays(from, to) <= 92` — a 92-day diff is accepted, 93 is rejected.
 * Both sides of that boundary are pinned by tests in AuditTrailTest.
 */
final class ListAuditEventsRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 92;

    /**
     * Optional parameters whose blank string form means "absent".
     *
     * @var list<string>
     */
    private const OPTIONAL_PARAMS = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'from',
        'to',
        'page',
        'per_page',
        'include',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $blanks = [];
        foreach (self::OPTIONAL_PARAMS as $key) {
            $value = $this->input($key);
            if (is_string($value) && trim($value) === '') {
                $blanks[$key] = null;
            }
        }
        if ($blanks !== []) {
            $this->merge($blanks);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            // `required_with` is deliberately unpaired with `sometimes`: the
            // counterpart must be rejected when its partner is present even
            // though the field itself is absent from the payload. It is an
            // implicit rule, so `nullable` does not disarm it for a blank half.
            'aggregate_type' => ['required_with:aggregate_id', 'nullable', 'string', 'max:120'],
            // Storage contract is a 100-char string
            // (database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:19);
            // domain keys such as `doc-123` must stay valid, so this is never `uuid`.
            'aggregate_id' => ['required_with:aggregate_type', 'nullable', 'string', 'max:100'],
            'from' => ['required_with:to', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'include' => ['sometimes', 'nullable', 'string', 'in:payload'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }
            $from = $this->input('from');
            $to = $this->input('to');
            if (! is_string($from) || ! is_string($to)) {
                return;
            }
            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('to', (string) __('validation.audit_date_range_max', [
                    'max' => self::MAX_SPAN_DAYS,
                ]));
            }
        });
    }
}
