<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Rules\ValidLocationAccess;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/stock-adjustments` (DPA V7 / plan §2).
 *
 * Shape copied from StoreStockTransferRequest: constructor-injected contexts,
 * `authorize(): true` (the route's `can:` middleware owns authorization),
 * `failedValidation()` translating the location rule into the established 403
 * LOCATION_ACCESS_DENIED contract, ScopedExists, and `bail`.
 *
 * Invariants DEFERRED to StockAdjustmentDocumentService, because they need the
 * per-line product or the locked row:
 *  - the lot predicates (BATCH_REQUIRED_FOR_LINE / BATCH_NOT_APPLICABLE) and the
 *    batch-tracked reason gate (USE_BATCH_WRITE_OFF) — D1b / D7a;
 *  - staleness and the reserved-aware availability boundary — D15 / D1a, both of
 *    which are only authoritative under the row lock.
 */
class StoreStockAdjustmentRequest extends FormRequest
{
    /**
     * SIGNED, 4 dp. The existing quantity regexes in this module are UNSIGNED
     * (StockMovementController, AdjustStockRequest); the leading `-?` is the one
     * place a copy-paste silently drops the negative half of this contract.
     */
    public const SIGNED_QUANTITY_REGEX = '/^-?\d+(\.\d{1,4})?$/';

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $message = 'You do not have permission to access this location.';
        $errors = $validator->errors()->toArray();

        if (count($errors) === 1 && ($errors['location_id'] ?? null) === [$message]) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'LOCATION_ACCESS_DENIED',
                    'message' => 'You do not have permission to act on this location.',
                    'details' => [
                        'location_id' => (string) $this->input('location_id'),
                        'user_id' => $this->user()?->getAuthIdentifier(),
                    ],
                ],
            ], Response::HTTP_FORBIDDEN));
        }

        parent::failedValidation($validator);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'location_id' => [
                'bail',
                'required',
                'string',
                'uuid',
                ScopedExists::company('locations', $company->id),
                new ValidLocationAccess($this->locationContext, $this->companyContext, $company->id),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'post_immediately' => ['sometimes', 'boolean'],
            // Post-time ACKNOWLEDGEMENTS of a specific divergence at a specific
            // moment — never persisted on the draft (D15 / D1a).
            'acknowledge_stale' => ['sometimes', 'boolean'],
            'ignore_reservations' => ['sometimes', 'boolean'],
            // v1 forbids backdating (D11): occurred_at is stamped server-side.
            'occurred_at' => ['prohibited'],

            ...StockAdjustmentLineRules::forCompany($company->tenant_id, $company->id),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StockAdjustmentLineRules::messages();
    }

    public function withValidator(Validator $validator): void
    {
        StockAdjustmentLineRules::attachSignInvariant($validator);
        StockAdjustmentLineRules::attachLineUniqueness($validator);
    }

    public function reasonFor(int $index): MovementReason
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = (array) $this->input('lines', []);

        return MovementReason::from((string) ($lines[$index]['reason_code'] ?? ''));
    }
}
