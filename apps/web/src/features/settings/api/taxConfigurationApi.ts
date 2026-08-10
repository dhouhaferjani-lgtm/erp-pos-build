import { apiGet, apiPost, apiPatch, apiDelete } from '../../../lib/api'
import type {
  TaxConfiguration,
  TaxConfigurationFormData,
  DocumentType,
  TaxConfigurationCapabilities,
} from '../types/tax'

export const taxConfigurationApi = {
  list: () =>
    apiGet<TaxConfiguration[]>('/taxation/configurations'),

  get: (id: string) =>
    apiGet<TaxConfiguration>(`/taxation/configurations/${id}`),

  create: (data: TaxConfigurationFormData) =>
    apiPost<TaxConfiguration>('/taxation/configurations', data),

  update: (id: string, data: Partial<TaxConfigurationFormData>) =>
    apiPatch<TaxConfiguration>(`/taxation/configurations/${id}`, data),

  delete: (id: string) =>
    apiDelete<void>(`/taxation/configurations/${id}`),

  reorder: (order: { id: string; sequence_order: number }[]) =>
    apiPost<void>('/taxation/configurations/reorder', { order }),

  getDocumentTypes: () =>
    apiGet<DocumentType[]>('/taxation/configurations/document-types'),

  getCapabilities: () =>
    apiGet<TaxConfigurationCapabilities>('/taxation/configurations/capabilities'),
}
