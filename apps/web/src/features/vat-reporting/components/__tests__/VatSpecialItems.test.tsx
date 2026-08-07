import { render, screen } from '@testing-library/react'
import { VatSpecialItems } from '../VatSpecialItems'
import { useCompanyStore } from '@/stores/companyStore'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'finance:vatReporting.specialItems.title': 'Special Items',
        'finance:vatReporting.specialItems.timbreFiscalCount': 'Timbre Fiscal Count',
        'finance:vatReporting.specialItems.timbreFiscalAmount': 'Timbre Fiscal Amount',
        'finance:vatReporting.specialItems.retenueSourceAmount': 'Retenue à la Source Amount',
        'finance:vatReporting.specialItems.intraCommunityAcquisitions': 'Intra-Community Acquisitions',
        'finance:vatReporting.specialItems.intraCommunitySupplies': 'Intra-Community Supplies',
        'finance:vatReporting.specialItems.ecSupplies': 'EC Supplies',
        'finance:vatReporting.specialItems.ecAcquisitions': 'EC Acquisitions',
      }
      return translations[key] ?? key
    },
  }),
}))

describe('VatSpecialItems', () => {
  // MAJOR-1/MAJOR-2 (2026-08-07 FE gate,
  // docs/superpowers/reviews/2026-08-07-r2g-fe-gate.md): pin a TND company
  // so money renders at TND's 3-decimal (millime) scale, not a hardcoded
  // en-US/2dp truncation.
  beforeEach(() => {
    useCompanyStore.setState({
      currentCompanyId: 'c1',
      companies: [
        {
          id: 'c1',
          name: 'Test TN Co',
          legalName: 'Test TN Co SARL',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'fr-TN',
          timezone: 'Africa/Tunis',
        },
      ],
      isLoading: false,
    })
  })

  afterEach(() => {
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  /**
   * MAJOR-2: the real shape TunisiaVatStrategy::getSpecialLineItems()
   * emits (apps/api/.../TunisiaVatStrategy.php:118-122) --
   * stamp_duty_count / stamp_duty_total / retenue_source_total. The panel
   * used to key on timbre_fiscal_count / timbre_fiscal_amount /
   * retenue_source_amount, which the backend never sends, so it NEVER
   * rendered. This is the render-with-real-strategy-output proof.
   */
  it('renders with a real TN-strategy payload: money at TND 3dp + integer count', () => {
    render(
      <VatSpecialItems
        specialItems={{
          stamp_duty_count: 3,
          stamp_duty_total: '1.800',
          retenue_source_total: '234.567',
        }}
        countryCode="TN"
      />
    )

    expect(screen.getByText('Special Items')).toBeInTheDocument()

    // Count: a plain integer, never money-formatted (MINOR-3: "3.00" was wrong).
    expect(screen.getByText('Timbre Fiscal Count')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
    expect(screen.queryByText('3.00')).not.toBeInTheDocument()
    expect(screen.queryByText('3,00')).not.toBeInTheDocument()

    // Money: TND's own 3-decimal (millime) scale, fr-TN grouping -- not
    // en-US/2dp (1.80 would be the old, wrong, truncated rendering).
    expect(screen.getByText('Timbre Fiscal Amount')).toBeInTheDocument()
    expect(screen.getByText('1,800')).toBeInTheDocument()
    expect(screen.queryByText('1.80')).not.toBeInTheDocument()

    expect(screen.getByText('Retenue à la Source Amount')).toBeInTheDocument()
    // A real millime (234.567) that a 2dp formatter would have rounded
    // away to 234.57 -- now preserved at TND's own scale.
    expect(screen.getByText('234,567')).toBeInTheDocument()
    expect(screen.queryByText('234.57')).not.toBeInTheDocument()
  })

  // MAJOR-3 (2026-08-07 FE re-gate): FR/GB strategies emit hardcoded
  // deferred-feature STUBS ('0.000'/0) -- rendering them would present
  // placeholder zeros as computed fact ("Acquisitions intracommunautaires
  // 0,00" implying none were found when the system never tracks them).
  // The panel must render NOTHING for FR/GB until the strategies compute
  // real values.
  it('renders nothing for FR while the FranceVatStrategy emits deferred-feature stubs', () => {
    const { container } = render(
      <VatSpecialItems
        specialItems={{
          intra_community_acquisitions: '0.000',
          intra_community_supplies: '0.000',
        }}
        countryCode="FR"
      />
    )

    expect(container.firstChild).toBeNull()
  })

  it('renders nothing for GB while the UkVatStrategy emits deferred-feature stubs', () => {
    const { container } = render(
      <VatSpecialItems
        specialItems={{
          ec_supplies: 0,
          ec_acquisitions: 0,
        }}
        countryCode="GB"
      />
    )

    expect(container.firstChild).toBeNull()
  })

  it('renders nothing when no configured key has a value (unknown country)', () => {
    const { container } = render(
      <VatSpecialItems specialItems={{ some_unrelated_key: '1.000' }} countryCode="XX" />
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('renders nothing for a known country whose special items are all null/undefined', () => {
    const { container } = render(
      <VatSpecialItems
        specialItems={{ stamp_duty_count: null, stamp_duty_total: null, retenue_source_total: null }}
        countryCode="TN"
      />
    )

    expect(container).toBeEmptyDOMElement()
  })

  /**
   * MINOR-2: junk input renders as itself -- no float coercion (not even
   * a Number()-based validity check) ever touches the value.
   */
  it('renders non-numeric junk input verbatim instead of float-prefix-parsing it', () => {
    render(
      <VatSpecialItems
        specialItems={{ stamp_duty_total: '12abc' }}
        countryCode="TN"
      />
    )

    expect(screen.getByText('12abc')).toBeInTheDocument()
    expect(screen.queryByText('12.00')).not.toBeInTheDocument()
    expect(screen.queryByText('12,000')).not.toBeInTheDocument()
  })
})
