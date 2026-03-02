<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Modules\Accounting\Application\DTOs\JournalEntryData;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\DoubleEntryValidator;
use App\Modules\Accounting\Presentation\Requests\CreateJournalEntryRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly DoubleEntryValidator $validator,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $entries = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->with('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $entries->getCollection()->map(
            fn (JournalEntry $entry) => JournalEntryData::fromModel($entry)->toArray()
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function store(CreateJournalEntryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<int, array{account_id: string, debit: string, credit: string, description?: string}> $lines */
        $lines = $request->validated()['lines'];

        // Validate double-entry rules
        if (! $this->validator->isBalanced($lines)) {
            return response()->json([
                'error' => [
                    'code' => 'UNBALANCED_ENTRY',
                    'message' => 'Journal entry debits must equal credits',
                ],
            ], 422);
        }

        if (! $this->validator->hasValidLines($lines)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_LINE',
                    'message' => 'Each line must have either a debit or credit amount, not both',
                ],
            ], 422);
        }

        $entry = DB::transaction(function () use ($request, $lines, $tenantId, $companyId): JournalEntry {
            $entryNumber = $this->generateEntryNumber($tenantId);

            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $request->validated()['entry_date'],
                'description' => $request->validated()['description'] ?? null,
                'status' => JournalEntryStatus::Draft,
            ]);

            foreach ($lines as $index => $lineData) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $lineData['account_id'],
                    'debit' => $lineData['debit'],
                    'credit' => $lineData['credit'],
                    'description' => $lineData['description'] ?? null,
                    'line_order' => $index,
                ]);
            }

            return $entry->load('lines');
        });

        return response()->json([
            'data' => JournalEntryData::fromModel($entry)->toArray(),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $entry = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->with('lines')
            ->findOrFail($id);

        return response()->json([
            'data' => JournalEntryData::fromModel($entry)->toArray(),
        ]);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $entry = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->with('lines')
            ->findOrFail($id);

        if ($entry->status !== JournalEntryStatus::Draft) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Only draft entries can be posted',
                ],
            ], 422);
        }

        $entry->update([
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
            'posted_by' => $user->id,
            'fiscal_hash' => $this->calculateHash($entry),
            'previous_hash' => $this->getPreviousHash($tenantId),
        ]);

        /** @var JournalEntry $freshEntry */
        $freshEntry = $entry->fresh(['lines']);

        return response()->json([
            'data' => JournalEntryData::fromModel($freshEntry)->toArray(),
        ]);
    }

    private function generateEntryNumber(string $tenantId): string
    {
        $year = date('Y');
        $lastEntry = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('entry_number', 'like', "JE-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry !== null) {
            $lastNumber = (int) substr($lastEntry->entry_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('JE-%s-%06d', $year, $nextNumber);
    }

    private function calculateHash(JournalEntry $entry): string
    {
        $data = json_encode([
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'lines' => $entry->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ])->toArray(),
        ]);

        $previousHash = $this->getPreviousHash($entry->tenant_id);

        return hash('sha256', $previousHash.'|'.$data);
    }

    private function getPreviousHash(string $tenantId): string
    {
        $lastPosted = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('status', JournalEntryStatus::Posted)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('posted_at')
            ->first();

        return $lastPosted !== null ? $lastPosted->fiscal_hash ?? '' : '';
    }
}
