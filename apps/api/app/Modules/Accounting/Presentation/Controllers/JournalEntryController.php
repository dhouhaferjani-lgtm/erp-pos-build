<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Modules\Accounting\Application\DTOs\JournalEntryData;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\DoubleEntryValidator;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Accounting\Presentation\Requests\CreateJournalEntryRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly DoubleEntryValidator $validator,
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // W-8 F-1: tenant_id ALONE let company A list company B's journal
        // entries inside the same tenant. company_id is the authorization axis
        // here, not a filter.
        $entries = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('lines.account')
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
                'source_type' => 'manual',
            ]);
            $entry->update(['source_id' => $entry->id]);

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

            return $entry->load('lines.account');
        });

        $this->dispatchJournalEntryCreatedEvent($entry);

        return response()->json([
            'data' => JournalEntryData::fromModel($entry)->toArray(),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // W-8 F-1: company_id pins the authorization axis; a sibling-company
        // entry id now 404s exactly as a cross-tenant one already did.
        $entry = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('lines.account')
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

        // W-8 F-1: company_id pins the authorization axis; a sibling-company
        // entry id now 404s exactly as a cross-tenant one already did.
        $entry = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('lines.account')
            ->findOrFail($id);

        if ($entry->status !== JournalEntryStatus::Draft) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Only draft entries can be posted',
                ],
            ], 422);
        }

        // W-8 F-1 (settlement currency): never settle with the CALLER's currency.
        // GeneralLedgerService derives the currency from $entry->company_id when
        // none is supplied, so the entry is always sealed in its OWN company's
        // currency even if a future caller-scope regression slips through.
        $this->generalLedgerService->postEntry($entry, $user);

        /** @var JournalEntry $freshEntry */
        $freshEntry = $entry->fresh(['lines.account']);

        return response()->json([
            'data' => JournalEntryData::fromModel($freshEntry)->toArray(),
        ]);
    }

    /**
     * Allocate the next journal-entry number for the MANUAL-entry endpoint.
     *
     * Since migration `2026_08_30_100900`, persistence uniqueness is
     * `(company_id, entry_number)`, named
     * `JournalEntryIndexNames::COMPANY_ENTRY_NUMBER_UNIQUE`. JE-* allocation
     * deliberately remains TENANT-wide: this scan and the service share one
     * sequence and one byte-identical lock key,
     * `journal_entry_number:{tenantId}`. That remains unique under the narrower
     * company constraint. Per-company JE-* allocation is a separate follow-up
     * (LEDGER D-J0-2). The caller (`store()`) runs this inside `DB::transaction`,
     * so the transaction-scoped lock is held to commit.
     * No company-keyed chain lock is taken here — this path creates a DRAFT entry
     * and never touches `chain_sequence`; the per-company chain lock is taken later
     * by `sealAndPersistEntry` when the entry is posted, which preserves the global
     * tenant-key-before-company-key order.
     */
    private function generateEntryNumber(string $tenantId): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["journal_entry_number:{$tenantId}"],
            );
        }

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

    private function dispatchJournalEntryCreatedEvent(JournalEntry $entry): void
    {
        [$totalDebit, $totalCredit] = $this->journalTotals($entry);

        event(new JournalEntryCreated(
            journalEntryId: $entry->id,
            tenantId: $entry->tenant_id,
            companyId: $entry->company_id,
            entryNumber: $entry->entry_number,
            entryDate: $entry->entry_date->toDateString(),
            entryType: 'manual',
            sourceType: $entry->source_type ?? 'manual',
            sourceId: $entry->source_id ?? $entry->id,
            totalDebit: $totalDebit,
            totalCredit: $totalCredit,
            fiscalHash: $entry->fiscal_hash ?? '',
            chainSequence: $entry->chain_sequence ?? 0,
            createdAt: ($entry->created_at ?? now())->toIso8601String(),
        ));
    }

    /**
     * @return array{numeric-string, numeric-string}
     */
    private function journalTotals(JournalEntry $entry): array
    {
        /** @var numeric-string $totalDebit */
        $totalDebit = '0';
        /** @var numeric-string $totalCredit */
        $totalCredit = '0';
        $scale = $this->scaleResolver->getScale();

        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, $scale);
            $totalCredit = bcadd($totalCredit, $line->credit, $scale);
        }

        return [$totalDebit, $totalCredit];
    }
}
