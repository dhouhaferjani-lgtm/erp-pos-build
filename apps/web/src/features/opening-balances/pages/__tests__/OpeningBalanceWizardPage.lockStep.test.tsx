import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { OpeningBalanceWizardPage } from '../OpeningBalanceWizardPage'
import type {
  OpeningBalanceBatch,
  OpeningBatchStatus,
  OpeningBatchStatusInfo,
  OpeningBatchStatusResponse,
  OpeningBatchType,
} from '../../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ format: (value: string) => value }),
}))

const mockLockMutateAsync = vi.hoisted(() => vi.fn())
const mockStatus = vi.hoisted(
  () => vi.fn<() => { data: OpeningBatchStatusResponse | undefined }>()
)
const mockBatch = vi.hoisted(() => vi.fn<() => { data: OpeningBalanceBatch | undefined }>())

const idleMutation = () => ({ mutateAsync: vi.fn(), isPending: false })

vi.mock('../../api/queries', () => ({
  useOpeningBatchStatus: () => mockStatus(),
  useOpeningBatch: () => mockBatch(),
  useOpeningBatchRows: () => ({ data: undefined, refetch: vi.fn() }),
  useOpeningBatchPreview: () => ({ data: undefined, isLoading: false }),
  useCreateOpeningBatch: () => idleMutation(),
  useImportOpeningRows: () => idleMutation(),
  useValidateOpeningBatch: () => idleMutation(),
  usePostOpeningBatch: () => idleMutation(),
  useLockOpeningBatch: () => ({ mutateAsync: mockLockMutateAsync, isPending: false }),
}))

/**
 * W4R-1. The six wizard steps run for every batch type, but posting does NOT
 * leave every batch type in the same state: `AccountingOpeningService::postBatch`
 * seals the batch itself (LOCKED), while AR/AP and Inventory finish `post` at
 * VALIDATED and genuinely need step 6. These two cases pin that asymmetry from
 * the screen the operator actually sees.
 */
const batchFixture = (status: OpeningBatchStatus): OpeningBalanceBatch => ({
  id: 'b1111111-1111-4111-8111-111111111111',
  tenant_id: 't1111111-1111-4111-8111-111111111111',
  company_id: 'c1111111-1111-4111-8111-111111111111',
  type: 'ACCOUNTING',
  name: 'Ouverture 2026',
  cutover_date: '2026-01-01',
  status,
  source_system: null,
  import_file_reference: null,
  hash: status === 'LOCKED' ? 'a'.repeat(64) : null,
  previous_hash: null,
  validated_at: status === 'DRAFT' ? null : '2026-01-01T00:00:00+00:00',
  validated_by: null,
  locked_at: status === 'LOCKED' ? '2026-01-01T00:00:00+00:00' : null,
  locked_by: null,
  created_at: '2026-01-01T00:00:00+00:00',
  updated_at: '2026-01-01T00:00:00+00:00',
  created_by: 'u1111111-1111-4111-8111-111111111111',
  rows_count: 2,
  valid_rows_count: 2,
  invalid_rows_count: 0,
})

const statusInfo = (type: OpeningBatchType, batch: OpeningBalanceBatch | null): OpeningBatchStatusInfo => ({
  type,
  label: type,
  has_batch: batch !== null,
  batch,
  is_locked: batch?.status === 'LOCKED',
  is_ready: batch?.status === 'LOCKED',
})

const statusResponse = (batch: OpeningBalanceBatch): OpeningBatchStatusResponse => ({
  types: {
    ACCOUNTING: statusInfo('ACCOUNTING', batch.type === 'ACCOUNTING' ? batch : null),
    INVENTORY: statusInfo('INVENTORY', null),
    AR_OPEN_ITEMS: statusInfo('AR_OPEN_ITEMS', null),
    AP_OPEN_ITEMS: statusInfo('AP_OPEN_ITEMS', null),
  },
  all_ready: false,
  inventory_ready: false,
})

const renderWizard = () =>
  render(
    <MemoryRouter initialEntries={['/settings/opening-balances/accounting']}>
      <Routes>
        <Route path="/settings/opening-balances/:type" element={<OpeningBalanceWizardPage />} />
      </Routes>
    </MemoryRouter>
  )

describe('OpeningBalanceWizardPage — lock step', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockLockMutateAsync.mockResolvedValue(batchFixture('LOCKED'))
  })

  it('shows the completed screen — not a Lock button — for a batch that posting already sealed', () => {
    const locked = batchFixture('LOCKED')
    mockStatus.mockReturnValue({ data: statusResponse(locked) })
    mockBatch.mockReturnValue({ data: locked })

    renderWizard()

    expect(screen.getByText('openingBalances.wizard.complete.title')).toBeInTheDocument()
    expect(screen.queryByText('openingBalances.wizard.lock.lockButton')).not.toBeInTheDocument()
  })

  it('keeps the Lock step for a VALIDATED batch and completes the wizard when it succeeds', async () => {
    const validated = batchFixture('VALIDATED')
    mockStatus.mockReturnValue({ data: statusResponse(validated) })
    mockBatch.mockReturnValue({ data: validated })

    renderWizard()

    const lockButton = screen.getByText('openingBalances.wizard.lock.lockButton')
    expect(lockButton).toBeInTheDocument()

    await userEvent.click(lockButton)

    expect(mockLockMutateAsync).toHaveBeenCalledWith(validated.id)
    await waitFor(() => {
      expect(screen.getByText('openingBalances.wizard.complete.title')).toBeInTheDocument()
    })
  })
})
