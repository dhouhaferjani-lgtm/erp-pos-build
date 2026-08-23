<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload contract for `POST /api/v1/documents/auto-save`.
 *
 * P1 (ticket 2026-08-22 §1): the route previously ran `$request->all()` straight
 * into `DraftPersistenceService::saveDraft()` with NO validator at all, so any
 * shape reached the persistence layer and every malformed value was absorbed by
 * the controller's blanket `catch (\Throwable) → 200`.
 *
 * Deliberately LENIENT where the endpoint is legitimately lenient — this is a
 * keystroke-frequency auto-save of a half-finished editor session, not a
 * document submission:
 *
 * - `lines` is `sometimes` and MAY be empty. A lineless DRAFT is a legal
 *   intermediate state (the operator cleared the grid); owner ruling O-26
 *   refuses a lineless document at POSTING, which is the correct place.
 * - line fields are `nullable`: a line being typed has no product yet, and the
 *   `api` middleware group converts `""` to null before validation.
 *
 * STRICT where correctness is not negotiable:
 *
 * - `type` must be a real `DocumentType` (it was `DocumentType::from()` on raw
 *   input, i.e. a swallowed `\ValueError` and a silent 200).
 * - every money / quantity / percent field carries the decimal ceiling required
 *   by CLAUDE.md rule 19, matching `CreateDocumentRequest` column for column.
 *
 * NOT scoped with `ScopedExists` — `lines.*.product_id` / `lines.*.service_id` /
 * `lines.*.variant_id`: `DraftPersistenceService` deliberately resolves those
 * through tenant+company-scoped lookups and persists NULL when the lookup misses
 * (api.document.012/013/043/044/045). Turning a miss into a 422 here would
 * relocate that decision and break the isolation tests that pin the null-write.
 *
 * NOT DECLARED AT ALL — `line_total`, `discount_percent`, `discount_amount`,
 * `free_quantity`, `price_entry_mode`. The editor sends them (they are part of
 * `buildLinePayload`, shared with the manual-submit payload) but
 * `DraftPersistenceService` reads none of them: it recomputes `line_total`
 * itself and ignores the rest. `validated()` therefore drops them, which is the
 * right outcome. Declaring rules for a field this endpoint discards buys no
 * safety and adds a way for a mid-typing value to start failing a keystroke
 * auto-save. Their ceilings ARE enforced where they are persisted, by
 * `CreateDocumentRequest` / `UpdateDocumentRequest`.
 */
class AutoSaveDraftRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Route middleware (`can:documents.update`) carries the authorization.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $company->tenant_id;

        return [
            'draft_id' => ['nullable', 'uuid'],
            'type' => ['required', Rule::enum(DocumentType::class)],
            'partner_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'document_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['sometimes', 'array'],
            'lines.*' => ['array'],

            // NOT `uuid`. The editor mints CLIENT-side ids for lines that have
            // never been saved — `line-<epoch>-<rand>`
            // (DocumentLineEditor.tsx:328) — and `DocumentForm.tsx:295` puts
            // that id straight into the auto-save payload. A `uuid` rule here
            // would 422 every keystroke auto-save of a line the operator just
            // added. See the blast-radius note for the (separate, pre-existing)
            // bug this id scheme causes downstream.
            'lines.*.id' => ['nullable', 'string', 'max:64'],

            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.service_id' => ['nullable', 'uuid'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],

            // Rule 19 decimal ceilings on the two money/quantity columns this
            // endpoint actually PERSISTS. The sign is left open (`-?`) because
            // the draft grid is shared by credit notes and returns; the
            // ceiling, not the sign, is what this rule exists to enforce.
            'lines.*.quantity' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.quantity.regex' => 'Line quantity must have at most 4 decimal places',
            'lines.*.unit_price.regex' => 'Line unit price must have at most 3 decimal places',
            'lines.*.tax_rate.regex' => 'Line tax rate must have at most 2 decimal places',
        ];
    }
}
