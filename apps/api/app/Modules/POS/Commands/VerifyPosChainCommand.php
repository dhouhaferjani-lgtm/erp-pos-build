<?php

declare(strict_types=1);

namespace App\Modules\POS\Commands;

use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * CLI command to verify POS receipt and Z-report hash chain integrity.
 *
 * Used for NF525 compliance auditing. Verifies that all hash chains
 * across terminals are intact and have not been tampered with.
 *
 * @cross-tenant-by-design Iterates Terminal::active()->orderBy('code')->get()
 *                         across all companies for fiscal hash chain integrity
 *                         verification (NF525 audit). The optional --company /
 *                         --terminal options are narrowing filters, not a tenant
 *                         scope; the canonical run path is fleet-wide. Per
 *                         master plan §14 invariant 1, this is a cat-(b)
 *                         legitimate cross-tenant command.
 */
final class VerifyPosChainCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pos:verify-chains
        {--company= : Company UUID to verify}
        {--terminal= : Specific terminal UUID to verify}
        {--type=all : Chain type to verify: all, receipts, z-reports}';

    /**
     * @var string
     */
    protected $description = 'Verify POS receipt and Z-report hash chain integrity';

    public function __construct(
        private readonly ReceiptHashService $receiptHashService,
        private readonly ZReportHashService $zReportHashService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = $this->option('type');
        if (! in_array($type, ['all', 'receipts', 'z-reports'], true)) {
            $this->error("Invalid type '{$type}'. Must be one of: all, receipts, z-reports");

            return self::FAILURE;
        }

        $terminals = $this->resolveTerminals();

        if ($terminals->isEmpty()) {
            $this->info('No active terminals found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Verifying %d terminal(s)...', $terminals->count()));
        $this->newLine();

        /** @var list<array{terminal_code: string, company: string, chain_type: string, status: string, count: int, break_point: string}> $rows */
        $rows = [];
        $hasFailure = false;

        foreach ($terminals as $terminal) {
            $companyName = $terminal->company->name;

            if ($type === 'all' || $type === 'receipts') {
                $result = $this->verifyReceiptChain($terminal);
                $rows[] = [
                    'terminal_code' => $terminal->code,
                    'company' => $companyName,
                    'chain_type' => 'Receipts',
                    'status' => $result['is_valid'] ? "\u{2713}" : "\u{2717}",
                    'count' => $result['count'],
                    'break_point' => $result['break_point'],
                ];

                if (! $result['is_valid']) {
                    $hasFailure = true;
                }
            }

            if ($type === 'all' || $type === 'z-reports') {
                $result = $this->verifyZReportChain($terminal);
                $rows[] = [
                    'terminal_code' => $terminal->code,
                    'company' => $companyName,
                    'chain_type' => 'Z-Reports',
                    'status' => $result['is_valid'] ? "\u{2713}" : "\u{2717}",
                    'count' => $result['count'],
                    'break_point' => $result['break_point'],
                ];

                if (! $result['is_valid']) {
                    $hasFailure = true;
                }
            }
        }

        $this->table(
            ['Terminal Code', 'Company', 'Chain Type', 'Status', 'Count', 'Break Point'],
            $rows,
        );

        $this->newLine();

        if ($hasFailure) {
            $this->error('Chain verification FAILED - one or more chains are broken.');

            return self::FAILURE;
        }

        $this->info('All chains verified successfully.');

        return self::SUCCESS;
    }

    /**
     * Resolve terminals based on command options.
     *
     * @return Collection<int, Terminal>
     */
    private function resolveTerminals(): Collection
    {
        $terminalId = $this->option('terminal');
        if (is_string($terminalId) && $terminalId !== '') {
            $terminal = Terminal::with('company')->find($terminalId);
            if ($terminal === null) {
                $this->error("Terminal '{$terminalId}' not found.");

                return collect();
            }

            return collect([$terminal]);
        }

        $companyId = $this->option('company');
        if (is_string($companyId) && $companyId !== '') {
            return Terminal::with('company')
                ->where('company_id', $companyId)
                ->active()
                ->orderBy('code')
                ->get();
        }

        return Terminal::with('company')
            ->active()
            ->orderBy('code')
            ->get();
    }

    /**
     * Verify receipt chain for a terminal.
     *
     * @return array{is_valid: bool, count: int, break_point: string}
     */
    private function verifyReceiptChain(Terminal $terminal): array
    {
        $count = Receipt::where('terminal_id', $terminal->id)
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->count();

        if ($count === 0) {
            return [
                'is_valid' => true,
                'count' => 0,
                'break_point' => '-',
            ];
        }

        $isValid = $this->receiptHashService->verifyTerminalChain($terminal);

        $breakPoint = '-';
        if (! $isValid) {
            $breakPoint = $this->findReceiptChainBreak($terminal);
        }

        return [
            'is_valid' => $isValid,
            'count' => $count,
            'break_point' => $breakPoint,
        ];
    }

    /**
     * Find the receipt chain break point by iterating through receipts.
     */
    private function findReceiptChainBreak(Terminal $terminal): string
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->orderBy('chain_sequence')
            ->get();

        $previousHash = null;

        foreach ($receipts as $receipt) {
            if ($receipt->previous_hash !== $previousHash) {
                return sprintf('Sequence #%d (link)', $receipt->chain_sequence);
            }

            $expectedHash = $this->receiptHashService->calculateHash($receipt, $previousHash);
            if ($expectedHash !== $receipt->fiscal_hash) {
                return sprintf('Sequence #%d (hash)', $receipt->chain_sequence);
            }

            $previousHash = $receipt->fiscal_hash;
        }

        // Chain data matches but terminal last_hash may be stale
        return 'Terminal last_hash mismatch';
    }

    /**
     * Verify Z-report chain for a terminal.
     *
     * @return array{is_valid: bool, count: int, break_point: string}
     */
    private function verifyZReportChain(Terminal $terminal): array
    {
        $count = ZReport::where('terminal_id', $terminal->id)->count();

        if ($count === 0) {
            return [
                'is_valid' => true,
                'count' => 0,
                'break_point' => '-',
            ];
        }

        $isValid = $this->zReportHashService->verifyZReportChain($terminal);

        $breakPoint = '-';
        if (! $isValid) {
            $brokenReport = $this->zReportHashService->findChainBreak($terminal);
            if ($brokenReport !== null) {
                $breakPoint = sprintf('Z%04d', $brokenReport->z_number);
            }
        }

        return [
            'is_valid' => $isValid,
            'count' => $count,
            'break_point' => $breakPoint,
        ];
    }
}
