/**
 * Round-2 T32-B2: wire-up + render tests for UnsyncedRiskIndicator.
 *
 * Round-1's POS-side coverage only tested the OffDeviceDurabilityService —
 * the component itself was never asserted to render with a real store
 * shape, and no caller mounted it. Round-2 wires the indicator to read
 * from `useDurabilityStore`; these tests pin the rendering contract
 * (null pre-first-poll; colored badge with `data-risk-level` after a poll).
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, cleanup, screen } from '@testing-library/react';

import { useDurabilityStore } from '@/stores/durabilityStore';
import { UnsyncedRiskIndicator } from '../UnsyncedRiskIndicator';

// Translate the bare keys to themselves so the assertions can match without
// requiring the full i18next setup.
import { I18nextProvider, initReactI18next } from 'react-i18next';
import i18n from 'i18next';

void i18n.use(initReactI18next).init({
  lng: 'en',
  fallbackLng: 'en',
  ns: ['fiscal'],
  defaultNS: 'fiscal',
  resources: {
    en: {
      fiscal: {
        unsyncedRisk: {
          label: { normal: 'All synced', elevated: 'Elevated', escalated: 'Critical' },
          detail: { normal: 'ok', elevated: 'warn', escalated: 'stop' },
        },
      },
    },
  },
  interpolation: { escapeValue: false },
});

function renderWithI18n(node: React.ReactElement) {
  return render(<I18nextProvider i18n={i18n}>{node}</I18nextProvider>);
}

afterEach(() => {
  cleanup();
  useDurabilityStore.getState().reset();
});

describe('UnsyncedRiskIndicator — store-driven rendering', () => {
  it('renders nothing before the first poll result lands', () => {
    renderWithI18n(<UnsyncedRiskIndicator />);
    expect(screen.queryByTestId('unsynced-risk-indicator')).toBeNull();
  });

  it('renders a green normal badge when the store reports normal risk', () => {
    useDurabilityStore.getState().setPollResult({
      riskLevel: 'normal',
      forceArchiveRequired: false,
    });

    renderWithI18n(<UnsyncedRiskIndicator />);
    const badge = screen.getByTestId('unsynced-risk-indicator');
    expect(badge).not.toBeNull();
    expect(badge.getAttribute('data-risk-level')).toBe('normal');
    expect(badge.getAttribute('role')).toBe('status');
    expect(badge.getAttribute('aria-live')).toBe('polite');
    expect(badge.textContent).toContain('All synced');
  });

  it('renders an amber elevated badge when the store reports elevated risk', () => {
    useDurabilityStore.getState().setPollResult({
      riskLevel: 'elevated',
      forceArchiveRequired: true,
    });

    renderWithI18n(<UnsyncedRiskIndicator />);
    const badge = screen.getByTestId('unsynced-risk-indicator');
    expect(badge.getAttribute('data-risk-level')).toBe('elevated');
  });

  it('renders a red escalated badge when the store reports escalated risk', () => {
    useDurabilityStore.getState().setPollResult({
      riskLevel: 'escalated',
      forceArchiveRequired: true,
    });

    renderWithI18n(<UnsyncedRiskIndicator />);
    const badge = screen.getByTestId('unsynced-risk-indicator');
    expect(badge.getAttribute('data-risk-level')).toBe('escalated');
  });
});
