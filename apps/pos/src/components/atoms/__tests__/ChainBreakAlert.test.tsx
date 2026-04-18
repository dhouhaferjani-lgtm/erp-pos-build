import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ChainBreakAlert } from '@/components/atoms/ChainBreakAlert';
import { useSyncStore } from '@/stores/syncStore';
import '@/lib/i18n';

describe('ChainBreakAlert', () => {
  beforeEach(() => {
    useSyncStore.setState({
      chainBreak: false,
      chainBreakReceiptNumber: null,
      chainBreakAcknowledgedAt: null,
    });
  });

  it('renders nothing when chainBreak is false', () => {
    const { container } = render(<ChainBreakAlert />);
    expect(container.firstChild).toBeNull();
  });

  it('renders alert banner when chainBreak is true', () => {
    useSyncStore.setState({ chainBreak: true, chainBreakReceiptNumber: 'MAIN-T001-2026-00000042' });
    render(<ChainBreakAlert />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText(/MAIN-T001-2026-00000042/)).toBeInTheDocument();
  });

  it('records acknowledgement timestamp when dismiss button clicked', () => {
    useSyncStore.setState({ chainBreak: true, chainBreakReceiptNumber: 'R1' });
    render(<ChainBreakAlert />);
    fireEvent.click(screen.getByRole('button'));
    expect(useSyncStore.getState().chainBreakAcknowledgedAt).toBeTruthy();
  });

  it('after acknowledge, renders compact acknowledged variant', () => {
    useSyncStore.setState({
      chainBreak: true,
      chainBreakReceiptNumber: 'R1',
      chainBreakAcknowledgedAt: null,
    });
    render(<ChainBreakAlert />);
    fireEvent.click(screen.getByRole('button'));
    // description and contactSupport text no longer visible
    expect(
      screen.queryByText(/hash chain has a break/i),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(/Reference this alert/i),
    ).not.toBeInTheDocument();
    // title and acknowledgedAt line are still visible
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText(/Fiscal receipt chain broken/i)).toBeInTheDocument();
    expect(screen.getByText(/Acknowledged at/i)).toBeInTheDocument();
  });
});
