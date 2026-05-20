/**
 * Round-2 T32-B2: integration test for DurabilityGateModal.
 *
 * Spec §12's "forced archive/export threshold" is delivered as a blocking
 * modal: when `OffDeviceDurabilityService.shouldForceArchive()` returns
 * true AND no live acknowledgment grace covers the current moment, the
 * modal renders and blocks the entire POS UI. These tests pin the
 * gate-armed predicate behavior + the operator-acknowledgment grace
 * window.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, fireEvent, cleanup, screen, act } from '@testing-library/react';

import { useDurabilityStore } from '@/stores/durabilityStore';
import { DurabilityGateModal } from '../DurabilityGateModal';

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
        durabilityGate: {
          title: 'Off-device archive required',
          description: 'desc',
          acknowledgeButton: 'Acknowledge & continue',
          phase2Note: 'phase 2 note',
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

describe('DurabilityGateModal — spec §12 forced-archive gate', () => {
  it('renders nothing when forceArchiveRequired is false', () => {
    useDurabilityStore.getState().setPollResult({
      riskLevel: 'normal',
      forceArchiveRequired: false,
    });

    renderWithI18n(<DurabilityGateModal />);
    expect(screen.queryByTestId('durability-gate-modal')).toBeNull();
  });

  it('shows the blocking modal when forceArchiveRequired flips to true', () => {
    useDurabilityStore.getState().setPollResult({
      riskLevel: 'escalated',
      forceArchiveRequired: true,
    });

    renderWithI18n(<DurabilityGateModal />);
    const modal = screen.getByTestId('durability-gate-modal');
    expect(modal).not.toBeNull();
    expect(modal.getAttribute('role')).toBe('dialog');
    expect(modal.getAttribute('aria-modal')).toBe('true');
    expect(screen.getByTestId('durability-gate-acknowledge')).not.toBeNull();
  });

  it('hides the modal after the operator acknowledges (until grace expires)', () => {
    useDurabilityStore.getState().setPollResult({
      riskLevel: 'escalated',
      forceArchiveRequired: true,
    });

    renderWithI18n(<DurabilityGateModal />);
    expect(screen.getByTestId('durability-gate-modal')).not.toBeNull();

    act(() => {
      fireEvent.click(screen.getByTestId('durability-gate-acknowledge'));
    });

    expect(screen.queryByTestId('durability-gate-modal')).toBeNull();
    // The acknowledgment grace is recorded with a future expiry.
    const ack = useDurabilityStore.getState().acknowledgedUntil;
    expect(ack).not.toBeNull();
    expect(ack as number).toBeGreaterThan(Date.now());
  });

  it('re-arms the gate after the acknowledgment grace expires (next poll keeps forceArchiveRequired true)', () => {
    // Simulate an acknowledgment that has already expired.
    useDurabilityStore.setState({
      riskLevel: 'escalated',
      forceArchiveRequired: true,
      acknowledgedUntil: Date.now() - 1000,
    });

    renderWithI18n(<DurabilityGateModal />);
    expect(screen.getByTestId('durability-gate-modal')).not.toBeNull();
  });
});
