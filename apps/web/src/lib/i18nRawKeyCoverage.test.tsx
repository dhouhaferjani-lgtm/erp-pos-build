import { screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import i18n from '@/lib/i18n'
import { OpeningBalancesPage } from '@/features/opening-balances/pages/OpeningBalancesPage'
import { renderWithProviders } from '@/test/renderWithProviders'

vi.mock('@/features/opening-balances/api/queries', () => ({
  useOpeningBatchStatus: () => ({
    data: {
      types: {
        ACCOUNTING: {
          type: 'ACCOUNTING',
          has_batch: false,
          batch: null,
        },
        INVENTORY: {
          type: 'INVENTORY',
          has_batch: false,
          batch: null,
        },
        AR_OPEN_ITEMS: {
          type: 'AR_OPEN_ITEMS',
          has_batch: false,
          batch: null,
        },
        AP_OPEN_ITEMS: {
          type: 'AP_OPEN_ITEMS',
          has_batch: false,
          batch: null,
        },
      },
    },
    isLoading: false,
  }),
}))

const locales = ['en', 'fr', 'ar'] as const

const expectedStringKeys = [
  'common:openingBalances.progress.title',
  'common:openingBalances.progress.completed',
  'common:openingBalances.progress.allComplete',
  'common:openingBalances.batchTypes',
  'common:openingBalances.rowsCount',
  'common:openingBalances.howItWorks.title',
  'common:openingBalances.howItWorks.description',
  'common:openingBalances.howItWorks.step1',
  'common:openingBalances.notes.title',
  'common:openingBalances.notes.note1',
  'common:table.actions',
  'common:fields.total',
  'sales:documents.supplier',
  'sales:purchaseOrders.received',
  'sales:invoices.paymentHistory.label',
  'sales:purchaseOrders.paymentHistory',
  'inventory:counting.viewAll',
  'products:parapharmacy.requiresConsultation',
] as const

describe('raw i18n key coverage', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it.each(locales)('resolves catalogued raw-key fixes in %s', async (locale) => {
    await i18n.changeLanguage(locale)

    for (const key of expectedStringKeys) {
      const translated = i18n.t(key)
      expect(typeof translated, key).toBe('string')
      expect(translated, key).not.toBe(key)
      expect(translated, key).not.toMatch(/[a-z]+(?:\.[a-zA-Z_]+)+/)
    }
  })

  it('renders opening balance labels instead of raw keys', () => {
    renderWithProviders(<OpeningBalancesPage />, {
      route: '/settings/opening-balances',
    })

    expect(screen.getByText('Opening Balances')).toBeInTheDocument()
    expect(screen.getByText('Progress Overview')).toBeInTheDocument()
    expect(screen.getByText('AR Open Items')).toBeInTheDocument()
    expect(screen.getByText('AP Open Items')).toBeInTheDocument()
    expect(screen.queryByText('openingBalances.progress.title')).not.toBeInTheDocument()
    expect(screen.queryByText('openingBalances.types.aropen_items.title')).not.toBeInTheDocument()
  })
})
