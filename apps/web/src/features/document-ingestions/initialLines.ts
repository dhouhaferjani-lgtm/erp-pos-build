import type { ReviewedLineState } from './components/LineMappingTable'
import type { DocumentIngestionDetail, ProductCandidate, ReceiptLineCandidate } from './types'

function lineSourceId(candidate: ReceiptLineCandidate | undefined): string {
  return candidate?.poLineId ?? candidate?.po_line_id ?? ''
}

function productTaxRate(candidate: ProductCandidate | undefined): string {
  return candidate?.taxRate ?? candidate?.tax_rate ?? ''
}

export function initialLines(detail: DocumentIngestionDetail): ReviewedLineState[] {
  const extractionLines = detail.extraction?.lines ?? []
  return extractionLines.map((line, index) => {
    const product = detail.suggestions?.productCandidates[index]?.[0]
    const receipt = detail.suggestions?.receiptLineCandidates[index]
    return {
      productId: product?.id ?? '',
      quantity: line.quantity.value,
      unitPrice: line.unitPrice?.value ?? '',
      vatRate: line.taxRate?.value ?? productTaxRate(product),
      freeQuantity: '0',
      batchNumber: line.batchNumber?.value ?? '',
      expiryDate: line.expiryDate?.value ?? '',
      sourceLineId: lineSourceId(receipt),
    }
  })
}
