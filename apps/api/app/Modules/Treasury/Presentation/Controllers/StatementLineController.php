<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Application\Services\StatementMatchingService;
use App\Modules\Treasury\Application\Services\StatementSuggestionService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementMatchExecution;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use App\Modules\Treasury\Presentation\Requests\AllocateStatementLineRequest;
use App\Modules\Treasury\Presentation\Requests\ExecuteStatementActionRequest;
use App\Modules\Treasury\Presentation\Requests\IgnoreStatementLineRequest;
use App\Modules\Treasury\Presentation\Requests\UnallocateStatementLineRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class StatementLineController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly StatementMatchingService $matching,
        private readonly StatementSuggestionService $suggestions,
    ) {}

    public function suggestions(string $statementLine): JsonResponse
    {
        $line = $this->findLine($statementLine);

        return response()->json([
            'data' => array_map(
                static fn ($suggestion): array => $suggestion->toArray(),
                $this->suggestions->suggest($line->id),
            ),
        ]);
    }

    public function targetProvenance(string $targetType, string $targetId): JsonResponse
    {
        if (! in_array($targetType, ['payment_instrument', 'expense_document', 'income_document'], true)
            || ! Str::isUuid($targetId)) {
            abort(404);
        }
        $company = $this->companyContext->requireCompany();
        $executions = BankStatementMatchExecution::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->whereIn('bank_statement_line_id', BankStatementLine::query()
                ->select('id')
                ->whereIn('bank_statement_id', BankStatement::query()
                    ->select('id')
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)))
            ->with('line:id,bank_statement_id')
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $executions->map(static function (BankStatementMatchExecution $execution): array {
            $line = $execution->line;
            if (! $line instanceof BankStatementLine) {
                throw new \LogicException('Statement execution provenance requires its statement line.');
            }

            return [
                'bank_statement_id' => $line->bank_statement_id,
                'bank_statement_line_id' => $execution->bank_statement_line_id,
                'action_type' => $execution->action_type->value,
                'executed_at' => $execution->executed_at->toIso8601String(),
            ];
        })->values()]);
    }

    public function allocate(AllocateStatementLineRequest $request, string $statementLine): JsonResponse
    {
        $line = $this->findLine($statementLine);
        /** @var array{allocations: list<array{repository_movement_id: string, amount: string}>} $validated */
        $validated = $request->validated();
        $allocations = array_map(static fn (array $allocation): array => [
            'movementId' => $allocation['repository_movement_id'],
            'amount' => $allocation['amount'],
        ], $validated['allocations']);
        $this->matching->allocate($line->id, $allocations, $this->userId($request));

        return response()->json(['data' => $this->format($line)]);
    }

    public function unallocate(UnallocateStatementLineRequest $request, string $statementLine): JsonResponse
    {
        $line = $this->findLine($statementLine);
        /** @var array{repository_movement_id?: string|null} $validated */
        $validated = $request->validated();
        $this->matching->unallocate(
            $line->id,
            $validated['repository_movement_id'] ?? null,
            $this->userId($request),
        );

        return response()->json(['data' => $this->format($line)]);
    }

    public function ignore(IgnoreStatementLineRequest $request, string $statementLine): JsonResponse
    {
        $line = $this->findLine($statementLine);
        /** @var array{reason: string, text: string} $validated */
        $validated = $request->validated();
        $this->matching->ignore(
            $line->id,
            StatementLineIgnoreReason::from($validated['reason']),
            $validated['text'],
            $this->userId($request),
        );

        return response()->json(['data' => $this->format($line)]);
    }

    public function unignore(Request $request, string $statementLine): JsonResponse
    {
        $line = $this->findLine($statementLine);
        $this->matching->unignore($line->id, $this->userId($request));

        return response()->json(['data' => $this->format($line)]);
    }

    public function execute(ExecuteStatementActionRequest $request, string $statementLine): JsonResponse
    {
        $line = $this->findLine($statementLine);
        /** @var array{action: string, params?: array<string, mixed>} $validated */
        $validated = $request->validated();
        $this->matching->executeAndAllocate(
            $line->id,
            MatchActionType::from($validated['action']),
            $validated['params'] ?? [],
            $this->userId($request),
        );

        return response()->json(['data' => $this->format($line)]);
    }

    private function findLine(string $id): BankStatementLine
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $company = $this->companyContext->requireCompany();

        return BankStatementLine::query()
            ->whereIn('bank_statement_id', BankStatement::query()
                ->select('id')
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id))
            ->findOrFail($id);
    }

    private function userId(Request $request): string
    {
        $userId = $request->user()?->id;
        if (! is_string($userId)) {
            abort(401);
        }

        return $userId;
    }

    /** @return array<string, mixed> */
    private function format(BankStatementLine $line): array
    {
        $line = $line->fresh(['allocations.movement', 'executions']) ?? $line;

        return [
            'id' => $line->id,
            'match_status' => $line->match_status->value,
            'ignore_reason' => $line->ignore_reason?->value,
            'ignore_text' => $line->ignore_text,
            'allocations' => $line->allocations->map(static fn ($allocation): array => [
                'repository_movement_id' => $allocation->repository_movement_id,
                'matched_amount' => $allocation->matched_amount,
                'match_type' => $allocation->match_type->value,
                'movement_direction' => $allocation->movement->direction->value,
            ])->values(),
            'executions' => $line->executions->map(static fn ($execution): array => [
                'action_type' => $execution->action_type->value,
                'target_type' => $execution->target_type,
                'target_id' => $execution->target_id,
                'produced_repository_movement_ids' => $execution->produced_repository_movement_ids,
            ])->values(),
        ];
    }
}
