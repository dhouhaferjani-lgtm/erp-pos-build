<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

/**
 * NF525 JET event types for XML export.
 *
 * Maps internal domain events to their French fiscal nomenclature
 * as required by the NF525 certification standard.
 */
enum Nf525EventType: string
{
    case ReceiptCreated = 'TICKET';
    case ReceiptVoided = 'ANNULATION';
    case ReceiptReturn = 'RETOUR';
    case ReceiptReprinted = 'DUPLICATA';
    case ShiftOpened = 'OUVERTURE_CAISSE';
    case ShiftClosed = 'FERMETURE_CAISSE';
    case ZReportGenerated = 'RAPPORT_Z';
    case CashDrawerDeposit = 'DEPOT_ESPECES';
    case CashDrawerPayout = 'RETRAIT_ESPECES';
    case CashDrawerRefund = 'REMBOURSEMENT';
    case TerminalActivated = 'ACTIVATION_TERMINAL';
    case TerminalDeactivated = 'DESACTIVATION_TERMINAL';
    case TrainingModeChanged = 'MODE_FORMATION';
    case SoftwareUpdated = 'MAJ_LOGICIEL';
}
