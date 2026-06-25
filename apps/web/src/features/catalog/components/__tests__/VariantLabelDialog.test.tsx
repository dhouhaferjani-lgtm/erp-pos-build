import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { VariantLabelDialog } from '../VariantLabelDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => {
      if (second && typeof second === 'object') {
        const opts = second as Record<string, unknown>
        if ('defaultValue' in opts && typeof opts['defaultValue'] === 'string') {
          return opts['defaultValue']
        }
        return `${key} ${JSON.stringify(second)}`
      }
      return key
    },
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const mockFormats = [
  {
    key: 'avery-l7160',
    label: 'Avery L7160',
    label_width_mm: 63.5,
    label_height_mm: 38.1,
    rows: 7,
    cols: 3,
  },
]
vi.mock('../../hooks/useLabels', () => ({
  useLabelFormats: () => ({ data: mockFormats }),
}))

const mockPrepare = vi.fn()
const mockPdf = vi.fn()
vi.mock('../../api/labelApi', () => ({
  prepareVariantLabels: (...args: unknown[]) => mockPrepare(...args),
  downloadVariantLabelsPdf: (...args: unknown[]) => mockPdf(...args),
}))

const VARIANTS = [
  { id: 'v1', name_suffix: 'Red / S' },
  { id: 'v2', name_suffix: 'Blue / M' },
]

describe('VariantLabelDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    Object.defineProperty(URL, 'createObjectURL', {
      writable: true,
      value: vi.fn(() => 'blob:mock'),
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      writable: true,
      value: vi.fn(),
    })
  })

  it('lists the variants and the format select', () => {
    render(
      <VariantLabelDialog open onClose={vi.fn()} variants={VARIANTS} />,
    )
    expect(screen.getByText('Red / S')).toBeInTheDocument()
    expect(screen.getByText('Blue / M')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Avery L7160' })).toBeInTheDocument()
  })

  it('confirm calls prepare, shows skipped, then calls pdf and triggers download', async () => {
    const user = userEvent.setup()
    mockPrepare.mockResolvedValue({
      data: {
        ready: [
          {
            variant_id: 'v1',
            quantity: 1,
            barcode_value: 'SKU-1',
            symbology: 'CODE128',
          },
        ],
      },
      meta: { skipped: [{ variant_id: 'v2', reason: 'not_found' }] },
    })
    mockPdf.mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }))

    render(
      <VariantLabelDialog open onClose={vi.fn()} variants={VARIANTS} />,
    )

    await user.click(screen.getByText('catalog:labels.download'))

    await waitFor(() => {
      expect(mockPrepare).toHaveBeenCalledWith([
        { variant_id: 'v1', quantity: 1 },
        { variant_id: 'v2', quantity: 1 },
      ])
    })

    expect(
      screen.getByText('catalog:labels.skipped {"count":1}'),
    ).toBeInTheDocument()

    await waitFor(() => {
      expect(mockPdf).toHaveBeenCalledWith({
        format: 'avery-l7160',
        items: [{ variant_id: 'v1', quantity: 1 }],
      })
    })
    expect(URL.createObjectURL).toHaveBeenCalled()
  })

  it('forwards the start cell to the pdf request when provided', async () => {
    const user = userEvent.setup()
    mockPrepare.mockResolvedValue({
      data: {
        ready: [
          { variant_id: 'v1', quantity: 2, barcode_value: 'X', symbology: 'C' },
        ],
      },
      meta: { skipped: [] },
    })
    mockPdf.mockResolvedValue(new Blob(['%PDF']))

    render(
      <VariantLabelDialog open onClose={vi.fn()} variants={[VARIANTS[0]]} />,
    )

    await user.type(
      screen.getByRole('spinbutton', { name: 'catalog:labels.startCell' }),
      '4',
    )
    await user.click(screen.getByText('catalog:labels.download'))

    await waitFor(() => {
      expect(mockPdf).toHaveBeenCalledWith({
        format: 'avery-l7160',
        items: [{ variant_id: 'v1', quantity: 2 }],
        start_cell: 4,
      })
    })
  })

  it('all-skipped shows nothing-printable and never calls pdf', async () => {
    const user = userEvent.setup()
    mockPrepare.mockResolvedValue({
      data: { ready: [] },
      meta: {
        skipped: [
          { variant_id: 'v1', reason: 'not_found' },
          { variant_id: 'v2', reason: 'barcode_conflict' },
        ],
      },
    })

    render(
      <VariantLabelDialog open onClose={vi.fn()} variants={VARIANTS} />,
    )

    await user.click(screen.getByText('catalog:labels.download'))

    await waitFor(() => {
      expect(
        screen.getByText('catalog:labels.nothingPrintable'),
      ).toBeInTheDocument()
    })
    expect(mockPdf).not.toHaveBeenCalled()
  })

  it('cancel calls onClose and makes no api calls', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    render(
      <VariantLabelDialog open onClose={onClose} variants={VARIANTS} />,
    )
    await user.click(screen.getByText('catalog:labels.cancel'))
    expect(onClose).toHaveBeenCalled()
    expect(mockPrepare).not.toHaveBeenCalled()
    expect(mockPdf).not.toHaveBeenCalled()
  })
})
