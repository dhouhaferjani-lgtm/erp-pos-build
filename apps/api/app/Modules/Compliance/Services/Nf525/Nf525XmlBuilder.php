<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services\Nf525;

use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Domain\Enums\Nf525EventType;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\GrandtotalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPrint;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;

/**
 * Builder for NF525 JET (Journal des Evenements Techniques) XML documents.
 *
 * Constructs the XML structure required for French fiscal authority audits.
 * Each section maps to a specific NF525 requirement for POS system certification.
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
     * @param  array{company_name: string, company_id: string, siret?: string, address?: string, software_name: string, software_version: string, certification_number?: string, period_start: string, period_end: string, export_date: string}  $data
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

        if (isset($data['siret'])) {
            $this->addElement($company, 'SIRET', $data['siret']);
        }
        if (isset($data['address'])) {
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
     * @param  Collection<int, Receipt>  $receipts
     */
    public function addReceipts(Collection $receipts): self
    {
        $section = $this->doc->createElement('Tickets');
        $section->setAttribute('type', Nf525EventType::ReceiptCreated->value);
        $section->setAttribute('count', (string) $receipts->count());

        foreach ($receipts as $receipt) {
            $ticket = $this->doc->createElement('Ticket');
            $this->addElement($ticket, 'Identifiant', $receipt->id);
            $this->addElement($ticket, 'Numero', $receipt->receipt_number);
            $this->addElement($ticket, 'TerminalId', $receipt->terminal_id);
            $this->addElement($ticket, 'Date', $receipt->posted_at->toIso8601String());
            $this->addElement($ticket, 'SequenceChaine', (string) $receipt->chain_sequence);
            $this->addElement($ticket, 'HashFiscal', $receipt->fiscal_hash);
            $this->addElement($ticket, 'HashPrecedent', $receipt->previous_hash ?? '');
            $this->addElement($ticket, 'SousTotal', (string) $receipt->subtotal);
            $this->addElement($ticket, 'Taxe', (string) $receipt->tax_amount);
            $this->addElement($ticket, 'Remise', (string) $receipt->discount_amount);
            $this->addElement($ticket, 'Total', (string) $receipt->total);
            $this->addElement($ticket, 'Devise', $receipt->currency);
            $this->addElement($ticket, 'Caissier', $receipt->cashier_name);

            if ($receipt->customer_name !== null) {
                $this->addElement($ticket, 'Client', $receipt->customer_name);
            }

            // Lines
            if ($receipt->relationLoaded('lines')) {
                $lines = $this->doc->createElement('Lignes');
                foreach ($receipt->lines as $line) {
                    $lineEl = $this->doc->createElement('Ligne');
                    $this->addElement($lineEl, 'Numero', (string) $line->line_number);
                    $this->addElement($lineEl, 'CodeProduit', $line->product_code ?? '');
                    $this->addElement($lineEl, 'NomProduit', $line->product_name);
                    $this->addElement($lineEl, 'Quantite', (string) $line->quantity);
                    $this->addElement($lineEl, 'PrixUnitaire', (string) $line->unit_price);
                    $this->addElement($lineEl, 'TotalLigne', (string) $line->line_total);
                    $this->addElement($lineEl, 'TauxTVA', (string) $line->tax_rate);
                    $this->addElement($lineEl, 'MontantTVA', (string) $line->tax_amount);
                    $this->addElement($lineEl, 'Remise', (string) ($line->discount_amount ?? '0.00'));
                    $lines->appendChild($lineEl);
                }
                $ticket->appendChild($lines);
            }

            // VAT details
            if ($receipt->relationLoaded('vatDetails')) {
                $vatSection = $this->doc->createElement('VentilationTVA');
                foreach ($receipt->vatDetails as $vat) {
                    $vatEl = $this->doc->createElement('TVA');
                    $this->addElement($vatEl, 'Taux', (string) $vat->tax_rate);
                    $this->addElement($vatEl, 'BaseHT', (string) $vat->net_amount);
                    $this->addElement($vatEl, 'MontantTVA', (string) $vat->vat_amount);
                    $this->addElement($vatEl, 'TotalTTC', (string) $vat->gross_amount);
                    $vatSection->appendChild($vatEl);
                }
                $ticket->appendChild($vatSection);
            }

            // Payments
            if ($receipt->relationLoaded('payments')) {
                $paymentsSection = $this->doc->createElement('Paiements');
                foreach ($receipt->payments as $payment) {
                    $payEl = $this->doc->createElement('Paiement');
                    $this->addElement($payEl, 'Type', $payment->payment_type);
                    $this->addElement($payEl, 'Montant', (string) $payment->amount);
                    $paymentsSection->appendChild($payEl);
                }
                $ticket->appendChild($paymentsSection);
            }

            $section->appendChild($ticket);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add voided receipts section (ANNULATION events).
     *
     * @param  Collection<int, Receipt>  $voidedReceipts
     */
    public function addVoids(Collection $voidedReceipts): self
    {
        $section = $this->doc->createElement('Annulations');
        $section->setAttribute('type', Nf525EventType::ReceiptVoided->value);
        $section->setAttribute('count', (string) $voidedReceipts->count());

        foreach ($voidedReceipts as $receipt) {
            $annulation = $this->doc->createElement('Annulation');
            $this->addElement($annulation, 'Identifiant', $receipt->id);
            $this->addElement($annulation, 'Numero', $receipt->receipt_number);
            $this->addElement($annulation, 'TerminalId', $receipt->terminal_id);
            $this->addElement($annulation, 'Total', (string) $receipt->total);
            $this->addElement($annulation, 'Motif', $receipt->void_reason ?? '');
            $this->addElement($annulation, 'AnnulePar', $receipt->voided_by ?? '');
            $this->addElement($annulation, 'DateAnnulation', $receipt->voided_at?->toIso8601String() ?? '');
            $this->addElement($annulation, 'HashFiscalOriginal', $receipt->fiscal_hash);
            $section->appendChild($annulation);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add return receipts section (RETOUR events).
     *
     * @param  Collection<int, Receipt>  $returnReceipts
     */
    public function addReturns(Collection $returnReceipts): self
    {
        $section = $this->doc->createElement('Retours');
        $section->setAttribute('type', Nf525EventType::ReceiptReturn->value);
        $section->setAttribute('count', (string) $returnReceipts->count());

        foreach ($returnReceipts as $receipt) {
            $retour = $this->doc->createElement('Retour');
            $this->addElement($retour, 'Identifiant', $receipt->id);
            $this->addElement($retour, 'Numero', $receipt->receipt_number);
            $this->addElement($retour, 'TerminalId', $receipt->terminal_id);
            $this->addElement($retour, 'Total', (string) $receipt->total);
            $this->addElement($retour, 'HashFiscal', $receipt->fiscal_hash);
            $this->addElement($retour, 'TicketOriginal', $receipt->original_receipt_id ?? '');
            $this->addElement($retour, 'MotifRetour', $receipt->return_reason !== null ? $receipt->return_reason->value : '');
            $this->addElement($retour, 'Date', $receipt->posted_at->toIso8601String());
            $section->appendChild($retour);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add reprint log section (DUPLICATA events).
     *
     * @param  Collection<int, ReceiptPrint>  $receiptPrints
     */
    public function addReprints(Collection $receiptPrints): self
    {
        $section = $this->doc->createElement('Duplicatas');
        $section->setAttribute('type', Nf525EventType::ReceiptReprinted->value);
        $section->setAttribute('count', (string) $receiptPrints->count());

        foreach ($receiptPrints as $print) {
            $duplicata = $this->doc->createElement('Duplicata');
            $this->addElement($duplicata, 'Identifiant', $print->id);
            $this->addElement($duplicata, 'TicketId', $print->receipt_id);
            $this->addElement($duplicata, 'TerminalId', $print->terminal_id);
            $this->addElement($duplicata, 'UtilisateurId', $print->user_id);
            $this->addElement($duplicata, 'TypeImpression', $print->print_type->value);
            $this->addElement($duplicata, 'NumeroCopie', (string) $print->copy_number);
            $this->addElement($duplicata, 'MethodeImpression', $print->print_method->value);
            $this->addElement($duplicata, 'DateImpression', $print->printed_at->toIso8601String());
            $section->appendChild($duplicata);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add Z reports section (RAPPORT_Z events).
     *
     * @param  Collection<int, ZReport>  $zReports
     */
    public function addZReports(Collection $zReports): self
    {
        $section = $this->doc->createElement('RapportsZ');
        $section->setAttribute('type', Nf525EventType::ZReportGenerated->value);
        $section->setAttribute('count', (string) $zReports->count());

        foreach ($zReports as $zReport) {
            $rapport = $this->doc->createElement('RapportZ');
            $this->addElement($rapport, 'Identifiant', $zReport->id);
            $this->addElement($rapport, 'TerminalId', $zReport->terminal_id);
            $this->addElement($rapport, 'NumeroZ', (string) $zReport->z_number);
            $this->addElement($rapport, 'HashFiscal', $zReport->fiscal_hash);
            $this->addElement($rapport, 'HashPrecedent', $zReport->previous_z_hash ?? '');
            $this->addElement($rapport, 'DateGeneration', $zReport->generated_at->toIso8601String());

            // Report data summary
            $reportData = $zReport->report_data ?? [];
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
     * @param  Collection<int, GrandtotalEvent>  $grandtotals
     */
    public function addGrandTotals(Collection $grandtotals): self
    {
        $section = $this->doc->createElement('GrandsTotaux');
        $section->setAttribute('count', (string) $grandtotals->count());

        foreach ($grandtotals as $grandtotal) {
            $gt = $this->doc->createElement('GrandTotal');
            $this->addElement($gt, 'Identifiant', $grandtotal->id);
            $this->addElement($gt, 'TerminalId', $grandtotal->terminal_id);
            $this->addElement($gt, 'TypeEvenement', $grandtotal->event_type);
            $this->addElement($gt, 'NumeroSequence', (string) $grandtotal->sequence_number);
            $this->addElement($gt, 'HashFiscal', $grandtotal->fiscal_hash);
            $this->addElement($gt, 'HashPrecedent', $grandtotal->previous_hash ?? '');
            $this->addElement($gt, 'DebutPeriode', $grandtotal->period_start->toIso8601String());
            $this->addElement($gt, 'FinPeriode', $grandtotal->period_end->toIso8601String());
            $this->addElement($gt, 'DateGeneration', $grandtotal->generated_at->toIso8601String());

            $periodTotals = $grandtotal->period_totals ?? [];
            $periodEl = $this->doc->createElement('TotauxPeriode');
            $this->addElement($periodEl, 'VentesBrutes', (string) ($periodTotals['gross_sales'] ?? '0.00'));
            $this->addElement($periodEl, 'Taxe', (string) ($periodTotals['tax_amount'] ?? '0.00'));
            $gt->appendChild($periodEl);

            $perpetualTotals = $grandtotal->perpetual_totals ?? [];
            $perpetualEl = $this->doc->createElement('TotauxPerpetuels');
            $this->addElement($perpetualEl, 'VentesCumulees', (string) ($perpetualTotals['lifetime_sales'] ?? '0.00'));
            $this->addElement($perpetualEl, 'TaxeCumulee', (string) ($perpetualTotals['lifetime_tax'] ?? '0.00'));
            $gt->appendChild($perpetualEl);

            $section->appendChild($gt);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add cash drawer operations section.
     *
     * @param  Collection<int, CashDrawerOperation>  $operations
     */
    public function addCashDrawer(Collection $operations): self
    {
        $section = $this->doc->createElement('MouvementsCaisse');
        $section->setAttribute('count', (string) $operations->count());

        foreach ($operations as $operation) {
            $mouvement = $this->doc->createElement('Mouvement');
            $this->addElement($mouvement, 'Identifiant', $operation->id);
            $this->addElement($mouvement, 'ShiftId', $operation->shift_id);
            $this->addElement($mouvement, 'TypeOperation', $operation->operation_type);
            $this->addElement($mouvement, 'Montant', (string) $operation->amount);
            $this->addElement($mouvement, 'UtilisateurId', $operation->user_id);
            $this->addElement($mouvement, 'Motif', $operation->reason ?? '');
            $this->addElement($mouvement, 'Date', $operation->created_at->toIso8601String());

            // Map operation type to NF525 event type
            $nf525Type = match ($operation->operation_type) {
                'DEPOSIT' => Nf525EventType::CashDrawerDeposit->value,
                'PAYOUT' => Nf525EventType::CashDrawerPayout->value,
                'REFUND' => Nf525EventType::CashDrawerRefund->value,
                default => $operation->operation_type,
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
     * @param  Collection<int, Shift>  $shifts
     */
    public function addTechnicalEvents(Collection $shifts): self
    {
        $section = $this->doc->createElement('EvenementsTechniques');
        $section->setAttribute('count', (string) $shifts->count());

        foreach ($shifts as $shift) {
            // Opening event
            $ouverture = $this->doc->createElement('Evenement');
            $ouverture->setAttribute('type', Nf525EventType::ShiftOpened->value);
            $this->addElement($ouverture, 'ShiftId', $shift->id);
            $this->addElement($ouverture, 'TerminalId', $shift->terminal_id);
            $this->addElement($ouverture, 'CaissierId', $shift->cashier_id);
            $this->addElement($ouverture, 'NumeroShift', (string) $shift->shift_number);
            $this->addElement($ouverture, 'SoldeOuverture', (string) $shift->opening_cash);
            $this->addElement($ouverture, 'DateOuverture', $shift->opened_at->toIso8601String());
            $section->appendChild($ouverture);

            // Closing event (if shift is closed)
            if ($shift->closed_at !== null) {
                $fermeture = $this->doc->createElement('Evenement');
                $fermeture->setAttribute('type', Nf525EventType::ShiftClosed->value);
                $this->addElement($fermeture, 'ShiftId', $shift->id);
                $this->addElement($fermeture, 'TerminalId', $shift->terminal_id);
                $this->addElement($fermeture, 'CaissierId', $shift->cashier_id);
                $this->addElement($fermeture, 'NumeroShift', (string) $shift->shift_number);
                $this->addElement($fermeture, 'EspecesAttendues', (string) ($shift->expected_cash ?? '0.00'));
                $this->addElement($fermeture, 'EspecesReelles', (string) ($shift->actual_cash ?? '0.00'));
                $this->addElement($fermeture, 'Ecart', (string) ($shift->variance ?? '0.00'));
                $this->addElement($fermeture, 'DateFermeture', $shift->closed_at->toIso8601String());
                $section->appendChild($fermeture);
            }
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add terminal lifecycle events section (ACTIVATION_TERMINAL, DESACTIVATION_TERMINAL, MAJ_LOGICIEL).
     *
     * @param  Collection<int, AuditEvent>  $events
     */
    public function addTerminalEvents(Collection $events): self
    {
        $section = $this->doc->createElement('EvenementsTerminal');
        $section->setAttribute('count', (string) $events->count());

        foreach ($events as $auditEvent) {
            $eventType = $auditEvent->event_type;
            $payload = $auditEvent->payload ?? [];

            $nf525Type = match ($eventType) {
                'terminal.activated' => Nf525EventType::TerminalActivated->value,
                'terminal.deactivated' => Nf525EventType::TerminalDeactivated->value,
                'terminal.software_updated' => Nf525EventType::SoftwareUpdated->value,
                default => $eventType,
            };

            $eventEl = $this->doc->createElement('EvenementTerminal');
            $eventEl->setAttribute('type', $nf525Type);
            $this->addElement($eventEl, 'Identifiant', $auditEvent->id);
            $this->addElement($eventEl, 'TerminalId', $auditEvent->aggregate_id);
            $this->addElement($eventEl, 'CodeTerminal', (string) ($payload['terminal_code'] ?? ''));
            $this->addElement($eventEl, 'TypeEvenement', $nf525Type);
            $this->addElement($eventEl, 'Date', $auditEvent->occurred_at->toIso8601String());

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
     * @param  Collection<string, int>  $trainingCounts  Keyed by terminal_id
     */
    public function addTrainingMode(Collection $trainingCounts): self
    {
        $section = $this->doc->createElement('ModeFormation');
        $totalCount = $trainingCounts->sum();
        $section->setAttribute('count', (string) $totalCount);

        foreach ($trainingCounts as $terminalId => $count) {
            $terminal = $this->doc->createElement('TerminalFormation');
            $this->addElement($terminal, 'TerminalId', (string) $terminalId);
            $this->addElement($terminal, 'NombreTicketsFormation', (string) $count);
            $section->appendChild($terminal);
        }

        $this->root->appendChild($section);

        return $this;
    }

    /**
     * Add hash chain summaries per terminal.
     *
     * @param  Collection<int, Terminal>  $terminals
     */
    public function addHashChains(Collection $terminals): self
    {
        $section = $this->doc->createElement('ChainesHash');
        $section->setAttribute('count', (string) $terminals->count());

        foreach ($terminals as $terminal) {
            $chain = $this->doc->createElement('ChaineTerminal');
            $this->addElement($chain, 'TerminalId', $terminal->id);
            $this->addElement($chain, 'CodeTerminal', $terminal->code);
            $this->addElement($chain, 'NomTerminal', $terminal->name);
            $this->addElement($chain, 'GraineSeed', $terminal->genesis_seed);
            $this->addElement($chain, 'SequenceActuelle', (string) $terminal->current_sequence);
            $this->addElement($chain, 'AnneeActuelle', (string) $terminal->current_year);
            $this->addElement($chain, 'DernierHash', $terminal->last_hash ?? '');
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
