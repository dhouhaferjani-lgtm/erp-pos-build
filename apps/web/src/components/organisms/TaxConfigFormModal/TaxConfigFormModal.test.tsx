import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { TaxConfigFormModal } from './TaxConfigFormModal'

vi.mock('../../../hooks/useTaxConfigurations', () => ({
  useCreateTaxConfiguration: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateTaxConfiguration: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDocumentTypes: () => ({ data: [{ value: 'TAX_INVOICE', label: 'Tax Invoice' }] }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

describe('TaxConfigFormModal', () => {
  const baseProps = {
    isOpen: true,
    onClose: vi.fn(),
    onSaved: vi.fn(),
  }

  it('lets the admin mark a configuration as a stamp duty', () => {
    render(<TaxConfigFormModal {...baseProps} />)
    const checkbox = screen.getByLabelText('settings:tax.configurations.form.stampDuty')
    expect(checkbox).toBeInTheDocument()
    expect(checkbox).toHaveAttribute('type', 'checkbox')
  })

  it('exposes effective-from / effective-to date inputs', () => {
    render(<TaxConfigFormModal {...baseProps} />)
    const from = screen.getByLabelText('settings:tax.configurations.form.effectiveFrom')
    const to = screen.getByLabelText('settings:tax.configurations.form.effectiveTo')
    expect(from).toHaveAttribute('type', 'date')
    expect(to).toHaveAttribute('type', 'date')
  })
})
