<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services\Nf525;

use App\Modules\Compliance\Domain\Enums\Nf525EventType;
use App\Shared\Contracts\Compliance\DTOs\Nf525CashDrawerOperationData;
use App\Shared\Contracts\Compliance\DTOs\Nf525GrandTotalData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptPrintData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ShiftData;
use App\Shared\Contracts\Compliance\DTOs\Nf525TerminalData;
use App\Shared\Contracts\Compliance\DTOs\Nf525TerminalLifecycleEventData;
use App\Shared\Contracts\Compliance\DTOs\Nf525TrainingModeCount;
use App\Shared\Contracts\Compliance\DTOs\Nf525ZReportData;
use DOMDocument;
use DOMElement;

/**
 * Builder for NF525 JET (Journal des Evenements Techniques) XML documents.
 *
 * Constructs the XML structure required for French fiscal authority audits.
 * Each section maps to a specific NF525 requirement for POS system certification.
 *
 * After H3: this builder consumes only DTOs from
 * `App\Shared\Contracts\Compliance\DTOs\` — it has zero direct knowledge of
 * POS Eloquent models. The byte-stable JET XML it produces is locked by
 * `tests/Feature/Compliance/Nf525ExportSnapshotTest.php`.
 */
final class Nf525XmlBuilder
{
    private DOMDocument $doc;

    private DOMElement $root;

    /**
     * Create a new XML document with the JET root element.
     */
    public function createDocument(): self
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $this->root = $this->doc->createElement('JET');
        $this->root->setAttribute('xmlns', 'urn:nf525:jet:v1');
        $this->root->setAttribute('version', '1.0');
        $this->doc->appendChild($this->root);

        return $this;
    }

    /**
     * Add header section with company and export metadata.
     *
     * @param  array{company_name: string, company_id: string, siret?: string|null, address?: string|null, software_name: string, software_version: string, certification_number?: string, period_start: string, period_end: string, export_date: string}  $data
     */
    public function addHeader(array $data): self
    {
        $header = $this->doc->createElement('Entete');

        $this->addElement($header, 'Editeur', $data['software_name']);
        $this->addElement($header, 'VersionLogiciel', $data['software_version']);

        if (isset($data['certification_number']) && $data['certification_number'] !== '') {
            $this->addElement($header, 'NumeroCertification', $data['certification_number']);
        }

        $this->addElement($header, 'DateExport', $data['export_date']);

        $company = $this->doc->createElement('Societe');
        $this->addElement($company, 'Identifiant', $data['company_id']);
        $this->addElement($company, 'RaisonSociale', $data['company_name']);

        if (array_key_exists('siret', $data) && $data['siret'] !== null) {
            $this->addElement($company, 'SIRET', $data['siret']);
        }
        if (array_key_exists('address', $data) && $data['address'] !== null) {
            $this->addElement($company, 'Adresse', $data['address']);
        }

        $header->appendChild($company);

        $period = $this->doc->createElement('Periode');
        $this->addElement($period, 'DateDebut', $data['period_start']);
        $this->addElement($period, 'DateFin', $data['period_end']);
        $header->appendChild($period);

        $this->root->appendChild($header);

        return $this;
    }

    /**
     * Add receipts section (TICKET events).
     *
     * @param  list<Nf525ReceiptData>  $receipts
     */
    public function addReceipts(array $receipts): self
    {
        $section = $this->doc->createElement('Tickets');
        $section->setAttribute('type', Nf525EventType::ReceiptCreated->value);
        $section->setAttribute('count', (string) count($receipts));

        foreach ($receipts as $receipt) {
            $ticket = $this->doc->createElement('Ticket');
            $this->addElement($ticket, 'Identifiant', $receipt->id);
            $this->addElement($ticket, 'Numero', $receipt->receiptNumber);
            $this->addElement($ticket, 'TerminalId', $receipt->terminalId);
            $this->addElement($ticket, 'Date', $receipt->postedAtIso8601);
            $this->addElement($ticket, 'SequenceChaine', (string) $receipt->chainSequence);
            $this->addElement($ticket, 'HashFiscal', $receipt->fiscalHash);
            $this->addElement($ticket, 'HashPrecedent', $receipt->previousHash ?? '');
            $this->addElement($ticket, 'SousTotal', $receipt->subtotal);
            $this->addElement($ticket, 'Taxe', $receipt->taxAmount);
            $this->addElement($ticket, 'Remise', $receipt->discountAmount);
            $this->addElement($ticket, 'Total', $receipt->total);
            $this->addElement($ticket, 'Devise', $receipt->currency);
            $this->addElement($ticket, 'Caissier', $receipt->cashierName);

            if ($receipt->customerName !== null) {
                $this->addElement($ticket, 'Client', $receipt->customerName);
            }

            $lines = $this->doc->createElement('Lignes');
            foreach ($receipt->lines as $line) {
                $lineEl = $this->doc->createElement('Ligne');
                $this->addElement($lineEl, 'Numero', (string) $line->lineNumber);
                $this->addElement($lineEl, 'CodeProduit', $line->productCode ?? '');
                $this->addElement($lineEl, 'NomProduit', $line->productName);
                $this->addElement($lineEl, 'Quantite', $line->quantity);
                $this->addElement($lineEl, 'PrixUnitaire', $line->unitPrice);
                $this->addElement($lineEl, 'TotalLigne', $line->lineTotal);
                $this->addElement($lineEl, 'TauxTVA', $line->taxRate);
                $this->addElement($lineEl, 'MontantTVA', $line->taxAmount);
                $this->addElement($lineEl, 'Remise', $line->discountAmount);
                $lines->appendChild($lineEl);
            }
            $ticket->appendChild($lines);

            $vatSection = $this->doc->createElement('VentilationTVA');
            foreach ($receipt->vatDetails as $vat) {
                $vatEl = $this->doc->createElement('TVA');
                $this->addElement($vatEl, 'Taux', $vat->taxRate);
                $this->addElement($vatEl, 'BaseHT', $vat->netAmount);
                $this->addElement($vatEl, 'MontantTVA', $vat->vatAmount);
                $this->addElement($vatEl, 'TotalTTC', $vat->grossAmount);
                $vatSection->appendChild($vatEl);
            }
            $ticket->appendChild($vatSection);

            $paymentsSection = $this->doc->createElement('Paiements');
            foreach ($receipt->payments as $payment) {
                $payEl = $this->doc->createElement('Paiement');
                $this->addElement($payEl, 'Type', $payment->paymentType);
                $this->addElement($payEl, 'Montant', $payment->amount);
                $paymentsSection->appendChild($payEl);
            }
            $ticket->appendChild($paymentsSection);

            $section->appendChild($ticket);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add voided receipts section (ANNULATION events).
     *
     * @param  list<Nf525ReceiptData>  $voidedReceipts
     */
    public function addVoids(array $voidedReceipts): self
    {
        $section = $this->doc->createElement('Annulations');
        $section->setAttribute('type', Nf525EventType::ReceiptVoided->value);
        $section->setAttribute('count', (string) count($voidedReceipts));

        foreach ($voidedReceipts as $receipt) {
            $annulation = $this->doc->createElement('Annulation');
            $this->addElement($annulation, 'Identifiant', $receipt->id);
            $this->addElement($annulation, 'Numero', $receipt->receiptNumber);
            $this->addElement($annulation, 'TerminalId', $receipt->terminalId);
            $this->addElement($annulation, 'Total', $receipt->total);
            $this->addElement($annulation, 'Motif', $receipt->voidReason ?? '');
            $this->addElement($annulation, 'AnnulePar', $receipt->voidedBy ?? '');
            $this->addElement($annulation, 'DateAnnulation', $receipt->voidedAtIso8601 ?? '');
            $this->addElement($annulation, 'HashFiscalOriginal', $receipt->fiscalHash);
            $section->appendChild($annulation);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add return receipts section (RETOUR events).
     *
     * @param  list<Nf525ReceiptData>  $returnReceipts
     */
    public function addReturns(array $returnReceipts): self
    {
        $section = $this->doc->createElement('Retours');
        $section->setAttribute('type', Nf525EventType::ReceiptReturn->value);
        $section->setAttribute('count', (string) count($returnReceipts));

        foreach ($returnReceipts as $receipt) {
            $retour = $this->doc->createElement('Retour');
            $this->addElement($retour, 'Identifiant', $receipt->id);
            $this->addElement($retour, 'Numero', $receipt->receiptNumber);
            $this->addElement($retour, 'TerminalId', $receipt->terminalId);
            $this->addElement($retour, 'Total', $receipt->total);
            $this->addElement($retour, 'HashFiscal', $receipt->fiscalHash);
            $this->addElement($retour, 'TicketOriginal', $receipt->originalReceiptId ?? '');
            $this->addElement($retour, 'MotifRetour', $receipt->returnReasonValue ?? '');
            $this->addElement($retour, 'Date', $receipt->postedAtIso8601);
            $section->appendChild($retour);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add reprint log section (DUPLICATA events).
     *
     * @param  list<Nf525ReceiptPrintData>  $receiptPrints
     */
    public function addReprints(array $receiptPrints): self
    {
        $section = $this->doc->createElement('Duplicatas');
        $section->setAttribute('type', Nf525EventType::ReceiptReprinted->value);
        $section->setAttribute('count', (string) count($receiptPrints));

        foreach ($receiptPrints as $print) {
            $duplicata = $this->doc->createElement('Duplicata');
            $this->addElement($duplicata, 'Identifiant', $print->id);
            $this->addElement($duplicata, 'TicketId', $print->receiptId);
            $this->addElement($duplicata, 'TerminalId', $print->terminalId);
            $this->addElement($duplicata, 'UtilisateurId', $print->userId);
            $this->addElement($duplicata, 'TypeImpression', $print->printType);
            $this->addElement($duplicata, 'NumeroCopie', (string) $print->copyNumber);
            $this->addElement($duplicata, 'MethodeImpression', $print->printMethod);
            $this->addElement($duplicata, 'DateImpression', $print->printedAtIso8601);
            $section->appendChild($duplicata);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add Z reports section (RAPPORT_Z events).
     *
     * @param  list<Nf525ZReportData>  $zReports
     */
    public function addZReports(array $zReports): self
    {
        $section = $this->doc->createElement('RapportsZ');
        $section->setAttribute('type', Nf525EventType::ZReportGenerated->value);
        $section->setAttribute('count', (string) count($zReports));

        foreach ($zReports as $zReport) {
            $rapport = $this->doc->createElement('RapportZ');
            $this->addElement($rapport, 'Identifiant', $zReport->id);
            $this->addElement($rapport, 'TerminalId', $zReport->terminalId);
            $this->addElement($rapport, 'NumeroZ', (string) $zReport->zNumber);
            $this->addElement($rapport, 'HashFiscal', $zReport->fiscalHash);
            $this->addElement($rapport, 'HashPrecedent', $zReport->previousZHash ?? '');
            $this->addElement($rapport, 'DateGeneration', $zReport->generatedAtIso8601);

            $reportData = $zReport->reportData;
            $donnees = $this->doc->createElement('Donnees');
            $this->addElement($donnees, 'NombreVentes', (string) ($reportData['sales_count'] ?? 0));
            $this->addElement($donnees, 'VentesBrutes', (string) ($reportData['gross_sales'] ?? '0.00'));
            $this->addElement($donnees, 'VentesNettes', (string) ($reportData['net_sales'] ?? '0.00'));
            $this->addElement($donnees, 'MontantTaxe', (string) ($reportData['tax_amount'] ?? '0.00'));
            $this->addElement($donnees, 'NombreAnnulations', (string) ($reportData['voided_count'] ?? 0));
            $rapport->appendChild($donnees);

            $section->appendChild($rapport);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add grand totals section (perpetual counters).
     *
     * @param  list<Nf525GrandTotalData>  $grandtotals
     */
    public function addGrandTotals(array $grandtotals): self
    {
        $section = $this->doc->createElement('GrandsTotaux');
        $section->setAttribute('count', (string) count($grandtotals));

        foreach ($grandtotals as $grandtotal) {
            $gt = $this->doc->createElement('GrandTotal');
            $this->addElement($gt, 'Identifiant', $grandtotal->id);
            $this->addElement($gt, 'TerminalId', $grandtotal->terminalId);
            $this->addElement($gt, 'TypeEvenement', $grandtotal->eventType);
            $this->addElement($gt, 'NumeroSequence', (string) $grandtotal->sequenceNumber);
            $this->addElement($gt, 'HashFiscal', $grandtotal->fiscalHash);
            $this->addElement($gt, 'HashPrecedent', $grandtotal->previousHash ?? '');
            $this->addElement($gt, 'DebutPeriode', $grandtotal->periodStartIso8601);
            $this->addElement($gt, 'FinPeriode', $grandtotal->periodEndIso8601);
            $this->addElement($gt, 'DateGeneration', $grandtotal->generatedAtIso8601);

            $periodEl = $this->doc->createElement('TotauxPeriode');
            $this->addElement($periodEl, 'VentesBrutes', (string) ($grandtotal->periodTotals['gross_sales'] ?? '0.00'));
            $this->addElement($periodEl, 'Taxe', (string) ($grandtotal->periodTotals['tax_amount'] ?? '0.00'));
            $gt->appendChild($periodEl);

            $perpetualEl = $this->doc->createElement('TotauxPerpetuels');
            $this->addElement($perpetualEl, 'VentesCumulees', (string) ($grandtotal->perpetualTotals['lifetime_sales'] ?? '0.00'));
            $this->addElement($perpetualEl, 'TaxeCumulee', (string) ($grandtotal->perpetualTotals['lifetime_tax'] ?? '0.00'));
            $gt->appendChild($perpetualEl);

            $section->appendChild($gt);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add cash drawer operations section.
     *
     * @param  list<Nf525CashDrawerOperationData>  $operations
     */
    public function addCashDrawer(array $operations): self
    {
        $section = $this->doc->createElement('MouvementsCaisse');
        $section->setAttribute('count', (string) count($operations));

        foreach ($operations as $operation) {
            $mouvement = $this->doc->createElement('Mouvement');
            $this->addElement($mouvement, 'Identifiant', $operation->id);
            $this->addElement($mouvement, 'ShiftId', $operation->shiftId);
            $this->addElement($mouvement, 'TypeOperation', $operation->operationType);
            $this->addElement($mouvement, 'Montant', $operation->amount);
            $this->addElement($mouvement, 'UtilisateurId', $operation->userId);
            $this->addElement($mouvement, 'Motif', $operation->reason ?? '');
            $this->addElement($mouvement, 'Date', $operation->createdAtIso8601);

            $nf525Type = match ($operation->operationType) {
                'DEPOSIT' => Nf525EventType::CashDrawerDeposit->value,
                'PAYOUT' => Nf525EventType::CashDrawerPayout->value,
                'REFUND' => Nf525EventType::CashDrawerRefund->value,
                default => $operation->operationType,
            };
            $mouvement->setAttribute('nf525Type', $nf525Type);

            $section->appendChild($mouvement);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add technical events section (shift open/close).
     *
     * @param  list<Nf525ShiftData>  $shifts
     */
    public function addTechnicalEvents(array $shifts): self
    {
        $section = $this->doc->createElement('EvenementsTechniques');
        $section->setAttribute('count', (string) count($shifts));

        foreach ($shifts as $shift) {
            // Opening event
            $ouverture = $this->doc->createElement('Evenement');
            $ouverture->setAttribute('type', Nf525EventType::ShiftOpened->value);
            $this->addElement($ouverture, 'ShiftId', $shift->id);
            $this->addElement($ouverture, 'TerminalId', $shift->terminalId);
            $this->addElement($ouverture, 'CaissierId', $shift->cashierId);
            $this->addElement($ouverture, 'NumeroShift', (string) $shift->shiftNumber);
            $this->addElement($ouverture, 'SoldeOuverture', $shift->openingCash);
            $this->addElement($ouverture, 'DateOuverture', $shift->openedAtIso8601);
            $section->appendChild($ouverture);

            if ($shift->closedAtIso8601 !== null) {
                $fermeture = $this->doc->createElement('Evenement');
                $fermeture->setAttribute('type', Nf525EventType::ShiftClosed->value);
                $this->addElement($fermeture, 'ShiftId', $shift->id);
                $this->addElement($fermeture, 'TerminalId', $shift->terminalId);
                $this->addElement($fermeture, 'CaissierId', $shift->cashierId);
                $this->addElement($fermeture, 'NumeroShift', (string) $shift->shiftNumber);
                $this->addElement($fermeture, 'EspecesAttendues', $shift->expectedCash ?? '0.00');
                $this->addElement($fermeture, 'EspecesReelles', $shift->actualCash ?? '0.00');
                $this->addElement($fermeture, 'Ecart', $shift->variance ?? '0.00');
                $this->addElement($fermeture, 'DateFermeture', $shift->closedAtIso8601);
                $section->appendChild($fermeture);
            }
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add terminal lifecycle events section (ACTIVATION_TERMINAL, DESACTIVATION_TERMINAL, MAJ_LOGICIEL).
     *
     * @param  list<Nf525TerminalLifecycleEventData>  $events
     */
    public function addTerminalEvents(array $events): self
    {
        $section = $this->doc->createElement('EvenementsTerminal');
        $section->setAttribute('count', (string) count($events));

        foreach ($events as $auditEvent) {
            $eventType = $auditEvent->eventType;
            $payload = $auditEvent->payload;

            $nf525Type = match ($eventType) {
                'terminal.activated' => Nf525EventType::TerminalActivated->value,
                'terminal.deactivated' => Nf525EventType::TerminalDeactivated->value,
                'terminal.software_updated' => Nf525EventType::SoftwareUpdated->value,
                default => $eventType,
            };

            $eventEl = $this->doc->createElement('EvenementTerminal');
            $eventEl->setAttribute('type', $nf525Type);
            $this->addElement($eventEl, 'Identifiant', $auditEvent->id);
            $this->addElement($eventEl, 'TerminalId', $auditEvent->terminalId);
            $this->addElement($eventEl, 'CodeTerminal', (string) ($payload['terminal_code'] ?? ''));
            $this->addElement($eventEl, 'TypeEvenement', $nf525Type);
            $this->addElement($eventEl, 'Date', $auditEvent->occurredAtIso8601);

            if ($eventType === 'terminal.activated') {
                $this->addElement($eventEl, 'ActivePar', (string) ($payload['activated_by'] ?? ''));
            }

            if ($eventType === 'terminal.deactivated') {
                $this->addElement($eventEl, 'DesactivePar', (string) ($payload['deactivated_by'] ?? ''));
                $this->addElement($eventEl, 'Motif', (string) ($payload['reason'] ?? ''));
            }

            if ($eventType === 'terminal.software_updated') {
                $this->addElement($eventEl, 'VersionPrecedente', (string) ($payload['previous_version'] ?? ''));
                $this->addElement($eventEl, 'NouvelleVersion', (string) ($payload['new_version'] ?? ''));
            }

            $section->appendChild($eventEl);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add training mode section listing training receipt counts per terminal.
     *
     * @param  list<Nf525TrainingModeCount>  $trainingCounts
     */
    public function addTrainingMode(array $trainingCounts): self
    {
        $section = $this->doc->createElement('ModeFormation');
        $totalCount = 0;
        foreach ($trainingCounts as $row) {
            $totalCount += $row->count;
        }
        $section->setAttribute('count', (string) $totalCount);

        foreach ($trainingCounts as $row) {
            $terminal = $this->doc->createElement('TerminalFormation');
            $this->addElement($terminal, 'TerminalId', $row->terminalId);
            $this->addElement($terminal, 'NombreTicketsFormation', (string) $row->count);
            $section->appendChild($terminal);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add hash chain summaries per terminal.
     *
     * @param  list<Nf525TerminalData>  $terminals
     */
    public function addHashChains(array $terminals): self
    {
        $section = $this->doc->createElement('ChainesHash');
        $section->setAttribute('count', (string) count($terminals));

        foreach ($terminals as $terminal) {
            $chain = $this->doc->createElement('ChaineTerminal');
            $this->addElement($chain, 'TerminalId', $terminal->id);
            $this->addElement($chain, 'CodeTerminal', $terminal->code);
            $this->addElement($chain, 'NomTerminal', $terminal->name);
            $this->addElement($chain, 'GraineSeed', $terminal->genesisSeed);
            $this->addElement($chain, 'SequenceActuelle', (string) $terminal->currentSequence);
            $this->addElement($chain, 'AnneeActuelle', (string) $terminal->currentYear);
            $this->addElement($chain, 'DernierHash', $terminal->lastHash ?? '');
            $section->appendChild($chain);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Output the XML document as a formatted string.
     */
    public function toString(): string
    {
        $result = $this->doc->saveXML();

        return $result !== false ? $result : '';
    }

    /**
     * Add a text element to a parent element.
     */
    private function addElement(DOMElement $parent, string $name, string $value): void
    {
        $element = $this->doc->createElement($name);
        $element->appendChild($this->doc->createTextNode($value));
        $parent->appendChild($element);
    }
}
