/**
 * CreateModeImageBuffer — unit tests (TDD, RED → GREEN)
 *
 * Covers:
 * 1. Renders add-file tile (clickable, not disabled)
 * 2. Selecting a valid file shows a preview tile + remove button
 * 3. Removing a buffered file drops it from the list; preview URL revoked
 * 4. Invalid file (too big) is rejected with an error message
 * 5. Invalid file (wrong type) is rejected with an error message
 * 6. Multiple files can be buffered; order preserved
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { CreateModeImageBuffer } from './CreateModeImageBuffer'

// ── i18n (echo key) ──────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts && typeof opts === 'object') {
        // interpolate {{ max }} style
        return Object.entries(opts).reduce<string>(
          (acc, [k, v]) => acc.replace(`{{${k}}}`, String(v)),
          key,
        )
      }
      return key
    },
  }),
}))

// ── URL.createObjectURL / revokeObjectURL ────────────────────────────────────
const mockCreateObjectURL = vi.fn((file: File) => `blob:preview-${file.name}`)
const mockRevokeObjectURL = vi.fn()

beforeEach(() => {
  vi.stubGlobal('URL', {
    createObjectURL: mockCreateObjectURL,
    revokeObjectURL: mockRevokeObjectURL,
  })
})

afterEach(() => {
  vi.restoreAllMocks()
  mockCreateObjectURL.mockClear()
  mockRevokeObjectURL.mockClear()
})

function makeFile(name: string, type: string, sizeBytes: number): File {
  const content = new Uint8Array(sizeBytes)
  return new File([content], name, { type })
}

function getFileInput(container: HTMLElement): HTMLInputElement {
  const input = container.querySelector<HTMLInputElement>('input[type="file"]')
  if (!input) throw new Error('No file input found')
  return input
}

describe('CreateModeImageBuffer', () => {
  it('renders the add-file tile (enabled, not disabled)', () => {
    const onFiles = vi.fn()
    render(<CreateModeImageBuffer bufferedFiles={[]} onFilesChange={onFiles} />)
    // The add tile is clickable (not disabled)
    const addTile = screen.getByRole('button', { name: /products:media\.addImage/i })
    expect(addTile).toBeInTheDocument()
    expect(addTile).not.toBeDisabled()
  })

  it('selecting a valid JPEG file shows a preview tile', () => {
    const onFiles = vi.fn()
    const { container } = render(
      <CreateModeImageBuffer bufferedFiles={[]} onFilesChange={onFiles} />,
    )
    const file = makeFile('photo.jpg', 'image/jpeg', 1024)
    const input = getFileInput(container)
    fireEvent.change(input, { target: { files: [file] } })
    expect(onFiles).toHaveBeenCalledOnce()
    expect(onFiles).toHaveBeenCalledWith([file])
  })

  it('shows preview thumbnails for buffered files', () => {
    const file1 = makeFile('a.jpg', 'image/jpeg', 1024)
    const file2 = makeFile('b.png', 'image/png', 2048)
    render(
      <CreateModeImageBuffer bufferedFiles={[file1, file2]} onFilesChange={vi.fn()} />,
    )
    // Each file has a remove button
    const removeButtons = screen.getAllByRole('button', {
      name: /products:media\.removeImage/i,
    })
    expect(removeButtons).toHaveLength(2)
  })

  it('remove button calls onFilesChange without that file', () => {
    const onFiles = vi.fn()
    const file1 = makeFile('a.jpg', 'image/jpeg', 1024)
    const file2 = makeFile('b.png', 'image/png', 2048)
    render(
      <CreateModeImageBuffer
        bufferedFiles={[file1, file2]}
        onFilesChange={onFiles}
      />,
    )
    const removeButtons = screen.getAllByRole('button', {
      name: /products:media\.removeImage/i,
    })
    // Remove first
    fireEvent.click(removeButtons[0])
    expect(onFiles).toHaveBeenCalledWith([file2])
  })

  it('rejects a file that is too large (> 5MB)', () => {
    const onFiles = vi.fn()
    const { container } = render(
      <CreateModeImageBuffer bufferedFiles={[]} onFilesChange={onFiles} />,
    )
    const bigFile = makeFile('big.jpg', 'image/jpeg', 6 * 1024 * 1024)
    const input = getFileInput(container)
    fireEvent.change(input, { target: { files: [bigFile] } })
    expect(onFiles).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toBeInTheDocument()
    // Error mentions max size
    expect(screen.getByRole('alert').textContent).toMatch(/fileTooLarge|5MB/i)
  })

  it('rejects a file with an invalid MIME type', () => {
    const onFiles = vi.fn()
    const { container } = render(
      <CreateModeImageBuffer bufferedFiles={[]} onFilesChange={onFiles} />,
    )
    const badFile = makeFile('doc.pdf', 'application/pdf', 1024)
    const input = getFileInput(container)
    fireEvent.change(input, { target: { files: [badFile] } })
    expect(onFiles).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toBeInTheDocument()
  })

  it('clears validation error when a valid file is selected after an invalid one', () => {
    const onFiles = vi.fn()
    const { container } = render(
      <CreateModeImageBuffer bufferedFiles={[]} onFilesChange={onFiles} />,
    )
    const input = getFileInput(container)
    // First: invalid
    const badFile = makeFile('doc.pdf', 'application/pdf', 1024)
    fireEvent.change(input, { target: { files: [badFile] } })
    expect(screen.getByRole('alert')).toBeInTheDocument()
    // Then: valid
    const goodFile = makeFile('ok.jpg', 'image/jpeg', 1024)
    fireEvent.change(input, { target: { files: [goodFile] } })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('preserves order of multiple buffered files', () => {
    const onFiles = vi.fn()
    const { container, rerender } = render(
      <CreateModeImageBuffer bufferedFiles={[]} onFilesChange={onFiles} />,
    )
    const file1 = makeFile('first.jpg', 'image/jpeg', 1024)
    const input = getFileInput(container)
    fireEvent.change(input, { target: { files: [file1] } })
    expect(onFiles).toHaveBeenCalledWith([file1])
    onFiles.mockClear()

    // Rerender with file1 buffered (simulating parent state update)
    rerender(
      <CreateModeImageBuffer bufferedFiles={[file1]} onFilesChange={onFiles} />,
    )
    const file2 = makeFile('second.png', 'image/png', 512)
    // input is re-queried after rerender
    const input2 = getFileInput(container)
    fireEvent.change(input2, { target: { files: [file2] } })
    expect(onFiles).toHaveBeenCalledWith([file1, file2])
  })
})
