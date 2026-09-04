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
 */
final class ListAuditEventsRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'string', 'max:120'],
            // `required_with` is deliberately unpaired with `sometimes`: the
            // counterpart must be rejected when its partner is present even
            // though the field itself is absent from the payload.
            'aggregate_type' => ['required_with:aggregate_id', 'string', 'max:120'],
            // Storage contract is a 100-char string
            // (database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:19);
            // domain keys such as `doc-123` must stay valid, so this is never `uuid`.
            'aggregate_id' => ['required_with:aggregate_type', 'string', 'max:100'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'include' => ['sometimes', 'string', 'in:payload'],
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
