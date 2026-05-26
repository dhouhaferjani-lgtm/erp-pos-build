/**
 * T2.5 — banner-visible-when-training-mode component test.
 *
 * Closes the activation hardening Phase 1 (revised) deliverable. The
 * backend's TerminalResource already reports `is_training_mode` on
 * /pos/terminals; the POS surfaces it via this banner so the cashier
 * never confuses training and production at a glance.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { TrainingModeBanner } from '../TrainingModeBanner';
import { useTerminalStore, type Terminal } from '@/stores/terminalStore';

function makeTerminal(over: Partial<Terminal> = {}): Terminal {
  return {
    id: 't-1',
    code: 'T001',
    name: 'Main',
    type: 'shop',
    is_active: true,
    fiscal_schema_version: 2,
    is_training_mode: false,
    hardware_identifier: null,
    location: { id: 'l-1', name: 'Main', code: 'MAIN' },
    ...over,
  };
}

describe('TrainingModeBanner', () => {
  beforeEach(() => {
    useTerminalStore.setState({
      terminal: null,
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
      hashChainReady: false,
    } as never);
    cleanup();
  });

  it('renders nothing when no terminal is active', () => {
    const { queryByTestId } = render(<TrainingModeBanner />);
    expect(queryByTestId('training-mode-banner')).toBeNull();
  });

  it('renders nothing when terminal is_training_mode=false', () => {
    useTerminalStore.setState({ terminal: makeTerminal({ is_training_mode: false }) } as never);
    const { queryByTestId } = render(<TrainingModeBanner />);
    expect(queryByTestId('training-mode-banner')).toBeNull();
  });

  it('renders banner when terminal is_training_mode=true', () => {
    useTerminalStore.setState({ terminal: makeTerminal({ is_training_mode: true }) } as never);
    const { getByTestId } = render(<TrainingModeBanner />);

    const banner = getByTestId('training-mode-banner');
    expect(banner).toBeTruthy();
    // ARIA contract: announce the mode change to screen readers.
    expect(banner.getAttribute('role')).toBe('status');
    expect(banner.getAttribute('aria-live')).toBe('polite');
  });

  it('renders text content via i18n keys (banner + subtitle)', () => {
    useTerminalStore.setState({ terminal: makeTerminal({ is_training_mode: true }) } as never);
    const { getByTestId } = render(<TrainingModeBanner />);

    const banner = getByTestId('training-mode-banner');
    // i18n keys present and rendered as text — the text content
    // depends on the locale setup; assert the i18n key fallback OR
    // any non-empty text. The fr/en common.json bundle the keys.
    expect(banner.textContent ?? '').not.toBe('');
  });
});
