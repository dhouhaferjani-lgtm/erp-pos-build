import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type React from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { EnrichmentCapturePanel } from '../EnrichmentCapturePanel'
import { uploadEnrichmentPhoto } from '../../api/enrichmentPhotos'

vi.mock('../../api/enrichmentPhotos', () => ({
  MAX_PHOTO_BYTES: 5_242_880,
  uploadEnrichmentPhoto: vi.fn(),
}))

const mockUploadEnrichmentPhoto = vi.mocked(uploadEnrichmentPhoto)

describe('EnrichmentCapturePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders photo-first guidance and an image-only file input', () => {
    renderPanel()

    expect(screen.getByText('A photo helps us identify the exact product.')).toBeInTheDocument()
    expect(screen.getByLabelText('Add product photo')).toHaveAttribute('accept', 'image/*')
  })

  it('uploads a selected photo and emits the uploaded photo list', async () => {
    mockUploadEnrichmentPhoto.mockResolvedValue({ photoId: 'ph_1', filename: 'front.jpg' })
    const onPhotosChange = vi.fn()
    renderPanel({ onPhotosChange })

    fireEvent.change(screen.getByLabelText('Add product photo'), {
      target: { files: [new File(['front'], 'front.jpg', { type: 'image/jpeg' })] },
    })

    await waitFor(() => {
      expect(onPhotosChange).toHaveBeenCalledWith([{ photoId: 'ph_1', filename: 'front.jpg' }])
    })
  })

  it('does not upload more than two photos', () => {
    renderPanel({
      photos: [
        { photoId: 'ph_1', filename: 'front.jpg' },
        { photoId: 'ph_2', filename: 'barcode.jpg' },
      ],
    })

    expect(screen.getByLabelText('Add product photo')).toBeDisabled()
  })

  it('shows upload failure copy without blocking the panel', async () => {
    mockUploadEnrichmentPhoto.mockRejectedValue(new Error('put failed'))
    renderPanel()

    fireEvent.change(screen.getByLabelText('Add product photo'), {
      target: { files: [new File(['front'], 'front.jpg', { type: 'image/jpeg' })] },
    })

    expect(await screen.findByText('Photo upload failed. You can still save the product.')).toBeInTheDocument()
    expect(screen.getByLabelText('Brand (optional)')).toBeEnabled()
  })

  it('rejects files over five megabytes without uploading', async () => {
    renderPanel()

    fireEvent.change(screen.getByLabelText('Add product photo'), {
      target: {
        files: [new File([new Uint8Array(5_242_881)], 'large.jpg', { type: 'image/jpeg' })],
      },
    })

    expect(await screen.findByText('Photos must be 5 MB or smaller.')).toBeInTheDocument()
    expect(mockUploadEnrichmentPhoto).not.toHaveBeenCalled()
  })

  it('treats brand as optional and emits brand changes', () => {
    const onBrandChange = vi.fn()
    renderPanel({ onBrandChange })

    fireEvent.change(screen.getByLabelText('Brand (optional)'), {
      target: { value: 'BrandX' },
    })

    expect(onBrandChange).toHaveBeenCalledWith('BrandX')
  })
})

function renderPanel(overrides: Partial<React.ComponentProps<typeof EnrichmentCapturePanel>> = {}) {
  const props: React.ComponentProps<typeof EnrichmentCapturePanel> = {
    photos: [],
    onPhotosChange: vi.fn(),
    brand: '',
    onBrandChange: vi.fn(),
    attributes: [],
    onAttributesChange: vi.fn(),
    ...overrides,
  }

  return render(<EnrichmentCapturePanel {...props} />)
}
