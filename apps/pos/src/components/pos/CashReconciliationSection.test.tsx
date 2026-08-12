import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act, within } from '@testing-library/react';
import {
  CashReconciliationSection,
  type CashReconciliationSectionProps,
  type CompanyFraudSettings,
} from './CashReconciliationSection';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import enPos from '@/locales/en/pos.json';
import frPos from '@/locales/fr/pos.json';

const i18nTestState = vi.hoisted(() => ({ locale: 'en' as 'en' | 'fr' }));

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const french: Record<string, string> = {
        'cash_count.count_instruction':
          "Comptez tout l'argent présent dans le tiroir, y compris le fonds de caisse de {{amount}}.",
        'cash_count.expected_includes_float':
          'Le montant attendu inclut le fonds de caisse.',
        'cash_count.summary.opening_float': 'Fonds de caisse',
        'cash_count.summary.cash_sales_net':
          'Ventes en espèces (net rendu monnaie)',
        'cash_count.summary.drawer_movements': "Entrées / sorties d'espèces",
        'cash_count.summary.expected_in_drawer': 'Attendu en caisse',
        'cash_count.summary.counted': 'Compté',
        'cash_count.no_difference': 'Aucun écart',
      };
      const value =
        (i18nTestState.locale === 'fr' ? french[key] : undefined) ??
        (opts?.defaultValue as string) ??
        key;
      return value.replace('{{amount}}', String(opts?.amount ?? ''));
    },
  }),
}));

vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: (code: string) => (code === 'TND' ? 3 : code === 'JPY' ? 0 : 2),
}));

// ── Fixtures ──────────────────────────────────────────────────────────────────

const baseFraudSettings: CompanyFraudSettings = {
  cash_variance_over_soft: '5.00',
  cash_variance_over_hard: '20.00',
  cash_variance_under_soft: '5.00',
  cash_variance_under_hard: '20.00',
  require_blind_cash_count: false,
  require_manager_pin_above_hard: false,
};

/**
 * Build a minimal EndOfDayPreview with one CASH physical tender (optional second).
 * Accepts an extended PaymentMethodItem shape with optional payment_method_name.
 */
function makePreview(
  methods: Array<{
    payment_method_id: string;
    payment_method_code: string;
    payment_method_name?: string;
    is_physical: boolean;
    total_amount: string;
    transaction_count: number;
  }>,
): EndOfDayPreview {
  return {
    sales_count: 1,
    gross_sales: '100.00',
    net_sales: '84.03',
    tax_amount: '15.97',
    opening_cash: '50.00',
    cash_sales_net: '50.00',
    drawer_movements_net: '0.00',
    expected_cash: '100.00',
    variance: null,
    vat_breakdown: [],
    payment_methods: methods as EndOfDayPreview['payment_methods'],
    tolerance_summary: null,
    cash_rounding_summary: null,
    tolerance_auto_accept_count: 0,
  };
}

const singleCashPreview = makePreview([
  {
    payment_method_id: 'pm-cash',
    payment_method_code: 'CASH',
    payment_method_name: 'Cash',
    is_physical: true,
    total_amount: '100.00',
    transaction_count: 3,
  },
]);

function buildProps(
  overrides: Partial<CashReconciliationSectionProps> = {},
): CashReconciliationSectionProps {
  return {
    preview: singleCashPreview,
    fraudSettings: baseFraudSettings,
    authorizedManagers: [],
    cashierUserId: 'user-1',
    currencyCode: 'EUR',
    onVerifyManagerPin: vi.fn().mockResolvedValue({ valid: true }),
    onChange: vi.fn(),
    managerPinThrottle: { until: null, failedAttempts: 0 },
    onManagerPinThrottleUpdate: vi.fn(),
    ...overrides,
  };
}

/** Click the Actual input for a given tender code then tap numpad digits. */
async function enterActual(code: string, value: string) {
  fireEvent.click(screen.getByTestId(`tender-actual-input-${code}`));
  await Promise.resolve();
  const panel = screen.getByTestId('cash-count-numpad-panel');
  for (const ch of value) {
    if (ch === '.') {
      fireEvent.click(panel.querySelector('[data-testid="numpad-dot"]')!);
    } else {
      fireEvent.click(
        panel.querySelector(`[data-testid="numpad-digit-${ch}"]`)!,
      );
    }
    await Promise.resolve();
  }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('CashReconciliationSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    i18nTestState.locale = 'en';
  });

  describe('SV-11 — whole-drawer copy and reveal', () => {
    it('shows the float disclosure immediately when counting is not blind', () => {
      render(<CashReconciliationSection {...buildProps()} />);

      expect(
        screen.getByText('Expected includes the opening float.'),
      ).toBeInTheDocument();
    });

    it('keeps the interpolated whole-drawer instruction visible before blind commit', () => {
      render(
        <CashReconciliationSection
          {...buildProps({
            fraudSettings: { ...baseFraudSettings, require_blind_cash_count: true },
          })}
        />,
      );

      expect(screen.getByTestId('cash-count-instruction')).toHaveTextContent(
        'Count all the cash in the drawer, including the opening float of 50.00.',
      );
      expect(screen.queryByTestId('cash-count-reveal-summary')).not.toBeInTheDocument();
      expect(screen.queryByText(/rounding/i)).not.toBeInTheDocument();
    });

    it('reveals the six-line decomposition after blind commit and names zero variance', async () => {
      render(
        <CashReconciliationSection
          {...buildProps({
            fraudSettings: { ...baseFraudSettings, require_blind_cash_count: true },
          })}
        />,
      );

      await act(async () => {
        await enterActual('CASH', '100');
      });
      fireEvent.click(screen.getByTestId('commit-counts-button'));

      const summary = await screen.findByTestId('cash-count-reveal-summary');
      expect(summary).toHaveTextContent('Opening float50.00');
      expect(summary).toHaveTextContent('Cash sales (net of change)50.00');
      expect(summary).toHaveTextContent('Paid in / paid out0.00');
      expect(summary).toHaveTextContent('Expected in drawer100.00');
      expect(summary).toHaveTextContent('Counted100');
      expect(summary).toHaveTextContent('No difference');
      expect(screen.getByTestId('tender-variance-CASH')).toHaveTextContent(
        'No difference',
      );
      expect(summary).toHaveTextContent('Expected includes the opening float.');
      expect(within(summary).queryByText(/rounding/i)).not.toBeInTheDocument();
    });

    it('ships the exact English and French locale strings and keeps Écart', () => {
      expect(enPos.cash_count.count_instruction).toBe(
        'Count all the cash in the drawer, including the opening float of {{amount}}.',
      );
      expect(frPos.cash_count.count_instruction).toBe(
        "Comptez tout l'argent présent dans le tiroir, y compris le fonds de caisse de {{amount}}.",
      );
      expect(enPos.cash_count.expected_includes_float).toBe(
        'Expected includes the opening float.',
      );
      expect(frPos.cash_count.expected_includes_float).toBe(
        'Le montant attendu inclut le fonds de caisse.',
      );
      expect(frPos.cash_count.no_difference).toBe('Aucun écart');
      expect(frPos.cash_count.variance).toBe('Écart');
    });

    it('renders the exact French instruction, disclosure, and six-line reveal', async () => {
      i18nTestState.locale = 'fr';
      render(
        <CashReconciliationSection
          {...buildProps({
            fraudSettings: { ...baseFraudSettings, require_blind_cash_count: true },
          })}
        />,
      );

      expect(screen.getByTestId('cash-count-instruction')).toHaveTextContent(
        "Comptez tout l'argent présent dans le tiroir, y compris le fonds de caisse de 50.00.",
      );
      expect(
        screen.queryByText('Le montant attendu inclut le fonds de caisse.'),
      ).not.toBeInTheDocument();

      await act(async () => {
        await enterActual('CASH', '100');
      });
      fireEvent.click(screen.getByTestId('commit-counts-button'));

      const summary = await screen.findByTestId('cash-count-reveal-summary');
      expect(summary).toHaveTextContent(
        'Le montant attendu inclut le fonds de caisse.',
      );
      expect(summary).toHaveTextContent('Fonds de caisse50.00');
      expect(summary).toHaveTextContent(
        'Ventes en espèces (net rendu monnaie)50.00',
      );
      expect(summary).toHaveTextContent("Entrées / sorties d'espèces0.00");
      expect(summary).toHaveTextContent('Attendu en caisse100.00');
      expect(summary).toHaveTextContent('Compté100');
      expect(summary).toHaveTextContent('Aucun écart');
    });
  });

  // ── E4: payment_method_name display ────────────────────────────────────────

  describe('E4 — payment_method_name display', () => {
    it('renders payment_method_name when provided instead of payment_method_code', () => {
      const preview = makePreview([
        {
          payment_method_id: 'pm-cash',
          payment_method_code: 'CASH',
          payment_method_name: 'Cash Drawer',
          is_physical: true,
          total_amount: '100.00',
          transaction_count: 3,
        },
      ]);
      render(<CashReconciliationSection {...buildProps({ preview })} />);

      // The display name 'Cash Drawer' must appear in the table row
      expect(screen.getByTestId('tender-row-CASH')).toHaveTextContent('Cash Drawer');
    });

    it('falls back to payment_method_code when payment_method_name is undefined at runtime', () => {
      /**
       * When a PaymentMethodItem arrives without payment_method_name (e.g. legacy data),
       * the component must display the code rather than rendering blank.
       * Simulate by omitting payment_method_name from the fixture so it is undefined at runtime.
       */
      const preview = makePreview([
        {
          payment_method_id: 'pm-cash',
          payment_method_code: 'CASH',
          // payment_method_name intentionally absent → undefined at runtime
          is_physical: true,
          total_amount: '100.00',
          transaction_count: 3,
        },
      ]);
      render(<CashReconciliationSection {...buildProps({ preview })} />);
      expect(screen.getByTestId('tender-row-CASH')).toHaveTextContent('CASH');
    });
  });

  // ── G12/D1: aggregate signed-sum severity ──────────────────────────────────

  describe('G12/D1 — aggregate signed-sum severity', () => {
    it('two physical methods with offsetting variances (+6 and -6) produce balanced severity — no reason input shown', async () => {
      /**
       * CASH expected 100, actual 106  → variance +6.00 (over, >soft=5)
       * CARD expected  50, actual  44  → variance -6.00 (under, >soft=5)
       * Signed sum = +6.00 + -6.00 = 0 → balanced
       *
       * Old "worst-of-any" logic: each is over soft → warning severity per tender →
       * worst = warning → reason textarea shown.
       * New aggregate logic: signed sum = 0 → balanced → no reason textarea.
       */
      const preview = makePreview([
        {
          payment_method_id: 'pm-cash',
          payment_method_code: 'CASH',
          payment_method_name: 'Cash',
          is_physical: true,
          total_amount: '100.00',
          transaction_count: 2,
        },
        {
          payment_method_id: 'pm-card',
          payment_method_code: 'CARD',
          payment_method_name: 'Card',
          is_physical: true,
          total_amount: '50.00',
          transaction_count: 1,
        },
      ]);

      const props = buildProps({
        preview: {
          ...preview,
          // expected_cash for CASH computed from opening+tendered-change = 100.00
          expected_cash: '100.00',
        },
      });

      render(<CashReconciliationSection {...props} />);

      // Enter CASH actual = 106 (over by 6 — exceeds soft=5, old code → warning)
      await act(async () => {
        await enterActual('CASH', '106');
      });

      // Enter CARD actual = 44 (under by 6 — exceeds soft=5, old code → warning)
      await act(async () => {
        await enterActual('CARD', '44');
      });

      // Reason section should NOT appear — aggregate signed sum = 0 → balanced
      await waitFor(() => {
        expect(
          screen.queryByTestId('variance-reason-section'),
        ).not.toBeInTheDocument();
      });
    });

    it('single physical method over soft threshold shows reason section', async () => {
      /**
       * CASH expected 100, actual 110 → over by 10 > soft(5) → warning → reason required
       */
      render(<CashReconciliationSection {...buildProps()} />);

      await act(async () => {
        await enterActual('CASH', '110');
      });

      await waitFor(() => {
        expect(screen.getByTestId('variance-reason-section')).toBeInTheDocument();
      });
    });

    it('two physical methods with individual-warning variances but aggregate info → no reason', async () => {
      /**
       * CASH expected 100, actual 108  → +8.00 signed variance (>soft=5 → warning per tender)
       * CARD expected  50, actual  43  → -7.00 signed variance (>soft=5 → warning per tender)
       * Aggregate = +8.00 - 7.00 = +1.00, which is <= soft(5) → info → no reason
       *
       * Old "worst-of-any" picks warning → shows reason textarea.
       * New aggregate: signed sum = +1.00 ≤ soft(5) → info → no reason.
       */
      const preview = makePreview([
        {
          payment_method_id: 'pm-cash',
          payment_method_code: 'CASH',
          payment_method_name: 'Cash',
          is_physical: true,
          total_amount: '100.00',
          transaction_count: 2,
        },
        {
          payment_method_id: 'pm-card',
          payment_method_code: 'CARD',
          payment_method_name: 'Card',
          is_physical: true,
          total_amount: '50.00',
          transaction_count: 1,
        },
      ]);

      render(
        <CashReconciliationSection
          {...buildProps({
            preview: { ...preview, expected_cash: '100.00' },
          })}
        />,
      );

      await act(async () => {
        await enterActual('CASH', '108');
      });
      await act(async () => {
        await enterActual('CARD', '43');
      });

      // Aggregate = +1.00 which is info → no reason textarea
      await waitFor(() => {
        expect(
          screen.queryByTestId('variance-reason-section'),
        ).not.toBeInTheDocument();
      });
    });
  });

  // ── E3: verifiedManager invalidated when actuals change ───────────────────

  describe('E3 — verifiedManager cleared on actuals change after verification', () => {
    it('manager-verified badge disappears when actuals change after PIN entry', async () => {
      /**
       * Setup: CASH with require_manager_pin_above_hard=true.
       * Enter actual in critical range → manager PIN panel appears.
       * Verify PIN → manager-verified badge shown.
       * Change actual directly via setActuals path → manager-verified must clear.
       *
       * We simulate the actual change by directly firing a change event on the
       * numpad panel's digit buttons after re-opening the numpad.
       */
      const onVerifyManagerPin = vi.fn().mockResolvedValue({ valid: true });

      const preview = makePreview([
        {
          payment_method_id: 'pm-cash',
          payment_method_code: 'CASH',
          payment_method_name: 'Cash',
          is_physical: true,
          total_amount: '100.00',
          transaction_count: 2,
        },
      ]);

      render(
        <CashReconciliationSection
          {...buildProps({
            preview: { ...preview, expected_cash: '100.00' },
            fraudSettings: {
              ...baseFraudSettings,
              require_manager_pin_above_hard: true,
            },
            authorizedManagers: [{ id: 'mgr-1', name: 'Manager One' }],
            onVerifyManagerPin,
          })}
        />,
      );

      // Enter actual = 50 → under by 50 → exceeds hard(20) → critical
      await act(async () => {
        await enterActual('CASH', '50');
      });

      // Manager PIN section must appear
      await waitFor(() => {
        expect(screen.getByTestId('manager-pin-section')).toBeInTheDocument();
      });

      // Verify manager PIN: enter 4 digits then click verify
      const pinSection = screen.getByTestId('manager-pin-section');
      await act(async () => {
        for (const d of ['1', '2', '3', '4']) {
          fireEvent.click(
            pinSection.querySelector(`[data-testid="numpad-digit-${d}"]`)!,
          );
          await Promise.resolve();
        }
      });
      await act(async () => {
        fireEvent.click(
          pinSection.querySelector('[data-testid="manager-pin-verify"]')!,
        );
      });

      // Manager verified badge must appear
      await waitFor(() => {
        expect(screen.getByTestId('manager-verified')).toBeInTheDocument();
      });

      // Now re-open the cash numpad and change the actual value.
      // The button toggles: first click (activeMethodId=pm-cash) closes it,
      // second click opens it again.
      await act(async () => {
        fireEvent.click(screen.getByTestId('tender-actual-input-CASH'));
        await Promise.resolve();
        fireEvent.click(screen.getByTestId('tender-actual-input-CASH'));
        await Promise.resolve();
      });

      const panel = screen.getByTestId('cash-count-numpad-panel');

      await act(async () => {
        // Append digit '6' to change the numeric value
        fireEvent.click(panel.querySelector('[data-testid="numpad-digit-6"]')!);
        await Promise.resolve();
      });

      // manager-verified must now be cleared
      await waitFor(() => {
        expect(screen.queryByTestId('manager-verified')).not.toBeInTheDocument();
      });
    });
  });
});
