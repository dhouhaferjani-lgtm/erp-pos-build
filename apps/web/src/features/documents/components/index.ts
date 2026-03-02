/**
 * Document Components
 * Credit Notes, Return Notes, Delivery Notes, Attachments, and Document Management Components
 */

// Shared Document Components (Phase 3 Refactoring)
export { DocumentHeader, type DocumentHeaderProps, type QuoteExpiryInfo } from './DocumentHeader'
export { DocumentLines, type DocumentLinesProps } from './DocumentLines'
export { DocumentTotals, type DocumentTotalsProps } from './DocumentTotals'
export { DocumentActions, type DocumentActionsProps } from './DocumentActions'
export { DocumentActionBar, type DocumentActionBarProps } from './DocumentActionBar'
export { DocumentPartnerInfo, type DocumentPartnerInfoProps } from './DocumentPartnerInfo'
export { DocumentInfo, type DocumentInfoProps } from './DocumentInfo'
export { DocumentPaymentHistory, type DocumentPaymentHistoryProps } from './DocumentPaymentHistory'
export { PaymentHistorySection, type PaymentHistorySectionProps } from './PaymentHistorySection'
export { OutstandingAmountSection, type OutstandingAmountSectionProps } from './OutstandingAmountSection'

// Credit Note Components
export { CreateCreditNoteForm } from './CreateCreditNoteForm'
export { CreditNoteList } from './CreditNoteList'
export { CreditNoteDetail } from './CreditNoteDetail'

// Return Note Components
export { CreateReturnNoteForm } from './CreateReturnNoteForm'
export { ReturnNoteMetadata } from './ReturnNoteMetadata'

// Document Management Components
export { DocumentLineEditor, type DocumentLine } from './DocumentLineEditor'
export { DeliveryNoteConsolidation } from './DeliveryNoteConsolidation'
export { DocumentAttachments } from './DocumentAttachments'
export { RelatedDocumentsTab } from './RelatedDocumentsTab'

// Financial Components
export { DocumentOutstandingCallout, type DocumentOutstandingCalloutProps } from './DocumentOutstandingCallout'

// Status Badge Components
export { PaymentStatusBadge } from './PaymentStatusBadge'
export { FulfillmentStatusBadge } from './FulfillmentStatusBadge'
