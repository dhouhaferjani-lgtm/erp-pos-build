import { describe, it, expect, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { TerminalForm } from './TerminalForm'
import type { Terminal } from '../hooks/useTerminals'

// Mock translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (params) {
        return Object.entries(params).reduce(
          (acc, [k, v]) => acc.replace(`{{${k}}}`, String(v)),
          key
        )
      }
      return key
    },
  }),
}))

const mockLocations = [
  { id: 'loc-1', name: 'Main Store', code: 'MAIN' },
  { id: 'loc-2', name: 'Warehouse', code: 'WH01' },
]

const mockTerminal: Terminal = {
  id: '1',
  type: 'web',
  code: 'POS01',
  name: 'Main Counter Terminal',
  description: 'Primary checkout terminal',
  location_id: 'loc-1',
  location: mockLocations[0],
  is_active: true,
  is_training_mode: false,
  has_history: false,
  activated_at: '2025-01-01T00:00:00Z',
  deactivated_at: null,
  deactivation_reason: null,
  current_sequence: 0,
  current_year: 2025,
  max_discount_percent: 100,
  allow_line_discounts: true,
  allow_transaction_discounts: true,
  created_at: '2025-01-01T00:00:00Z',
  updated_at: '2025-01-01T00:00:00Z',
}

describe('TerminalForm', () => {
  const defaultProps = {
    locations: mockLocations,
    isSubmitting: false,
    onSubmit: vi.fn(),
    onCancel: vi.fn(),
  }

  describe('Create Mode', () => {
    it('renders all fields for creating a terminal', () => {
      render(<TerminalForm {...defaultProps} />)

      // Code field (only in create mode)
      expect(screen.getByLabelText(/pos\.terminal\.code/)).toBeInTheDocument()
      expect(screen.getByText('common.leaveBlankForAutoGenerate')).toBeInTheDocument()

      // Name field
      expect(screen.getByLabelText(/pos\.terminal\.name/)).toBeInTheDocument()

      // Location dropdown
      expect(screen.getByLabelText(/pos\.terminal\.location/)).toBeInTheDocument()

      // Description field
      expect(screen.getByLabelText(/pos\.terminal\.description/)).toBeInTheDocument()

      // Buttons
      expect(screen.getByRole('button', { name: /common\.cancel/ })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: /common\.create/ })).toBeInTheDocument()
    })

    it('populates location dropdown with provided locations', () => {
      render(<TerminalForm {...defaultProps} />)

      // Check that options are present
      expect(screen.getByText('Main Store (MAIN)')).toBeInTheDocument()
      expect(screen.getByText('Warehouse (WH01)')).toBeInTheDocument()
    })

    it('shows validation error when name is missing', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        const errors = screen.getAllByText('validation.required')
        expect(errors.length).toBeGreaterThan(0)
      })

      expect(onSubmit).not.toHaveBeenCalled()
    })

    it('shows validation error when location is missing', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      await user.type(nameInput, 'Test Terminal')

      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText('validation.required')).toBeInTheDocument()
      })

      expect(onSubmit).not.toHaveBeenCalled()
    })

    it('shows validation error for invalid terminal code format', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      const codeInput = screen.getByLabelText(/pos\.terminal\.code/)
      await user.type(codeInput, 'pos-01') // Lowercase and hyphen not allowed

      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText('validation.terminalCodeFormat')).toBeInTheDocument()
      })

      expect(onSubmit).not.toHaveBeenCalled()
    })

    it('submits form with valid data', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      // Fill in required fields
      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      await user.type(nameInput, 'New Terminal')

      const locationSelect = screen.getByLabelText(/pos\.terminal\.location/)
      await user.selectOptions(locationSelect, 'loc-1')

      // Submit form
      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledTimes(1)
        expect(onSubmit).toHaveBeenCalledWith(
          expect.objectContaining({
            name: 'New Terminal',
            location_id: 'loc-1',
          })
        )
      })
    })

    it('submits with optional code and description', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      // Fill in all fields
      const codeInput = screen.getByLabelText(/pos\.terminal\.code/)
      await user.type(codeInput, 'POS99')

      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      await user.type(nameInput, 'Custom Terminal')

      const locationSelect = screen.getByLabelText(/pos\.terminal\.location/)
      await user.selectOptions(locationSelect, 'loc-2')

      const descriptionInput = screen.getByLabelText(/pos\.terminal\.description/)
      await user.type(descriptionInput, 'Special purpose terminal')

      // Submit form
      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledWith(
          expect.objectContaining({
            code: 'POS99',
            name: 'Custom Terminal',
            location_id: 'loc-2',
            description: 'Special purpose terminal',
          })
        )
      })
    })

    it('calls onCancel when cancel button is clicked', async () => {
      const user = userEvent.setup()
      const onCancel = vi.fn()

      render(<TerminalForm {...defaultProps} onCancel={onCancel} />)

      const cancelButton = screen.getByRole('button', { name: /common\.cancel/ })
      await user.click(cancelButton)

      expect(onCancel).toHaveBeenCalledTimes(1)
    })
  })

  describe('Edit Mode', () => {
    it('renders form with terminal data pre-filled', () => {
      render(<TerminalForm {...defaultProps} terminal={mockTerminal} />)

      // Code field should NOT be present in edit mode
      expect(screen.queryByLabelText(/pos\.terminal\.code/)).not.toBeInTheDocument()

      // Name should be pre-filled
      const nameInput = screen.getByLabelText(/pos\.terminal\.name/) as HTMLInputElement
      expect(nameInput.value).toBe('Main Counter Terminal')

      // Location should be pre-selected
      const locationSelect = screen.getByLabelText(
        /pos\.terminal\.location/
      ) as HTMLSelectElement
      expect(locationSelect.value).toBe('loc-1')

      // Description should be pre-filled
      const descriptionInput = screen.getByLabelText(
        /pos\.terminal\.description/
      ) as HTMLTextAreaElement
      expect(descriptionInput.value).toBe('Primary checkout terminal')

      // Button should say "Update" not "Create"
      expect(screen.getByRole('button', { name: /common\.update/ })).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: /common\.create/ })).not.toBeInTheDocument()
    })

    it('submits updated data', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} terminal={mockTerminal} onSubmit={onSubmit} />)

      // Update name
      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      await user.clear(nameInput)
      await user.type(nameInput, 'Updated Terminal Name')

      // Submit form
      const submitButton = screen.getByRole('button', { name: /common\.update/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledWith(
          expect.objectContaining({
            name: 'Updated Terminal Name',
            location_id: 'loc-1',
            description: 'Primary checkout terminal',
          })
        )
      })
    })
  })

  describe('Submitting State', () => {
    it('disables buttons when isSubmitting is true', () => {
      render(<TerminalForm {...defaultProps} isSubmitting={true} />)

      const submitButton = screen.getByRole('button', { name: /common\.saving/ })
      const cancelButton = screen.getByRole('button', { name: /common\.cancel/ })

      expect(submitButton).toBeDisabled()
      expect(cancelButton).toBeDisabled()
    })

    it('shows "Saving..." text when submitting', () => {
      render(<TerminalForm {...defaultProps} isSubmitting={true} />)

      expect(screen.getByText('common.saving')).toBeInTheDocument()
    })
  })

  describe('Validation', () => {
    it('enforces max length on name field', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      const longName = 'a'.repeat(101) // Exceeds 100 char limit
      await user.type(nameInput, longName)

      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText('validation.maxLength')).toBeInTheDocument()
      })

      expect(onSubmit).not.toHaveBeenCalled()
    })

    it('enforces max length on description field', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      await user.type(nameInput, 'Valid Name')

      const locationSelect = screen.getByLabelText(/pos\.terminal\.location/)
      await user.selectOptions(locationSelect, 'loc-1')

      const descriptionInput = screen.getByLabelText(/pos\.terminal\.description/)
      const longDescription = 'a'.repeat(501) // Exceeds 500 char limit
      await user.type(descriptionInput, longDescription)

      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText('validation.maxLength')).toBeInTheDocument()
      })

      expect(onSubmit).not.toHaveBeenCalled()
    })

    it('accepts valid uppercase alphanumeric code', async () => {
      const user = userEvent.setup()
      const onSubmit = vi.fn()

      render(<TerminalForm {...defaultProps} onSubmit={onSubmit} />)

      const codeInput = screen.getByLabelText(/pos\.terminal\.code/)
      await user.type(codeInput, 'POS123')

      const nameInput = screen.getByLabelText(/pos\.terminal\.name/)
      await user.type(nameInput, 'Test Terminal')

      const locationSelect = screen.getByLabelText(/pos\.terminal\.location/)
      await user.selectOptions(locationSelect, 'loc-1')

      const submitButton = screen.getByRole('button', { name: /common\.create/ })
      await user.click(submitButton)

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledWith(
          expect.objectContaining({
            code: 'POS123',
          })
        )
      })
    })
  })
})
