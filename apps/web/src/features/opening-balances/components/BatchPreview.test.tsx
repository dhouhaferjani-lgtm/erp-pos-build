import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { BatchPreview } from './BatchPreview'
import { useCompanyStore } from '@/stores/companyStore'
import type { AccountingPostPreview, ArApPostPreview, InventoryPostPreview } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

/**
 * Every fixture below is the REAL payload of
 * `GET /companies/{id}/opening-batches/{id}/preview`, transcribed from the
 * server transformers and pinned by
 * `apps/api/tests/Feature/Accounting/OpeningBalancePreviewContractTest.php`.
 * Do not "tidy" a key here without changing that test first.
 */
const accountingPreview: AccountingPostPreview = {
  batch_type: 'ACCOUNTING',
  entry: {
    entry_date: '2026-01-01',
    description: 'GL Opening Balance - Ouverture 2026',
    is_historical: true,
    source_type: 'opening_balance',
  },
  lines: [
    {
      row_number: 1,
      account_code: '5310',
      account_name: 'Caisse',
      debit: '10000.000',
      credit: '0.000',
      description: 'Solde d ouverture caisse',
      repository_code: 'CASH-01',
      repository_name: 'Caisse principale',
    },
  ],
  totals: {
    debit: '10000.000',
    credit: '0.000',
    is_balanced: false,
  },
}

const inventoryPreview: InventoryPostPreview = {
  batch_type: 'INVENTORY',
  batch: {
    cutover_date: '2026-07-01',
    description: 'Inventory Opening Balance - Precision Preview',
    is_historical: true,
    source_type: 'opening_balance',
  },
  lines: [
    {
      row_number: 1,
      product_sku: 'SKU-1',
      product_name: 'Precision product',
      location_code: 'WH-1',
      location_name: 'Warehouse',
      quantity: '1.25',
      quantity_decimals: 3,
      unit_cost: '2.000',
      line_value: '2.500',
      expiry_date: null,
    },
  ],
  totals: {
    total_lines: 1,
    total_quantity: '1.2500',
    total_value: '2.500',
  },
  gl_entry: {
    debit_account: 'Inventory',
    credit_account: 'Opening Balance Equity',
    amount: '2.500',
  },
}

const arPreview: ArApPostPreview = {
  batch_type: 'AR_OPEN_ITEMS',
  batch: {
    cutover_date: '2026-01-01',
    description: 'AR Open Items - Encours clients',
    is_historical: true,
    batch_type: 'AR_OPEN_ITEMS',
  },
  documents: [
    {
      row_number: 1,
      partner_code: 'CUST-1',
      partner_name: 'Client Un',
      external_invoice_number: 'INV-2025-001',
      document_type: 'invoice',
      document_date: '2025-12-01',
      due_date: '2026-01-15',
      currency: 'TND',
      total: '1200.000',
      open_amount: '900.000',
    },
  ],
  totals: {
    total_documents: 1,
    total_amount: '1200.000',
    total_open_amount: '900.000',
  },
  note: 'Historical documents will be created with is_historical=true.',
}

describe('BatchPreview', () => {
  beforeEach(() => {
    // Gate r1 F-5: amounts render through the company currency, so the tenant
    // under test is the campaign's TND one — millimes and fr-TN grouping.
    useCompanyStore.setState({
      currentCompanyId: 'company-1',
      companies: [
        {
          id: 'company-1',
          name: 'ParaBio Tunisie SARL',
          legalName: 'ParaBio Tunisie SARL',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'fr_TN',
          timezone: 'Africa/Tunis',
        },
      ],
      isLoading: false,
    })
  })

  // N-3: this render used to throw
  // "Cannot read properties of undefined (reading 'cutover_date')" and take the
  // whole wizard to the ErrorBoundary, because the ACCOUNTING payload has no
  // `batch` key — its header is `entry`.
  it('renders the ACCOUNTING header from entry.entry_date, not batch.cutover_date', () => {
    render(<BatchPreview preview={accountingPreview} />)

    expect(screen.getByText('2026-01-01')).toBeInTheDocument()
    expect(screen.getByText('GL Opening Balance - Ouverture 2026')).toBeInTheDocument()
  })

  // The ACCOUNTING totals are `debit`/`credit`; the component read
  // `total_debit`/`total_credit`, which the API has never sent, so the footer
  // rendered two blank cells even once the crash was gone.
  it('renders the ACCOUNTING totals from totals.debit / totals.credit', () => {
    render(<BatchPreview preview={accountingPreview} />)

    // once in the line row, once in the tfoot total — TND, so millimes and
    // fr-TN grouping ("10 000,000"), never the raw '10000.000' the API sends.
    // The grouping separator is a non-breaking space of some flavour, so match
    // on the whitespace-stripped text rather than reproducing Intl's exact bytes.
    const groupedTenThousand = (_content: string, element: Element | null): boolean =>
      element !== null && element.tagName === 'TD' && element.textContent.replace(/\s/gu, '') === '10000,000'
    expect(screen.getAllByText(groupedTenThousand)).toHaveLength(2)
    expect(screen.queryByText('10000.000')).not.toBeInTheDocument()
    // credit is '0.000' on both the line and the total; a zero LINE amount is
    // blanked (never float-parsed), so only the tfoot total prints it
    expect(screen.getAllByText('0,000')).toHaveLength(1)
    expect(screen.getByText('5310')).toBeInTheDocument()
    expect(screen.getByText('Caisse')).toBeInTheDocument()
  })

  it('formats inventory quantities with each mapped products served scale', () => {
    render(<BatchPreview preview={inventoryPreview} />)

    expect(screen.getByText('1.250')).toBeInTheDocument()
  })

  it('renders the INVENTORY header from batch.cutover_date', () => {
    render(<BatchPreview preview={inventoryPreview} />)

    expect(screen.getByText('2026-07-01')).toBeInTheDocument()
    expect(screen.getByText('Inventory Opening Balance - Precision Preview')).toBeInTheDocument()
  })

  it('renders the AR_OPEN_ITEMS documents table and server note', () => {
    render(<BatchPreview preview={arPreview} />)

    expect(screen.getByText('2026-01-01')).toBeInTheDocument()
    expect(screen.getByText('INV-2025-001')).toBeInTheDocument()
    // once in the document row, once in the "open amount" summary card
    expect(screen.getAllByText('900.000')).toHaveLength(2)
    expect(
      screen.getByText('Historical documents will be created with is_historical=true.')
    ).toBeInTheDocument()
  })
})
