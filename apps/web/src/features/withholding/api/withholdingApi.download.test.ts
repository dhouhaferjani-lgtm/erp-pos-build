import { beforeEach, describe, expect, it, vi } from 'vitest'

const authenticatedDownload = vi.hoisted(() => vi.fn<() => Promise<void>>())

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPatch: vi.fn(),
  apiDelete: vi.fn(),
  authenticatedDownload,
}))

import {
  downloadBatchTEJXML,
  downloadCertificatePDF,
  downloadCertificateTEJXML,
} from './withholdingApi'

describe('withholding authenticated downloads', () => {
  beforeEach(() => {
    authenticatedDownload.mockReset()
    authenticatedDownload.mockResolvedValue()
  })

  it('routes every export through the authenticated API client', async () => {
    await downloadCertificatePDF('certificate-1')
    await downloadCertificateTEJXML('certificate-1')
    await downloadBatchTEJXML(2026, 'purchase')

    expect(authenticatedDownload).toHaveBeenNthCalledWith(
      1,
      '/withholding/certificates/certificate-1/download-pdf',
    )
    expect(authenticatedDownload).toHaveBeenNthCalledWith(
      2,
      '/withholding/certificates/certificate-1/download-tej-xml',
    )
    expect(authenticatedDownload).toHaveBeenNthCalledWith(
      3,
      '/withholding/certificates/export-tej-batch?year=2026&direction=purchase',
    )
  })
})
