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
 * - `type` must be one of the SEVEN types the editor actually auto-saves (see
 *   `AUTO_SAVABLE_CREATE_ABILITY` below), not any `DocumentType` case.
 * - the caller must hold the per-type `*.create` ability the sibling store route
 *   demands (see `authorize()`).
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
    /**
     * The document types this endpoint accepts, each mapped to the ability its
     * SIBLING create route in the same `routes.php` demands.
     *
     * BOTH halves are derived from code, not chosen:
     *
     * - The KEY SET is the frontend's own declared payload contract for this
     *   endpoint — the `DraftData['type']` union at
     *   `apps/web/src/hooks/useDraftAutoSave.ts:83`. `DocumentForm` is the only
     *   consumer of the hook (`DocumentForm.tsx:319`) and is mounted for six of
     *   them (`routes/index.tsx` quotes/orders/invoices/credit-notes ×
     *   new+edit, purchases orders, inventory delivery-notes); `return_note` is
     *   declared in the union and in `documentTypeToApiEndpoint`
     *   (`DocumentForm.tsx:105`) but has no mounted route today. It is kept in
     *   the set because it is part of the declared contract and is gated by
     *   `deliveries.create` either way.
     *
     * - The VALUES are the `can:` of each type's `Route::post` store sibling:
     *   quotes `:80`, orders `:113`, invoices `:150`, credit-notes `:234`,
     *   purchase-orders `:264`, delivery-notes `:307`, return-notes `:329`.
     *
     * The six `DocumentType` cases NOT listed are refused by the validator, not
     * by authorization — they have no auto-save flow to preserve. The one that
     * matters most is `correcting_entry`: every correcting-entry route,
     * including the reads, is gated on the admin-tier `documents.correct`
     * (`routes.php:365-388`) precisely because it "both exposes and writes raw
     * general-ledger accounts and amounts, which is strictly more powerful than
     * anything those permissions buy". A bare `Rule::enum(DocumentType::class)`
     * let any `documents.update` holder author a `CE-`numbered document through
     * this endpoint and burn a CE sequence number.
     *
     * @var array<string, string>
     */
    private const AUTO_SAVABLE_CREATE_ABILITY = [
        'quote' => 'quotes.create',
        'sales_order' => 'orders.create',
        'invoice' => 'invoices.create',
        'credit_note' => 'credit-notes.create',
        'purchase_order' => 'purchase-orders.create',
        'delivery_note' => 'deliveries.create',
        'return_note' => 'deliveries.create',
    ];

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Per-TYPE authorization, on top of the route's coarse `can:documents.update`.
     *
     * `documents.update` alone made this endpoint a universal document-authoring
     * bypass around the entire per-type `*.create` catalogue: a principal
     * holding no `purchase-orders.*` permission at all could author
     * `PO-2026-0001` here and burn a number out of the PO sequence, when the
     * sibling `POST /purchase-orders` would have refused them.
     *
     * The `*.create` ability is required on BOTH branches — new draft and
     * existing draft. Verified against `RolesAndPermissionsSeeder`: no seeded
     * role holds `<family>.update`/`.edit` without `<family>.create` for any of
     * the six families, so requiring `.create` regresses no seeded role, and the
     * editor's own edit routes already demand `<family>.update`
     * (`routes/index.tsx:669, :711, :755, :960`) which those roles hold too.
     *
     * A missing / unknown / non-auto-savable `type` returns TRUE here on
     * purpose: the answer to a bad shape is the validator's 422, not a 403 that
     * would tell the caller nothing about what was wrong.
     */
    public function authorize(): bool
    {
        $rawType = $this->input('type');

        if (! is_string($rawType)) {
            return true;
        }

        $ability = self::AUTO_SAVABLE_CREATE_ABILITY[$rawType] ?? null;

        if ($ability === null) {
            return true;
        }

        $user = $this->user();

        return $user !== null && $user->can($ability);
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
            'type' => ['required', Rule::enum(DocumentType::class)->only(self::autoSavableTypes())],
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
     * The accepted `DocumentType` cases, as enum instances for `Rule::enum()->only()`.
     *
     * @return list<DocumentType>
     */
    private static function autoSavableTypes(): array
    {
        return array_map(
            static fn (string $value): DocumentType => DocumentType::from($value),
            array_keys(self::AUTO_SAVABLE_CREATE_ABILITY),
        );
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
