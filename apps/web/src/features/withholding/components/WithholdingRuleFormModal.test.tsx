import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { WithholdingRuleFormModal } from './WithholdingRuleFormModal'

const mockCreateMutation = vi.hoisted(() => ({
  mutateAsync: vi.fn(),
  isPending: false,
}))
const mockUpdateMutation = vi.hoisted(() => ({
  mutateAsync: vi.fn(),
  isPending: false,
}))

vi.mock('../hooks/useWithholding', () => ({
  useCreateWithholdingRule: () => mockCreateMutation,
  useUpdateWithholdingRule: () => mockUpdateMutation,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: unknown) =>
      typeof options === 'string' ? options : key,
  }),
}))

describe('WithholdingRuleFormModal', () => {
  const baseProps = {
    isOpen: true,
    onClose: vi.fn(),
  }

  it('renders the create title when no rule is provided', () => {
    render(<WithholdingRuleFormModal {...baseProps} />)
    expect(screen.getByText('rules.createRule')).toBeInTheDocument()
  })

  it('renders key form fields via atom labels', () => {
    render(<WithholdingRuleFormModal {...baseProps} />)
    expect(screen.getByLabelText(/rules\.code/)).toBeInTheDocument()
    expect(screen.getByLabelText(/rules\.ratePercentage/)).toBeInTheDocument()
    expect(screen.getByLabelText(/rules\.name/)).toBeInTheDocument()
    expect(screen.getByLabelText(/rules\.transactionType/)).toBeInTheDocument()
  })

  it('renders the submit action as a button (atom)', () => {
    render(<WithholdingRuleFormModal {...baseProps} />)
    const submitButton = screen.getByRole('button', { name: 'common:actions.create' })
    expect(submitButton.tagName).toBe('BUTTON')
    expect(submitButton).toHaveAttribute('type', 'submit')
  })
})
