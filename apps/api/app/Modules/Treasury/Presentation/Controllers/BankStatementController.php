<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Application\Exceptions\DuplicateStatementFileException;
use App\Modules\Treasury\Application\Services\StatementCompletionService;
use App\Modules\Treasury\Application\Services\StatementImportService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use App\Modules\Treasury\Presentation\Requests\ConfirmBankStatementRequest;
use App\Modules\Treasury\Presentation\Requests\UploadBankStatementRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class BankStatementController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly StatementImportService $imports,
        private readonly StatementCompletionService $completion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'status' => ['nullable', 'in:imported,reconciling,reconciled,voided'],
            'payment_repository_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = BankStatement::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->withCount('lines');
        foreach (['status', 'payment_repository_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        /** @var LengthAwarePaginator<int, BankStatement> $paginator */
        $paginator = $query->orderByDesc('period_end')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (BankStatement $statement): array => $this->format($statement)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $bankStatement): JsonResponse
    {
        $statement = $this->findStatement($bankStatement);
        $statement->load(['lines', 'parserProfile'])->loadCount('lines');

        return response()->json(['data' => $this->format($statement, true)]);
    }

    public function upload(UploadBankStatementRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var array{payment_repository_id: string, parser_profile_id: string} $validated */
        $validated = $request->validated();
        $repository = PaymentRepository::query()->findOrFail($validated['payment_repository_id']);
        $profile = StatementImportProfile::query()->findOrFail($validated['parser_profile_id']);
        try {
            $preview = $this->imports->preview(
                $request->file('file'),
                $repository,
                $profile,
                $company->tenant_id,
                $company->id,
            );
        } catch (DuplicateStatementFileException $exception) {
            return $this->duplicateFileResponse($exception);
        }

        return response()->json(['data' => $preview]);
    }

    public function store(ConfirmBankStatementRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var array{preview_token: string, currency: string, period_start: string, period_end: string, opening_balance: string, closing_balance: string, acknowledge_empty?: bool} $validated */
        $validated = $request->validated();
        $userId = $request->user()?->id;
        if (! is_string($userId)) {
            abort(401);
        }
        try {
            $result = $this->imports->confirm(
                $validated['preview_token'],
                $validated,
                $userId,
                $company->tenant_id,
                $company->id,
            );
        } catch (DuplicateStatementFileException $exception) {
            return $this->duplicateFileResponse($exception);
        }

        return response()->json([
            'data' => $this->format($result['statement']),
            'meta' => [
                'imported_line_count' => $result['imported_line_count'],
                'skipped_duplicate_count' => $result['skipped_duplicate_count'],
                'continuity_warning' => $result['continuity_warning'],
            ],
        ], 201);
    }

    public function void(string $bankStatement): JsonResponse
    {
        $statement = $this->imports->void($this->findStatement($bankStatement));

        return response()->json(['data' => $this->format($statement)]);
    }

    public function complete(Request $request, string $bankStatement): JsonResponse
    {
        $validated = $request->validate([
            'acknowledge_ignored_total' => ['sometimes', 'boolean'],
        ]);
        $statement = $this->findStatement($bankStatement);
        $userId = $request->user()?->id;
        if (! is_string($userId)) {
            abort(401);
        }
        $completed = $this->completion->complete(
            $statement->id,
            $userId,
            (bool) ($validated['acknowledge_ignored_total'] ?? false),
        );

        return response()->json(['data' => $this->format($completed)]);
    }

    public function reopen(Request $request, string $bankStatement): JsonResponse
    {
        $statement = $this->findStatement($bankStatement);
        $userId = $request->user()?->id;
        if (! is_string($userId)) {
            abort(401);
        }
        $reopened = $this->completion->reopen($statement->id, $userId);

        return response()->json(['data' => $this->format($reopened)]);
    }

    private function findStatement(string $id): BankStatement
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $company = $this->companyContext->requireCompany();

        return BankStatement::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
    }

    private function duplicateFileResponse(DuplicateStatementFileException $exception): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'DUPLICATE_STATEMENT_FILE', 'message' => $exception->getMessage()],
            'errors' => ['existing_statement_id' => $exception->statementId],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function format(BankStatement $statement, bool $withLines = false): array
    {
        $data = [
            'id' => $statement->id,
            'payment_repository_id' => $statement->payment_repository_id,
            'currency' => $statement->currency,
            'period_start' => $statement->period_start->toDateString(),
            'period_end' => $statement->period_end->toDateString(),
            'opening_balance' => $statement->opening_balance,
            'closing_balance' => $statement->closing_balance,
            'status' => $statement->status->value,
            'parser_profile_id' => $statement->parser_profile_id,
            'imported_at' => $statement->imported_at->toISOString(),
            'lines_count' => $statement->getAttribute('lines_count'),
        ];
        if ($withLines) {
            $data['lines'] = $statement->lines->map(static fn ($line): array => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'value_date' => $line->value_date->toDateString(),
                'booking_date' => $line->booking_date?->toDateString(),
                'direction' => $line->direction->value,
                'amount' => $line->amount,
                'reference' => $line->reference,
                'label' => $line->label,
                'match_status' => $line->match_status->value,
                'location_id' => $line->location_id,
            ])->values();
        }

        return $data;
    }
}
