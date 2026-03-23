<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Exporters;

use App\Modules\Taxation\Domain\Contracts\VatExporterInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEC (Fichier des Ecritures Comptables) exporter.
 *
 * Produces a tab-delimited flat file with 18 fields per the French tax authority format.
 * Only VAT-relevant journal entries (accounts 44x) are included.
 */
class FecExporter implements VatExporterInterface
{
    private const FIELD_SEPARATOR = "\t";

    /** @var string[] */
    private const HEADERS = [
        'JournalCode',
        'JournalLib',
        'EcritureNum',
        'EcritureDate',
        'CompteNum',
        'CompteLib',
        'CompAuxNum',
        'CompAuxLib',
        'PieceRef',
        'PieceDate',
        'EcritureLib',
        'Debit',
        'Credit',
        'EcritureLet',
        'DateLet',
        'ValidDate',
        'Montantdevise',
        'Idevise',
    ];

    public function __construct(
        private readonly string $siren = '000000000',
    ) {}

    public function supports(VatExportFormat $format): bool
    {
        return $format === VatExportFormat::Fec;
    }

    public function export(VatSummary $summary, VatDeclarationData $declaration): StreamedResponse
    {
        $callback = function () use ($summary): void {
            // Header row
            echo implode(self::FIELD_SEPARATOR, self::HEADERS)."\n";

            $entryNum = 1;
            $dateStr = date('Ymd');

            // Output VAT entries (collected VAT — credit side, account 4457x)
            foreach ($summary->outputBreakdowns as $breakdown) {
                $this->writeEntry(
                    journalCode: 'OD',
                    journalLib: 'Operations Diverses',
                    ecritureNum: (string) $entryNum,
                    ecritureDate: $dateStr,
                    compteNum: $this->resolveOutputAccount($breakdown->taxRate),
                    compteLib: "TVA collectee {$breakdown->taxRate}%",
                    pieceRef: "VAT-{$entryNum}",
                    pieceDate: $dateStr,
                    ecritureLib: "TVA collectee {$breakdown->taxRate}%",
                    debit: '0.00',
                    credit: $this->formatAmount($breakdown->vatAmount),
                    validDate: $dateStr,
                );
                $entryNum++;
            }

            // Input VAT entries (deductible VAT — debit side, account 4456x)
            foreach ($summary->inputBreakdowns as $breakdown) {
                $this->writeEntry(
                    journalCode: 'OD',
                    journalLib: 'Operations Diverses',
                    ecritureNum: (string) $entryNum,
                    ecritureDate: $dateStr,
                    compteNum: $this->resolveInputAccount($breakdown->taxRate),
                    compteLib: "TVA deductible {$breakdown->taxRate}%",
                    pieceRef: "VAT-{$entryNum}",
                    pieceDate: $dateStr,
                    ecritureLib: "TVA deductible {$breakdown->taxRate}%",
                    debit: $this->formatAmount($breakdown->vatAmount),
                    credit: '0.00',
                    validDate: $dateStr,
                );
                $entryNum++;
            }
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => $this->getContentType(),
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function getContentType(): string
    {
        return 'text/plain; charset=UTF-8';
    }

    public function getFilename(VatPeriod $period): string
    {
        $closingDate = $period->period_end->format('Ymd');

        return "{$this->siren}FEC{$closingDate}.txt";
    }

    /**
     * Format a monetary amount to 2 decimal places.
     */
    private function formatAmount(string $amount): string
    {
        /** @var numeric-string $amount */
        return bcadd($amount, '0', 2);
    }

    /**
     * Resolve PCG output VAT account number (4457xx).
     * Standard French PCG: 44571 = TVA collectee
     */
    private function resolveOutputAccount(string $taxRate): string
    {
        $rateKey = str_replace('.', '', $taxRate);

        return "44571{$rateKey}";
    }

    /**
     * Resolve PCG input VAT account number (4456xx).
     * Standard French PCG: 44566 = TVA deductible sur biens et services
     */
    private function resolveInputAccount(string $taxRate): string
    {
        $rateKey = str_replace('.', '', $taxRate);

        return "44566{$rateKey}";
    }

    private function writeEntry(
        string $journalCode,
        string $journalLib,
        string $ecritureNum,
        string $ecritureDate,
        string $compteNum,
        string $compteLib,
        string $pieceRef,
        string $pieceDate,
        string $ecritureLib,
        string $debit,
        string $credit,
        string $validDate,
    ): void {
        $fields = [
            $journalCode,       // JournalCode
            $journalLib,        // JournalLib
            $ecritureNum,       // EcritureNum
            $ecritureDate,      // EcritureDate (AAAAMMJJ)
            $compteNum,         // CompteNum
            $compteLib,         // CompteLib
            '',                 // CompAuxNum
            '',                 // CompAuxLib
            $pieceRef,          // PieceRef
            $pieceDate,         // PieceDate (AAAAMMJJ)
            $ecritureLib,       // EcritureLib
            $debit,             // Debit
            $credit,            // Credit
            '',                 // EcritureLet
            '',                 // DateLet
            $validDate,         // ValidDate (AAAAMMJJ)
            '',                 // Montantdevise
            'EUR',              // Idevise
        ];

        echo implode(self::FIELD_SEPARATOR, $fields)."\n";
    }
}
