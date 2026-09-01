<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptExpiredLotPolicy;
use App\Modules\Inventory\Domain\Exceptions\GoodsReceiptException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

final class ReceiveGoodsRequest extends FormRequest
{
    private ?GoodsReceiptException $expiredLotRefusal = null;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GoodsReceiptExpiredLotPolicy $expiredLotPolicy,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $canEditPrice = $user !== null && $user->can('goods-receipt.edit-price');
        $canReceiveExpired = $user !== null && $user->can('goods-receipt.receive-expired');

        return [
            'quantities' => ['required_with:received_unit_prices', 'array'],
            'quantities.*' => ['numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'free_quantities' => ['sometimes', 'array'],
            'free_quantities.*' => ['numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'batches' => ['sometimes', 'array'],
            'batches.*.batch_number' => ['required_with:batches.*', 'string', 'max:255'],
            'batches.*.expiry_date' => ['required_with:batches.*', 'date'],
            'batches.*.manufacturing_date' => ['nullable', 'date'],
            'allow_expired' => $canReceiveExpired ? ['sometimes', 'boolean'] : ['prohibited'],
            'received_unit_prices' => $canEditPrice ? ['sometimes', 'array'] : ['prohibited'],
            'received_unit_prices.*' => ['numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
            'price_override_reason' => ['nullable', 'string', 'max:255'],
            'save_as_draft' => ['sometimes', 'boolean'],
            'location_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('allow_expired')) {
                return;
            }

            $this->expiredLotRefusal = $this->resolveExpiredLotRefusal();
            if ($this->expiredLotRefusal !== null) {
                $validator->errors()->add('batches', 'An expired receipt lot requires an explicit permitted override.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expiredLotRefusal !== null) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'GOODS_RECEIPT_FAILED',
                    'reason' => $this->expiredLotRefusal->reason->value,
                    'message' => $this->expiredLotRefusal->getMessage(),
                    'details' => $this->expiredLotRefusal->details->toArray(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        parent::failedValidation($validator);
    }

    private function resolveExpiredLotRefusal(): ?GoodsReceiptException
    {
        $document = Document::query()
            ->where('tenant_id', $this->companyContext->requireTenantId())
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->ofType(DocumentType::PurchaseOrder)
            ->with('lines')
            ->find((string) $this->route('purchaseOrder'));

        return $this->expiredLotPolicy->firstRefusal(
            $document,
            $this->quantityMap('quantities'),
            $this->quantityMap('free_quantities'),
            $this->batchExpiryMap(),
        );
    }

    /** @return array<string, string> */
    private function quantityMap(string $key): array
    {
        $input = $this->input($key);
        if (! is_array($input)) {
            return [];
        }

        $quantities = [];
        foreach ($input as $lineId => $quantity) {
            if (is_string($quantity) || is_int($quantity) || is_float($quantity)) {
                $quantities[(string) $lineId] = (string) $quantity;
            }
        }

        return $quantities;
    }

    /** @return array<string, array{expiry_date?: string|null}> */
    private function batchExpiryMap(): array
    {
        $input = $this->input('batches');
        if (! is_array($input)) {
            return [];
        }

        $batches = [];
        foreach ($input as $lineId => $batch) {
            if (! is_array($batch)) {
                continue;
            }

            $expiry = $batch['expiry_date'] ?? null;
            if (is_string($expiry) || $expiry === null) {
                $batches[(string) $lineId] = ['expiry_date' => $expiry];
            }
        }

        return $batches;
    }
}
