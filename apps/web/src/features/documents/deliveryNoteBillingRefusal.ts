export interface DeliveryNoteBillingRefusalDocument {
  id: string
  document_number: string
  invoice_id: string | null
  invoice_number: string | null
  invoice_date: string | null
  invoiced_via: string | null
}

export interface DeliveryNoteBillingRefusal {
  documents: DeliveryNoteBillingRefusalDocument[]
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function nullableString(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null
}

export function parseDeliveryNoteBillingRefusal(error: unknown): DeliveryNoteBillingRefusal | null {
  if (!isRecord(error)) return null
  const response = error['response']
  if (!isRecord(response) || response['status'] !== 422) return null
  const data = response['data']
  if (!isRecord(data)) return null
  const envelope = data['error']
  if (!isRecord(envelope) || envelope['code'] !== 'DELIVERY_NOTE_ALREADY_INVOICED') return null
  const details = envelope['details']
  if (!isRecord(details) || !Array.isArray(details['documents'])) return null

  const documents = new Map<string, DeliveryNoteBillingRefusalDocument>()
  for (const candidate of details['documents']) {
    if (!isRecord(candidate)) continue
    const id = nullableString(candidate['id'])
    const documentNumber = nullableString(candidate['document_number'])
    if (id === null || documentNumber === null) continue

    documents.set(id, {
      id,
      document_number: documentNumber,
      invoice_id: nullableString(candidate['invoice_id']),
      invoice_number: nullableString(candidate['invoice_number']),
      invoice_date: nullableString(candidate['invoice_date']),
      invoiced_via: nullableString(candidate['invoiced_via']),
    })
  }

  return documents.size > 0 ? { documents: Array.from(documents.values()) } : null
}
