/**
 * Document Components
 * Credit Notes, Return Notes, Delivery Notes, Attachments, and Document Management Components
 */

// Shared Document Components (Phase 3 Refactoring)
export { DocumentHeader, type DocumentHeaderProps, type QuoteExpiryInfo } from './DocumentHeader'
export { DocumentLines, type DocumentLinesProps } from './DocumentLines'
export { DocumentTotals, type DocumentTotalsProps } from './DocumentTotals'
export { DocumentActions, type DocumentActionsProps } from './DocumentActions'
export { DocumentPartnerInfo, type DocumentPartnerInfoProps } from './DocumentPartnerInfo'
export { DocumentInfo, type DocumentInfoProps } from './DocumentInfo'
export { DocumentPaymentHistory, type DocumentPaymentHistoryProps } from './DocumentPaymentHistory'

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
