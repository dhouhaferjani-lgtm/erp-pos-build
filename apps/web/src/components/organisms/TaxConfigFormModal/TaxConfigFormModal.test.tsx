import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { beforeEach, describe, it, expect, vi } from 'vitest'
import { TaxConfigFormModal } from './TaxConfigFormModal'
import type { TaxConfigurationFormData } from '../../../features/settings/types/tax'

const capabilityState = vi.hoisted(() => ({
  data: { supports_stamp_duty: true } as { supports_stamp_duty: boolean } | undefined,
  isLoading: false,
  isError: false,
}))
const createSpy = vi.hoisted(() =>
  vi.fn<(data: TaxConfigurationFormData) => Promise<{ id: string }>>(),
)

vi.mock('../../../hooks/useTaxConfigurations', () => ({
  useCreateTaxConfiguration: () => ({ mutateAsync: createSpy, isPending: false }),
  useUpdateTaxConfiguration: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDocumentTypes: () => ({ data: [{ value: 'TAX_INVOICE', label: 'Tax Invoice' }] }),
  useTaxConfigurationCapabilities: () => ({
    data: capabilityState.data,
    isLoading: capabilityState.isLoading,
    isError: capabilityState.isError,
  }),
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

  beforeEach(() => {
    capabilityState.data = { supports_stamp_duty: true }
    capabilityState.isLoading = false
    capabilityState.isError = false
    createSpy.mockReset()
    createSpy.mockResolvedValue({ id: 'tax-1' })
  })

  it('lets the admin mark a configuration as a stamp duty', () => {
    render(<TaxConfigFormModal {...baseProps} />)
    const checkbox = screen.getByLabelText('settings:tax.configurations.form.stampDuty')
    expect(checkbox).toBeInTheDocument()
    expect(checkbox).toHaveAttribute('type', 'checkbox')
    expect(checkbox).toBeEnabled()
  })

  it('disables stamp-duty and document-total controls with a reason when the country is unsupported', () => {
    capabilityState.data = { supports_stamp_duty: false }

    render(<TaxConfigFormModal {...baseProps} />)

    expect(screen.getByLabelText('settings:tax.configurations.form.stampDuty')).toBeDisabled()
    expect(screen.getByRole('option', {
      name: 'settings:tax.configurations.form.appliesToDocument',
    })).toBeDisabled()
    expect(screen.getByText('settings:tax.configurations.form.stampDutyUnavailable')).toBeInTheDocument()
  })

  // F-5: the loading/unresolved state was never exercised, so "fails closed"
  // was an unproven claim about the branch that matters most.
  it('fails closed while the capability is still unresolved', () => {
    capabilityState.data = undefined
    capabilityState.isLoading = true

    render(<TaxConfigFormModal {...baseProps} />)

    expect(screen.getByLabelText('settings:tax.configurations.form.stampDuty')).toBeDisabled()
    expect(screen.getByRole('option', {
      name: 'settings:tax.configurations.form.appliesToDocument',
    })).toBeDisabled()
  })

  // F-6: a failed fetch must not be reported as "your country is unsupported".
  it('distinguishes a failed capability fetch from an unsupported country', () => {
    capabilityState.data = undefined
    capabilityState.isError = true

    render(<TaxConfigFormModal {...baseProps} />)

    expect(
      screen.getByText('settings:tax.configurations.form.stampDutyCapabilityUnavailable'),
    ).toBeInTheDocument()
    expect(
      screen.queryByText('settings:tax.configurations.form.stampDutyUnavailable'),
    ).not.toBeInTheDocument()
    expect(screen.getByLabelText('settings:tax.configurations.form.stampDuty')).toBeDisabled()
  })

  // F-2: the create payload must carry a `stacks_on` the backend actually
  // accepts (`in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS`). Asserting the payload —
  // not the mocked hook resolving — is the whole point: the previous default
  // 'BASE_AMOUNT' made every real create 422 while this suite stayed green.
  it('submits a stacks_on value the backend accepts', async () => {
    render(<TaxConfigFormModal {...baseProps} />)

    fireEvent.click(screen.getByText('common:save'))

    await waitFor(() => { expect(createSpy).toHaveBeenCalledTimes(1) })

    const payload = createSpy.mock.calls[0][0]
    expect(['SUBTOTAL', 'TOTAL_INCLUDING_PREVIOUS']).toContain(payload.stacks_on)
    expect(payload.applies_to).toBe('LINE_ITEMS')
  })

  it('exposes effective-from / effective-to date inputs', () => {
    render(<TaxConfigFormModal {...baseProps} />)
    const from = screen.getByLabelText('settings:tax.configurations.form.effectiveFrom')
    const to = screen.getByLabelText('settings:tax.configurations.form.effectiveTo')
    expect(from).toHaveAttribute('type', 'date')
    expect(to).toHaveAttribute('type', 'date')
  })
})
