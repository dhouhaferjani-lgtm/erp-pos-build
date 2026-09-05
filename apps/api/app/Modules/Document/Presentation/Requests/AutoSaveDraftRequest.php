<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Catalog\Presentation\Rules\TaxConfigurationCountryCoherent;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Presentation\Rules\DueDateNotBeforeDocumentDate;
use App\Shared\Presentation\Validation\ScopedExists;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
     * - The VALUES are the `can:` middleware of each type's `Route::post` store
     *   sibling in `Document/Presentation/routes.php`, cited by ROUTE NAME
     *   because line numbers in that file drift with every edit to it (gate
     *   R2-2): `quotes.store`, `orders.store`, `invoices.store`,
     *   `credit-notes.store`, `purchase-orders.store`, `delivery-notes.store`,
     *   `return-notes.store`.
     *
     * The six `DocumentType` cases NOT listed are refused by the validator, not
     * by authorization — they have no auto-save flow to preserve. The one that
     * matters most is `correcting_entry`: every correcting-entry route,
     * including the reads, is gated on the admin-tier `documents.correct`
     * (the `documents.correcting-entries.*` / `correcting-entries.*` block in
     * `Document/Presentation/routes.php`) precisely because it "both exposes and writes raw
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

    private ?Document $resolvedTargetDraft = null;

    private bool $targetDraftResolved = false;

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
     * ⚠️ DELIBERATE SEMANTIC DIVERGENCE, awaiting a ruling (gate R2-3).
     * The sibling MANUAL edits gate on `<family>.update` / `deliveries.edit`;
     * auto-save's update branch demands `<family>.create`. The no-regression
     * argument above only covers one direction. The converse is real: a
     * `.create`-only principal may line-replace an EXISTING draft that the
     * matching `PATCH` would refuse them — concretely `cashier`
     * (`quotes.create`, `invoices.create`, neither `.update`) and `operator`
     * (`invoices.create`, no `invoices.update`). It is not FE-reachable, since
     * the editor's edit routes are `<family>.update`-gated, and it is not a
     * regression — before this lane the endpoint had no per-type gate at all.
     * `.create` was chosen because auto-save's characteristic act is AUTHORING
     * (it is what allocates the document number), and because it is the stricter
     * of the two for the create branch. Recorded as R-11 in
     * `docs/superpowers/tickets/2026-08-23-autosave-residuals.md`; whoever rules
     * on it should decide `.create` vs `.update` per branch.
     *
     * The claimed `type` is only half the control — `authorize()` cannot see the
     * target document, so on the update branch
     * `DraftPersistenceService::assertTypeMatches()` refuses a `type` that
     * disagrees with the persisted one. Without that pairing this gate is
     * decorative on updates (gate R2-1).
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

        $submittedDocumentDate = $this->input('document_date');

        return [
            'draft_id' => ['nullable', 'uuid'],
            'type' => ['required', Rule::enum(DocumentType::class)->only(self::autoSavableTypes())],
            'partner_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'document_date' => ['nullable', 'date'],
            // DEV-QA-008/057, gate r1 F3 + gate r2 N-1. `createNewDraft()` writes
            // BOTH dates straight onto the `documents` row
            // (DraftPersistenceService.php:261-262), so before this rule the
            // editor's own keystroke auto-save was the app's primary way to
            // persist `due_date < document_date` — and that draft is the row
            // that later gets confirmed and numbered.
            //
            // The comparand is resolved in the same order the service resolves
            // the value it will actually write: the payload's own
            // `document_date`, then the stored draft's, then — on the CREATE
            // branch — `now()`, because that is literally what
            // `DraftPersistenceService.php:261` substitutes. The rule stays
            // silent only when `due_date` itself is absent, so a half-typed
            // draft with no dates still saves.
            'due_date' => [
                'nullable',
                'date',
                new DueDateNotBeforeDocumentDate(
                    submittedDocumentDate: is_string($submittedDocumentDate) ? $submittedDocumentDate : null,
                    storedDocumentDate: $this->documentDateFallback(),
                ),
            ],
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

            // Campaign defect N-1, gate r1 finding 1. DECLARED, not optional:
            // after the N-1 frontend fix `buildLinePayload()` sends this id
            // INSTEAD OF `tax_rate` whenever the line knows its configuration —
            // which on a correctly-configured tenant is every product line.
            // Undeclared, `validated()` dropped the key before it reached
            // `DraftPersistenceService`, which then wrote `tax_rate ?? 0` and
            // persisted a 0 % draft line. Copied column-for-column from
            // `CreateDocumentRequest` so the two document write paths accept
            // exactly the same tax payload, country scope included: a
            // configuration from another country, or one that is not a
            // LINE_ITEMS configuration, is refused rather than silently
            // resolving to a different rate.
            'lines.*.tax_configuration_id' => [
                'nullable',
                'uuid',
                Rule::exists('tax_configurations', 'id')
                    ->where('country_code', $company->country_code)
                    ->where('applies_to', 'LINE_ITEMS'),
                new TaxConfigurationCountryCoherent($company->country_code),
            ],
        ];
    }

    /**
     * The `document_date` this auto-save will end up written against, as
     * `Y-m-d`, for a payload that does not carry one itself.
     *
     * Gate r2 N-1. The previous version returned null on the CREATE branch and
     * the rule then went silent — but "unknown" was the wrong word for that
     * state: `DraftPersistenceService::createNewDraft()` substitutes
     * `now()->format('Y-m-d')` (`:261`) for a missing `document_date`, so the
     * comparand is known exactly. The reviewer reproduced the consequence
     * end-to-end through the shipped UI: `DocumentForm.tsx:248` emits
     * `document_date: ''` when the operator clears the Issue Date input,
     * Laravel's global `ConvertEmptyStringsToNull` turns it into null, the rule
     * saw no comparand, and the draft was born `document_date = today` with a
     * `due_date` a month earlier — then confirmed and numbered
     * (`QuoteController::confirm()` re-validates no dates).
     *
     * Same date source and timezone as the service: `now()->format('Y-m-d')`,
     * i.e. the app timezone, evaluated within the same request.
     *
     * On the UPDATE branch the stored row's own `document_date` is used. It is
     * NOT NULL in the schema (`2025_11_30_080000_create_documents_table.php:21`),
     * so a resolved draft always yields a date; `now()` is reached only when no
     * draft resolved, which is precisely when `saveDraft()` takes the create
     * branch (`DraftPersistenceService.php:104-119` — same tenant+company
     * scoping, so an unknown or foreign `draft_id` is a create on both sides).
     */
    private function documentDateFallback(): ?string
    {
        $draft = $this->resolveTargetDraft();

        if ($draft === null) {
            return now()->format('Y-m-d');
        }

        $documentDate = $draft->getAttribute('document_date');

        return $documentDate instanceof CarbonInterface ? $documentDate->toDateString() : null;
    }

    /**
     * The draft this auto-save targets, or null when it will author a new one.
     *
     * Scoped by tenant AND company, the same scoping
     * `DraftPersistenceService::saveDraft()` uses to decide whether `draft_id`
     * addresses a row at all — a foreign id resolves to null there and must
     * resolve to null here too, or this rule would leak one company's document
     * date into another company's 422. `Str::isUuid()` guards the lookup:
     * `documents.id` is a PostgreSQL `uuid` column and a non-UUID in a `where`
     * on it raises 22P02 rather than returning no rows.
     *
     * Memoised (gate r2 N-4) so the resolution costs one query per request even
     * if `rules()` is ever evaluated more than once, matching its update-side
     * twin `UpdateDocumentRequest::storedDocumentDate()`.
     */
    private function resolveTargetDraft(): ?Document
    {
        if ($this->targetDraftResolved) {
            return $this->resolvedTargetDraft;
        }

        $this->targetDraftResolved = true;

        $draftId = $this->input('draft_id');

        if (! is_string($draftId) || ! Str::isUuid($draftId)) {
            return null;
        }

        $this->resolvedTargetDraft = Document::query()
            ->where('tenant_id', $this->companyContext->requireCompany()->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->find($draftId);

        return $this->resolvedTargetDraft;
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
