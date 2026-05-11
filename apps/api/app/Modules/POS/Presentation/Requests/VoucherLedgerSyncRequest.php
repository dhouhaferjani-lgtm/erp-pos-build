<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for POST /api/v1/pos/voucher-ledger/sync.
 *
 * The Tauri client posts one entry at a time per request (looped one-by-one
 * to avoid partial-batch failure semantics) but the endpoint accepts 1+ entries
 * defensively for batch flexibility. Each entry must carry a client-supplied
 * UUID `id` so the server can dedupe replays — that is the Phase 1 retry
 * semantics.
 */
final class VoucherLedgerSyncRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        // Authorization handled by middleware + Gate::authorize in controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.id' => ['required', 'uuid'],
            'entries.*.voucher_id' => ['required', 'uuid'],
            'entries.*.event' => ['required', 'string', Rule::in(array_column(VoucherEvent::cases(), 'name'))],
            'entries.*.amount' => ['required', 'string'],
            'entries.*.currency' => ['required', 'string', 'size:3'],
            'entries.*.receipt_id' => ['nullable', 'uuid'],
            // api.pos-stabilization round-2 Opus Finding 1 — scope
            // entries.*.terminal_id by authenticated tenant + company so a
            // cross-tenant terminal_id is rejected at the validator BEFORE
            // VoucherLedgerPushService::push runs. Belt-and-braces with the
            // service-tier Terminal lookup that also pins tenant + company
            // from CompanyContext.
            'entries.*.terminal_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('pos_terminals', $company->tenant_id, $company->id),
            ],
            'entries.*.user_id' => ['required', 'uuid'],
            'entries.*.occurred_at' => ['required', 'date'],
            // The client also sends sync_status / sync_error / synced_at;
            // we accept and ignore them. They describe the local mirror's
            // perspective, not anything the server records.
            'entries.*.sync_status' => ['nullable', 'string'],
            'entries.*.sync_error' => ['nullable', 'string'],
            'entries.*.synced_at' => ['nullable', 'date'],
        ];
    }
}
