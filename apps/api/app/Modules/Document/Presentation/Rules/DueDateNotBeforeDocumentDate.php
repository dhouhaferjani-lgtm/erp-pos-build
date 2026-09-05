<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a `due_date` / `valid_until` that precedes the document's own
 * `document_date`, comparing against the PERSISTED value when the request
 * omits one.
 *
 * DEV-QA-008 / DEV-QA-057, gate r1 finding F2. Laravel's
 * `after_or_equal:document_date` resolves its comparand with
 * `Validator::getValue('document_date')`. When the field is absent from the
 * request — every partial `PATCH /quotes/{id} {due_date}` — the comparand is
 * `null`, `getDateTimestamp(null)` returns `null`, and `compare(int, null,
 * '>=')` juggles `null` to `0` and PASSES. The rule never reaches the stored
 * row, so the exact defect the guard exists to close stayed reachable through
 * any payload that omitted the date (mobile, scripted API, the autosave-shaped
 * flows).
 *
 * This rule takes both halves explicitly:
 *
 * - `$submittedDocumentDate` — the value the request itself carries (already
 *   normalised from the frontend's `issue_date` alias by the request's
 *   `prepareForValidation()`). It WINS whenever it is present, which preserves
 *   the pre-existing precedence: a client sending both keeps the explicit
 *   `document_date`.
 * - `$storedDocumentDate` — the persisted row's `document_date`, resolved by
 *   the FormRequest from the route-bound document (update) or from `draft_id`
 *   (auto-save). Used only as a FALLBACK.
 *
 * SILENT when neither is known: an incomplete draft is a legal intermediate
 * state, and a 422 on a keystroke auto-save strands the operator's work
 * (`useDraftAutoSave.ts:270-280` surfaces a failure as `autosaveFailed` and
 * saves nothing). Absence/emptiness of the value under test is the `nullable`
 * rule's job, and a malformed date on either side is the `date` rule's — this
 * rule stays silent for both rather than emitting a second, confusing error.
 *
 * Comparison is at DAY granularity on purpose. Both columns are `date` casts
 * (`Document.php:183`) and every payload in play is a plain `Y-m-d` string, so
 * a time component on either side must never decide the outcome. Equality
 * PASSES — same semantics as `after_or_equal`.
 */
final class DueDateNotBeforeDocumentDate implements ValidationRule
{
    public function __construct(
        private readonly ?string $submittedDocumentDate,
        private readonly ?string $storedDocumentDate,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $comparand = $this->comparandDay();

        if ($comparand === null) {
            return;
        }

        $day = self::toDay($value);

        if ($day === null) {
            return;
        }

        if ($day < $comparand) {
            $fail(__($this->messageKey($attribute), ['document_date' => $comparand]));
        }
    }

    /**
     * The `Y-m-d` day the value under test must not precede: the submitted
     * `document_date` when the request carries one, else the persisted one.
     */
    private function comparandDay(): ?string
    {
        foreach ([$this->submittedDocumentDate, $this->storedDocumentDate] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            $day = self::toDay($candidate);

            if ($day !== null) {
                return $day;
            }

            // A submitted value that is present but unparseable is the `date`
            // rule's 422, not this one's — and falling through to the stored
            // value would compare against a date the client did not send.
            return null;
        }

        return null;
    }

    /**
     * `Y-m-d` for a well-formed date string, null for anything else.
     *
     * `strtotime()` rather than Carbon::parse(): a malformed value must yield
     * null here, never an exception at a validation boundary.
     */
    private static function toDay(string $value): ?string
    {
        $timestamp = strtotime(trim($value));

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function messageKey(string $attribute): string
    {
        return $attribute === 'valid_until'
            ? 'documents.dates.valid_until_before_document_date'
            : 'documents.dates.due_date_before_document_date';
    }
}
