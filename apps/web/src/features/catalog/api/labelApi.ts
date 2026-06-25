import { api, apiGet } from '@/lib/api'

/**
 * A printable label format offered by the backend (`GET /labels/formats`). The
 * dimensions/grid describe the physical sheet layout the PDF will be rendered
 * onto (e.g. an Avery template): `rows`×`cols` labels per page, each
 * `label_width_mm`×`label_height_mm`.
 */
export interface LabelFormat {
  key: string
  label: string
  label_width_mm: number
  label_height_mm: number
  rows: number
  cols: number
}

/**
 * One requested label line: how many copies of a given variant to print.
 */
export interface LabelItem {
  variant_id: string
  quantity: number
}

/**
 * A variant the backend resolved a barcode for and is ready to print. The
 * `barcode_value` (server assigns barcode=sku) plus `symbology` are echoed back
 * so the UI can show what will be encoded.
 */
export interface ReadyLabel {
  variant_id: string
  quantity: number
  barcode_value: string
  symbology: string
}

/**
 * A variant the backend could NOT prepare, with a machine reason
 * (`not_found` | `barcode_conflict` | `product_unavailable` | `error`).
 */
export interface SkippedLabel {
  variant_id: string
  reason: string
}

/**
 * Result envelope for `POST /labels/variants/prepare`. Unlike the unwrapped
 * `apiPost` helper we preserve both `data` and `meta`: `data.ready` is what we
 * forward to the PDF endpoint, and `meta.skipped` is surfaced inline so the user
 * knows which variants were dropped and why.
 */
export interface PrepareResult {
  data: { ready: ReadyLabel[] }
  meta: { skipped: SkippedLabel[] }
}

export interface DownloadPdfPayload {
  format: string
  items: LabelItem[]
  start_cell?: number
}

/**
 * Fetch the catalog of printable label formats. `apiGet` unwraps the
 * `{ data: [...] }` envelope and returns the array directly.
 */
export async function getLabelFormats(): Promise<LabelFormat[]> {
  return apiGet<LabelFormat[]>('/labels/formats')
}

/**
 * Resolve barcodes for the requested variants. Returns the full `data`+`meta`
 * envelope (ready + skipped). The caller should print only the `ready` items.
 */
export async function prepareVariantLabels(
  items: LabelItem[],
): Promise<PrepareResult> {
  const response = await api.post('/labels/variants/prepare', { items })
  return response.data as PrepareResult
}

/**
 * Render the label sheet as a PDF blob. Only `ready` items (those with a
 * resolved barcode) should be sent — the backend returns 422 otherwise.
 */
export async function downloadVariantLabelsPdf(
  payload: DownloadPdfPayload,
): Promise<Blob> {
  const response = await api.post('/labels/variants/pdf', payload, {
    responseType: 'blob',
  })
  return response.data as Blob
}
