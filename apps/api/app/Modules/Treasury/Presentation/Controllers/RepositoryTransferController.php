<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\Services\RepositoryTransferService;
use App\Modules\Treasury\Presentation\Requests\TransferRepositoryRequest;
use Illuminate\Http\JsonResponse;

final class RepositoryTransferController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RepositoryTransferService $transferService,
    ) {}

    public function store(TransferRepositoryRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();

        $result = $this->transferService->transfer(
            tenantId: $company->tenant_id,
            companyId: $company->id,
            fromRepositoryId: (string) $validated['from_repository_id'],
            toRepositoryId: (string) $validated['to_repository_id'],
            amount: (string) $validated['amount'],
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            transferGroupId: isset($validated['transfer_group_id']) ? (string) $validated['transfer_group_id'] : null,
            userId: $user->id,
        );

        return response()->json([
            'message' => __('messages.treasury.transfer_recorded'),
            'data' => [
                'transfer_group_id' => $result->transferGroupId,
                'journal_entry_id' => $result->journalEntryId,
                'idempotent_replay' => $result->idempotentReplay,
                'out' => [
                    'movement_id' => $result->out->movementId,
                    'balance_after' => $result->out->balanceAfter,
                    'repository_id' => (string) $validated['from_repository_id'],
                ],
                'in' => [
                    'movement_id' => $result->in->movementId,
                    'balance_after' => $result->in->balanceAfter,
                    'repository_id' => (string) $validated['to_repository_id'],
                ],
            ],
        ], 201);
    }
}
