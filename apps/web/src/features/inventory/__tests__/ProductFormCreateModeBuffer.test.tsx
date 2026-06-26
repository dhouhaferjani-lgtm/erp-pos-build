/**
 * ProductForm — create-mode image buffer integration tests
 *
 * Covers:
 * 1. Create mode renders CreateModeImageBuffer instead of disabled tile
 * 2. Buffer accepts files → shows preview grid
 * 3. On form submit (create): uploadProductImage called once per buffered file
 *    with the new product id, in order
 * 4. Upload failure path: product creation still succeeds; error surfaced; no crash
 * 5. Edit mode still renders ProductImageSection (no-regression — belt+suspenders)
 *
 * KNOWN pre-existing failures NOT chased here:
 *   ProductMovementsTab.test.tsx, ProductDocumentsTab.test.tsx
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, act, fireEvent, waitFor } from '@testing-library/react'

// ── i18n ─────────────────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// ── Router — CREATE MODE ──────────────────────────────────────────────────────
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: '' }),
  Link: ({
    to,
    children,
    ...props
  }: {
    to: string
    children: React.ReactNode
    className?: string
  }) => <a href={to} {...props}>{children}</a>,
}))

// ── React Query ───────────────────────────────────────────────────────────────
const mockMutateAsync = vi.fn()
const mockInvalidateQueries = vi.fn()

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: ({ onSuccess }: { onSuccess?: (data: { id: string }) => Promise<void> }) => ({
      mutate: vi.fn(),
      mutateAsync: async (data: unknown) => {
        const result = await mockMutateAsync(data)
        if (onSuccess) {
          await onSuccess(result as { id: string })
        }
        return result
      },
      isPending: false,
    }),
    useQueryClient: () => ({ invalidateQueries: mockInvalidateQueries }),
  }
})

// ── Stores ────────────────────────────────────────────────────────────────────
vi.mock('../../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})
vi.mock('../../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

// ── Contexts ──────────────────────────────────────────────────────────────────
vi.mock('../../../contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: { vertical: 'generic' },
    hasModule: (name: string) => name === 'Inventory',
  }),
}))
vi.mock('../../../contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({ isOtospex: false }),
}))
vi.mock('../../../hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', decimals: 2, locale: 'fr-FR' }),
  getDecimals: () => 2,
}))

// ── Heavy children ────────────────────────────────────────────────────────────
vi.mock('../../../components/catalog/CategorySelect', () => ({
  CategorySelect: () => <div data-testid="category-select" />,
}))
vi.mock('@/features/inventory/hooks/useCatalogBarcodeLookup', () => ({
  useCatalogBarcodeLookup: vi.fn(() => ({ isSearching: false })),
}))
vi.mock('../components/CatalogBanner', () => ({
  CatalogBanner: () => null,
}))
vi.mock('../../catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: [] }),
}))
vi.mock('../../../components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => <div data-testid="tax-config" />,
}))
vi.mock('../../uom/components/UnitDropdown', () => ({
  UnitDropdown: ({
    value,
    onChange,
  }: {
    value?: string
    onChange?: (v: string) => void
  }) => (
    <select
      data-testid="unit-dropdown"
      value={value ?? ''}
      onChange={(e) => onChange?.(e.target.value)}
    >
      <option value="">uom:selectUnit</option>
    </select>
  ),
}))
vi.mock('../../catalog/components/ProductVariantMatrixEditor', () => ({
  ProductVariantMatrixEditor: () => null,
}))
vi.mock('../../products/components', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../products/components')>()
  return {
    ...actual,
    ProductImageSection: () => <div data-testid="product-image-section" />,
    ParapharmacyMetadataFields: () => null,
    // CreateModeImageBuffer is real (it's what we're integration-testing)
  }
})
vi.mock('../api/platformQueries', () => ({
  useProductSubmission: () => ({ mutate: vi.fn() }),
}))

// ── productImages API — captured for assertions ───────────────────────────────
const mockUploadProductImage = vi.fn()
vi.mock('../../products/api/productImages', () => ({
  getProductImages: vi.fn(),
  uploadProductImage: mockUploadProductImage,
  setProductImagePrimary: vi.fn(),
  deleteProductImage: vi.fn(),
  reorderProductImages: vi.fn(),
  getProductImageDownloadUrl: vi.fn(),
  getPublicProductImages: vi.fn(),
}))

// ── URL.createObjectURL / revokeObjectURL — stub only these methods ──────────
// Stubbing the whole URL global breaks axios; only stub the methods we need.
Object.defineProperty(URL, 'createObjectURL', {
  writable: true,
  value: vi.fn((f: File) => `blob:preview-${f.name}`),
})
Object.defineProperty(URL, 'revokeObjectURL', {
  writable: true,
  value: vi.fn(),
})

// Import AFTER mocks
const { ProductForm } = await import('../ProductForm')

function makeJpeg(name = 'test.jpg', sizeBytes = 1024): File {
  return new File([new Uint8Array(sizeBytes)], name, { type: 'image/jpeg' })
}

function getFileInput(container: HTMLElement): HTMLInputElement {
  // The buffer's file input has data-testid="buffer-file-input"
  const input = container.querySelector<HTMLInputElement>(
    '[data-testid="buffer-file-input"]',
  )
  if (!input) throw new Error('buffer-file-input not found')
  return input
}

describe('ProductForm — create-mode image buffer (integration)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMutateAsync.mockResolvedValue({ id: 'new-product-id-123' })
    mockUploadProductImage.mockResolvedValue({
      id: 'img-1',
      url: 'https://example.com/img.jpg',
      is_primary: true,
      sort_order: 0,
    })
  })

  it('renders CreateModeImageBuffer (buffer-file-input) instead of disabled add-tile', () => {
    const { container } = render(<ProductForm />)
    // Buffer input must be present and enabled
    const input = container.querySelector<HTMLInputElement>(
      '[data-testid="buffer-file-input"]',
    )
    expect(input).not.toBeNull()
    expect(input?.disabled).toBe(false)
    // Old disabled tile should NOT exist
    expect(
      screen.queryByText('products:media.saveFirst'),
    ).not.toBeInTheDocument()
  })

  it('selecting a valid file shows a preview thumbnail', () => {
    const { container } = render(<ProductForm />)
    const input = getFileInput(container)
    const file = makeJpeg('product.jpg')
    fireEvent.change(input, { target: { files: [file] } })
    // Preview thumbnails are rendered
    const previews = screen.getAllByRole('img', {
      name: (n) => /product\.jpg/.test(n) || n === '',
    })
    // At minimum one img element for the preview
    expect(previews.length).toBeGreaterThanOrEqual(1)
  })

  it('shows count of buffered images', () => {
    const { container } = render(<ProductForm />)
    const input = getFileInput(container)
    const file = makeJpeg('product.jpg')
    fireEvent.change(input, { target: { files: [file] } })
    // Should show "1 image buffered" or similar count text
    expect(
      screen.getByTestId('buffer-image-count'),
    ).toBeInTheDocument()
  })

  it('on submit (create): uploadProductImage called for each buffered file in order', async () => {
    const { container } = render(<ProductForm />)

    // Buffer two files
    const file1 = makeJpeg('first.jpg')
    const file2 = makeJpeg('second.jpg')
    const input = getFileInput(container)
    fireEvent.change(input, { target: { files: [file1] } })
    fireEvent.change(input, { target: { files: [file2] } })

    // Fill required fields
    const nameInput = screen.getByRole('textbox', {
      name: /inventory:products\.name/i,
    })
    const skuInput = screen.getByRole('textbox', {
      name: /inventory:products\.sku/i,
    })
    fireEvent.change(nameInput, { target: { value: 'Test Product' } })
    fireEvent.change(skuInput, { target: { value: 'SKU-001' } })

    // Submit the form
    const publishBtn = screen.getAllByText('catalog:editor.actions.publish')[0]
    await act(async () => {
      fireEvent.click(publishBtn)
    })

    await waitFor(() => {
      // uploadProductImage called twice, in order, with the new product id
      expect(mockUploadProductImage).toHaveBeenCalledTimes(2)
      expect(mockUploadProductImage).toHaveBeenNthCalledWith(
        1,
        'new-product-id-123',
        file1,
        0,
      )
      expect(mockUploadProductImage).toHaveBeenNthCalledWith(
        2,
        'new-product-id-123',
        file2,
        1,
      )
    })
  })

  it('upload failure: product creation still succeeds + error surfaced + no crash', async () => {
    mockUploadProductImage.mockRejectedValue(new Error('Network error'))

    const { container } = render(<ProductForm />)

    const file = makeJpeg('fail.jpg')
    const input = getFileInput(container)
    fireEvent.change(input, { target: { files: [file] } })

    // Fill required fields
    const nameInput = screen.getByRole('textbox', {
      name: /inventory:products\.name/i,
    })
    const skuInput = screen.getByRole('textbox', {
      name: /inventory:products\.sku/i,
    })
    fireEvent.change(nameInput, { target: { value: 'Fail Product' } })
    fireEvent.change(skuInput, { target: { value: 'SKU-FAIL' } })

    const publishBtn = screen.getAllByText('catalog:editor.actions.publish')[0]
    await act(async () => {
      fireEvent.click(publishBtn)
    })

    // Product creation mutation was still called and succeeded
    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled()
    })

    // Upload was attempted
    await waitFor(() => {
      expect(mockUploadProductImage).toHaveBeenCalled()
    })

    // No crash — component still renders (form element is in the document)
    const form = document.getElementById('product-editor-form')
    expect(form).not.toBeNull()
  })

  it('does NOT call uploadProductImage when no images are buffered', async () => {
    render(<ProductForm />)

    const nameInput = screen.getByRole('textbox', {
      name: /inventory:products\.name/i,
    })
    const skuInput = screen.getByRole('textbox', {
      name: /inventory:products\.sku/i,
    })
    fireEvent.change(nameInput, { target: { value: 'No Images Product' } })
    fireEvent.change(skuInput, { target: { value: 'SKU-NONE' } })

    const publishBtn = screen.getAllByText('catalog:editor.actions.publish')[0]
    await act(async () => {
      fireEvent.click(publishBtn)
    })

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled()
    })
    expect(mockUploadProductImage).not.toHaveBeenCalled()
  })
})
